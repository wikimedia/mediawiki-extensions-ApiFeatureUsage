<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use InvalidArgumentException;
use MediaWiki\Extension\Elastica\ElasticaConnection;

/**
 * Class to create the connection
 */
class ApiFeatureUsageQueryEngineElasticaConnection extends ElasticaConnection {
	/** @var array */
	private $options = [];

	/**
	 * @param array|null $options
	 */
	public function __construct( $options = null ) {
		if ( !is_array( $options ) ) {
			$options = [];
		}

		if ( empty( $options['serverList'] ) || !is_array( $options['serverList'] ) ) {
			throw new InvalidArgumentException( __METHOD__ . ': serverList is not set or is not valid.' );
		}

		$this->options = $options + [
			'maxConnectionAttempts' => 1,
		];
	}

	/** @inheritDoc */
	public function getServerList() {
		return $this->options['serverList'];
	}

	/** @inheritDoc */
	public function getMaxConnectionAttempts() {
		return $this->options['maxConnectionAttempts'];
	}
}
