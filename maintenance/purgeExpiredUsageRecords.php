<?php

use MediaWiki\Extension\ApiFeatureUsage\ApiFeatureUsageQueryEngine;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class PurgeExpiredUsageRecords extends Maintenance {
	/** @var null|string */
	private $lastProgress;
	/** @var null|float */
	private $lastTimestamp;
	/** @var int */
	private $calls = 0;

	/** @inheritDoc */
	public function __construct() {
		parent::__construct();

		$this->addDescription( "Purge expired records from the usage query engine" );
	}

	/** @inheritDoc */
	public function execute() {
		$this->output( "Deleting expired records\n" );
		/** @var ApiFeatureUsageQueryEngine $engine */
		$engine = MediaWikiServices::getInstance()->get( 'ApiFeatureUsage.QueryEngine' );
		$this->lastTimestamp = microtime( true );
		$engine->prune( [ $this, 'showProgressAndWait' ] );
		$this->showProgressAndWait( 100 );
		$this->output( "\nDone\n" );
	}

	/**
	 * @param float $percent
	 * @return void
	 */
	public function showProgressAndWait( $percent ) {
		$this->waitForReplication();
		$this->calls++;

		$percentString = sprintf( "%.1f", $percent );
		if ( $percentString === $this->lastProgress ) {
			// Only print a line if we've progressed >= 0.1% since the last printed line
			return;
		}

		$now = microtime( true );
		$sec = sprintf( "%.1f", $now - $this->lastTimestamp );

		// Give a sense of how much time is spent in the delete operations vs the sleep time,
		// by recording the number of iterations we've completed since the last progress update.
		$this->output( "... {$percentString}% done (+{$this->calls} iterations in {$sec}s)\n" );

		$this->lastProgress = $percentString;
		$this->lastTimestamp = $now;
		$this->calls = 0;
	}
}

$maintClass = PurgeExpiredUsageRecords::class;
require_once RUN_MAINTENANCE_IF_MAIN;
