<?php
/**
 * AbuseFilterCachingParser is the version of AbuseFilterParser which parses
 * the code into an abstract syntax tree before evaluating it, and caches that
 * tree.
 *
 * It currently inherits AbuseFilterParser in order to avoid code duplication.
 * In future, this code will replace current AbuseFilterParser entirely.
 */
class AbuseFilterCachingParser extends AbuseFilterParser {
	/**
	 * Return the generated version of the parser for cache invalidation
	 * purposes.  Automatically tracks list of all functions and invalidates the
	 * cache if it is changed.
	 * @return string
	 */
	public static function getCacheVersion() {
		static $version = null;
		if ( $version !== null ) {
			return $version;
		}

		$versionKey = [
			AFPTreeParser::CACHE_VERSION,
			AbuseFilterTokenizer::CACHE_VERSION,
			array_keys( AbuseFilterParser::$mFunctions ),
			array_keys( AbuseFilterParser::$mKeywords ),
		];
		$version = hash( 'sha256', serialize( $versionKey ) );

		return $version;
	}

	/**
	 * Resets the state of the parser
	 */
	public function resetState() {
		$this->mVariables = new AbuseFilterVariableHolder;
		$this->mCur = new AFPToken();
		$this->mCondCount = 0;
	}

	/**
	 * @param string $code
	 * @return AFPData
	 */
	public function intEval( $code ) {
		static $cache = null;
		if ( !$cache ) {
			$cache = ObjectCache::getLocalServerInstance( 'hash' );
		}

		$tree = $cache->getWithSetCallback(
			$cache->makeGlobalKey(
				__CLASS__,
				self::getCacheVersion(),
				hash( 'sha256', $code )
			),
			$cache::TTL_DAY,
			function () use ( $code ) {
				$parser = new AFPTreeParser();
				return $parser->parse( $code ) ?: false;
			}
		);

		$res = $tree
			? $this->evalNode( $tree )
			: new AFPData( AFPData::DNULL, null );

		if ( $res->getType() === AFPData::DUNDEFINED ) {
			$res = new AFPData( AFPData::DBOOL, false );
		}
		return $res;
	}

