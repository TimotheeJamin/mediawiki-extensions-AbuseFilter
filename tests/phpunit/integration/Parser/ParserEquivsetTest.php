<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Parser;

use EmptyBagOStuff;
use Generator;
use LanguageEn;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterCachingParser;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterParser;
use MediaWiki\Extension\AbuseFilter\Parser\AFPException;
use MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser\ParserTest;
use MediaWiki\Extension\AbuseFilter\Tests\Unit\Parser\ParserTestCase;
use MediaWikiIntegrationTestCase;

/**
 * Tests that require Equivset, separated from the parser unit tests.
 *
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterCachingParser
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AFPTreeParser
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AFPTransitionBase
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AFPTreeNode
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AFPSyntaxTree
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AFPParserState
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterParser
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterTokenizer
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AFPToken
 * @covers \MediaWiki\Extension\AbuseFilter\Parser\AFPData
 */
class ParserEquivsetTest extends MediaWikiIntegrationTestCase {
	/**
	 * @return AbuseFilterParser[]
	 * @see ParserTestCase::getParsers() - we cannot reuse that due to inheritance
	 */
	protected function getParsers() {
		static $parsers = null;
		if ( !$parsers ) {
			// We're not interested in caching or logging; tests should call respectively setCache
			// and setLogger if they want to test any of those.
			$contLang = new LanguageEn();
			$cache = new EmptyBagOStuff();
			$logger = new \Psr\Log\NullLogger();
			$keywordsManager = AbuseFilterServices::getKeywordsManager();

			$parser = new AbuseFilterParser( $contLang, $cache, $logger, $keywordsManager, 1000 );
			$parser->toggleConditionLimit( false );
			$cachingParser = new AbuseFilterCachingParser( $contLang, $cache, $logger, $keywordsManager, 1000 );
			$cachingParser->toggleConditionLimit( false );
			$parsers = [ $parser, $cachingParser ];
		} else {
			// Reset so that already executed tests don't influence new ones
			$parsers[0]->resetState();
			$parsers[0]->clearFuncCache();
			$parsers[1]->resetState();
			$parsers[1]->clearFuncCache();
		}
		return $parsers;
	}

	/**
	 * @param string $rule The rule to parse
	 * @dataProvider provideGenericTests
	 */
	public function testGeneric( $rule ) {
		if ( !class_exists( 'Wikimedia\Equivset\Equivset' ) ) {
			$this->markTestSkipped( 'Equivset is not installed' );
		}
		foreach ( $this->getParsers() as $parser ) {
			$this->assertTrue( $parser->parse( $rule ), 'Parser used: ' . get_class( $parser ) );
		}
	}

	/**
	 * @return Generator|array
	 */
	public function provideGenericTests() {
		$testPath = __DIR__ . "/../../../parserTestsEquivset";
		$testFiles = glob( $testPath . "/*.t" );

		foreach ( $testFiles as $testFile ) {
			$testName = basename( substr( $testFile, 0, -2 ) );
			$rule = trim( file_get_contents( $testFile ) );

			yield $testName => [ $rule ];
		}
	}

	/**
	 * @param string $func
	 * @see ParserTest::testVariadicFuncsArbitraryArgsAllowed()
	 * @dataProvider variadicFuncs
	 */
	public function testVariadicFuncsArbitraryArgsAllowed( $func ) {
		$argsList = str_repeat( ', "arg"', 50 );
		$code = "$func( 'arg' $argsList )";
		foreach ( $this->getParsers() as $parser ) {
			$pname = get_class( $parser );
			try {
				$parser->parse( $code );
				$this->assertTrue( true );
			} catch ( AFPException $e ) {
				$this->fail( "Got exception with parser $pname.\n$e" );
			}
		}
	}

	/**
	 * @return array
	 */
	public function variadicFuncs() {
		return [
			[ 'ccnorm_contains_any' ],
			[ 'ccnorm_contains_all' ],
		];
	}
}
