<?php

use Wikimedia\Rdbms\Database;
use MediaWiki\MediaWikiServices;
use MediaWiki\Logger\LoggerFactory;

class AFComputedVariable {
	/**
	 * @var string The method used to compute the variable
	 */
	public $mMethod;
	/**
	 * @var array Parameters to be used with the specified method
	 */
	public $mParameters;
	/**
	 * @var User[] Cache containing User objects already constructed
	 */
	public static $userCache = [];
	/**
	 * @var WikiPage[] Cache containing Page objects already constructed
	 */
	public static $articleCache = [];

	/** @var float The amount of time to subtract from profiling */
	public static $profilingExtraTime = 0;

	/**
	 * @param string $method
	 * @param array $parameters
	 */
	public function __construct( $method, $parameters ) {
		$this->mMethod = $method;
		$this->mParameters = $parameters;
	}

	/**
	 * It's like Article::prepareContentForEdit, but not for editing (old wikitext usually)
	 *
	 *
	 * @param string $wikitext
	 * @param WikiPage $article
	 *
	 * @return object
	 */
	public function parseNonEditWikitext( $wikitext, WikiPage $article ) {
		static $cache = [];

		$cacheKey = md5( $wikitext ) . ':' . $article->getTitle()->getPrefixedText();

		if ( isset( $cache[$cacheKey] ) ) {
			return $cache[$cacheKey];
		}

		$edit = (object)[];
		$options = new ParserOptions;
		$options->setTidy( true );
		$parser = MediaWikiServices::getInstance()->getParser();
		$edit->output = $parser->parse( $wikitext, $article->getTitle(), $options );
		$cache[$cacheKey] = $edit;

		return $edit;
	}

	/**
	 * For backwards compatibility: Get the user object belonging to a certain name
	 * in case a user name is given as argument. Nowadays user objects are passed
	 * directly but many old log entries rely on this.
	 *
	 * @param string|User $user
	 * @return User
	 */
	public static function getUserObject( $user ) {
		if ( $user instanceof User ) {
			$username = $user->getName();
		} else {
			$username = $user;
			if ( isset( self::$userCache[$username] ) ) {
				return self::$userCache[$username];
			}

			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->debug( "Couldn't find user $username in cache" );
		}

		if ( count( self::$userCache ) > 1000 ) {
			self::$userCache = [];
		}

		if ( $user instanceof User ) {
			$ret = $user;
		} elseif ( IP::isIPAddress( $username ) ) {
			$ret = new User;
			$ret->setName( $username );
		} else {
			$ret = User::newFromName( $username );
			$ret->load();
		}
		self::$userCache[$username] = $ret;

		return $ret;
	}

	/**
	 * @param int $namespace
	 * @param string $title
	 * @return WikiPage
	 */
	public function pageFromTitle( $namespace, $title ) {
		if ( isset( self::$articleCache["$namespace:$title"] ) ) {
			return self::$articleCache["$namespace:$title"];
		}

		if ( count( self::$articleCache ) > 1000 ) {
			self::$articleCache = [];
		}

		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$logger->debug( "Creating wikipage object for $namespace:$title in cache" );

		$t = $this->buildTitle( $namespace, $title );
		self::$articleCache["$namespace:$title"] = WikiPage::factory( $t );

		return self::$articleCache["$namespace:$title"];
	}

	/**
	 * Mockable wrapper
	 *
	 * @param int $namespace
	 * @param string $title
	 * @return Title
	 */
	protected function buildTitle( $namespace, $title ) : Title {
		return Title::makeTitle( $namespace, $title );
	}

