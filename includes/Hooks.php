<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use MediaWiki\Api\Hook\ApiDeprecationHelpHook;
use Message;

class Hooks implements ApiDeprecationHelpHook {

	/**
	 * Add deprecation help referring to Special:ApiFeatureUsage
	 * @param Message[] &$msgs
	 */
	public function onApiDeprecationHelp( &$msgs ) {
		$msgs[] = wfMessage( 'apifeatureusage-deprecation-help' );
	}
}
