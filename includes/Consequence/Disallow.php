<?php

namespace MediaWiki\Extension\AbuseFilter\Consequence;

use MediaWiki\Extension\AbuseFilter\GlobalNameUtils;

/**
 * Consequence that simply disallows the ongoing action.
 */
class Disallow extends Consequence implements HookAborterConsequence {
	/** @var string */
	private $message;

	/**
	 * @param Parameters $parameters
	 * @param string $message
	 */
	public function __construct( Parameters $parameters, string $message ) {
		parent::__construct( $parameters );
		$this->message = $message;
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		$filter = $this->parameters->getFilter();
		return [
			$this->message,
			$filter->getName(),
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			GlobalNameUtils::buildGlobalName( $filter->getID(), $this->parameters->getIsGlobalFilter() )
		];
	}
}
