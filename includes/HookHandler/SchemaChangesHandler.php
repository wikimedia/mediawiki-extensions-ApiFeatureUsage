<?php

namespace MediaWiki\Extension\ApiFeatureUsage\HookHandler;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @codeCoverageIgnore This is tested by installing or updating MediaWiki
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
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
