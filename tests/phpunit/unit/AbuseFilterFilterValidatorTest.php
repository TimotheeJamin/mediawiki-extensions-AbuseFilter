<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagValidator;
use MediaWiki\Extension\AbuseFilter\Filter\AbstractFilter;
use MediaWiki\Extension\AbuseFilter\FilterValidator;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterSave
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\FilterValidator
 */
class AbuseFilterFilterValidatorTest extends MediaWikiUnitTestCase {

	/**
	 * @param AbuseFilterPermissionManager|null $permissionManager
	 * @param AbuseFilterParser|null $parser
	 * @param array $restrictions
	 * @return FilterValidator
	 */
	private function getFilterValidator(
		AbuseFilterPermissionManager $permissionManager = null,
		AbuseFilterParser $parser = null,
		array $restrictions = []
	) : FilterValidator {
		if ( !$parser ) {
			$parser = $this->createMock( AbuseFilterParser::class );
			$parser->method( 'checkSyntax' )->willReturn( true );
		}
		$parserFactory = $this->createMock( ParserFactory::class );
		$parserFactory->method( 'newParser' )->willReturn( $parser );
		if ( !$permissionManager ) {
			$permissionManager = $this->createMock( AbuseFilterPermissionManager::class );
			$permissionManager->method( 'canEditFilter' )->willReturn( true );
		}
		return new FilterValidator(
			$this->createMock( ChangeTagValidator::class ),
			$parserFactory,
			$permissionManager,
			$restrictions
		);
	}

	/**
	 * @param array $actions
	 * @return AbstractFilter|MockObject
	 */
	private function getFilterWithActions( array $actions ) : AbstractFilter {
		$ret = $this->createMock( AbstractFilter::class );
		$ret->method( 'getRules' )->willReturn( '1' );
		$ret->method( 'getName' )->willReturn( 'Foo' );
		$ret->method( 'getActions' )->willReturn( $actions );
		return $ret;
	}

	/**
	 * Helper to check that $expected is null and $actual is good, or the messages match
	 * @param string|null $expected
	 * @param Status $actual
	 */
	private function assertStatusMessage( ?string $expected, Status $actual ) : void {
		$actualError = $actual->isGood() ? null : $actual->getErrors()[0]['message'];
		$this->assertSame( $expected, $actualError );
	}

	/**
	 * @param bool $valid
	 * @param string|null $expected
	 * @covers ::checkValidSyntax
	 * @dataProvider provideSyntax
	 */
	public function testCheckValidSyntax( bool $valid, ?string $expected ) {
		$parser = $this->createMock( AbuseFilterParser::class );
		$parser->method( 'checkSyntax' )->willReturn( $valid ?: [ 'x' ] );
		$validator = $this->getFilterValidator( null, $parser );

		$this->assertStatusMessage(
			$expected,
			$validator->checkValidSyntax( $this->createMock( AbstractFilter::class ) )
		);
	}

	public function provideSyntax() : array {
		return [
			'valid' => [ true, null ],
			'invalid' => [ false, 'abusefilter-edit-badsyntax' ]
		];
	}

	/**
	 * @param string $rules
	 * @param string $name
	 * @param string|null $expected
	 * @covers ::checkRequiredFields
	 * @dataProvider provideRequiredFields
	 */
	public function testCheckRequiredFields( string $rules, string $name, ?string $expected ) {
		$filter = $this->createMock( AbstractFilter::class );
		$filter->method( 'getRules' )->willReturn( $rules );
		$filter->method( 'getName' )->willReturn( $name );
		$validator = $this->getFilterValidator();
		$this->assertStatusMessage( $expected, $validator->checkRequiredFields( $filter ) );
	}

	public function provideRequiredFields() : array {
		return [
			'valid' => [ '0', '0', null ],
			'no rules' => [ '', 'bar', 'abusefilter-edit-missingfields' ],
			'no name' => [ 'bar', '   ', 'abusefilter-edit-missingfields' ],
			'no rules and no name' => [ '', '', 'abusefilter-edit-missingfields' ]
		];
	}

	/**
	 * @param array $actions
	 * @param string|null $expected
	 * @covers ::checkEmptyMessages
	 * @dataProvider provideEmptyMessages
	 */
	public function testCheckEmptyMessages( array $actions, ?string $expected ) {
		$filter = $this->getFilterWithActions( $actions );
		$this->assertStatusMessage( $expected, $this->getFilterValidator()->checkEmptyMessages( $filter ) );
	}

