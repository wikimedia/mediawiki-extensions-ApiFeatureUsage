<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use MediaWiki\Api\Hook\ApiDeprecationHelpHook;
use MediaWiki\Api\Hook\ApiLogFeatureUsageHook;
use MediaWiki\Message\Message;

class Hooks implements ApiDeprecationHelpHook, ApiLogFeatureUsageHook {
	/** @var ApiFeatureUsageQueryEngine */
	private $engine;

	/**
	 * @param ApiFeatureUsageQueryEngine $queryEngine
	 */
	public function __construct( ApiFeatureUsageQueryEngine $queryEngine ) {
		$this->engine = $queryEngine;
	}

	/**
	 * Add deprecation help referring to Special:ApiFeatureUsage
	 * @param Message[] &$msgs
	 */
	public function onApiDeprecationHelp( &$msgs ) {
		$msgs[] = wfMessage( 'apifeatureusage-deprecation-help' );
	}

	/**
	 * Log/tally the use of deprecated API features
	 *
	 * @param string $feature
	 * @param array $clientInfo
	 * @phan-param array{userName:string,userAgent:string,ipAddress:string} $clientInfo
	 * @return void
	 */
	public function onApiLogFeatureUsage( $feature, array $clientInfo ): void {
		$this->engine->record(
			$feature,
			$clientInfo['userAgent'],
			$clientInfo['ipAddress']
		);
	}
}
