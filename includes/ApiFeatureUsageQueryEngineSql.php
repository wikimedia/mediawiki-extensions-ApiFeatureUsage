<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use MediaWiki\Deferred\AutoCommitUpdate;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Utils\MWTimestamp;
use ObjectCacheFactory;
use Wikimedia\IPUtils;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
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
	 *   - maxAgeDays: the age, in days, at which hit count rows are considered expired.
	 *   - purgePeriod: average hit count row upserts needed to trigger expired row purges.
	 *   - purgeBatchSize: maximum number of rows to delete per query when purging expired rows.
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
			'maxAgeDays' => 90,
			'purgePeriod' => 10,
			'purgeBatchSize' => 30,
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
		?array $features = null
	) {
		$cutoff = $this->getCutoffTimestamp();

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
				substr( $cutoff->getTimestamp( TS_MW ), 0, 8 )
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

		$cutoff = $this->getCutoffTimestamp();

		$dbr = $this->dbProvider->getReplicaDatabase( 'virtual-apifeatureusage' );
		$date = $dbr->newSelectQueryBuilder()
			->select( 'afu_date' )
			->from( 'api_feature_usage' )
			->where( $dbr->expr(
				'afu_date',
				'>=',
				substr( $cutoff->getTimestamp( TS_MW ), 0, 8 )
			) )
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
		if (
			defined( 'MW_PHPUNIT_TEST' ) &&
			MediaWikiServices::getInstance()->isStorageDisabled()
		) {
			// Bail out immediately if storage is disabled. This should never happen in normal
			// operations, but can happen in API module tests via the ApiLogFeatureUsage hook.
			// If such a test is not in the database group, this code will be reached without
			// being able to access the DB.
			return;
		}

		$now = MWTimestamp::now( TS_MW );
		DeferredUpdates::addCallableUpdate(
			function ( $fname ) use ( $feature, $agent, $ipAddress, $now ) {
				$key = $this->cache->makeGlobalKey(
					'afu-recent-hits',
					$feature,
					sha1( $agent ),
					substr( $now, 0, 8 )
				);

				$this->cache->watchErrors();
				// Get the feature hit count from this agent today
				$hits = $this->cache->get( $key );
				$error = $this->cache->getLastError();
				if ( $error !== BagOStuff::ERR_NONE ) {
					// Do not risk flooding the DB
					return;
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
						->caller( $fname )
						->fetchField();

					$this->cache->add( $key, $hits, ExpirationAwareness::TTL_HOUR );

					if ( !$hits && $this->pingInsertLimiter( $ipAddress ) ) {
						// Do not flood the DB due to user agent churn
						return;
					}
				}

				$delta = $this->getCounterLotteryDelta( $hits );
				if ( $delta <= 0 ) {
					// No DB update this time
					return;
				}

				$this->cache->incrWithInit( $key, ExpirationAwareness::TTL_HOUR, $delta, $delta );

				// @todo refactor AutoCommitUpdate to eliminate the outer MWCallableUpdate here
				DeferredUpdates::addUpdate( new AutoCommitUpdate(
					$this->dbProvider->getPrimaryDatabase( 'virtual-apifeatureusage' ),
					$fname,
					function ( IDatabase $dbw, $fname ) use ( $feature, $agent, $delta, $now ) {
						// Increment the counter in way that is safe for both primary/replica
						// replication and circular statement-based replication. Do the query
						// in autocommit mode to limit lock contention.
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

						$this->maybeDoPeriodicPrune();
					}
				) );
			}
		);
	}

	private function maybeDoPeriodicPrune() {
		if (
			$this->options['purgePeriod'] > 0 &&
			mt_rand( 1, $this->options['purgePeriod'] ) == 1
		) {
			$this->prune( null, $this->options['purgeBatchSize'] );
		}
	}

	/** @inheritDoc */
	public function prune( $progressFn = null, $limit = INF ) {
		$cutoff = $this->getCutoffTimestamp();
		$batchSize = min( $this->options['purgeBatchSize'], $limit );

		$dbw = $this->dbProvider->getPrimaryDatabase( 'virtual-apifeatureusage' );

		$doneCount = 0;
		$totalCount = null;
		if ( $progressFn ) {
			$totalCount = $dbw->newSelectQueryBuilder()
				->select( [ 'COUNT(*)' ] )
				->from( 'api_feature_usage' )
				->where( $dbw->expr(
					'afu_date',
					'<',
					substr( $cutoff->getTimestamp( TS_MW ), 0, 8 )
				) )
				->caller( __METHOD__ )
				->fetchField();
		}

		do {
			$res = $dbw->newSelectQueryBuilder()
				->select( [ 'afu_date', 'afu_feature', 'afu_agent' ] )
				->from( 'api_feature_usage' )
				->where( $dbw->expr(
					'afu_date',
					'<',
					substr( $cutoff->getTimestamp( TS_MW ), 0, 8 )
				) )
				->orderBy( 'afu_date', SelectQueryBuilder::SORT_ASC )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchResultSet();

			if ( $res->numRows() ) {
				$keyTuples = [];
				foreach ( $res as $row ) {
					$keyTuples[] = (array)$row;
				}

				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'api_feature_usage' )
					->where( $dbw->factorConds( $keyTuples ) )
					->caller( __METHOD__ )
					->execute();

				$doneCount += $dbw->affectedRows();
			}

			if ( $progressFn ) {
				$ratio = $totalCount ? ( $doneCount / $totalCount ) : 1.0;
				$progressFn( $ratio * 100 );
			}
		} while ( $res->numRows() && $doneCount < $limit );

		return $doneCount;
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

	/**
	 * @return MWTimestamp
	 */
	private function getCutoffTimestamp() {
		$cutoff = new MWTimestamp();
		$cutoff->setTimezone( 'UTC' );
		$cutoff->sub( 'P' . $this->options['maxAgeDays'] . 'D' );

		return $cutoff;
	}
}