	public function provideEmptyMessages() : array {
		return [
			'valid' => [ [ 'warn' => [ 'foo' ], 'disallow' => [ 'bar' ] ], null ],
			'empty warn' => [ [ 'warn' => [ '' ], 'disallow' => [ 'bar' ] ], 'abusefilter-edit-invalid-warn-message' ],
			'empty disallow' =>
				[ [ 'warn' => [ 'foo' ], 'disallow' => [ '' ] ], 'abusefilter-edit-invalid-disallow-message' ],
			'both empty' => [ [ 'warn' => [ '' ], 'disallow' => [ '' ] ], 'abusefilter-edit-invalid-warn-message' ]
		];
	}

	/**
	 * @param bool $enabled
	 * @param bool $deleted
	 * @param string|null $expected
	 * @covers ::checkConflictingFields
	 * @dataProvider provideConflictingFields
	 */
	public function testCheckConflictingFields( bool $enabled, bool $deleted, ?string $expected ) {
		$filter = $this->createMock( AbstractFilter::class );
		$filter->method( 'isEnabled' )->willReturn( $enabled );
		$filter->method( 'isDeleted' )->willReturn( $deleted );
		$this->assertStatusMessage( $expected, $this->getFilterValidator()->checkConflictingFields( $filter ) );
	}

	public function provideConflictingFields() : array {
		return [
			'valid' => [ true, false, null ],
			'invalid' => [ true, true, 'abusefilter-edit-deleting-enabled' ]
		];
	}

	/**
	 * @param bool $canEditNew
	 * @param bool $canEditOrig
	 * @param string|null $expected
	 * @covers ::checkGlobalFilterEditPermission
	 * @dataProvider provideCheckGlobalFilterEditPermission
	 */
	public function testCheckGlobalFilterEditPermission(
		bool $canEditNew,
		bool $canEditOrig,
		?string $expected
	) {
		$permManager = $this->createMock( AbuseFilterPermissionManager::class );
		$permManager->method( 'canEditFilter' )->willReturnOnConsecutiveCalls( $canEditNew, $canEditOrig );
		$validator = $this->getFilterValidator( $permManager );
		$actual = $validator->checkGlobalFilterEditPermission(
			$this->createMock( User::class ),
			$this->createMock( AbstractFilter::class ),
			$this->createMock( AbstractFilter::class )
		);
		$this->assertStatusMessage( $expected, $actual );
	}

	public function provideCheckGlobalFilterEditPermission() : array {
		return [
			'none' => [ false, false, 'abusefilter-edit-notallowed-global' ],
			'cur only' => [ true, false, 'abusefilter-edit-notallowed-global' ],
			'orig only' => [ false, true, 'abusefilter-edit-notallowed-global' ],
			'both' => [ true, true, null ]
		];
	}

	/**
	 * @param array $actions
	 * @param bool $isGlobal
	 * @param string|null $expected
	 * @covers ::checkMessagesOnGlobalFilters
	 * @dataProvider provideMessagesOnGlobalFilters
	 */
	public function testCheckMessagesOnGlobalFilters( array $actions, bool $isGlobal, ?string $expected ) {
		$filter = $this->getFilterWithActions( $actions );
		$filter->method( 'isGlobal' )->willReturn( $isGlobal );
		$this->assertStatusMessage( $expected, $this->getFilterValidator()->checkMessagesOnGlobalFilters( $filter ) );
	}

	public function provideMessagesOnGlobalFilters() : array {
		return [
			'valid' => [
				[ 'warn' => [ 'abusefilter-warning' ], 'disallow' => [ 'abusefilter-disallowed' ] ],
				true,
				null
			],
			'custom warn' => [
				[ 'warn' => [ 'foo' ], 'disallow' => [ 'abusefilter-disallowed' ] ],
				true,
				'abusefilter-edit-notallowed-global-custom-msg'
			],
			'custom disallow' => [
				[ 'warn' => [ 'abusefilter-warn' ], 'disallow' => [ 'bar' ] ],
				true,
				'abusefilter-edit-notallowed-global-custom-msg'
			],
			'both custom' => [
				[ 'warn' => [ 'xxx' ], 'disallow' => [ 'yyy' ] ],
				true,
				'abusefilter-edit-notallowed-global-custom-msg'
			],
			'both custom but not global' => [ [ 'warn' => [ 'xxx' ], 'disallow' => [ 'yyy' ] ], false, null ]
		];
	}

