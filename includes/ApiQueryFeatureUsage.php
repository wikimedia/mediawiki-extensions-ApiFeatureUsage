<?php
class ApiQueryFeatureUsage extends ApiQueryBase {

	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 */
	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'afu' );
	}

	/** @inheritDoc */
	public function execute() {
		$params = $this->extractRequestParams();

		$agent = $params['agent'] === null
			? $this->getMain()->getUserAgent()
			: $params['agent'];
		if ( empty( $agent ) ) {
			$encParamName = $this->encodeParamName( 'agent' );
			$this->dieWithError( 'apierror-apifeatureusage-emptyagent', "bad_$encParamName" );
		}

		$conf = \MediaWiki\MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'ApiFeatureUsage' );
		$engine = ApiFeatureUsageQueryEngine::getEngine( $conf );

		if ( $params['start'] === null || $params['end'] === null ) {
			list( $start, $end ) = $engine->suggestDateRange();
		}
		if ( $params['start'] !== null ) {
			$start = new MWTimestamp( $params['start'] );
			$start->setTimezone( 'UTC' );
		}
		if ( $params['end'] !== null ) {
			$end = new MWTimestamp( $params['end'] );
			$end->setTimezone( 'UTC' );
		}
		'@phan-var MWTimestamp $start';
		'@phan-var MWTimestamp $end';

		$status = $engine->execute( $agent, $start, $end, $params['features'] );
		if ( !$status->isOk() ) {
			$this->dieStatus( $status );
		}

		$this->addMessagesFromStatus( $status );

		$r = [
			'agent' => $agent,
			'start' => $start->getTimestamp( TS_ISO_8601 ),
			'end' => $end->getTimestamp( TS_ISO_8601 ),
			'usage' => $status->value,
		];

		$this->getResult()->setIndexedTagName( $r['usage'], 'v' );
		$this->getResult()->addValue( 'query', $this->getModuleName(), $r );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'start' => [
				ApiBase::PARAM_TYPE => 'timestamp',
			],
			'end' => [
				ApiBase::PARAM_TYPE => 'timestamp',
			],
			'agent' => [
				ApiBase::PARAM_TYPE => 'string',
			],
			'features' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_ISMULTI => true,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&meta=featureusage'
				=> 'apihelp-query+featureusage-example-simple',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:ApiFeatureUsage';
	}

}
