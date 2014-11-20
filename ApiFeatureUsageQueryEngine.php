<?php
abstract class ApiFeatureUsageQueryEngine {
	public $options;

	/**
	 * @return ApiFeatureUsageQueryEngine
	 */
	public static function getEngine( Config $config ) {
		$conf = $config->get( 'ApiFeatureUsageQueryEngineConf' );
		if ( isset( $conf['factory'] ) ) {
			return $conf['factory']( $conf );
		} elseif ( isset( $conf['class'] ) ) {
			return new $conf['class']( $conf );
		} else {
			throw new MWException( '$wgApiFeatureUsageQueryEngineConf does not define an engine' );
		}
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
	 * @return Status
	 */
	abstract public function execute( $agent, MWTimestamp $start, MWTimestamp $end );

	/**
	 * Get a suggested date range
	 * @return MWTimestamp[]
	 */
	abstract public function suggestDateRange();
}
