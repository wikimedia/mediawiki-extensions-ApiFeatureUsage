<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigException;
use MediaWiki\Status\Status;
use MediaWiki\Utils\MWTimestamp;

abstract class ApiFeatureUsageQueryEngine {
	/** @var array */
	public $options;

	/**
	 * @param Config $config
	 * @return ApiFeatureUsageQueryEngine
	 */
	public static function getEngine( Config $config ) {
		$conf = $config->get( 'ApiFeatureUsageQueryEngineConf' );
		if ( isset( $conf['factory'] ) ) {
			return $conf['factory']( $conf );
		}

		if ( isset( $conf['class'] ) ) {
			return new $conf['class']( $conf );
		}

		throw new ConfigException( '$wgApiFeatureUsageQueryEngineConf does not define an engine' );
	}

	/**
	 * @param array $options
	 */
	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Execute the query
	 *
	 * Status object's value is an array of arrays, with the subarrays each
	 * having keys 'feature', 'date', and 'count'.
	 *
	 * @param string $agent
	 * @param MWTimestamp $start
	 * @param MWTimestamp $end
	 * @param string[]|null $features
	 * @return Status
	 */
	abstract public function execute(
		$agent, MWTimestamp $start, MWTimestamp $end, array $features = null
	);

	/**
	 * Get a suggested date range
	 * @return MWTimestamp[]
	 */
	abstract public function suggestDateRange();
}
