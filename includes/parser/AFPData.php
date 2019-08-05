<?php

class AFPData {
	// Datatypes
	const DINT = 'int';
	const DSTRING = 'string';
	const DNULL = 'null';
	const DBOOL = 'bool';
	const DFLOAT = 'float';
	const DARRAY = 'array';
	// Special purpose type for non-initialized stuff
	const DNONE = 'none';

	// Translation table mapping shell-style wildcards to PCRE equivalents.
	// Derived from <http://www.php.net/manual/en/function.fnmatch.php#100207>
	private static $wildcardMap = [
		'\*' => '.*',
		'\+' => '\+',
		'\-' => '\-',
		'\.' => '\.',
		'\?' => '.',
		'\[' => '[',
		'\[\!' => '[^',
		'\\' => '\\\\',
		'\]' => ']',
	];

	/**
	 * @var string One of the D* const from this class
	 * @private Use $this->getType()
	 */
	public $type;
	/**
	 * @var mixed|null|AFPData[] The actual data contained in this object
	 * @private Use $this->getData()
	 */
	public $data;

	/**
	 * @return string
	 */
	public function getType() {
		return $this->type;
	}

	/**
	 * @return AFPData[]|mixed|null
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * @param string $type
	 * @param AFPData[]|mixed|null $val
	 */
	public function __construct( $type, $val = null ) {
		$this->type = $type;
		$this->data = $val;
	}

	/**
	 * @param mixed $var
	 * @return AFPData
	 * @throws AFPException
	 */
	public static function newFromPHPVar( $var ) {
		switch ( gettype( $var ) ) {
			case 'string':
				return new AFPData( self::DSTRING, $var );
			case 'integer':
				return new AFPData( self::DINT, $var );
			case 'double':
				return new AFPData( self::DFLOAT, $var );
			case 'boolean':
				return new AFPData( self::DBOOL, $var );
			case 'array':
				$result = [];
				foreach ( $var as $item ) {
					$result[] = self::newFromPHPVar( $item );
				}
				return new AFPData( self::DARRAY, $result );
			case 'NULL':
				return new AFPData( self::DNULL );
			default:
				throw new AFPException(
					'Data type ' . gettype( $var ) . ' is not supported by AbuseFilter'
				);
		}
	}

	/**
	 * @return AFPData
	 */
	private function dup() {
		return new AFPData( $this->type, $this->data );
	}

	/**
	 * @param AFPData $orig
	 * @param string $target
	 * @return AFPData
	 */
	public static function castTypes( AFPData $orig, $target ) {
		if ( $orig->type === $target ) {
			return $orig->dup();
		}
		if ( $target === self::DNULL ) {
			// We don't expose any method to cast to null. And, actually, should we?
			return new AFPData( self::DNULL );
		}

		if ( $orig->type === self::DARRAY ) {
			if ( $target === self::DBOOL ) {
				return new AFPData( self::DBOOL, (bool)count( $orig->data ) );
			} elseif ( $target === self::DFLOAT ) {
				return new AFPData( self::DFLOAT, floatval( count( $orig->data ) ) );
			} elseif ( $target === self::DINT ) {
				return new AFPData( self::DINT, intval( count( $orig->data ) ) );
			} elseif ( $target === self::DSTRING ) {
				$s = '';
				foreach ( $orig->data as $item ) {
					$s .= $item->toString() . "\n";
				}

				return new AFPData( self::DSTRING, $s );
			}
		}

		if ( $target === self::DBOOL ) {
			return new AFPData( self::DBOOL, (bool)$orig->data );
		} elseif ( $target === self::DFLOAT ) {
			return new AFPData( self::DFLOAT, floatval( $orig->data ) );
		} elseif ( $target === self::DINT ) {
			return new AFPData( self::DINT, intval( $orig->data ) );
		} elseif ( $target === self::DSTRING ) {
			return new AFPData( self::DSTRING, strval( $orig->data ) );
		} elseif ( $target === self::DARRAY ) {
			// We don't expose any method to cast to array
			return new AFPData( self::DARRAY, [ $orig ] );
		}
		throw new AFPException( 'Cannot cast ' . $orig->type . " to $target." );
	}

	/**
	 * @param AFPData $value
	 * @return AFPData
	 */
	public static function boolInvert( AFPData $value ) {
		if ( $value->type === self::DNONE ) {
			return new AFPData( self::DNONE );
		}
		return new AFPData( self::DBOOL, !$value->toBool() );
	}

	/**
	 * @param AFPData $base
	 * @param AFPData $exponent
	 * @return AFPData
	 */
	public static function pow( AFPData $base, AFPData $exponent ) {
		if ( $base->type === self::DNONE || $exponent->type === self::DNONE ) {
			return new AFPData( self::DNONE );
		}
		$res = pow( $base->toNumber(), $exponent->toNumber() );
		$type = is_int( $res ) ? self::DINT : self::DFLOAT;

		return new AFPData( $type, $res );
	}

