<?php

namespace MediaWiki\Extension\ApiFeatureUsage;

use Message;

class Hooks {

	/**
	 * Add deprecation help referring to Special:ApiFeatureUsage
	 * @param Message[] &$msgs
	 */
	public static function onApiDeprecationHelp( &$msgs ) {
		$msgs[] = wfMessage( 'apifeatureusage-deprecation-help' );
	}
}
