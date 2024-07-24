<?php

use MediaWiki\Config\ConfigException;
use MediaWiki\Extension\ApiFeatureUsage\ApiFeatureUsageQueryEngine;
use MediaWiki\Extension\ApiFeatureUsage\ApiFeatureUsageQueryEngineSql;
use MediaWiki\MediaWikiServices;

return [
	'ApiFeatureUsage.QueryEngine' => static function ( MediaWikiServices $services ): ApiFeatureUsageQueryEngine {
		$conf = $services->getMainConfig()->get( 'ApiFeatureUsageQueryEngineConf' );

		if ( isset( $conf['factory'] ) ) {
			$spec = [ 'factory' => $conf['factory'], 'args' => [ $conf ] ];
		} elseif ( isset( $conf['class'] ) ) {
			$class = $conf['class'];
			$spec = [ 'class' => $class, 'args' => [ $conf ] ];
			if ( is_a( $class, ApiFeatureUsageQueryEngineSql::class, true ) ) {
				$spec['services'] = [
					'ConnectionProvider',
					'WRStatsFactory',
					'ObjectCacheFactory'
				];
			}
		} else {
			throw new ConfigException( '$wgApiFeatureUsageQueryEngineConf does not define an engine' );
		}

		/** @var ApiFeatureUsageQueryEngine $instance */
		// @phan-suppress-next-line PhanTypeInvalidCallableArraySize
		$instance = $services->getObjectFactory()->createObject( $spec );

		return $instance;
	},
];
