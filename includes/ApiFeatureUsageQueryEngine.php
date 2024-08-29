<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use MediaWiki\Status\Status;
use MediaWiki\Utils\MWTimestamp;

abstract class ApiFeatureUsageQueryEngine {
	/** @var array<string,mixed> */
	public $options;

	/**
	 * @param array $options
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Execute the query to enumerate the daily aggregated feature use counts
	 *
	 * Status object's value is an array of arrays, with the subarrays each
	 * having keys 'feature', 'date', and 'count'. The 'date' key is the date
	 * of the day formatted as "YYYY-MM-DD".
	 *
	 * @param string $agent
	 * @param MWTimestamp $start
	 * @param MWTimestamp $end
	 * @param string[]|null $features
	 * @return Status
	 */
	abstract public function enumerate(
		string $agent,
		MWTimestamp $start,
		MWTimestamp $end,
		array $features = null
	);

	/**
	 * Get a suggested date range
	 * @return MWTimestamp[]
	 */
	abstract public function suggestDateRange();

	/**
	 * Record the usage of an API feature
	 *
	 * @param string $feature
	 * @param string $agent
	 * @param string $ipAddress
	 * @return void
	 */
	abstract public function record(
		string $feature,
		string $agent,
		string $ipAddress
	);
}
