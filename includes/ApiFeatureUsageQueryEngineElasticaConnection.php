<?php
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
