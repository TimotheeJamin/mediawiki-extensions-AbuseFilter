<?php

use MediaWiki\Auth\AbstractPreAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\MediaWikiServices;

class AbuseFilterPreAuthenticationProvider extends AbstractPreAuthenticationProvider {
	/**
	 * @param User $user
	 * @param User $creator
	 * @param AuthenticationRequest[] $reqs
	 * @return StatusValue
	 */
	public function testForAccountCreation( $user, $creator, array $reqs ) {
		return $this->testUser( $user, $creator, false );
	}

	/**
	 * @param User $user
	 * @param bool|string $autocreate
	 * @param array $options
	 * @return StatusValue
	 */
	public function testUserForCreation( $user, $autocreate, array $options = [] ) {
		// if this is not an autocreation, testForAccountCreation already handled it
		if ( $autocreate ) {
			return $this->testUser( $user, $user, true );
		}
		return StatusValue::newGood();
	}

	/**
	 * @param User $user The user being created or autocreated
	 * @param User $creator The user who caused $user to be created (or $user itself on autocreation)
	 * @param bool $autocreate Is this an autocreation?
	 * @return StatusValue
	 */
	protected function testUser( $user, $creator, $autocreate ) {
		$startTime = microtime( true );
		if ( $user->getName() === wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text() ) {
			return StatusValue::newFatal( 'abusefilter-accountreserved' );
		}

		$vars = new AbuseFilterVariableHolder;

		// generateUserVars records $creator->getName() which would be the IP for unregistered users
		if ( $creator->isLoggedIn() ) {
			$vars->addHolders( AbuseFilter::generateUserVars( $creator ) );
		}

		$vars->setVar( 'action', $autocreate ? 'autocreateaccount' : 'createaccount' );
		$vars->setVar( 'accountname', $user->getName() );

		// pass creator in explicitly to prevent recording the current user on autocreation - T135360
		$runner = new AbuseFilterRunner(
			$creator,
			SpecialPage::getTitleFor( 'Userlogin' ),
			$vars,
			'default'
		);
		$status = $runner->run();

		MediaWikiServices::getInstance()->getStatsdDataFactory()
			->timing( 'timing.createaccountAbuseFilter', microtime( true ) - $startTime );

		return $status->getStatusValue();
	}
}
