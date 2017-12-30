<?php
class ApiQueryFeatureUsage extends ApiQueryBase {

	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'afu' );
	}

	public function execute() {
		$params = $this->extractRequestParams();

		$agent = $params['agent'] === null
			? $this->getMain()->getUserAgent()
			: $params['agent'];
		if ( empty( $agent ) ) {
			$encParamName = $this->encodeParamName( 'agent' );
			$this->dieWithError( 'apierror-apifeatureusage-emptyagent', "bad_$encParamName" );
		}

		$conf = ConfigFactory::getDefaultInstance()->makeConfig( 'ApiFeatureUsage' );
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

		$status = $engine->execute( $agent, $start, $end );
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

	protected function getExamplesMessages() {
		return [
			'action=query&meta=featureusage'
				=> 'apihelp-query+featureusage-example-simple',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Extension:ApiFeatureUsage';
	}

}
