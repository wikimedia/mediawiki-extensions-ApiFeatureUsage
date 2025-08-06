<?php

namespace MediaWiki\Extension\ApiFeatureUsage\HookHandler;

use MediaWiki\Extension\ApiFeatureUsage\ApiFeatureUsageQueryEngineSql;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @codeCoverageIgnore This is tested by installing or updating MediaWiki
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		global $wgApiFeatureUsageQueryEngineConf;

		if ( !is_a( $wgApiFeatureUsageQueryEngineConf['class'], ApiFeatureUsageQueryEngineSql::class, true ) ) {
			return;
		}

		$dbType = $updater->getDB()->getType();
		$dir = __DIR__ . "/../../schema";

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-apifeatureusage',
			'addTable',
			'api_feature_usage',
			"$dir/$dbType/tables-generated.sql",
			true
		] );
	}
}