	/**
	 * @param AbstractFilter $newFilter
	 * @param AbstractFilter $oldFilter
	 * @param array $restrictions
	 * @param AbuseFilterPermissionManager $permManager
	 * @param string|null $expected
	 * @covers ::checkRestrictedActions
	 * @dataProvider provideRestrictedActions
	 */
	public function testCheckRestrictedActions(
		AbstractFilter $newFilter,
		AbstractFilter $oldFilter,
		array $restrictions,
		AbuseFilterPermissionManager $permManager,
		?string $expected
	) {
		$validator = $this->getFilterValidator( $permManager, null, $restrictions );
		$user = $this->createMock( User::class );
		$this->assertStatusMessage( $expected, $validator->checkRestrictedActions( $user, $newFilter, $oldFilter ) );
	}

	public function provideRestrictedActions() : Generator {
		$canModifyRestrictedPM = $this->createMock( AbuseFilterPermissionManager::class );
		$canModifyRestrictedPM->method( 'canEditFilterWithRestrictedActions' )->willReturn( true );
		$cannotModifyRestrictedPM = $this->createMock( AbuseFilterPermissionManager::class );
		$cannotModifyRestrictedPM->method( 'canEditFilterWithRestrictedActions' )->willReturn( false );

		$newFilter = $oldFilter = $this->getFilterWithActions( [] );
		yield 'no restricted actions, with modify-restricted' =>
			[ $newFilter, $oldFilter, [], $canModifyRestrictedPM, null ];
		yield 'no restricted actions, no modify-restricted' =>
			[ $newFilter, $oldFilter, [], $cannotModifyRestrictedPM, null ];

		$restrictions = [ 'degroup' ];
		$restricted = $this->getFilterWithActions( [ 'warn' => [ 'foo' ], 'degroup' => [] ] );
		$unrestricted = $this->getFilterWithActions( [ 'warn' => [ 'foo' ] ] );

		yield 'restricted actions in new version, no modify-restricted' =>
			[ $restricted, $unrestricted, $restrictions, $cannotModifyRestrictedPM, 'abusefilter-edit-restricted' ];

		yield 'restricted actions in old version, no modify-restricted' =>
			[ $unrestricted, $restricted, $restrictions, $cannotModifyRestrictedPM, 'abusefilter-edit-restricted' ];

		yield 'restricted actions in new version, with modify-restricted' =>
			[ $restricted, $unrestricted, $restrictions, $canModifyRestrictedPM, null ];

		yield 'restricted actions in old version, with modify-restricted' =>
			[ $unrestricted, $restricted, $restrictions, $canModifyRestrictedPM, null ];
	}

	/**
	 * @covers ::checkAllTags
	 */
	public function testCheckAllTags_noTags() {
		$this->assertStatusMessage( 'tags-create-no-name', $this->getFilterValidator()->checkAllTags( [] ) );
	}

	/**
	 * @param array $params Throttle parameters
	 * @param string|null $expectedError The expected error message. Null if validations should pass
	 * @covers ::checkThrottleParameters
	 * @dataProvider provideThrottleParameters
	 */
	public function testCheckThrottleParameters( array $params, ?string $expectedError ) {
		$result = $this->getFilterValidator()->checkThrottleParameters( $params );
		$this->assertStatusMessage( $expectedError, $result );
	}

	/**
	 * Data provider for testCheckThrottleParameters
	 * @return array
	 */
	public function provideThrottleParameters() {
		return [
			[ [ '1', '5,23', 'user', 'ip', 'page,range', 'ip,user', 'range,ip' ], null ],
			[ [ '1', '5.3,23', 'user', 'ip' ], 'abusefilter-edit-invalid-throttlecount' ],
			[ [ '1', '-3,23', 'user', 'ip' ], 'abusefilter-edit-invalid-throttlecount' ],
			[ [ '1', '5,2.3', 'user', 'ip' ], 'abusefilter-edit-invalid-throttleperiod' ],
			[ [ '1', '4,-14', 'user', 'ip' ], 'abusefilter-edit-invalid-throttleperiod' ],
			[ [ '1', '3,33,44', 'user', 'ip' ], 'abusefilter-edit-invalid-throttleperiod' ],
			[ [ '1', '3,33' ], 'abusefilter-edit-empty-throttlegroups' ],
			[ [ '1', '3,33', 'user', 'ip,foo,user' ], 'abusefilter-edit-invalid-throttlegroups' ],
			[ [ '1', '3,33', 'foo', 'ip,user' ], 'abusefilter-edit-invalid-throttlegroups' ],
			[ [ '1', '3,33', 'foo', 'ip,user,bar' ], 'abusefilter-edit-invalid-throttlegroups' ],
			[ [ '1', '3,33', 'user', 'ip,page,user' ], null ],
			[
				[ '1', '3,33', 'ip', 'user','user,ip', 'ip,user', 'user,ip,user', 'user', 'ip,ip,user' ],
				'abusefilter-edit-duplicated-throttlegroups'
			],
			[ [ '1', '3,33', 'ip,ip,user' ], 'abusefilter-edit-duplicated-throttlegroups' ],
			[ [ '1', '3,33', 'user,ip', 'ip,user' ], 'abusefilter-edit-duplicated-throttlegroups' ],
		];
	}

