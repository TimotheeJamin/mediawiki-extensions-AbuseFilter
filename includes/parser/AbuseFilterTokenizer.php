<?php

use Psr\Log\LoggerInterface;

/**
 * Tokenizer for AbuseFilter rules.
 */
class AbuseFilterTokenizer {
	/** @var int Tokenizer cache version. Increment this when changing the syntax. **/
	const CACHE_VERSION = 3;
	const COMMENT_START_RE = '/\s*\/\*/A';
	const ID_SYMBOL_RE = '/[0-9A-Za-z_]+/A';
	const OPERATOR_RE =
		'/(\!\=\=|\!\=|\!|\*\*|\*|\/|\+|\-|%|&|\||\^|\:\=|\?|\:|\<\=|\<|\>\=|\>|\=\=\=|\=\=|\=)/A';
	/** @deprecated In favour of V2 */
	const RADIX_RE = '/([0-9A-Fa-f]+(?:\.\d*)?|\.\d+)([bxo])?(?![a-z])/Au';
	const BASE = '0(?<base>[xbo])';
	const DIGIT = '[0-9A-Fa-f]';
	const DIGITS = self::DIGIT . '+' . '(?:\.\d*)?|\.\d+';
	// New numbers regex. Note that the last lookahead can be changed to (?!self::DIGIT) once we
	// drop the old syntax
	const RADIX_RE_V2 = '/(?:' . self::BASE . ')?(?<input>' . self::DIGITS . ')(?!\w)/Au';
	const WHITESPACE = "\011\012\013\014\015\040";

	// Order is important. The punctuation-matching regex requires that
	// ** comes before *, etc. They are sorted to make it easy to spot
	// such errors.
	public static $operators = [
		// Inequality
		'!==', '!=', '!',
		// Multiplication/exponentiation
		'**', '*',
		// Other arithmetic
		'/', '+', '-', '%',
		// Logic
		'&', '|', '^',
		// Setting
		':=',
		// Ternary
		'?', ':',
		// Less than
		'<=', '<',
		// Greater than
		'>=', '>',
		// Equality
		'===', '==', '=',
	];

	public static $punctuation = [
		',' => AFPToken::TCOMMA,
		'(' => AFPToken::TBRACE,
		')' => AFPToken::TBRACE,
		'[' => AFPToken::TSQUAREBRACKET,
		']' => AFPToken::TSQUAREBRACKET,
		';' => AFPToken::TSTATEMENTSEPARATOR,
	];

	public static $bases = [
		'b' => 2,
		'x' => 16,
		'o' => 8
	];

	public static $baseCharsRe = [
		2  => '/^[01]+$/',
		8  => '/^[0-7]+$/',
		16 => '/^[0-9A-Fa-f]+$/',
		10 => '/^[0-9.]+$/',
	];

	public static $keywords = [
		'in', 'like', 'true', 'false', 'null', 'contains', 'matches',
		'rlike', 'irlike', 'regex', 'if', 'then', 'else', 'end',
	];

	/**
	 * @var BagOStuff
	 */
	private $cache;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @param BagOStuff $cache
	 * @param LoggerInterface $logger
	 */
	public function __construct( BagOStuff $cache, LoggerInterface $logger ) {
		$this->cache = $cache;
		$this->logger = $logger;
	}

	/**
	 * Get a cache key used to store the tokenized code
	 *
	 * @param string $code Not yet tokenized
	 * @return string
	 * @internal
	 */
	public function getCacheKey( $code ) {
		return $this->cache->makeGlobalKey( __CLASS__, self::CACHE_VERSION, crc32( $code ) );
	}

	/**
	 * Get the tokens for the given code.
	 *
	 * @param string $code
	 * @return array[]
	 */
	public function getTokens( $code ) {
		$tokens = $this->cache->getWithSetCallback(
			$this->getCacheKey( $code ),
			BagOStuff::TTL_DAY,
			function () use ( $code ) {
				return $this->tokenize( $code );
			}
		);

		return $tokens;
	}

	/**
	 * @param string $code
	 * @return array[]
	 */
	private function tokenize( $code ) {
		$tokens = [];
		$curPos = 0;

		do {
			$prevPos = $curPos;
			$token = $this->nextToken( $code, $curPos );
			$tokens[ $token->pos ] = [ $token, $curPos ];
		} while ( $curPos !== $prevPos );

		return $tokens;
	}

