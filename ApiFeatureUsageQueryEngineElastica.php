<?php
/**
 * Query feature usage data from Elasticsearch.
 *
 * Config fields are:
 *  serverList: Array of servers to connect to
 *  maxConnectionAttempts: Maximum connection attempts
 *  indexPrefix: Index prefix
 *  indexFormat: Date format string for index
 *  type: Elasticsearch type to be searched
 *  featureField: Name of the field holding $feature
 *  timestampField: Name of the field holding the timestamp
 *  agentField: Name of the field holding the user agent
 */
class ApiFeatureUsageQueryEngineElastica extends ApiFeatureUsageQueryEngine {
	private $client = null;
	private $indexNames = null;

	public function __construct( array $options ) {
		$options += [
			'indexPrefix' => 'apifeatureusage-',
			'indexFormat' => 'Y.m.d',
			'type' => 'api-feature-usage-sanitized',
			'featureField' => 'feature',
			'featureFieldAggSize' => 10000,
			'timestampField' => '@timestamp',
			'agentField' => 'agent',
		];

		parent::__construct( $options );
	}

	protected function getClient() {
		if ( !$this->client ) {
			$connection = new ApiFeatureUsageQueryEngineElasticaConnection( $this->options );
			$this->client = $connection->getClient();
		}
		return $this->client;
	}

	protected function getIndexNames() {
		if ( !$this->indexNames ) {
			$this->indexNames = $this->getClient()->getCluster()->getIndexNames();
		}
		return $this->indexNames;
	}

	public function execute( $agent, MWTimestamp $start, MWTimestamp $end ) {
		$status = Status::newGood( [] );

		# Force $start and $end to day boundaries
		$oneday = new DateInterval( 'P1D' );
		$start = clone $start;
		$start->timestamp = clone $start->timestamp;
		$start->timestamp->setTime( 0, 0, 0 );
		$end = clone $end;
		$end->timestamp = clone $end->timestamp;
		$end->timestamp->setTime( 0, 0, 0 );
		$end->timestamp->add( $oneday )->sub( new DateInterval( 'PT1S' ) );

		$query = new Elastica\Query();

		$bools = new Elastica\Query\BoolQuery();

		$prefix = new Elastica\Query\Prefix();
		$prefix->setPrefix( $this->options['agentField'], $agent );
		$bools->addMust( $prefix );

		$bools->addMust( new Elastica\Query\Range( $this->options['timestampField'], [
			'gte' => $start->getTimestamp( TS_ISO_8601 ),
			'lte' => $end->getTimestamp( TS_ISO_8601 ),
		] ) );
		$query->setQuery( $bools );

		$termsAgg = new Elastica\Aggregation\Terms( 'feature' );
		$termsAgg->setField( $this->options['featureField'] );
		$termsAgg->setSize( $this->options['featureFieldAggSize'] );

		$datesAgg = new Elastica\Aggregation\DateHistogram(
			'date', $this->options['timestampField'], 'day'
		);
		$datesAgg->setFormat( 'yyyy-MM-dd' );

		$termsAgg->addAggregation( $datesAgg );
		$query->addAggregation( $termsAgg );

		$search = new Elastica\Search( $this->getClient() );
		$search->setOption( Elastica\Search::OPTION_SIZE, 0 );

		$allIndexes = $this->getIndexNames();
		$indexes = [];
		$skippedAny = false;
		$s = clone $start->timestamp;
		while ( $s <= $end->timestamp ) {
			$index = $this->options['indexPrefix'] . $s->format( $this->options['indexFormat'] );
			if ( in_array( $index, $allIndexes ) ) {
				$indexes[] = $index;
			} else {
				$skippedAny = true;
			}
			$s->add( $oneday );
		}
		if ( !$indexes ) {
			// No dates in range
			$status->warning( 'apifeatureusage-no-indexes' );
			return $status;
		}
		if ( $skippedAny ) {
			$status->warning( 'apifeatureusage-missing-indexes' );
		}

		$search->addType( $this->options['type'] );
		$search->setQuery( $query );

		$res = $search->search();

		if ( $res->getResponse()->hasError() ) {
			return Status::newFatal(
				'apifeatureusage-elasticsearch-error', $res->getResponse()->getError()
			);
		}

		$ret = [];
		$aggs = $res->getAggregations();
		if ( isset( $aggs['feature'] ) ) {
			foreach ( $aggs['feature']['buckets'] as $feature ) {
				foreach ( $feature['date']['buckets'] as $date ) {
					$ret[] = [
						'feature' => $feature['key'],
						'date' => $date['key_as_string'],
						'count' => $date['doc_count'],
					];
				}
			}
		}
		$status->value = $ret;

		return $status;
	}

	public function suggestDateRange() {
		$start = new MWTimestamp();
		$start->setTimezone( 'UTC' );
		$start->timestamp->setTime( 0, 0, 0 );
		$end = new MWTimestamp();
		$end->setTimezone( 'UTC' );

		$oneday = new DateInterval( 'P1D' );
		$allIndexes = $this->getIndexNames();
		while ( true ) {
			$start->timestamp->sub( $oneday );
			$index = $this->options['indexPrefix'] . $start->format( $this->options['indexFormat'] );
			if ( !in_array( $index, $allIndexes ) ) {
				$start->timestamp->add( $oneday );
				return [ $start, $end ];
			}
		}
	}
}

/**
 * Class to create the connection
 */
class ApiFeatureUsageQueryEngineElasticaConnection extends ElasticaConnection {
	private $options = [];

	public function __construct( $options = null ) {
		if ( !is_array( $options ) ) {
			$options = [];
		}

		if ( empty( $options['serverList'] ) || !is_array( $options['serverList'] ) ) {
			throw new MWException( __METHOD__ . ': serverList is not set or is not valid.' );
		}

		$this->options = $options + [
			'maxConnectionAttempts' => 1,
		];
	}

	public function getServerList() {
		return $this->options['serverList'];
	}

	public function getMaxConnectionAttempts() {
		return $this->options['maxConnectionAttempts'];
	}
}