	/**
	 * Evaluate the value of the specified AST node.
	 *
	 * @param AFPTreeNode $node The node to evaluate.
	 * @return AFPData|AFPTreeNode|string
	 * @throws AFPException
	 * @throws AFPUserVisibleException
	 * @throws MWException
	 */
	public function evalNode( AFPTreeNode $node ) {
		// A lot of AbuseFilterParser features rely on $this->mCur->pos or
		// $this->mPos for error reporting.
		// FIXME: this is a hack which needs to be removed when the parsers are merged.
		$this->mPos = $node->position;
		$this->mCur->pos = $node->position;

		switch ( $node->type ) {
			case AFPTreeNode::ATOM:
				$tok = $node->children;
				switch ( $tok->type ) {
					case AFPToken::TID:
						return $this->getVarValue( strtolower( $tok->value ) );
					case AFPToken::TSTRING:
						return new AFPData( AFPData::DSTRING, $tok->value );
					case AFPToken::TFLOAT:
						return new AFPData( AFPData::DFLOAT, $tok->value );
					case AFPToken::TINT:
						return new AFPData( AFPData::DINT, $tok->value );
					/** @noinspection PhpMissingBreakStatementInspection */
					case AFPToken::TKEYWORD:
						switch ( $tok->value ) {
							case "true":
								return new AFPData( AFPData::DBOOL, true );
							case "false":
								return new AFPData( AFPData::DBOOL, false );
							case "null":
								return new AFPData( AFPData::DNULL );
						}
					// Fallthrough intended
					default:
						// @codeCoverageIgnoreStart
						throw new AFPException( "Unknown token provided in the ATOM node" );
						// @codeCoverageIgnoreEnd
				}
			case AFPTreeNode::ARRAY_DEFINITION:
				$items = array_map( [ $this, 'evalNode' ], $node->children );
				return new AFPData( AFPData::DARRAY, $items );

			case AFPTreeNode::FUNCTION_CALL:
				$functionName = $node->children[0];
				$args = array_slice( $node->children, 1 );

				$func = self::$mFunctions[$functionName];
				$dataArgs = array_map( [ $this, 'evalNode' ], $args );

				/** @noinspection PhpToStringImplementationInspection */
				$funcHash = md5( $func . serialize( $dataArgs ) );

				if ( isset( $this->funcCache[$funcHash] ) &&
					!in_array( $func, self::$ActiveFunctions )
				) {
					$result = $this->funcCache[$funcHash];
				} else {
					$this->raiseCondCount();
					$hasUndefinedArg = false;
					foreach ( $dataArgs as $arg ) {
						if ( $arg->type === AFPData::DUNDEFINED ) {
							$hasUndefinedArg = true;
							break;
						}
					}
					$result = $this->funcCache[$funcHash] = $hasUndefinedArg
						? new AFPData( AFPData::DUNDEFINED )
						: $this->$func( $dataArgs );
				}

				if ( count( $this->funcCache ) > 1000 ) {
					// @codeCoverageIgnoreStart
					$this->clearFuncCache();
					// @codeCoverageIgnoreEnd
				}

				return $result;

			case AFPTreeNode::ARRAY_INDEX:
				list( $array, $offset ) = $node->children;

				$array = $this->evalNode( $array );

				if ( $array->getType() === AFPData::DUNDEFINED ) {
					return new AFPData( AFPData::DUNDEFINED );
				}

				if ( $array->getType() !== AFPData::DARRAY ) {
					throw new AFPUserVisibleException( 'notarray', $node->position, [] );
				}

				$offset = $this->evalNode( $offset )->toInt();

				$array = $array->toArray();
				if ( count( $array ) <= $offset ) {
					throw new AFPUserVisibleException( 'outofbounds', $node->position,
						[ $offset, count( $array ) ] );
				}

				return $array[$offset];

			case AFPTreeNode::UNARY:
				list( $operation, $argument ) = $node->children;
				$argument = $this->evalNode( $argument );
				if ( $operation === '-' ) {
					return $argument->unaryMinus();
				}
				return $argument;

			case AFPTreeNode::KEYWORD_OPERATOR:
				list( $keyword, $leftOperand, $rightOperand ) = $node->children;
				$func = self::$mKeywords[$keyword];
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );

				if (
					$leftOperand->getType() === AFPData::DUNDEFINED ||
					$rightOperand->getType() === AFPData::DUNDEFINED
				) {
					$result = new AFPData( AFPData::DUNDEFINED );
				} else {
					$this->raiseCondCount();

					// @phan-suppress-next-line PhanParamTooMany Not every function needs the position
					$result = $this->$func( $leftOperand, $rightOperand, $node->position );
				}

				return $result;
			case AFPTreeNode::BOOL_INVERT:
				list( $argument ) = $node->children;
				$argument = $this->evalNode( $argument );
				return $argument->boolInvert();

			case AFPTreeNode::POW:
				list( $base, $exponent ) = $node->children;
				$base = $this->evalNode( $base );
				$exponent = $this->evalNode( $exponent );
				return $base->pow( $exponent );

			case AFPTreeNode::MUL_REL:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				return $leftOperand->mulRel( $rightOperand, $op, $node->position );

			case AFPTreeNode::SUM_REL:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				switch ( $op ) {
					case '+':
						return $leftOperand->sum( $rightOperand );
					case '-':
						return $leftOperand->sub( $rightOperand );
					default:
						// @codeCoverageIgnoreStart
						throw new AFPException( "Unknown sum-related operator: {$op}" );
						// @codeCoverageIgnoreEnd
				}

			case AFPTreeNode::COMPARE:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				$this->raiseCondCount();
				return $leftOperand->compareOp( $rightOperand, $op );

			case AFPTreeNode::LOGIC:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$value = $leftOperand->getType() === AFPData::DUNDEFINED ? false : $leftOperand->toBool();
				// Short-circuit.
				if ( ( !$value && $op === '&' ) || ( $value && $op === '|' ) ) {
					if ( $rightOperand instanceof AFPTreeNode ) {
						$this->discardNode( $rightOperand );
					}
					return $leftOperand;
				}
				$rightOperand = $this->evalNode( $rightOperand );
				return $leftOperand->boolOp( $rightOperand, $op );

			case AFPTreeNode::CONDITIONAL:
				list( $condition, $valueIfTrue, $valueIfFalse ) = $node->children;
				$condition = $this->evalNode( $condition );
				if ( $condition->toBool() ) {
					return $this->evalNode( $valueIfTrue );
				} else {
					return $this->evalNode( $valueIfFalse );
				}

			case AFPTreeNode::ASSIGNMENT:
				list( $varName, $value ) = $node->children;
				$value = $this->evalNode( $value );
				$this->setUserVariable( $varName, $value );
				return $value;

			case AFPTreeNode::INDEX_ASSIGNMENT:
				list( $varName, $offset, $value ) = $node->children;

				if ( $this->isBuiltinVar( $varName ) ) {
					throw new AFPUserVisibleException( 'overridebuiltin', $node->position, [ $varName ] );
				} elseif ( !$this->mVariables->varIsSet( $varName ) ) {
					throw new AFPUserVisibleException( 'unrecognisedvar', $node->position, [ $varName ] );
				}
				$array = $this->mVariables->getVar( $varName );

				if ( $array->getType() !== AFPData::DUNDEFINED ) {
					// If it's a DUNDEFINED, leave it as is
					if ( $array->getType() !== AFPData::DARRAY ) {
						throw new AFPUserVisibleException( 'notarray', $node->position, [] );
					}

					$offset = $this->evalNode( $offset )->toInt();

					$array = $array->toArray();
					if ( count( $array ) <= $offset ) {
						throw new AFPUserVisibleException( 'outofbounds', $node->position,
							[ $offset, count( $array ) ] );
					}

					$array[$offset] = $this->evalNode( $value );
					$this->setUserVariable( $varName, new AFPData( AFPData::DARRAY, $array ) );
				}

				return $value;

			case AFPTreeNode::ARRAY_APPEND:
				list( $varName, $value ) = $node->children;

				if ( $this->isBuiltinVar( $varName ) ) {
					throw new AFPUserVisibleException( 'overridebuiltin', $node->position, [ $varName ] );
				} elseif ( !$this->mVariables->varIsSet( $varName ) ) {
					throw new AFPUserVisibleException( 'unrecognisedvar', $node->position, [ $varName ] );
				}

				$array = $this->mVariables->getVar( $varName );
				if ( $array->getType() !== AFPData::DUNDEFINED ) {
					// If it's a DUNDEFINED, leave it as is
					if ( $array->getType() !== AFPData::DARRAY ) {
						throw new AFPUserVisibleException( 'notarray', $node->position, [] );
					}

					$array = $array->toArray();
					$array[] = $this->evalNode( $value );
					$this->setUserVariable( $varName, new AFPData( AFPData::DARRAY, $array ) );
				}
				return $value;

			case AFPTreeNode::SEMICOLON:
				$lastValue = null;
				// @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach children is array here
				foreach ( $node->children as $statement ) {
					$lastValue = $this->evalNode( $statement );
				}

				return $lastValue;
			default:
				// @codeCoverageIgnoreStart
				throw new AFPException( "Unknown node type passed: {$node->type}" );
				// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * Intended to be used for short-circuit. Given a node, check it and its children; if there are
	 * assignments, initialize the variable. T214674
	 *
	 * @param AFPTreeNode $node
	 */
	private function discardNode( AFPTreeNode $node ) {
		if ( $node->type === AFPTreeNode::ASSIGNMENT ) {
			$this->setUserVariable( $node->children[0], new AFPData( AFPData::DUNDEFINED ) );
		} elseif (
			$node->type === AFPTreeNode::INDEX_ASSIGNMENT ||
			$node->type === AFPTreeNode::ARRAY_APPEND
		) {
			$varName = $node->children[0];
			if ( !$this->mVariables->varIsSet( $varName ) ) {
				throw new AFPUserVisibleException( 'unrecognisedvar', $node->position, [ $varName ] );
			}
			$this->setUserVariable( $varName, new AFPData( AFPData::DUNDEFINED ) );
		} elseif (
			$node->type === AFPTreeNode::FUNCTION_CALL &&
			in_array( $node->children[0], [ 'set', 'set_var' ] ) &&
			isset( $node->children[1] )
		) {
			$varnameNode = $node->children[1];
			if ( $varnameNode->type === AFPTreeNode::ATOM ) {
				$this->setUserVariable( $varnameNode->children->value, new AFPData( AFPData::DUNDEFINED ) );
			}
		} elseif ( $node->type === AFPTreeNode::ATOM ) {
			return;
		}
		// @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach ATOM case excluded above
		foreach ( $node->children as $child ) {
			if ( $child instanceof AFPTreeNode ) {
				$this->discardNode( $child );
			}
		}
	}
}
