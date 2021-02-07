<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use LogicException;
use MediaWiki\Extension\AbuseFilter\Parser\ParserStatus;
use MediaWiki\Extension\AbuseFilter\RunnerData;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\RunnerData
 */
class RunnerDataTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct()
	 * @covers ::getMatchesMap
	 * @covers ::getProfilingData
	 * @covers ::getTotalRuntime
	 * @covers ::getTotalConditions
	 */
	public function testRunnerData_empty() {
		$runnerData = new RunnerData();
		$this->assertSame( [], $runnerData->getMatchesMap() );
		$this->assertSame( [], $runnerData->getProfilingData() );
		$this->assertSame( 0, $runnerData->getTotalConditions() );
		$this->assertSame( 0.0, $runnerData->getTotalRuntime() );
	}

	/**
	 * @covers ::record
	 * @covers ::getMatchesMap
	 * @covers ::getProfilingData
	 * @covers ::getTotalRuntime
	 * @covers ::getTotalConditions
	 */
	public function testRecord() {
		$runnerData = new RunnerData();
		$runnerData->record(
			1, false,
			new ParserStatus( true, false, null, [] ),
			[ 'time' => 12.3, 'conds' => 7 ]
		);
		$runnerData->record(
			1, true,
			new ParserStatus( false, false, null, [] ),
			[ 'time' => 23.4, 'conds' => 5 ]
		);

		$this->assertArrayEquals(
			[ '1' => true, 'global-1' => false ],
			$runnerData->getMatchesMap(),
			false,
			true
		);
		$this->assertArrayEquals(
			[
				'1' => [ 'time' => 12.3, 'conds' => 7, 'result' => true ],
				'global-1' => [ 'time' => 23.4, 'conds' => 5, 'result' => false ],
			],
			$runnerData->getProfilingData(),
			false,
			true
		);
		$this->assertSame( 12, $runnerData->getTotalConditions() );
		$this->assertSame( 35.7, $runnerData->getTotalRuntime() );
	}

	/**
	 * @covers ::record
	 */
	public function testRecord_throwsOnSameFilter() {
		$runnerData = new RunnerData();
		$runnerData->record(
			1, false,
			new ParserStatus( true, false, null, [] ),
			[ 'time' => 12.3, 'conds' => 7 ]
		);
		$this->expectException( LogicException::class );
		$runnerData->record(
			1, false,
			new ParserStatus( false, false, null, [] ),
			[ 'time' => 23.4, 'conds' => 5 ]
		);
	}

}
