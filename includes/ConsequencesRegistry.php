<?php

namespace MediaWiki\Extension\AbuseFilter;

// phpcs:disable MediaWiki.Classes.UnusedUseStatement.UnusedUse
use MediaWiki\Extension\AbuseFilter\Consequence\Consequence;
use MediaWiki\Extension\AbuseFilter\Consequence\Parameters;
// phpcs:enable MediaWiki.Classes.UnusedUseStatement.UnusedUse
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use RuntimeException;

class ConsequencesRegistry {
	public const SERVICE_NAME = 'AbuseFilterConsequencesRegistry';

	private const DANGEROUS_ACTIONS = [
		'block',
		'blockautopromote',
		'degroup',
		'rangeblock'
	];

	/** @var AbuseFilterHookRunner */
	private $hookRunner;
	/** @var bool[] */
	private $configActions;
	/** @var callable[] */
	private $customHandlers;

	/** @var string[]|null */
	private $dangerousActionsCache;
	/** @var callable[]|null */
	private $customActionsCache;

	/**
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param bool[] $configActions
	 * @param callable[] $customHandlers
	 */
	public function __construct(
		AbuseFilterHookRunner $hookRunner,
		array $configActions,
		array $customHandlers
	) {
		$this->hookRunner = $hookRunner;
		$this->configActions = $configActions;
		$this->customHandlers = $customHandlers;
	}

	/**
	 * Get an array of actions which harm the user.
	 *
	 * @return string[]
	 */
	public function getDangerousActionNames() : array {
		if ( $this->dangerousActionsCache === null ) {
			$extActions = [];
			$this->hookRunner->onAbuseFilterGetDangerousActions( $extActions );
			$this->dangerousActionsCache = array_unique(
				array_merge( $extActions, self::DANGEROUS_ACTIONS )
			);
		}
		return $this->dangerousActionsCache;
	}

	/**
	 * @return string[]
	 */
	public function getAllActionNames() : array {
		return array_unique(
			array_merge(
				array_keys( $this->configActions ),
				array_keys( $this->customHandlers ),
				array_keys( $this->getCustomActions() )
			)
		);
	}

	/**
	 * @return callable[]
	 * @phan-return array<string,callable(Parameters,array):Consequence>
	 */
	public function getCustomActions() : array {
		if ( $this->customActionsCache === null ) {
			$this->customActionsCache = [];
			$this->hookRunner->onAbuseFilterCustomActions( $this->customActionsCache );
			$this->validateCustomActions();
		}
		return $this->customActionsCache;
	}

	/**
	 * Ensure that extensions aren't putting crap in this array, since we can't enforce types on closures otherwise
	 */
	private function validateCustomActions() : void {
		foreach ( $this->customActionsCache as $name => $cb ) {
			if ( !is_string( $name ) ) {
				throw new RuntimeException( 'Custom actions keys should be strings!' );
			}
			// Validating parameters and return value will happen later at runtime.
			if ( !is_callable( $cb ) ) {
				throw new RuntimeException( 'Custom actions values should be callables!' );
			}
		}
	}

	/**
	 * @return string[]
	 */
	public function getAllEnabledActionNames() : array {
		$disabledActions = array_keys( array_filter(
			$this->configActions,
			function ( $el ) {
				return $el === false;
			}
		) );
		return array_values( array_diff( $this->getAllActionNames(), $disabledActions ) );
	}
}
