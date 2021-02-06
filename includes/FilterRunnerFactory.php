<?php

namespace MediaWiki\Extension\AbuseFilter;

use BagOStuff;
use IBufferingStatsdDataFactory;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesExecutorFactory;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Extension\AbuseFilter\Watcher\EmergencyWatcher;
use MediaWiki\Extension\AbuseFilter\Watcher\UpdateHitCountWatcher;
use NullStatsdDataFactory;
use Psr\Log\LoggerInterface;
use Title;
use User;

class FilterRunnerFactory {
	public const SERVICE_NAME = 'AbuseFilterRunnerFactory';

	/** @var AbuseFilterHookRunner */
	private $hookRunner;
	/** @var FilterProfiler */
	private $filterProfiler;
	/** @var ChangeTagger */
	private $changeTagger;
	/** @var FilterLookup */
	private $filterLookup;
	/** @var ParserFactory */
	private $parserFactory;
	/** @var ConsequencesExecutorFactory */
	private $consExecutorFactory;
	/** @var AbuseLoggerFactory */
	private $abuseLoggerFactory;
	/** @var VariablesManager */
	private $varManager;
	/** @var VariableGeneratorFactory */
	private $varGeneratorFactory;
	/** @var UpdateHitCountWatcher */
	private $updateHitCountWatcher;
	/** @var EmergencyWatcher */
	private $emergencyWatcher;
	/** @var BagOStuff */
	private $localCache;
	/** @var LoggerInterface */
	private $logger;
	/** @var LoggerInterface */
	private $editStashLogger;
	/** @var IBufferingStatsdDataFactory */
	private $statsdDataFactory;
	/** @var ServiceOptions */
	private $options;

	/**
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param FilterProfiler $filterProfiler
	 * @param ChangeTagger $changeTagger
	 * @param FilterLookup $filterLookup
	 * @param ParserFactory $parserFactory
	 * @param ConsequencesExecutorFactory $consExecutorFactory
	 * @param AbuseLoggerFactory $abuseLoggerFactory
	 * @param VariablesManager $varManager
	 * @param VariableGeneratorFactory $varGeneratorFactory
	 * @param UpdateHitCountWatcher $updateHitCountWatcher
	 * @param EmergencyWatcher $emergencyWatcher
	 * @param BagOStuff $localCache
	 * @param LoggerInterface $logger
	 * @param LoggerInterface $editStashLogger
	 * @param IBufferingStatsdDataFactory $statsdDataFactory
	 * @param ServiceOptions $options
	 */
	public function __construct(
		AbuseFilterHookRunner $hookRunner,
		FilterProfiler $filterProfiler,
		ChangeTagger $changeTagger,
		FilterLookup $filterLookup,
		ParserFactory $parserFactory,
		ConsequencesExecutorFactory $consExecutorFactory,
		AbuseLoggerFactory $abuseLoggerFactory,
		VariablesManager $varManager,
		VariableGeneratorFactory $varGeneratorFactory,
		UpdateHitCountWatcher $updateHitCountWatcher,
		EmergencyWatcher $emergencyWatcher,
		BagOStuff $localCache,
		LoggerInterface $logger,
		LoggerInterface $editStashLogger,
		IBufferingStatsdDataFactory $statsdDataFactory,
		ServiceOptions $options
	) {
		$this->hookRunner = $hookRunner;
		$this->filterProfiler = $filterProfiler;
		$this->changeTagger = $changeTagger;
		$this->filterLookup = $filterLookup;
		$this->parserFactory = $parserFactory;
		$this->consExecutorFactory = $consExecutorFactory;
		$this->abuseLoggerFactory = $abuseLoggerFactory;
		$this->varManager = $varManager;
		$this->varGeneratorFactory = $varGeneratorFactory;
		$this->updateHitCountWatcher = $updateHitCountWatcher;
		$this->emergencyWatcher = $emergencyWatcher;
		$this->localCache = $localCache;
		$this->logger = $logger;
		$this->editStashLogger = $editStashLogger;
		$this->statsdDataFactory = $statsdDataFactory;
		$this->options = $options;
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @param VariableHolder $vars
	 * @param string $group
	 * @return FilterRunner
	 */
	public function newRunner(
		User $user,
		Title $title,
		VariableHolder $vars,
		string $group
	) : FilterRunner {
		// TODO Add a method to this class taking these as params? Add a hook for custom watchers
		$watchers = [ $this->updateHitCountWatcher, $this->emergencyWatcher ];
		return new FilterRunner(
			$this->hookRunner,
			$this->filterProfiler,
			$this->changeTagger,
			$this->filterLookup,
			$this->parserFactory,
			$this->consExecutorFactory,
			$this->abuseLoggerFactory,
			$this->varManager,
			$this->varGeneratorFactory,
			$watchers,
			new EditStashCache(
				$this->localCache,
				// Bots do not use edit stashing, so avoid distorting the stats
				$user->isBot() ? $this->statsdDataFactory : new NullStatsdDataFactory(),
				$this->varManager,
				$this->editStashLogger,
				$title,
				$group
			),
			$this->logger,
			$this->statsdDataFactory,
			$this->options,
			$user,
			$title,
			$vars,
			$group
		);
	}
}
