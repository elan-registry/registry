<?php

declare(strict_types=1);

use ElanRegistry\Owner;
use PHPUnit\Framework\TestCase;
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
        $this->owner    = new Owner();
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

    // Per-field regression tests — catch PROFILE_SIMPLE_FIELD_LABELS key typos.
    // Each test sets exactly one simple field and leaves everything else blank.
    // Expected: 1/7 * 100 rounded to 1 decimal = 14.3.

    public function testOnlyFnameScores14point3(): void
    {
        $row        = $this->emptyRow();
        $row->fname = 'Jim';

        $this->assertSame(14.3, Owner::qualityScoreFromRow($row));
    }

    public function testOnlyLnameScores14point3(): void
    {
        $row        = $this->emptyRow();
        $row->lname = 'Boone';

        $this->assertSame(14.3, Owner::qualityScoreFromRow($row));
    }

    public function testOnlyEmailScores14point3(): void
    {
        $row        = $this->emptyRow();
        $row->email = 'jim@example.com';

        $this->assertSame(14.3, Owner::qualityScoreFromRow($row));
    }

    public function testOnlyCityScores14point3(): void
    {
        $row       = $this->emptyRow();
        $row->city = 'Portland';

        $this->assertSame(14.3, Owner::qualityScoreFromRow($row));
    }

    public function testOnlyStateScores14point3(): void
    {
        $row        = $this->emptyRow();
        $row->state = 'OR';

        $this->assertSame(14.3, Owner::qualityScoreFromRow($row));
    }

    public function testOnlyCountryScores14point3(): void
    {
        $row          = $this->emptyRow();
        $row->country = 'USA';

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

    public function testMissingFnameReturnsFirstName(): void
    {
        $data        = $this->fullRow();
        $data->fname = '';
        $this->injectData($data);

        $this->assertContains('First Name', $this->owner->validateProfileCompleteness());
    }

    public function testMissingLnameReturnsLastName(): void
    {
        $data        = $this->fullRow();
        $data->lname = '';
        $this->injectData($data);

        $this->assertContains('Last Name', $this->owner->validateProfileCompleteness());
    }

    public function testMissingEmailReturnsEmail(): void
    {
        $data        = $this->fullRow();
        $data->email = '';
        $this->injectData($data);

        $this->assertContains('Email', $this->owner->validateProfileCompleteness());
    }

    public function testMissingCityReturnsCity(): void
    {
        $data       = $this->fullRow();
        $data->city = '';
        $this->injectData($data);

        $this->assertContains('City', $this->owner->validateProfileCompleteness());
    }

    public function testMissingStateReturnsState(): void
    {
        $data        = $this->fullRow();
        $data->state = '';
        $this->injectData($data);

        $this->assertContains('State', $this->owner->validateProfileCompleteness());
    }

    public function testMissingCountryReturnsCountry(): void
    {
        $data          = $this->fullRow();
        $data->country = '';
        $this->injectData($data);

        $this->assertContains('Country', $this->owner->validateProfileCompleteness());
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
}