	/**
	 * @param string $code
	 * @param int &$offset
	 * @return AFPToken
	 * @throws AFPException
	 * @throws AFPUserVisibleException
	 */
	private function nextToken( $code, &$offset ) {
		$matches = [];
		$start = $offset;

		// Read past comments
		while ( preg_match( self::COMMENT_START_RE, $code, $matches, 0, $offset ) ) {
			if ( strpos( $code, '*/', $offset ) === false ) {
				throw new AFPUserVisibleException(
					'unclosedcomment', $offset, [] );
			}
			$offset = strpos( $code, '*/', $offset ) + 2;
		}

		// Spaces
		$offset += strspn( $code, self::WHITESPACE, $offset );
		if ( $offset >= strlen( $code ) ) {
			return new AFPToken( AFPToken::TNONE, '', $start );
		}

		$chr = $code[$offset];

		// Punctuation
		if ( isset( self::$punctuation[$chr] ) ) {
			$offset++;
			return new AFPToken( self::$punctuation[$chr], $chr, $start );
		}

		// String literal
		if ( $chr === '"' || $chr === "'" ) {
			return self::readStringLiteral( $code, $offset, $start );
		}

		$matches = [];

		// Operators
		if ( preg_match( self::OPERATOR_RE, $code, $matches, 0, $offset ) ) {
			$token = $matches[0];
			$offset += strlen( $token );
			return new AFPToken( AFPToken::TOP, $token, $start );
		}

		// Numbers
		$matchesv2 = [];
		if ( preg_match( self::RADIX_RE_V2, $code, $matchesv2, 0, $offset ) ) {
			// Experimental new syntax for non-decimal numbers, T212730
			$token = $matchesv2[0];
			$baseChar = $matchesv2['base'];
			$input = $matchesv2['input'];
			$base = $baseChar ? self::$bases[$baseChar] : 10;
			if ( preg_match( self::$baseCharsRe[$base], $input ) ) {
				if ( $base !== 10 ) {
					// This is to check that the new syntax is working. Remove when removing the old syntax
					$this->logger->info(
						'Successfully parsed a non-decimal number with new syntax. ' .
						'Base: {number_base}, number: {number_input}',
						[ 'number_base' => $base, 'number_input' => $input ]
					);
				}
				$num = $base !== 10 ? base_convert( $input, $base, 10 ) : $input;
				$offset += strlen( $token );
				return ( strpos( $input, '.' ) !== false )
					? new AFPToken( AFPToken::TFLOAT, floatval( $num ), $start )
					: new AFPToken( AFPToken::TINT, intval( $num ), $start );
			}
		}
		if ( preg_match( self::RADIX_RE, $code, $matches, 0, $offset ) ) {
			list( $token, $input ) = $matches;
			$baseChar = $matches[2] ?? null;
			// Sometimes the base char gets mixed in with the rest of it because
			// the regex targets hex, too.
			// This mostly happens with binary
			if ( !$baseChar && !empty( self::$bases[ substr( $input, - 1 ) ] ) ) {
				$baseChar = substr( $input, - 1, 1 );
				$input = substr( $input, 0, - 1 );
			}

			$base = $baseChar ? self::$bases[$baseChar] : 10;

			// Check against the appropriate character class for input validation
			if ( preg_match( self::$baseCharsRe[$base], $input ) ) {
				if ( $base !== 10 ) {
					// Old syntax, this is deprecated
					$this->logger->warning(
						'DEPRECATED! This syntax for non-decimal numbers has been deprecated in 1.34 and will ' .
						'be removed in 1.35. Please switch to the new syntax, which is the same ' .
						'as PHP\'s. Found number with base: {number_base}, integer part: {number_input}.',
						[ 'number_base' => $base, 'number_input' => $input ]
					);
				}
				$num = $base !== 10 ? base_convert( $input, $base, 10 ) : $input;
				$offset += strlen( $token );
				return ( strpos( $input, '.' ) !== false )
					? new AFPToken( AFPToken::TFLOAT, floatval( $num ), $start )
					: new AFPToken( AFPToken::TINT, intval( $num ), $start );
			}
		}

		// IDs / Keywords

		if ( preg_match( self::ID_SYMBOL_RE, $code, $matches, 0, $offset ) ) {
			$token = $matches[0];
			$offset += strlen( $token );
			$type = in_array( $token, self::$keywords )
				? AFPToken::TKEYWORD
				: AFPToken::TID;
			return new AFPToken( $type, $token, $start );
		}

		throw new AFPUserVisibleException(
			'unrecognisedtoken', $start, [ substr( $code, $start ) ] );
	}

	/**
	 * @param string $code
	 * @param int &$offset
	 * @param int $start
	 * @return AFPToken
	 * @throws AFPException
	 * @throws AFPUserVisibleException
	 */
	private static function readStringLiteral( $code, &$offset, $start ) {
		$type = $code[$offset];
		$offset++;
		$length = strlen( $code );
		$token = '';
		while ( $offset < $length ) {
			if ( $code[$offset] === $type ) {
				$offset++;
				return new AFPToken( AFPToken::TSTRING, $token, $start );
			}

			// Performance: Use a PHP function (implemented in C)
			// to scan ahead.
			$addLength = strcspn( $code, $type . "\\", $offset );
			if ( $addLength ) {
				$token .= substr( $code, $offset, $addLength );
				$offset += $addLength;
			} elseif ( $code[$offset] === '\\' ) {
				switch ( $code[$offset + 1] ) {
					case '\\':
						$token .= '\\';
						break;
					case $type:
						$token .= $type;
						break;
					case 'n';
						$token .= "\n";
						break;
					case 'r':
						$token .= "\r";
						break;
					case 't':
						$token .= "\t";
						break;
					case 'x':
						$chr = substr( $code, $offset + 2, 2 );

						if ( preg_match( '/^[0-9A-Fa-f]{2}$/', $chr ) ) {
							$token .= chr( hexdec( $chr ) );
							// \xXX -- 2 done later
							$offset += 2;
						} else {
							$token .= '\\x';
						}
						break;
					default:
						$token .= "\\" . $code[$offset + 1];
				}

				$offset += 2;

			} else {
				// Should never happen
				// @codeCoverageIgnoreStart
				$token .= $code[$offset];
				$offset++;
				// @codeCoverageIgnoreEnd
			}
		}
		throw new AFPUserVisibleException( 'unclosedstring', $offset, [] );
	}
}
