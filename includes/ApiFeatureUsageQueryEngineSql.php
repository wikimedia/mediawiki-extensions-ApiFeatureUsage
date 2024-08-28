<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use BagOStuff;
use MediaWiki\Status\Status;
use MediaWiki\Utils\MWTimestamp;
use ObjectCacheFactory;
use Wikimedia\IPUtils;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\LightweightObjectStore\StorageAwareness;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\RawSQLValue;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\WRStats\LimitCondition;
use Wikimedia\WRStats\WRStatsFactory;

class ApiFeatureUsageQueryEngineSql extends ApiFeatureUsageQueryEngine {
	/** @var IConnectionProvider */
	private $dbProvider;
	/** @var WRStatsFactory */
	private $wrStatsFactory;
	/** @var BagOStuff */
	private $cache;

	/**
	 * @param IConnectionProvider $dbProvider
	 * @param WRStatsFactory $wrStatsFactory
	 * @param ObjectCacheFactory $objectCacheFactory
	 * @param array $options Additional options include:
	 *   - updateSampleFactorRatio: target ratio of ((hits per sampled hit) / total hits)
	 *      for updates to daily, per-agent, API feature use counters.
	 *   - minUpdateSampleFactor: minimum number of hits per sampled hit for updates to daily,
	 *      per-agent, API feature use counters.
	 *   - maxUpdateSampleFactor: maximum number of hits per sampled hit for updates to daily,
	 *      per-agent, API feature use counters.
	 *   - insertRateLimits: map with possible "ip" and "subnet" keys. Each value is a tuple
	 *      of (maximum new rows in the time window, the time window in seconds). The "ip"
	 *      entry applies to single client IP addresses. The "subnet" entry applies to the
	 *      /16 CIDR of IPv4 client addresses and the /64 CIDR of IPv6 client addresses.
	 *      These are safety limits to avoid flooding the database due to bots randomizing
	 *      their User-Agent or rotating their IP address.
	 */
	public function __construct(
		IConnectionProvider $dbProvider,
		WRStatsFactory $wrStatsFactory,
		ObjectCacheFactory $objectCacheFactory,
		array $options
	) {
		$options += [
			'updateSampleFactorRatio' => 0.1,
			'minUpdateSampleFactor' => 10,
			'maxUpdateSampleFactor' => 1_000,
			'insertRateLimits' => [
				'ip' => [ 30, 60 ]
			]
		];
		parent::__construct( $options );

		$this->dbProvider = $dbProvider;
		$this->wrStatsFactory = $wrStatsFactory;
		$this->cache = $objectCacheFactory->getLocalClusterInstance();
	}

	/** @inheritDoc */
	public function enumerate(
		string $agent,
		MWTimestamp $start,
		MWTimestamp $end,
		array $features = null
	) {
		$dbr = $this->dbProvider->getReplicaDatabase( 'virtual-apifeatureusage' );

		$sqb = $dbr->newSelectQueryBuilder()
			->select( [ 'afu_date', 'afu_feature', 'hits' => 'SUM(afu_hits)' ] )
			->from( 'api_feature_usage' )
			->where( $dbr->expr(
				'afu_agent',
				IExpression::LIKE,
				new LikeValue( $agent, $dbr->anyString() )
			) )
			->andWhere( $dbr->expr(
				'afu_date',
				'>=',
				substr( $start->getTimestamp( TS_MW ), 0, 8 )
			) )
			->andWhere( $dbr->expr(
				'afu_date',
				'<=',
				substr( $end->getTimestamp( TS_MW ), 0, 8 )
			) )
			->groupBy( [ 'afu_date', 'afu_feature' ] )
			->orderBy( [ 'afu_date', 'afu_feature' ], SelectQueryBuilder::SORT_ASC )
			->caller( __METHOD__ );

		if ( $features !== null ) {
			$sqb->andWhere( [ 'afu_feature' => $features ] );
		}

		$res = $sqb->fetchResultSet();

		$ret = [];
		foreach ( $res as $row ) {
			// Pad afu_date into TS_MW so that MWTimestamp can parse it
			$date = new MWTimestamp( $row->afu_date . '000000' );
			$ret[] = [
				'feature' => $row->afu_feature,
				'date' => $date->format( 'Y-m-d' ),
				'count' => (int)$row->hits
			];
		}

		return Status::newGood( $ret );
	}

	/** @inheritDoc */
	public function suggestDateRange() {
		$start = new MWTimestamp();
		$start->setTimezone( 'UTC' );
		$start->timestamp->setTime( 0, 0, 0 );
		$end = new MWTimestamp();
		$end->setTimezone( 'UTC' );

		$dbr = $this->dbProvider->getReplicaDatabase( 'virtual-apifeatureusage' );
		$date = $dbr->newSelectQueryBuilder()
			->select( 'afu_date' )
			->from( 'api_feature_usage' )
			->orderBy( 'afu_date', SelectQueryBuilder::SORT_ASC )
			->caller( __METHOD__ )
			->fetchField();

		if ( $date !== false ) {
			// Convert afu_date to TS_MW
			$start->setTimestamp( $date . '000000' );
		}

		return [ $start, $end ];
	}

