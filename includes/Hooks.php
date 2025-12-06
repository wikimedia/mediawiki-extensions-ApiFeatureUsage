<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use MediaWiki\Api\Hook\ApiDeprecationHelpHook;
use MediaWiki\Api\Hook\ApiLogFeatureUsageHook;
use MediaWiki\Message\Message;

class Hooks implements ApiDeprecationHelpHook, ApiLogFeatureUsageHook {
	public function __construct( private readonly ApiFeatureUsageQueryEngine $engine ) {
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