	/**
	 * Checks if $a contains $b
	 *
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	private static function containmentKeyword( AFPData $a, AFPData $b ) {
		$a = $a->toString();
		$b = $b->toString();

		if ( $a === '' || $b === '' ) {
			return new AFPData( self::DBOOL, false );
		}

		return new AFPData( self::DBOOL, strpos( $a, $b ) !== false );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function keywordIn( AFPData $a, AFPData $b ) {
		return self::containmentKeyword( $b, $a );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function keywordContains( AFPData $a, AFPData $b ) {
		return self::containmentKeyword( $a, $b );
	}

	/**
	 * @param AFPData $d1
	 * @param AFPData $d2
	 * @param bool $strict whether to also check types
	 * @return bool
	 */
	private static function equals( AFPData $d1, AFPData $d2, $strict = false ) {
		if ( $d1->type === self::DNONE || $d2->type === self::DNONE ) {
			// This could mean literally everything, and mostly happens when
			// comparing two expressions, both built basing on some non-initialized
			// AbuseFilter variable.
			// We always return false, like Nan !== NaN in JS.
			return false;
		} elseif ( $d1->type !== self::DARRAY && $d2->type !== self::DARRAY ) {
			$typecheck = $d1->type === $d2->type || !$strict;
			return $typecheck && $d1->toString() === $d2->toString();
		} elseif ( $d1->type === self::DARRAY && $d2->type === self::DARRAY ) {
			$data1 = $d1->data;
			$data2 = $d2->data;
			if ( count( $data1 ) !== count( $data2 ) ) {
				return false;
			}
			$length = count( $data1 );
			for ( $i = 0; $i < $length; $i++ ) {
				if ( self::equals( $data1[$i], $data2[$i], $strict ) === false ) {
					return false;
				}
			}
			return true;
		} else {
			// Trying to compare an array to something else
			if ( $strict ) {
				return false;
			}
			if ( $d1->type === self::DARRAY && count( $d1->data ) === 0 ) {
				return ( $d2->type === self::DBOOL && $d2->toBool() === false ) || $d2->type === self::DNULL;
			} elseif ( $d2->type === self::DARRAY && count( $d2->data ) === 0 ) {
				return ( $d1->type === self::DBOOL && $d1->toBool() === false ) || $d1->type === self::DNULL;
			} else {
				return false;
			}
		}
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $pattern
	 * @return AFPData
	 */
	public static function keywordLike( AFPData $str, AFPData $pattern ) {
		$str = $str->toString();
		$pattern = '#^' . strtr( preg_quote( $pattern->toString(), '#' ), self::$wildcardMap ) . '$#u';
		Wikimedia\suppressWarnings();
		$result = preg_match( $pattern, $str );
		Wikimedia\restoreWarnings();

		return new AFPData( self::DBOOL, (bool)$result );
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $regex
	 * @param int $pos
	 * @param bool $insensitive
	 * @return AFPData
	 * @throws Exception
	 */
	public static function keywordRegex( AFPData $str, AFPData $regex, $pos, $insensitive = false ) {
		$str = $str->toString();
		$pattern = $regex->toString();

		$pattern = preg_replace( '!(\\\\\\\\)*(\\\\)?/!', '$1\/', $pattern );
		$pattern = "/$pattern/u";

		if ( $insensitive ) {
			$pattern .= 'i';
		}

		Wikimedia\suppressWarnings();
		$result = preg_match( $pattern, $str );
		Wikimedia\restoreWarnings();
		if ( $result === false ) {
			throw new AFPUserVisibleException(
				'regexfailure',
				// Coverage bug
				// @codeCoverageIgnoreStart
				$pos,
				// @codeCoverageIgnoreEnd
				[ $pattern ]
			);
		}

		return new AFPData( self::DBOOL, (bool)$result );
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $regex
	 * @param int $pos
	 * @return AFPData
	 */
	public static function keywordRegexInsensitive( AFPData $str, AFPData $regex, $pos ) {
		return self::keywordRegex( $str, $regex, $pos, true );
	}

	/**
	 * @param AFPData $data
	 * @return AFPData
	 */
	public static function unaryMinus( AFPData $data ) {
		if ( $data->type === self::DNONE ) {
			return new AFPData( self::DNONE );
		} elseif ( $data->type === self::DINT ) {
			return new AFPData( $data->type, -$data->toInt() );
		} else {
			return new AFPData( $data->type, -$data->toFloat() );
		}
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @param string $op
	 * @return AFPData
	 * @throws AFPException
	 */
	public static function boolOp( AFPData $a, AFPData $b, $op ) {
		$a = $a->toBool();
		$b = $b->toBool();
		if ( $op === '|' ) {
			return new AFPData( self::DBOOL, $a || $b );
		} elseif ( $op === '&' ) {
			return new AFPData( self::DBOOL, $a && $b );
		} elseif ( $op === '^' ) {
			return new AFPData( self::DBOOL, $a xor $b );
		}
		// Should never happen.
		// @codeCoverageIgnoreStart
		throw new AFPException( "Invalid boolean operation: {$op}" );
		// @codeCoverageIgnoreEnd
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @param string $op
	 * @return AFPData
	 * @throws AFPException
	 */
	public static function compareOp( AFPData $a, AFPData $b, $op ) {
		if ( $op === '==' || $op === '=' ) {
			return new AFPData( self::DBOOL, self::equals( $a, $b ) );
		} elseif ( $op === '!=' ) {
			return new AFPData( self::DBOOL, !self::equals( $a, $b ) );
		} elseif ( $op === '===' ) {
			return new AFPData( self::DBOOL, self::equals( $a, $b, true ) );
		} elseif ( $op === '!==' ) {
			return new AFPData( self::DBOOL, !self::equals( $a, $b, true ) );
		}

		$a = $a->toString();
		$b = $b->toString();
		if ( $op === '>' ) {
			return new AFPData( self::DBOOL, $a > $b );
		} elseif ( $op === '<' ) {
			return new AFPData( self::DBOOL, $a < $b );
		} elseif ( $op === '>=' ) {
			return new AFPData( self::DBOOL, $a >= $b );
		} elseif ( $op === '<=' ) {
			return new AFPData( self::DBOOL, $a <= $b );
		}
		// Should never happen
		// @codeCoverageIgnoreStart
		throw new AFPException( "Invalid comparison operation: {$op}" );
		// @codeCoverageIgnoreEnd
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @param string $op
	 * @param int $pos
	 * @return AFPData
	 * @throws AFPUserVisibleException
	 * @throws AFPException
	 */
	public static function mulRel( AFPData $a, AFPData $b, $op, $pos ) {
		if ( $a->type === self::DNONE || $b->type === self::DNONE ) {
			return new AFPData( self::DNONE );
		}
		$a = $a->toNumber();
		$b = $b->toNumber();

		if ( $op !== '*' && (float)$b === 0.0 ) {
			throw new AFPUserVisibleException( 'dividebyzero', $pos, [ $a ] );
		}

		if ( $op === '*' ) {
			$data = $a * $b;
		} elseif ( $op === '/' ) {
			$data = $a / $b;
		} elseif ( $op === '%' ) {
			$data = $a % $b;
		} else {
			// Should never happen
			// @codeCoverageIgnoreStart
			throw new AFPException( "Invalid multiplication-related operation: {$op}" );
			// @codeCoverageIgnoreEnd
		}

		$type = is_int( $data ) ? self::DINT : self::DFLOAT;

		return new AFPData( $type, $data );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function sum( AFPData $a, AFPData $b ) {
		if ( $a->type === self::DNONE || $b->type === self::DNONE ) {
			return new AFPData( self::DNONE );
		} elseif ( $a->type === self::DSTRING || $b->type === self::DSTRING ) {
			return new AFPData( self::DSTRING, $a->toString() . $b->toString() );
		} elseif ( $a->type === self::DARRAY && $b->type === self::DARRAY ) {
			return new AFPData( self::DARRAY, array_merge( $a->toArray(), $b->toArray() ) );
		} else {
			$res = $a->toNumber() + $b->toNumber();
			$type = is_int( $res ) ? self::DINT : self::DFLOAT;

			return new AFPData( $type, $res );
		}
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function sub( AFPData $a, AFPData $b ) {
		if ( $a->type === self::DNONE || $b->type === self::DNONE ) {
			return new AFPData( self::DNONE );
		}
		$res = $a->toNumber() - $b->toNumber();
		$type = is_int( $res ) ? self::DINT : self::DFLOAT;

		return new AFPData( $type, $res );
	}

	/** Convert shorteners */

	/**
	 * @throws MWException
	 * @return mixed
	 */
	public function toNative() {
		switch ( $this->type ) {
			case self::DBOOL:
				return $this->toBool();
			case self::DSTRING:
				return $this->toString();
			case self::DFLOAT:
				return $this->toFloat();
			case self::DINT:
				return $this->toInt();
			case self::DARRAY:
				$input = $this->toArray();
				$output = [];
				foreach ( $input as $item ) {
					$output[] = $item->toNative();
				}

				return $output;
			case self::DNULL:
			case self::DNONE:
				return null;
			default:
				// @codeCoverageIgnoreStart
				throw new MWException( "Unknown type" );
				// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * @return bool
	 */
	public function toBool() {
		return self::castTypes( $this, self::DBOOL )->data;
	}

	/**
	 * @return string
	 */
	public function toString() {
		return self::castTypes( $this, self::DSTRING )->data;
	}

	/**
	 * @return float
	 */
	public function toFloat() {
		return self::castTypes( $this, self::DFLOAT )->data;
	}

	/**
	 * @return int
	 */
	public function toInt() {
		return self::castTypes( $this, self::DINT )->data;
	}

	/**
	 * @return int|float
	 */
	public function toNumber() {
		return $this->type === self::DINT ? $this->toInt() : $this->toFloat();
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return self::castTypes( $this, self::DARRAY )->data;
	}
}
