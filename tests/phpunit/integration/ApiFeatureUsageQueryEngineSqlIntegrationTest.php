<?php
namespace MediaWiki\Extension\ApiFeatureUsage\Test;

use MediaWiki\Extension\ApiFeatureUsage\ApiFeatureUsageQueryEngineSql;
use MediaWiki\Utils\MWTimestamp;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\ApiFeatureUsage\ApiFeatureUsageQueryEngineSql
 */
class ApiFeatureUsageQueryEngineSqlIntegrationTest extends MediaWikiIntegrationTestCase {
	public function testRecordAndEnumerate() {
		$services = $this->getServiceContainer();
		$engine = new ApiFeatureUsageQueryEngineSql(
			$services->getDBLoadBalancerFactory(),
			$services->getWRStatsFactory(),
			$services->getObjectCacheFactory(),
			[]
		);
		$featureA = 'legacy-feature-a';
		$featureB = 'legacy-feature-b';
		$featureO = 'legacy-feature-other';
		$userAgent = 'testing-bot-client';
		$ipAddress = '192.0.2.0';

		// Insert records from prior days...
		$seed = 25252;
		$then = 1721934403;
		$dateThen = '2024-07-25';
		for ( $i = 0; $i < 100; ++$i ) {
			MWTimestamp::setFakeTime( $then );
			mt_srand( $seed++ );
			$engine->record( $featureO, $userAgent, $ipAddress );
			$then += 1;
		}

		// Insert records from days of interest...
		$seed = 648950;
		$now = 1722020803;
		$dateNow = '2024-07-26';
		$start = MWTimestamp::getInstance( $now );
		for ( $i = 0; $i < 500; ++$i ) {
			MWTimestamp::setFakeTime( $now );
			mt_srand( $seed++ );
			$engine->record( $featureA, $userAgent, $ipAddress );
			$now += 1;
		}
		for ( $i = 0; $i < 100; ++$i ) {
			MWTimestamp::setFakeTime( $now );
			mt_srand( $seed++ );
			$engine->record( $featureB, $userAgent, $ipAddress );
			$now += 1;
		}
		MWTimestamp::setFakeTime( $now );
		$end = MWTimestamp::getInstance( $now );

		$status = $engine->enumerate( $userAgent, $start, $end, [ $featureA, $featureB ] );
		$expected = [
			[ 'feature' => $featureA, 'date' => $dateNow, 'count' => 484 ],
			[ 'feature' => $featureB, 'date' => $dateNow, 'count' => 101 ]
		];
		$this->assertTrue( $status->isGood() );
		$this->assertSame( $expected, $status->value );

		[ $sugStart, $sugEnd ] = $engine->suggestDateRange();
		$this->assertSame( $dateThen, $sugStart->format( 'Y-m-d' ), "Correct start range" );
		$this->assertSame( $dateNow, $sugEnd->format( 'Y-m-d' ), "Correct end range" );

		$status = $engine->enumerate( $userAgent, $sugStart, $sugEnd );
		$expected = [
			[ 'feature' => $featureO, 'date' => $dateThen, 'count' => 124 ],
			[ 'feature' => $featureA, 'date' => $dateNow, 'count' => 484 ],
			[ 'feature' => $featureB, 'date' => $dateNow, 'count' => 101 ]
		];

		$this->assertTrue( $status->isGood() );
		$this->assertSame( $expected, $status->value );
	}

	public function testPrune() {
		$services = $this->getServiceContainer();
		$engine = new ApiFeatureUsageQueryEngineSql(
			$services->getDBLoadBalancerFactory(),
			$services->getWRStatsFactory(),
			$services->getObjectCacheFactory(),
			[ 'maxAgeDays' => 1 ]
		);

		$featureA = 'legacy-feature-a';
		$userAgent = 'testing-bot-client';
		$ipAddress = '189.24.24.9';

		mt_srand( 64895 );
		$now = 1722020803;
		$start = MWTimestamp::getInstance( $now );
		$date = '2024-07-26';
		for ( $i = 0; $i < 100; ++$i ) {
			MWTimestamp::setFakeTime( $now );
			$engine->record( $featureA, $userAgent, $ipAddress );
			$now += 1;
		}
		MWTimestamp::setFakeTime( $now );
		$end = MWTimestamp::getInstance( $now );

		$engine->prune();

		$status = $engine->enumerate( $userAgent, $start, $end, [ $featureA ] );
		$expected = [
			[ 'feature' => $featureA, 'date' => $date, 'count' => 81 ]
		];
		$this->assertTrue( $status->isGood() );
		$this->assertSame( $expected, $status->value );

		$now += ( 2 * 86400 );
		MWTimestamp::setFakeTime( $now );

		$engine->prune();

		$status = $engine->enumerate( $userAgent, $start, $end, [ $featureA ] );
		$expected = [];
		$this->assertTrue( $status->isGood() );
		$this->assertSame( $expected, $status->value );
	}
}
