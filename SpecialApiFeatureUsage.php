<?php
class SpecialApiFeatureUsage extends SpecialPage {
	private $engine = null;

	function __construct() {
		parent::__construct( 'ApiFeatureUsage' );
	}

	function execute( $par ) {
		$this->setHeaders();
		$this->checkPermissions();

		/** @todo These should be migrated to core, once the jquery.ui
		 * objectors write their own date picker. */
		if ( !isset( HTMLForm::$typeMappings['date'] ) || !isset( HTMLForm::$typeMappings['daterange'] ) ) {
			HTMLForm::$typeMappings['date'] = 'ApiFeatureUsage_HTMLDateField';
			HTMLForm::$typeMappings['daterange'] = 'ApiFeatureUsage_HTMLDateRangeField';
			$this->getOutput()->addModules( 'ext.apifeatureusage.htmlform' );
		}

		$request = $this->getRequest();

		$conf = ConfigFactory::getDefaultInstance()->makeConfig( 'ApiFeatureUsage' );
		$this->engine = ApiFeatureUsageQueryEngine::getEngine( $conf );
		list( $start, $end ) = $this->engine->suggestDateRange();

		$form = new HTMLForm( array(
			'agent' => array(
				'type' => 'text',
				'default' => '',
				'label-message' => 'apifeatureusage-agent-label',
				'required' => true,
			),

			'dates' => array(
				'type' => 'daterange',
				'label-message' => 'apifeatureusage-dates-label',
				'layout-message' => 'apifeatureusage-dates-layout',
				'absolute' => true,
				'required' => true,
				'default' => array(
					$start->format( 'Y-m-d' ),
					$end->format( 'Y-m-d' ),
				),
			),
		), $this->getContext() );
		$form->setMethod( 'get' );
		$form->setSubmitCallback( array( $this, 'onSubmit' ) );
		$form->setWrapperLegend( $this->msg( 'apifeatureusage-legend' ) );
		$form->addHeaderText( $this->msg( 'apifeatureusage-text' )->parseAsBlock() );
		$form->setSubmitTextMsg( 'apifeatureusage-submit' );

		$form->prepareForm();
		if ( $request->getCheck( 'wpagent' ) || $request->getCheck( 'wpdates' ) ) {
			$status = $form->trySubmit();
		} else {
			$status = false;
		}
		$form->displayForm( $status );

		if ( $status instanceof Status && $status->isOk() ) {
			$out = $this->getOutput();
			$out->addModuleStyles( 'ext.apifeatureusage' );

			$warnings = array();
			foreach ( $status->getWarningsArray() as $warning ) {
				if ( !$warning instanceof Message ) {
					$key = array_shift( $warning );
					$warning = $this->msg( $key, $warning );
				}
				$warnings[] = $warning->plain();
			}
			if ( $warnings ) {
				if ( count( $warnings ) > 1 ) {
					$warnings = "\n* " . join( "\n* ", $warnings );
				} else {
					$warnings = $warnings[0];
				}
				$out->wrapWikiMsg( "<div class='error'>\n$1\n</div>",
					array( 'apifeatureusage-warnings', $warnings )
				);
			}

			$lang = $this->getLanguage();
			$rows = array();
			foreach ( $status->value as $row ) {
				$cells = array();
				$cells[] = Html::element( 'td', array(), $row['feature'] );
				$cells[] = Html::rawElement( 'td', array(),
					Html::element( 'time', array(), $row['date'] )
				);
				$cells[] = Html::element( 'td', array( 'class' => 'mw-apifeatureusage-count' ),
					$lang->formatNum( $row['count'] )
				);

				$rows[] = Html::rawElement( 'tr', array(), join( '', $cells ) );
			}
			$this->getOutput()->addHTML(
				Html::rawElement( 'table', array( 'class' => 'wikitable sortable mw-apifeatureusage' ),
					Html::rawElement( 'thead', array(),
						Html::rawElement( 'tr', array(),
							Html::rawElement( 'th', array(),
								$this->msg( 'apifeatureusage-column-feature' )->parse()
							) .
							Html::rawElement( 'th', array(),
								$this->msg( 'apifeatureusage-column-date' )->parse()
							) .
							Html::rawElement( 'th', array(),
								$this->msg( 'apifeatureusage-column-uses' )->parse()
							)
						)
					) .
					Html::rawElement( 'tbody', array(), join( '', $rows ) )
				)
			);
		}
	}

	public function onSubmit( $data, $form ) {
		wfProfileIn( __METHOD__ );

		$agent = $data['agent'];
		$start = new MWTimestamp( $data['dates'][0] . 'T00:00:00Z'  );
		$end = new MWTimestamp( $data['dates'][1] . 'T23:59:59Z'  );

		$status = $this->engine->execute( $agent, $start, $end );
		wfProfileOut( __METHOD__ );
		return $status;
	}

}
