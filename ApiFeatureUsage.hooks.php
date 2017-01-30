<?php
class ApiFeatureUsageHooks {

	/**
	 * Add deprecation help referring to Special:ApiFeatureUsage
	 * @param Message[] &$msgs
	 */
	public static function onApiDeprecationHelp( &$msgs ) {
		$msgs[] = wfMessage( 'apifeatureusage-deprecation-help' );
	}
}
