<?php
/**
 * Query a logging data source (e.g. Elasticsearch/Logstash) for API feature
 * usage statistics
 * Copyright (C) 2014 Brad Jorsch <bjorsch@wikimedia.org>
 * http://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'ApiFeatureUsage',
	'author' => 'Brad Jorsch',
	'url' => 'https://www.mediawiki.org/wiki/Extension:ApiFeatureUsage',
	'descriptionmsg' => 'apifeatureusage-desc',
	'version' => '1.0',
);

$wgAutoloadClasses['SpecialApiFeatureUsage'] = __DIR__ . '/SpecialApiFeatureUsage.php';
$wgAutoloadClasses['ApiQueryFeatureUsage'] = __DIR__ . '/ApiQueryFeatureUsage.php';
$wgAutoloadClasses['ApiFeatureUsageQueryEngine'] = __DIR__ . '/ApiFeatureUsageQueryEngine.php';
$wgAutoloadClasses['ApiFeatureUsageQueryEngineElastica'] = __DIR__ . '/ApiFeatureUsageQueryEngineElastica.php';
$wgAutoloadClasses['ApiFeatureUsageQueryEngineElasticaConnection'] = __DIR__ . '/ApiFeatureUsageQueryEngineElastica.php';

$wgMessagesDirs['ApiFeatureUsage'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['ApiFeatureUsageAlias'] = __DIR__ . '/ApiFeatureUsage.alias.php';
$wgSpecialPages['ApiFeatureUsage'] = 'SpecialApiFeatureUsage';
$wgSpecialPageGroups['ApiFeatureUsage'] = 'wiki';
$wgAPIMetaModules['featureusage'] = 'ApiQueryFeatureUsage';
$wgConfigRegistry['ApiFeatureUsage'] = 'GlobalVarConfig::newInstance';

$wgResourceModules['ext.apifeatureusage'] = array(
	'localBasePath' => __DIR__ . '/modules',
	'remoteExtPath' => 'ApiFeatureUsage/modules',
	'styles' => 'ext.apifeatureusage.css',
	'position' => 'top',
);

/**
 * Engine configuration. Must contain either a 'class' or a 'factory' member;
 * other members depend on the engine.
 */
$wgApiFeatureUsageQueryEngineConf = array();

/**
 * @todo HTMLForm stuff should be migrated to core
 */
$wgAutoloadClasses['ApiFeatureUsage_HTMLDateField'] = __DIR__ . '/htmlform/HTMLDateField.php';
$wgAutoloadClasses['ApiFeatureUsage_HTMLDateRangeField'] = __DIR__ . '/htmlform/HTMLDateRangeField.php';
$wgResourceModules['ext.apifeatureusage.htmlform'] = array(
	'localBasePath' => __DIR__ . '/htmlform',
	'remoteExtPath' => 'ApiFeatureUsage/htmlform',
	'scripts' => 'ext.apifeatureusage.htmlform.js',
);