	/** @inheritDoc */
	public function record(
		string $feature,
		string $agent,
		string $ipAddress
	) {
		$now = MWTimestamp::now( TS_MW );

		$key = $this->cache->makeGlobalKey(
			'afu-recent-hits',
			$feature,
			sha1( $agent ),
			substr( $now, 0, 8 )
		);

		$this->cache->watchErrors();
		$hits = $this->cache->get( $key );
		$error = $this->cache->getLastError();
		if ( $error !== StorageAwareness::ERR_NONE ) {
			// Do not risk flooding the DB
			return false;
		}

		if ( $hits === false ) {
			$dbr = $this->dbProvider->getReplicaDatabase( 'virtual-apifeatureusage' );

			$hits = (int)$dbr->newSelectQueryBuilder()
				->select( 'afu_hits' )
				->from( 'api_feature_usage' )
				->where( [
					'afu_feature' => $feature,
					'afu_agent' => substr( $agent, 0, 255 ),
					'afu_date' => substr( $now, 0, 8 )
				] )
				->caller( __METHOD__ )
				->fetchField();

			if ( $hits ) {
				$this->cache->add( $key, $hits, ExpirationAwareness::TTL_HOUR );
			} elseif ( $this->pingInsertLimiter( $ipAddress ) ) {
				// Do not flood the DB due to user agent churn
				return 0;
			}
		}

		$delta = $this->getCounterLotteryDelta( $hits );
		if ( $delta > 0 ) {
			$this->cache->incrWithInit( $key, ExpirationAwareness::TTL_HOUR, $delta, $delta );

			$dbw = $this->dbProvider->getPrimaryDatabase( 'virtual-apifeatureusage' );
			// Increment the counter in way that is safe for both primary/replica replication
			// and circular statement-based replication. Do the query in autocommit mode to
			// limit lock contention.
			$fname = __METHOD__;
			$dbw->onTransactionCommitOrIdle(
				static function () use ( $dbw, $feature, $agent, $delta, $now, $fname ) {
					$dbw->newInsertQueryBuilder()
						->insertInto( 'api_feature_usage' )
						->row( [
							'afu_feature' => $feature,
							'afu_agent' => $agent,
							'afu_date' => substr( $now, 0, 8 ),
							'afu_hits' => $delta
						] )
						->onDuplicateKeyUpdate()
						->uniqueIndexFields( [ 'afu_date', 'afu_feature', 'afu_agent' ] )
						->set( [ 'afu_hits' => new RawSQLValue( "afu_hits + $delta" ) ] )
						->caller( $fname )
						->execute();
				}
			);
		}
	}

	/**
	 * @param int $dayHitTotal
	 * @return int Number of samples represented by this hit (0 if not sampled)
	 */
	private function getCounterLotteryDelta( $dayHitTotal ) {
		// Always sample the first hit
		if ( $dayHitTotal <= 0 ) {
			return 1;
		}

		// How much to increment the feature use count for each sampled use hit
		$currentSampleFactor = (int)min(
			max(
				ceil( $this->options['updateSampleFactorRatio'] * $dayHitTotal ),
				$this->options['minUpdateSampleFactor']
			),
			$this->options['maxUpdateSampleFactor']
		);

		// Randomly decide whether to sample this feature use hit
		return ( mt_rand( 1, $currentSampleFactor ) == 1 ) ? $currentSampleFactor : 0;
	}

	/**
	 * @param string $ipAddress IP address of user triggering a row insertion
	 * @return bool Whether the row insertion limit was tripped
	 */
	private function pingInsertLimiter( $ipAddress ) {
		if ( $ipAddress === '' ) {
			return false;
		}

		$conds = [];
		foreach ( $this->options['insertRateLimits'] as $type => [ $limit, $window ] ) {
			$conds[$type] = new LimitCondition( $limit, $window );
		}
		$limiter = $this->wrStatsFactory->createRateLimiter(
			$conds,
			[ 'limiter', 'apifeatureusage-counter-init' ]
		);
		$limitBatch = $limiter->createBatch( 1 );
		if ( isset( $conds['ip'] ) ) {
			$limitBatch->globalOp( 'ip', $ipAddress );
		}
		if ( isset( $conds['subnet'] ) ) {
			$subnet = IPUtils::getSubnet( $ipAddress );
			if ( $subnet !== false ) {
				$limitBatch->globalOp( 'subnet', $subnet );
			}
		}

		$batchResult = $limitBatch->tryIncr();

		return !$batchResult->isAllowed();
	}
}
