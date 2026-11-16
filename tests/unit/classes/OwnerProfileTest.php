<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Owner;
use ElanRegistry\OwnerView;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for Owner::qualityScoreFromRow() and
 * Owner::validateProfileCompleteness()
 *
 * Regression guard for issue #960: both methods iterate the shared private
 * constant PROFILE_SIMPLE_FIELD_LABELS. A typo in any key (e.g. 'fname' →
 * 'firstname') would silently break scoring and completeness checks. The
 * per-field tests below catch that class of regression without touching the
 * constant directly.
 *
 * Scoring formula: round(($completed / 7) * 100, 1)
 *   - 6 simple fields: fname, lname, email, city, state, country (1 pt each)
 *   - lat AND lon together (1 pt combined)
 *
 * @see https://github.com/unibrain1/elanregistry/issues/960
 */
#[Group('fast')]
#[Group('unit')]
final class OwnerProfileTest extends TestCase
{
    private Owner $owner;
    private \ReflectionProperty $dataProp;

    protected function setUp(): void
    {
        // A DatabaseInterface double is required: Owner's constructor otherwise
        // falls back to dbi(), which is undefined in the unit tier (custom_functions.php
        // — where dbi() lives — is never loaded there).
        // A stub, not a mock — the tests sharing this instance exercise pure
        // scoring/completeness logic over reflection-injected data and never
        // reach the database.
        $this->owner    = new Owner(null, $this->createStub(DatabaseInterface::class));
        $ref            = new \ReflectionClass(Owner::class);
        $this->dataProp = $ref->getProperty('_data');
        // PHP 8.1+: setAccessible() is a no-op; ReflectionProperty accesses
        // private members directly via setValue()/getValue().
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * A row with every scoring field populated (score = 7/7 = 100.0).
     */
    private function fullRow(): object
    {
        return (object) [
            'fname'   => 'Jim',
            'lname'   => 'Boone',
            'email'   => 'jim@example.com',
            'city'    => 'Portland',
            'state'   => 'OR',
            'country' => 'USA',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
        ];
    }

    /**
     * A row with every scoring field blank (score = 0/7 = 0.0).
     */
    private function emptyRow(): object
    {
        return (object) [
            'fname'   => '',
            'lname'   => '',
            'email'   => '',
            'city'    => '',
            'state'   => '',
            'country' => '',
            'lat'     => '',
            'lon'     => '',
        ];
    }

    /**
     * Inject a value into the private $_data property of $this->owner.
     */
    private function injectData(?object $data): void
    {
        $this->dataProp->setValue($this->owner, $data);
    }

    // -----------------------------------------------------------------------
    // getProfileQualityScore() — instance method, delegates to qualityScoreFromRow()
    // -----------------------------------------------------------------------

    public function testNullDataQualityScoreReturnsZero(): void
    {
        $this->injectData(null);
        $this->assertSame(0.0, $this->owner->getProfileQualityScore());
    }

    // -----------------------------------------------------------------------
    // qualityScoreFromRow() — public static, accepts plain stdClass
    // -----------------------------------------------------------------------

    public function testAllFieldsPopulatedScores100(): void
    {
        $this->assertSame(100.0, Owner::qualityScoreFromRow($this->fullRow()));
    }

    public function testNoFieldsPopulatedScores0(): void
    {
        $this->assertSame(0.0, Owner::qualityScoreFromRow($this->emptyRow()));
    }

    /**
     * Only lat + lon set → 1 point → round(1/7 * 100, 1) = 14.3
     */
    public function testOnlyLatLonScores14point3(): void
    {
        $row      = $this->emptyRow();
        $row->lat = '45.5231';
        $row->lon = '-122.6765';

        $this->assertSame(14.3, Owner::qualityScoreFromRow($row));
    }

    /**
     * All 6 simple fields set, no coordinates → 6 points → round(6/7 * 100, 1) = 85.7
     */
    public function testAllSimpleFieldsButNoCoordinatesScores85point7(): void
    {
        $row      = $this->fullRow();
        $row->lat = '';
        $row->lon = '';

        $this->assertSame(85.7, Owner::qualityScoreFromRow($row));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function simpleFieldValueProvider(): array
    {
        return [
            'fname' => ['fname', 'Jim'],
            'lname' => ['lname', 'Boone'],
            'email' => ['email', 'jim@example.com'],
            'city' => ['city', 'Portland'],
            'state' => ['state', 'OR'],
            'country' => ['country', 'USA'],
        ];
    }

    /**
     * Per-field regression rows catch PROFILE_SIMPLE_FIELD_LABELS key typos.
     * One simple field set, all else blank → 1/7 * 100 rounded = 14.3.
     */
    #[DataProvider('simpleFieldValueProvider')]
    public function testOnlyOneSimpleFieldScores14point3(string $field, string $value): void
    {
        $row         = $this->emptyRow();
        $row->$field = $value;

        $this->assertSame(14.3, Owner::qualityScoreFromRow($row));
    }

    /**
     * lat set but lon empty → AND condition fails → 0 points → 0.0
     * Confirms that a partial coordinate pair does not score.
     */
    public function testLatWithoutLonDoesNotScore(): void
    {
        $row      = $this->emptyRow();
        $row->lat = '45.5231';

        $this->assertSame(0.0, Owner::qualityScoreFromRow($row));
    }

    /**
     * lon set but lat empty → AND condition fails → 0 points → 0.0
     */
    public function testLonWithoutLatDoesNotScore(): void
    {
        $row      = $this->emptyRow();
        $row->lon = '-122.6765';

        $this->assertSame(0.0, Owner::qualityScoreFromRow($row));
    }

    // -----------------------------------------------------------------------
    // validateProfileCompleteness() — instance method, $_data injected via Reflection
    // -----------------------------------------------------------------------

    public function testNullDataReturnsLoadedMessage(): void
    {
        $this->injectData(null);

        $this->assertSame(['Owner data not loaded'], $this->owner->validateProfileCompleteness());
    }

    public function testAllFieldsPopulatedReturnsEmpty(): void
    {
        $this->injectData($this->fullRow());

        $this->assertSame([], $this->owner->validateProfileCompleteness());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function missingSimpleFieldLabelProvider(): array
    {
        return [
            'fname' => ['fname', 'First Name'],
            'lname' => ['lname', 'Last Name'],
            'email' => ['email', 'Email'],
            'city' => ['city', 'City'],
            'state' => ['state', 'State'],
            'country' => ['country', 'Country'],
        ];
    }

    #[DataProvider('missingSimpleFieldLabelProvider')]
    public function testMissingSimpleFieldReturnsItsLabel(string $field, string $label): void
    {
        $data         = $this->fullRow();
        $data->$field = '';
        $this->injectData($data);

        $this->assertContains($label, $this->owner->validateProfileCompleteness());
    }

    /**
     * lat missing, lon present → OR condition true → 'Location Coordinates' added.
     */
    public function testMissingLatReturnsLocationCoordinates(): void
    {
        $data      = $this->fullRow();
        $data->lat = '';
        $this->injectData($data);

        $this->assertContains('Location Coordinates', $this->owner->validateProfileCompleteness());
    }

    /**
     * lon missing, lat present → OR condition true → 'Location Coordinates' added.
     */
    public function testMissingLonReturnsLocationCoordinates(): void
    {
        $data      = $this->fullRow();
        $data->lon = '';
        $this->injectData($data);

        $this->assertContains('Location Coordinates', $this->owner->validateProfileCompleteness());
    }

    /**
     * Multiple fields missing → all corresponding labels returned.
     */
    public function testMultipleMissingFieldsReturnsAllLabels(): void
    {
        $data        = $this->fullRow();
        $data->fname = '';
        $data->email = '';
        $data->lat   = '';
        $this->injectData($data);

        $missing = $this->owner->validateProfileCompleteness();

        $this->assertContains('First Name', $missing);
        $this->assertContains('Email', $missing);
        $this->assertContains('Location Coordinates', $missing);
    }

    /**
     * All fields missing → result order must match PROFILE_SIMPLE_FIELD_LABELS
     * declaration order (fname → lname → email → city → state → country) with
     * 'Location Coordinates' appended last.
     */
    public function testReturnOrderMatchesFieldOrder(): void
    {
        $this->injectData($this->emptyRow());

        $this->assertSame(
            ['First Name', 'Last Name', 'Email', 'City', 'State', 'Country', 'Location Coordinates'],
            $this->owner->validateProfileCompleteness()
        );
    }

    // -----------------------------------------------------------------------
    // qualityScoreFromRow() on search-result-shaped rows
    // Regression guard for issue #1230: process-owner-search.php now calls
    // qualityScoreFromRow() directly on rows from searchOwners(), which include
    // extra columns (e.g. id). These tests confirm the scoring is unaffected.
    // -----------------------------------------------------------------------

    public function testSearchResultShapedRowScoresCorrectly(): void
    {
        $row = (object) [
            'id'      => 42,
            'fname'   => 'Jim',
            'lname'   => 'Boone',
            'email'   => 'jim@example.com',
            'city'    => 'Portland',
            'state'   => 'OR',
            'country' => 'USA',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
        ];

        // 7/7 = 100.0
        $this->assertSame(100.0, Owner::qualityScoreFromRow($row));
    }

    public function testSearchResultShapedRowWithPartialDataScoresCorrectly(): void
    {
        $row = (object) [
            'id'      => 99,
            'fname'   => 'Jane',
            'lname'   => 'Smith',
            'email'   => 'jane@example.com',
            'city'    => '',
            'state'   => '',
            'country' => '',
            'lat'     => '',
            'lon'     => '',
        ];

        // 3 simple fields → round(3/7 * 100, 1) = 42.9
        $this->assertSame(42.9, Owner::qualityScoreFromRow($row));
    }

    public function testUnionBranchShapedRowScoresCorrectly(): void
    {
        // searchOwners() UNION branch returns an extra `priority` column.
        // Confirms qualityScoreFromRow() ignores unknown columns.
        $row = (object) [
            'id'       => 7,
            'fname'    => 'Greg',
            'lname'    => 'Surcouf',
            'email'    => 'greg@example.com',
            'city'     => 'London',
            'state'    => '',
            'country'  => 'GB',
            'lat'      => '51.5074',
            'lon'      => '-0.1278',
            'priority' => 1,
        ];

        // 5 simple fields + lat/lon → round(6/7 * 100, 1) = 85.7
        $this->assertSame(85.7, Owner::qualityScoreFromRow($row));
    }

    // -----------------------------------------------------------------------
    // find() behaviour (issue #1148)
    //
    // Owner accepts an injectable $db so we can mock the three paths:
    // $userId <= 0 (early-return), DB error, user not found, and success.
    // -----------------------------------------------------------------------

    /**
     * Database double for Owner::find().
     *
     * query() returns the double itself, mirroring the real \DB contract
     * (query() always returns $this for chaining), so the row data each test
     * needs is shaped with the count()/first() stubs on the same double.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject&DatabaseInterface
     */
    private function makeOwnerDbMock(): object
    {
        return $this->createMock(DatabaseInterface::class);
    }

    public function testFindReturnsFalseForNonPositiveUserId(): void
    {
        $db = $this->makeOwnerDbMock();
        $db->expects($this->never())->method('query');

        $owner = new Owner(null, $db);
        $this->assertFalse($owner->find(0));
        $this->assertFalse($owner->find(-1));
    }

    public function testFindThrowsOwnerDatabaseExceptionOnDatabaseError(): void
    {
        $db = $this->makeOwnerDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('connection lost');

        $owner = new Owner(null, $db);

        $this->expectException(\ElanRegistry\Exceptions\OwnerDatabaseException::class);
        $owner->find(42);
    }

    public function testFindReturnsFalseWhenUserNotFound(): void
    {
        $db = $this->makeOwnerDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $owner = new Owner(null, $db);
        $this->assertFalse($owner->find(99));
        $this->assertNull($owner->data());
    }

    public function testFindReturnsTrueAndPopulatesData(): void
    {
        $db = $this->makeOwnerDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) [
            'id'        => '7',
            'email'     => 'test@example.com',
            'fname'     => 'Test',
            'lname'     => 'User',
            'city'      => null,
            'state'     => null,
            'country'   => null,
            'lat'       => null,
            'lon'       => null,
            'website'   => null,
        ]);

        $owner = new Owner(null, $db);
        $this->assertTrue($owner->find(7));

        $data = $owner->data();
        $this->assertNotNull($data);
        $this->assertSame('', $data->city);    // null-coalesced to ''
        $this->assertSame('', $data->state);
        $this->assertSame('', $data->country);
        $this->assertNull($data->lat);         // null stays null
        $this->assertNull($data->lon);
    }

    // =========================================================================
    // getQualityBadgeClass() drift guard (#1618)
    //
    // Owner::getQualityBadgeClass() duplicates OwnerView::qualityBadgeClass()'s
    // thresholds/logic (Owner.php:462-471, OwnerView.php:42-53). OwnerViewTest
    // already covers OwnerView::qualityBadgeClass() directly with boundary
    // values; these tests assert Owner's copy returns the identical value at
    // the same boundaries, so a future edit to just one side is caught here
    // rather than silently drifting.
    // =========================================================================

    /**
     * Direct assertions on Owner::getQualityBadgeClass()'s own thresholds,
     * independent of OwnerView — a failure here identifies which side
     * (Owner's own logic) is wrong, not just that the two disagree.
     */
    public function testGetQualityBadgeClassReturnsExpectedClassForBoundaryScores(): void
    {
        $this->assertSame('danger', Owner::getQualityBadgeClass(0.0));
        $this->assertSame('danger', Owner::getQualityBadgeClass(59.9));
        $this->assertSame('warning', Owner::getQualityBadgeClass(60.0));
        $this->assertSame('warning', Owner::getQualityBadgeClass(79.9));
        $this->assertSame('success', Owner::getQualityBadgeClass(80.0));
        $this->assertSame('success', Owner::getQualityBadgeClass(100.0));
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function boundaryScoreProvider(): array
    {
        return [
            'zero score' => [0.0],
            'just below warning (59.9)' => [59.9],
            'warning boundary (60)' => [60.0],
            'just below success (79.9)' => [79.9],
            'success boundary (80)' => [80.0],
            'perfect score (100)' => [100.0],
        ];
    }

    #[DataProvider('boundaryScoreProvider')]
    public function testGetQualityBadgeClassMatchesOwnerViewAcrossBoundaries(float $score): void
    {
        $this->assertSame(
            OwnerView::qualityBadgeClass($score),
            Owner::getQualityBadgeClass($score),
            "Owner::getQualityBadgeClass({$score}) must match OwnerView::qualityBadgeClass({$score}) — they must not drift apart"
        );
    }
}
