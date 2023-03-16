<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use DateInterval;
use Elastica\Aggregation\DateHistogram;
use Elastica\Aggregation\Terms as AggregationTerms;
use Elastica\Client;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\Prefix;
use Elastica\Query\Range;
use Elastica\Query\Terms as QueryTerms;
use Elastica\Search;
use MWException;
use MWTimestamp;
use Status;

/**
 * Query feature usage data from Elasticsearch.
 *
 * Config fields are:
 *  serverList: Array of servers to connect to
 *  maxConnectionAttempts: Maximum connection attempts
 *  indexPrefix: Index prefix
 *  indexFormat: Date format string for index
 *  featureField: Name of the field holding $feature
 *  timestampField: Name of the field holding the timestamp
 *  agentField: Name of the field holding the user agent
 */
class ApiFeatureUsageQueryEngineElastica extends ApiFeatureUsageQueryEngine {
	/** @var Client|null */
	private $client = null;
	/** @var string[]|null */
	private $indexNames = null;

	/**
	 * @param array $options
	 */
	public function __construct( array $options ) {
		$options += [
			'indexPrefix' => 'apifeatureusage-',
			'indexFormat' => 'Y.m.d',
			'featureField' => 'feature',
			'featureFieldAggSize' => 10000,
			'timestampField' => '@timestamp',
			'agentField' => 'agent',
		];

		parent::__construct( $options );
	}

	/**
	 * @return Client
	 */
	protected function getClient() {
		if ( !$this->client ) {
			$connection = new ApiFeatureUsageQueryEngineElasticaConnection( $this->options );
			$this->client = $connection->getClient();
		}
		return $this->client;
	}

	/**
	 * @return string[]
	 */
	protected function getIndexNames() {
		if ( !$this->indexNames ) {
			$response = $this->getClient()->request(
				urlencode( $this->options['indexPrefix'] ) . '*/_alias'
			);
			if ( $response->isOK() ) {
				$this->indexNames = array_keys( $response->getData() );
			} else {
				throw new MWException( __METHOD__ .
					': Cannot fetch index names from elasticsearch: ' .
					$response->getError()
				);
			}
		}
		return $this->indexNames;
	}

	/** @inheritDoc */
	public function execute( $agent, MWTimestamp $start, MWTimestamp $end, array $features = null ) {
		$status = Status::newGood( [] );

		# Force $start and $end to day boundaries
		$oneDay = new DateInterval( 'P1D' );
		$start = clone $start;
		$start->timestamp = clone $start->timestamp;
		$start->timestamp->setTime( 0, 0, 0 );
		$end = clone $end;
		$end->timestamp = clone $end->timestamp;
		$end->timestamp->setTime( 0, 0, 0 );
		$end->timestamp->add( $oneDay )->sub( new DateInterval( 'PT1S' ) );

		$query = new Query();

		$bools = new BoolQuery();

		$prefix = new Prefix();
		$prefix->setPrefix( $this->options['agentField'], $agent );
		$bools->addMust( $prefix );

		$bools->addMust( new Range( $this->options['timestampField'], [
			'gte' => $start->getTimestamp( TS_ISO_8601 ),
			'lte' => $end->getTimestamp( TS_ISO_8601 ),
		] ) );

		if ( $features !== null ) {
			$bools->addMust( new QueryTerms( $this->options['featureField'], $features ) );
		}

		$query->setQuery( $bools );

		$termsAgg = new AggregationTerms( 'feature' );
		$termsAgg->setField( $this->options['featureField'] );
		$termsAgg->setSize( $this->options['featureFieldAggSize'] );

		$datesAgg = new DateHistogram(
			'date', $this->options['timestampField'], 'day'
		);
		$datesAgg->setFormat( '8uuuu-MM-dd' );

		$termsAgg->addAggregation( $datesAgg );
		$query->addAggregation( $termsAgg );

		$search = new Search( $this->getClient() );
		$search->setOption( Search::OPTION_SIZE, 0 );

		$allIndexes = $this->getIndexNames();
		$indexAvailable = false;
		$skippedAny = false;
		$s = clone $start->timestamp;
		while ( $s <= $end->timestamp ) {
			$index = $this->options['indexPrefix'] . $s->format( $this->options['indexFormat'] );
			if ( in_array( $index, $allIndexes ) ) {
				$indexAvailable = true;
			} else {
				$skippedAny = true;
			}
			$s->add( $oneDay );
		}
		if ( !$indexAvailable ) {
			// No dates in range
			$status->warning( 'apifeatureusage-no-indexes' );
			return $status;
		}
		if ( $skippedAny ) {
			$status->warning( 'apifeatureusage-missing-indexes' );
		}

		$search->setQuery( $query );
		// Prefer the wildcard approach over using an explicit list of indices to avoid building a
		// list that might be too long to encode in the search URL.
		// This feature is rarely used so that it's probably fine to hit all these indices and let
		// the date filtering quickly skip unrelated ones.
		$search->addIndexByName( $this->options['indexPrefix'] . '*' );

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

		$oneDay = new DateInterval( 'P1D' );
		$allIndexes = $this->getIndexNames();
		while ( true ) {
			$start->timestamp->sub( $oneDay );
			$index = $this->options['indexPrefix'] . $start->format( $this->options['indexFormat'] );
			if ( !in_array( $index, $allIndexes ) ) {
				$start->timestamp->add( $oneDay );
				return [ $start, $end ];
			}
		}
	}
}
