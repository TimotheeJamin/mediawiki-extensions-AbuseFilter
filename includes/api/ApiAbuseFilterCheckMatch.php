<?php

class ApiAbuseFilterCheckMatch extends ApiBase {
	/**
	 * @see ApiBase::execute
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'vars', 'rcid', 'logid' );

		// "Anti-DoS"
		if ( !AbuseFilter::canViewPrivate( $this->getUser() ) ) {
			$this->dieWithError( 'apierror-abusefilter-canttest', 'permissiondenied' );
		}

		$vars = null;
		if ( $params['vars'] ) {
			$pairs = FormatJson::decode( $params['vars'], true );
			$vars = AbuseFilterVariableHolder::newFromArray( $pairs );
		} elseif ( $params['rcid'] ) {
			$dbr = wfGetDB( DB_REPLICA );
			$rcQuery = RecentChange::getQueryInfo();
			$row = $dbr->selectRow(
				$rcQuery['tables'],
				$rcQuery['fields'],
				[ 'rc_id' => $params['rcid'] ],
				__METHOD__,
				[],
				$rcQuery['joins']
			);

			if ( !$row ) {
				$this->dieWithError( [ 'apierror-nosuchrcid', $params['rcid'] ] );
			}

			$vars = AbuseFilter::getVarsFromRCRow( $row );
		} elseif ( $params['logid'] ) {
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow(
				'abuse_filter_log',
				'afl_var_dump',
				[ 'afl_id' => $params['logid'] ],
				__METHOD__
			);

			if ( !$row ) {
				$this->dieWithError( [ 'apierror-abusefilter-nosuchlogid', $params['logid'] ], 'nosuchlogid' );
			}

			$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );
		}

		if ( AbuseFilter::checkSyntax( $params[ 'filter' ] ) !== true ) {
			$this->dieWithError( 'apierror-abusefilter-badsyntax', 'badsyntax' );
		}

		$parser = AbuseFilter::getDefaultParser( $vars );
		$result = [
			ApiResult::META_BC_BOOLS => [ 'result' ],
			'result' => AbuseFilter::checkConditions( $params['filter'], $parser ),
		];

		$this->getResult()->addValue(
			null,
			$this->getModuleName(),
			$result
		);
	}

	/**
	 * @see ApiBase::getAllowedParams
	 * @return array
	 */
	public function getAllowedParams() {
		return [
			'filter' => [
				ApiBase::PARAM_REQUIRED => true,
			],
			'vars' => null,
			'rcid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
			'logid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
		];
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=abusefiltercheckmatch&filter=!("autoconfirmed"%20in%20user_groups)&rcid=15'
				=> 'apihelp-abusefiltercheckmatch-example-1',
		];
	}
}