	/**
	 * @param AbstractFilter $newFilter
	 * @param string|null $expected
	 * @param AbuseFilterPermissionManager|null $permissionManager
	 * @param AbuseFilterParser|null $parser
	 * @param array $restrictions
	 * @covers \MediaWiki\Extension\AbuseFilter\FilterValidator::checkAll
	 * @dataProvider provideCheckAll
	 */
	public function testCheckAll(
		AbstractFilter $newFilter,
		?string $expected,
		AbuseFilterPermissionManager $permissionManager = null,
		AbuseFilterParser $parser = null,
		array $restrictions = []
	) {
		$validator = $this->getFilterValidator( $permissionManager, $parser, $restrictions );
		$origFilter = $this->createMock( AbstractFilter::class );

		$status = $validator->checkAll( $newFilter, $origFilter, $this->createMock( User::class ) );
		$actualError = $status->isGood() ? null : $status->getErrors()[0]['message'];
		$this->assertSame( $expected, $actualError );
	}

	public function provideCheckAll() : Generator {
		$noopFilter = $this->createMock( AbstractFilter::class );
		$noopFilter->method( 'getRules' )->willReturn( '1' );
		$noopFilter->method( 'getName' )->willReturn( 'Foo' );
		$noopFilter->method( 'isEnabled' )->willReturn( true );

		$parser = $this->createMock( AbuseFilterParser::class );
		$parser->method( 'checkSyntax' )->willReturn( [ 'x' ] );
		yield 'invalid syntax' => [ $noopFilter, 'abusefilter-edit-badsyntax', null, $parser ];

		$missingFieldsFilter = $this->createMock( AbstractFilter::class );
		$missingFieldsFilter->method( 'getRules' )->willReturn( '' );
		$missingFieldsFilter->method( 'getName' )->willReturn( '' );
		yield 'missing required fields' => [ $missingFieldsFilter, 'abusefilter-edit-missingfields' ];

		$conflictFieldsFilter = $this->createMock( AbstractFilter::class );
		$conflictFieldsFilter->method( 'getRules' )->willReturn( '1' );
		$conflictFieldsFilter->method( 'getName' )->willReturn( 'Foo' );
		$conflictFieldsFilter->method( 'isEnabled' )->willReturn( true );
		$conflictFieldsFilter->method( 'isDeleted' )->willReturn( true );
		yield 'conflicting fields' => [ $conflictFieldsFilter, 'abusefilter-edit-deleting-enabled' ];

		yield 'invalid tags' => [ $this->getFilterWithActions( [ 'tag' => [] ] ), 'tags-create-no-name' ];

		yield 'missing required messages' =>
			[ $this->getFilterWithActions( [ 'warn' => [ '' ] ] ),'abusefilter-edit-invalid-warn-message' ];

		yield 'invalid throttle params' => [
			$this->getFilterWithActions( [ 'throttle' => [ '1', '5.3,23', 'user', 'ip' ] ] ),
			'abusefilter-edit-invalid-throttlecount'
		];

		$permManager = $this->createMock( AbuseFilterPermissionManager::class );
		$permManager->method( 'canEditFilter' )->willReturn( false );
		yield 'global filter, no modify-global' => [ $noopFilter, 'abusefilter-edit-notallowed-global', $permManager ];

		$customWarnFilter = $this->getFilterWithActions( [ 'warn' => [ 'foo' ] ] );
		$customWarnFilter->method( 'isGlobal' )->willReturn( true );
		yield 'global filter, custom message' => [ $customWarnFilter, 'abusefilter-edit-notallowed-global-custom-msg' ];

		$permManager = $this->createMock( AbuseFilterPermissionManager::class );
		$permManager->method( 'canEditFilter' )->willReturn( true );
		$permManager->method( 'canEditFilterWithRestrictedActions' )->willReturn( false );
		$restrictedFilter = $this->getFilterWithActions( [ 'degroup' => [] ] );
		yield 'restricted actions' => [
			$restrictedFilter,
			'abusefilter-edit-restricted',
			$permManager,
			null,
			[ 'degroup' ]
		];

		$filter = $this->createMock( AbstractFilter::class );
		$filter->method( 'getRules' )->willReturn( 'true' );
		$filter->method( 'getName' )->willReturn( 'Foo' );
		yield 'valid' => [ $filter, null ];
	}
}
