<?php

class SpecialAbuseFilter extends AbuseFilterSpecialPage {
	/**
	 * @var int|string|null The current filter
	 */
	public $mFilter;
	/**
	 * @var string|null The history ID of the current version
	 */
	public $mHistoryID;

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct( 'AbuseFilter', 'abusefilter-view' );
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * @param string|null $subpage
	 */
	public function execute( $subpage ) {
		$out = $this->getOutput();
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.abuseFilter' );
		$view = 'AbuseFilterViewList';

		$this->setHeaders();
		$this->addHelpLink( 'Extension:AbuseFilter' );

		$this->loadParameters( $subpage );
		$out->setPageTitle( $this->msg( 'abusefilter-management' ) );

		$this->checkPermissions();

		if ( $request->getVal( 'result' ) === 'success' ) {
			$out->setSubtitle( $this->msg( 'abusefilter-edit-done-subtitle' ) );
			$changedFilter = intval( $request->getVal( 'changedfilter' ) );
			$changeId = intval( $request->getVal( 'changeid' ) );
			$out->wrapWikiMsg( '<p class="success">$1</p>',
				[
					'abusefilter-edit-done',
					$changedFilter,
					$changeId,
					$this->getLanguage()->formatNum( $changedFilter )
				]
			);
		}

		$this->mHistoryID = null;
		$pageType = 'home';

		$params = explode( '/', $subpage );

		// Filter by removing blanks.
		foreach ( $params as $index => $param ) {
			if ( $param === '' ) {
				unset( $params[$index] );
			}
		}
		$params = array_values( $params );

		if ( $subpage === 'tools' ) {
			$view = 'AbuseFilterViewTools';
			$pageType = 'tools';
			$out->addHelpLink( 'Extension:AbuseFilter/Rules format' );
		}

		if ( count( $params ) === 2 && $params[0] === 'revert' && is_numeric( $params[1] ) ) {
			$this->mFilter = $params[1];
			$view = 'AbuseFilterViewRevert';
			$pageType = 'revert';
		}

		if ( count( $params ) && $params[0] === 'test' ) {
			$view = 'AbuseFilterViewTestBatch';
			$pageType = 'test';
			$out->addHelpLink( 'Extension:AbuseFilter/Rules format' );
		}

		if ( count( $params ) && $params[0] === 'examine' ) {
			$view = 'AbuseFilterViewExamine';
			$pageType = 'examine';
			$out->addHelpLink( 'Extension:AbuseFilter/Rules format' );
		}

		if ( !empty( $params[0] ) && ( $params[0] === 'history' || $params[0] === 'log' ) ) {
			$pageType = '';
			if ( count( $params ) === 1 ) {
				$view = 'AbuseFilterViewHistory';
				$pageType = 'recentchanges';
			} elseif ( count( $params ) === 2 ) {
				// Second param is a filter ID
				$view = 'AbuseFilterViewHistory';
				$pageType = 'recentchanges';
				$this->mFilter = $params[1];
			} elseif ( count( $params ) === 4 && $params[2] === 'item' ) {
				$this->mFilter = $params[1];
				$this->mHistoryID = $params[3];
				$view = 'AbuseFilterViewEdit';
			} elseif ( count( $params ) === 5 && $params[2] === 'diff' ) {
				// Special:AbuseFilter/history/<filter>/diff/<oldid>/<newid>
				$view = 'AbuseFilterViewDiff';
			}
		}

		if ( is_numeric( $subpage ) || $subpage === 'new' ) {
			$this->mFilter = $subpage;
			$view = 'AbuseFilterViewEdit';
			$pageType = 'edit';
		}

		if ( $subpage === 'import' ) {
			$view = 'AbuseFilterViewImport';
			$pageType = 'import';
		}

		// Links at the top
		$this->addNavigationLinks( $pageType );

		/** @var AbuseFilterView $v */
		$v = new $view( $this, $params );
		$v->show();
	}

	/**
	 * @param string|null $filter
	 */
	public function loadParameters( $filter ) {
		if ( !is_numeric( $filter ) && $filter !== 'new' ) {
			$filter = $this->getRequest()->getIntOrNull( 'wpFilter' );
		}
		$this->mFilter = $filter;
	}
}