	/**
	 * @param WikiPage $article
	 * @return array
	 */
	public static function getLinksFromDB( WikiPage $article ) {
		// Stolen from ConfirmEdit, SimpleCaptcha::getLinksFromTracker
		$id = $article->getId();
		if ( !$id ) {
			return [];
		}

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'externallinks',
			[ 'el_to' ],
			[ 'el_from' => $id ],
			__METHOD__
		);
		$links = [];
		foreach ( $res as $row ) {
			$links[] = $row->el_to;
		}
		return $links;
	}

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @return AFPData
	 * @throws MWException
	 * @throws AFPException
	 */
	public function compute( AbuseFilterVariableHolder $vars ) {
		// TODO: find a way to inject the User object from hook parameters.
		global $wgUser;

		$parameters = $this->mParameters;
		$result = null;

		if ( !Hooks::run( 'AbuseFilter-interceptVariable',
							[ $this->mMethod, $vars, $parameters, &$result ] ) ) {
			// @phan-suppress-next-line PhanImpossibleCondition False positive due to hook reference
			return $result instanceof AFPData
				? $result : AFPData::newFromPHPVar( $result );
		}

		switch ( $this->mMethod ) {
			case 'diff':
				// Currently unused. Kept for backwards compatibility since it remains
				// as mMethod for old variables. A fallthrough would instead change old results.
				$text1Var = $parameters['oldtext-var'];
				$text2Var = $parameters['newtext-var'];
				$text1 = $vars->getVar( $text1Var )->toString();
				$text2 = $vars->getVar( $text2Var )->toString();
				$diffs = new Diff( explode( "\n", $text1 ), explode( "\n", $text2 ) );
				$format = new UnifiedDiffFormatter();
				$result = $format->format( $diffs );
				break;
			case 'diff-array':
				// Introduced with T74329 to uniform the diff to MW's standard one.
				// The difference with 'diff' method is noticeable when one of the
				// $text is empty: it'll be treated as **really** empty, instead of
				// an empty string.
				$text1Var = $parameters['oldtext-var'];
				$text2Var = $parameters['newtext-var'];
				$text1 = $vars->getVar( $text1Var )->toString();
				$text2 = $vars->getVar( $text2Var )->toString();
				$text1 = $text1 === '' ? [] : explode( "\n", $text1 );
				$text2 = $text2 === '' ? [] : explode( "\n", $text2 );
				$diffs = new Diff( $text1, $text2 );
				$format = new UnifiedDiffFormatter();
				$result = $format->format( $diffs );
				break;
			case 'diff-split':
				$diff = $vars->getVar( $parameters['diff-var'] )->toString();
				$line_prefix = $parameters['line-prefix'];
				$diff_lines = explode( "\n", $diff );
				$interest_lines = [];
				foreach ( $diff_lines as $line ) {
					if ( substr( $line, 0, 1 ) === $line_prefix ) {
						$interest_lines[] = substr( $line, strlen( $line_prefix ) );
					}
				}
				$result = $interest_lines;
				break;
			case 'links-from-wikitext':
				// This should ONLY be used when sharing a parse operation with the edit.

				/* @var WikiPage $article */
				if ( isset( $parameters['article'] ) ) {
					$article = $parameters['article'];
				} else {
					$article = $this->pageFromTitle(
						$parameters['namespace'],
						$parameters['title']
					);
				}
				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					// Shared with the edit, don't count it in profiling
					$startTime = microtime( true );
					$textVar = $parameters['text-var'];

					$new_text = $vars->getVar( $textVar )->toString();
					$content = ContentHandler::makeContent( $new_text, $article->getTitle() );
					try {
						// @fixme TEMPORARY WORKAROUND FOR T187153
						$editInfo = $article->prepareContentForEdit( $content );
						$links = array_keys( $editInfo->output->getExternalLinks() );
					} catch ( BadMethodCallException $e ) {
						$logger = LoggerFactory::getInstance( 'AbuseFilter' );
						$logger->warning( 'Caught BadMethodCallException - T187153' );
						$links = [];
					}
					$result = $links;
					self::$profilingExtraTime += ( microtime( true ) - $startTime );
					break;
				}
				// Otherwise fall back to database
			case 'links-from-wikitext-nonedit':
			case 'links-from-wikitext-or-database':
				// TODO: use Content object instead, if available!
				$article = $this->pageFromTitle(
					$parameters['namespace'],
					$parameters['title']
				);

				$logger = LoggerFactory::getInstance( 'AbuseFilter' );
				if ( $vars->forFilter ) {
					$links = $this->getLinksFromDB( $article );
					$logger->debug( 'Loading old links from DB' );
				} elseif ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					$logger->debug( 'Loading old links from Parser' );
					$textVar = $parameters['text-var'];

					$wikitext = $vars->getVar( $textVar )->toString();
					$editInfo = $this->parseNonEditWikitext( $wikitext, $article );
					$links = array_keys( $editInfo->output->getExternalLinks() );
				} else {
					// TODO: Get links from Content object. But we don't have the content object.
					// And for non-text content, $wikitext is usually not going to be a valid
					// serialization, but rather some dummy text for filtering.
					$links = [];
				}

				$result = $links;
				break;
			case 'link-diff-added':
			case 'link-diff-removed':
				$oldLinkVar = $parameters['oldlink-var'];
				$newLinkVar = $parameters['newlink-var'];

				$oldLinks = $vars->getVar( $oldLinkVar )->toString();
				$newLinks = $vars->getVar( $newLinkVar )->toString();

				$oldLinks = explode( "\n", $oldLinks );
				$newLinks = explode( "\n", $newLinks );

				if ( $this->mMethod === 'link-diff-added' ) {
					$result = array_diff( $newLinks, $oldLinks );
				}
				if ( $this->mMethod === 'link-diff-removed' ) {
					$result = array_diff( $oldLinks, $newLinks );
				}
				break;
			case 'parse-wikitext':
				// Should ONLY be used when sharing a parse operation with the edit.
				if ( isset( $parameters['article'] ) ) {
					$article = $parameters['article'];
				} else {
					$article = $this->pageFromTitle(
						$parameters['namespace'],
						$parameters['title']
					);
				}
				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					// Shared with the edit, don't count it in profiling
					$startTime = microtime( true );
					$textVar = $parameters['wikitext-var'];

					$new_text = $vars->getVar( $textVar )->toString();
					$content = ContentHandler::makeContent( $new_text, $article->getTitle() );
					try {
						// @fixme TEMPORARY WORKAROUND FOR T187153
						$editInfo = $article->prepareContentForEdit( $content );
					} catch ( BadMethodCallException $e ) {
						$result = '';
						break;
					}
					if ( isset( $parameters['pst'] ) && $parameters['pst'] ) {
						$result = $editInfo->pstContent->serialize( $editInfo->format );
					} else {
						$newHTML = $editInfo->output->getText();
						// Kill the PP limit comments. Ideally we'd just remove these by not setting the
						// parser option, but then we can't share a parse operation with the edit, which is bad.
						$result = preg_replace( '/<!--\s*NewPP limit report[^>]*-->\s*$/si', '', $newHTML );
					}
					self::$profilingExtraTime += ( microtime( true ) - $startTime );
					break;
				}
				// Otherwise fall back to database
			case 'parse-wikitext-nonedit':
				// TODO: use Content object instead, if available!
				$article = $this->pageFromTitle( $parameters['namespace'], $parameters['title'] );
				$textVar = $parameters['wikitext-var'];

				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					if ( isset( $parameters['pst'] ) && $parameters['pst'] ) {
						// $textVar is already PSTed when it's not loaded from an ongoing edit.
						$result = $vars->getVar( $textVar )->toString();
					} else {
						$text = $vars->getVar( $textVar )->toString();
						$editInfo = $this->parseNonEditWikitext( $text, $article );
						$result = $editInfo->output->getText();
					}
				} else {
					// TODO: Parser Output from Content object. But we don't have the content object.
					// And for non-text content, $wikitext is usually not going to be a valid
					// serialization, but rather some dummy text for filtering.
					$result = '';
				}

				break;
			case 'strip-html':
				$htmlVar = $parameters['html-var'];
				$html = $vars->getVar( $htmlVar )->toString();
				$result = StringUtils::delimiterReplace( '<', '>', '', $html );
				break;
			case 'load-recent-authors':
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );
				if ( !$title->exists() ) {
					$result = '';
					break;
				}

				$result = self::getLastPageAuthors( $title );
				break;
			case 'load-first-author':
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );

				$revision = $title->getFirstRevision();
				if ( $revision ) {
					$result = $revision->getUserText();
				} else {
					$result = '';
				}

				break;
			case 'get-page-restrictions':
				$action = $parameters['action'];
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );

				$result = $title->getRestrictions( $action );
				break;
			case 'simple-user-accessor':
				$user = $parameters['user'];
				$method = $parameters['method'];

				if ( !$user ) {
					throw new MWException( 'No user parameter given.' );
				}

				$obj = self::getUserObject( $user );

				if ( !$obj ) {
					throw new MWException( "Invalid username $user" );
				}

				$result = call_user_func( [ $obj, $method ] );
				break;
			case 'user-block':
				// @todo Support partial blocks
				$user = $parameters['user'];
				$result = (bool)$user->getBlock();
				break;
			case 'user-age':
				$user = $parameters['user'];
				$asOf = $parameters['asof'];
				$obj = self::getUserObject( $user );

				if ( $obj->getId() === 0 ) {
					$result = 0;
					break;
				}

				$registration = $obj->getRegistration();
				$result = wfTimestamp( TS_UNIX, $asOf ) - wfTimestampOrNull( TS_UNIX, $registration );
				break;
			case 'page-age':
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );

				$firstRevisionTime = $title->getEarliestRevTime();
				if ( !$firstRevisionTime ) {
					$result = 0;
					break;
				}

				$asOf = $parameters['asof'];
				$result = wfTimestamp( TS_UNIX, $asOf ) - wfTimestampOrNull( TS_UNIX, $firstRevisionTime );
				break;
			case 'user-groups':
				// Deprecated but needed by old log entries
				$user = $parameters['user'];
				$obj = self::getUserObject( $user );
				$result = $obj->getEffectiveGroups();
				break;
			case 'length':
				$s = $vars->getVar( $parameters['length-var'] )->toString();
				$result = strlen( $s );
				break;
			case 'subtract':
				// Currently unused, kept for backwards compatibility for old filters.
				$v1 = $vars->getVar( $parameters['val1-var'] )->toFloat();
				$v2 = $vars->getVar( $parameters['val2-var'] )->toFloat();
				$result = $v1 - $v2;
				break;
			case 'subtract-int':
				$v1 = $vars->getVar( $parameters['val1-var'] )->toInt();
				$v2 = $vars->getVar( $parameters['val2-var'] )->toInt();
				$result = $v1 - $v2;
				break;
			case 'revision-text-by-id':
				$rev = Revision::newFromId( $parameters['revid'] );
				$result = AbuseFilter::revisionToString( $rev, $wgUser );
				break;
			case 'revision-text-by-timestamp':
				$timestamp = $parameters['timestamp'];
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );
				$dbr = wfGetDB( DB_REPLICA );
				$rev = Revision::loadFromTimestamp( $dbr, $title, $timestamp );
				$result = AbuseFilter::revisionToString( $rev, $wgUser );
				break;
			default:
				if ( Hooks::run( 'AbuseFilter-computeVariable',
									[ $this->mMethod, $vars, $parameters, &$result ] ) ) {
					throw new AFPException( 'Unknown variable compute type ' . $this->mMethod );
				}
		}

		return $result instanceof AFPData
			? $result : AFPData::newFromPHPVar( $result );
	}

	/**
	 * @param Title $title
	 * @return string[] Usernames of the last 10 (unique) authors from $title
	 */
	public static function getLastPageAuthors( Title $title ) {
		if ( !$title->exists() ) {
			return [];
		}

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$fname = __METHOD__;

		return $cache->getWithSetCallback(
			$cache->makeKey( 'last-10-authors', 'revision', $title->getLatestRevID() ),
			$cache::TTL_MINUTE,
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $title, $fname ) {
				$dbr = wfGetDB( DB_REPLICA );
				$setOpts += Database::getCacheSetOptions( $dbr );
				// Get the last 100 edit authors with a trivial query (avoid T116557)
				$revQuery = Revision::getQueryInfo();
				$revAuthors = $dbr->selectFieldValues(
					$revQuery['tables'],
					$revQuery['fields']['rev_user_text'],
					[ 'rev_page' => $title->getArticleID() ],
					$fname,
					// Some pages have < 10 authors but many revisions (e.g. bot pages)
					[ 'ORDER BY' => 'rev_timestamp DESC, rev_id DESC',
						'LIMIT' => 100,
						// Force index per T116557
						'USE INDEX' => [ 'revision' => 'page_timestamp' ],
					],
					$revQuery['joins']
				);
				// Get the last 10 distinct authors within this set of edits
				$users = [];
				foreach ( $revAuthors as $author ) {
					$users[$author] = 1;
					if ( count( $users ) >= 10 ) {
						break;
					}
				}

				return array_keys( $users );
			}
		);
	}
}
