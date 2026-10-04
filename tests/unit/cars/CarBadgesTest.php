<?php

declare(strict_types=1);

use ElanRegistry\Car\CarBadges;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ElanRegistry\Car\CarBadges (issue #1900).
 *
 * Expected values are literal badge-key arrays and fixed day offsets, never
 * values that the test computes with the same rule as the code under test.
 */
#[Group('fast')]
#[Group('unit')]
final class CarBadgesTest extends TestCase
{
    // Expected markup is literal. Tooltip wording is from the AC of issue #1900.
    // htmlspecialchars(ENT_QUOTES) encodes the apostrophe as &#039; and leaves
    // the em dash as UTF-8 text.
    private const SOLD_FLAT = '<span class="er-badge er-badge--sold" data-bs-toggle="tooltip"'
        . ' data-bs-title="Reported sold by the owner — the car and its history stay in the registry."'
        . ' tabindex="0">Sold</span>' . "\n";
    private const NEW_FLAT = '<span class="er-badge er-badge--new" data-bs-toggle="tooltip"'
        . ' data-bs-title="Added to the registry in the last 90 days, or one of the 5 newest cars."'
        . ' tabindex="0">New</span>' . "\n";
    private const VERIFIED_FLAT = '<span class="er-badge er-badge--verified" data-bs-toggle="tooltip"'
        . ' data-bs-title="The owner confirmed, added, or updated this car&#039;s record in the last 12 months."'
        . ' tabindex="0"><span aria-hidden="true">✓</span> Verified</span>' . "\n";

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['mockLogEntries'] = [];
    }

    /** Datetime string at a fixed whole-day offset before now. */
    private static function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    /**
     * Build a car record the way Car::data() shapes it.
     *
     * @param array<string, mixed> $overrides
     */
    private static function car(array $overrides = []): stdClass
    {
        return (object) array_merge([
            'id'                 => 501,
            'solddate'           => null,
            'last_verified'      => null,
            'owner_last_updated' => self::daysAgo(800),
        ], $overrides);
    }

    /** Narrow a decorated row to an object row. */
    private static function asObject(object|array $row): object
    {
        self::assertIsObject($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private static function logEntries(): array
    {
        $entries = $GLOBALS['mockLogEntries'] ?? [];
        self::assertIsArray($entries);

        return array_values($entries);
    }

    // ------------------------------------------------------------------
    // resolve()
    // ------------------------------------------------------------------

    /** @return array<string, array{bool, bool, bool, list<string>}> */
    public static function resolveProvider(): array
    {
        return [
            'none'                  => [false, false, false, []],
            'sold only'             => [true, false, false, ['sold']],
            'fresh only'            => [false, true, false, ['verified']],
            'new only'              => [false, false, true, ['new']],
            'sold and fresh (AC2)'  => [true, true, false, ['sold']],
            'fresh and new (AC6)'   => [false, true, true, ['new']],
            'sold and new (AC6)'    => [true, false, true, ['new', 'sold']],
            'sold, fresh, and new'  => [true, true, true, ['new', 'sold']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('resolveProvider')]
    public function test_resolve_allCombinations_returnsLiteralKeys(
        bool $sold,
        bool $fresh,
        bool $isNew,
        array $expected
    ): void {
        $result = CarBadges::resolve($sold, $fresh, $isNew);

        $this->assertSame($expected, $result);
    }

    // ------------------------------------------------------------------
    // html()
    // ------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function flatBadgeProvider(): array
    {
        return [
            'new'      => ['new', self::NEW_FLAT],
            'sold'     => ['sold', self::SOLD_FLAT],
            'verified' => ['verified', self::VERIFIED_FLAT],
        ];
    }

    #[DataProvider('flatBadgeProvider')]
    public function test_html_flat_returnsLiteralMarkup(string $key, string $expected): void
    {
        $this->assertSame($expected, CarBadges::html([$key], 'flat'));
    }

    public function test_html_defaultStyle_isFlat(): void
    {
        $this->assertSame(self::SOLD_FLAT, CarBadges::html(['sold']));
    }

    public function test_html_unknownStyle_isFlat(): void
    {
        $this->assertSame(self::SOLD_FLAT, CarBadges::html(['sold'], 'bogus'));
    }

    public function test_html_stamp_addsStampClassOnly(): void
    {
        $this->assertSame(
            '<span class="er-badge er-badge--verified er-badge--stamp" data-bs-toggle="tooltip"'
            . ' data-bs-title="The owner confirmed, added, or updated this car&#039;s record in the last 12 months."'
            . ' tabindex="0"><span aria-hidden="true">✓</span> Verified</span>' . "\n",
            CarBadges::html(['verified'], 'stamp')
        );
        $this->assertSame(
            str_replace('er-badge--sold"', 'er-badge--sold er-badge--stamp"', self::SOLD_FLAT),
            CarBadges::html(['sold'], 'stamp')
        );
    }

    public function test_html_keepsKeyOrderAndSkipsUnknownKeys(): void
    {
        $this->assertSame(
            self::SOLD_FLAT . self::VERIFIED_FLAT,
            CarBadges::html(['sold', 'bogus', 'verified'])
        );
        $this->assertSame(
            self::VERIFIED_FLAT . self::NEW_FLAT,
            CarBadges::html(['verified', 'new'])
        );
    }

    /** @return array<string, array{list<string>}> */
    public static function noKnownKeyProvider(): array
    {
        return [
            'empty list'   => [[]],
            'unknown keys' => [['bogus', 'SOLD', '']],
        ];
    }

    /**
     * @param list<string> $keys
     */
    #[DataProvider('noKnownKeyProvider')]
    public function test_html_noKnownKey_returnsEmptyString(array $keys): void
    {
        $this->assertSame('', CarBadges::html($keys, 'stamp'));
    }

    public function test_html_onlyVerifiedHasAriaHiddenIcon(): void
    {
        $this->assertStringNotContainsString('aria-hidden', CarBadges::html(['new', 'sold']));
        $this->assertSame(1, substr_count(CarBadges::html(['new', 'sold', 'verified']), 'aria-hidden="true"'));
    }

    public function test_html_parsedDom_roundTripsTooltipAndReadsLabelWithoutIcon(): void
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="root">' . CarBadges::html(['sold', 'verified'], 'stamp') . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new DOMXPath($doc);

        $verified = $xp->query('//span[contains(@class,"er-badge--verified")]')?->item(0);
        $this->assertInstanceOf(DOMElement::class, $verified);
        $this->assertSame(
            "The owner confirmed, added, or updated this car's record in the last 12 months.",
            $verified->getAttribute('data-bs-title')
        );
        // A screen reader reads the badge text without the aria-hidden icon.
        $this->assertSame(
            'Verified',
            $xp->evaluate('normalize-space(string(//span[contains(@class,"er-badge--verified")]/text()))')
        );
        $this->assertSame(0, $xp->query('//*[@title or @aria-label]')?->length);
    }

    // ------------------------------------------------------------------
    // forCar()
    // ------------------------------------------------------------------

    public function test_forCar_freshOwnerDate_returnsVerified(): void
    {
        $car = self::car(['owner_last_updated' => self::daysAgo(10)]);

        $this->assertSame(['verified'], CarBadges::forCar($car));
        $this->assertSame([], self::logEntries());
    }

    public function test_forCar_soldDate_returnsSold(): void
    {
        $car = self::car(['solddate' => '2025-03-14']);

        $this->assertSame(['sold'], CarBadges::forCar($car));
    }

    public function test_forCar_soldAndFresh_returnsOnlySold(): void
    {
        $car = self::car([
            'solddate'           => '2025-03-14',
            'owner_last_updated' => self::daysAgo(10),
        ]);

        $this->assertSame(['sold'], CarBadges::forCar($car));
    }

    public function test_forCar_stale_returnsNoBadges(): void
    {
        $this->assertSame([], CarBadges::forCar(self::car()));
    }

    // The boundary tests use 360 and 370 days, not 364 and 366: the rule is
    // "-1 year", which is 366 days when the year includes 29 February.

    public function test_forCar_verifiedAt360Days_withStaleOwnerDate_returnsVerified(): void
    {
        $car = self::car([
            'last_verified'      => self::daysAgo(360),
            'owner_last_updated' => self::daysAgo(900),
        ]);

        $this->assertSame(['verified'], CarBadges::forCar($car));
    }

    public function test_forCar_verifiedAt370Days_withStaleOwnerDate_returnsNoBadges(): void
    {
        $car = self::car([
            'last_verified'      => self::daysAgo(370),
            'owner_last_updated' => self::daysAgo(900),
        ]);

        $this->assertSame([], CarBadges::forCar($car));
    }

    public function test_forCar_ownerDateOnlyAt360And370Days_followsSameBoundary(): void
    {
        $this->assertSame(
            ['verified'],
            CarBadges::forCar(self::car(['owner_last_updated' => self::daysAgo(360)]))
        );
        $this->assertSame(
            [],
            CarBadges::forCar(self::car(['owner_last_updated' => self::daysAgo(370)]))
        );
    }

    public function test_forCar_mtimeIsIgnored(): void
    {
        // A recent mtime must not make a stale car fresh.
        $car = self::car(['mtime' => self::daysAgo(1)]);

        $this->assertSame([], CarBadges::forCar($car));
    }

    // ------------------------------------------------------------------
    // Verified tooltip text (#1897)
    // ------------------------------------------------------------------

    public function test_verifiedTooltip_isTrueForBothFreshnessSources(): void
    {
        $expected = "The owner confirmed, added, or updated this car's record in the last 12 months.";

        foreach (['flat', 'stamp'] as $style) {
            $doc = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $doc->loadHTML(
                '<?xml encoding="UTF-8"><div id="root">' . CarBadges::html(['verified'], $style) . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $badge = (new DOMXPath($doc))->query('//span[contains(@class,"er-badge--verified")]')?->item(0);
            $this->assertInstanceOf(DOMElement::class, $badge);
            $title = $badge->getAttribute('data-bs-title');

            $this->assertSame($expected, $title, "style {$style}");
            $this->assertStringNotContainsString("confirmed this car's details", $title);
        }
    }

    // ------------------------------------------------------------------
    // verifiedStatus() (#1897)
    // ------------------------------------------------------------------

    public function test_verifiedStatus_freshLastVerified_staleOwner_isConfirmedWithLastVerifiedDate(): void
    {
        $verified = self::daysAgo(5);
        $status = CarBadges::verifiedStatus(self::car([
            'last_verified'      => $verified,
            'owner_last_updated' => self::daysAgo(800),
        ]));

        $this->assertNotNull($status);
        $this->assertSame('confirmed', $status['source']);
        $this->assertSame($verified, $status['date']->format('Y-m-d H:i:s'));
        $this->assertSame([], self::logEntries());
    }

    public function test_verifiedStatus_freshLastVerified_freshOwner_isConfirmed(): void
    {
        $verified = self::daysAgo(30);
        $status = CarBadges::verifiedStatus(self::car([
            'last_verified'      => $verified,
            'owner_last_updated' => self::daysAgo(2),
        ]));

        $this->assertNotNull($status);
        $this->assertSame('confirmed', $status['source']);
        $this->assertSame($verified, $status['date']->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array{mixed}> */
    public static function noUsableLastVerifiedProvider(): array
    {
        return [
            'null (new car)' => [null],
            'stale'          => [self::daysAgo(800)],
        ];
    }

    #[DataProvider('noUsableLastVerifiedProvider')]
    public function test_verifiedStatus_freshOwnerOnly_isCurrentWithOwnerDate(mixed $lastVerified): void
    {
        $owner = self::daysAgo(7);
        $status = CarBadges::verifiedStatus(self::car([
            'last_verified'      => $lastVerified,
            'owner_last_updated' => $owner,
        ]));

        $this->assertNotNull($status);
        $this->assertSame('current', $status['source']);
        $this->assertSame($owner, $status['date']->format('Y-m-d H:i:s'));
        $this->assertSame([], self::logEntries());
    }

    public function test_verifiedStatus_bothStale_isNullWithoutLog(): void
    {
        $this->assertNull(CarBadges::verifiedStatus(self::car([
            'last_verified'      => self::daysAgo(800),
            'owner_last_updated' => self::daysAgo(900),
        ])));
        $this->assertNull(CarBadges::verifiedStatus(self::car()));
        $this->assertSame([], self::logEntries());
    }

    public function test_verifiedStatus_soldFreshCar_isNullWithoutLog(): void
    {
        $this->assertNull(CarBadges::verifiedStatus(self::car([
            'solddate'           => '2025-03-14',
            'last_verified'      => self::daysAgo(5),
            'owner_last_updated' => self::daysAgo(5),
        ])));
        $this->assertSame([], self::logEntries());
    }

    /** @return array<string, array{string}> */
    public static function freshnessFieldProvider(): array
    {
        return [
            'last_verified'      => ['last_verified'],
            'owner_last_updated' => ['owner_last_updated'],
        ];
    }

    #[DataProvider('freshnessFieldProvider')]
    public function test_verifiedStatus_360DaysIsInside_370DaysIsOutside(string $field): void
    {
        $other = $field === 'last_verified' ? 'owner_last_updated' : 'last_verified';

        $inside = CarBadges::verifiedStatus(self::car([$field => self::daysAgo(360), $other => self::daysAgo(900)]));
        $outside = CarBadges::verifiedStatus(self::car([$field => self::daysAgo(370), $other => self::daysAgo(900)]));

        $this->assertNotNull($inside);
        $this->assertSame($field === 'last_verified' ? 'confirmed' : 'current', $inside['source']);
        $this->assertNull($outside);
    }

    public function test_verifiedStatus_recentMtimeWithStaleDates_isNull(): void
    {
        $this->assertNull(CarBadges::verifiedStatus(self::car(['mtime' => date('Y-m-d H:i:s')])));
    }

    public function test_verifiedStatus_neverReadsMtime(): void
    {
        $car = new class {
            public int $id = 501;
            public ?string $solddate = null;
            public ?string $last_verified = null;
            public string $owner_last_updated = '';

            public function __construct()
            {
                $this->owner_last_updated = date('Y-m-d H:i:s', strtotime('-3 days'));
            }

            public function __get(string $name): never
            {
                throw new LogicException("Read of undefined property {$name}");
            }

            public function __isset(string $name): never
            {
                throw new LogicException("isset of undefined property {$name}");
            }
        };

        $status = CarBadges::verifiedStatus($car);

        $this->assertNotNull($status);
        $this->assertSame('current', $status['source']);
    }

    /** @return array<string, array{mixed}> */
    public static function wrongTypedValueProvider(): array
    {
        return [
            'int'          => [1700000000],
            'array'        => [['2026-01-01 00:00:00']],
            'object'       => [new stdClass()],
            'true'         => [true],
            'false'        => [false],
            'empty string' => [''],
            'garbage'      => ['garbage'],
        ];
    }

    #[DataProvider('wrongTypedValueProvider')]
    public function test_verifiedStatus_badLastVerified_isNullAndLogsOnce(mixed $value): void
    {
        // A fresh owner date must not hide the bad last_verified.
        $car = self::car(['last_verified' => $value, 'owner_last_updated' => self::daysAgo(2)]);

        $this->assertNull(CarBadges::verifiedStatus($car));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
    }

    #[DataProvider('wrongTypedValueProvider')]
    public function test_verifiedStatus_badOwnerLastUpdated_isNullAndLogsOnce(mixed $value): void
    {
        // A fresh last_verified must not hide the bad owner_last_updated.
        $car = self::car(['last_verified' => self::daysAgo(2), 'owner_last_updated' => $value]);

        $this->assertNull(CarBadges::verifiedStatus($car));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
    }

    public function test_verifiedStatus_missingOwnerLastUpdated_isNullAndLogsOnce(): void
    {
        $car = self::car(['last_verified' => self::daysAgo(2)]);
        unset($car->owner_last_updated);

        $this->assertNull(CarBadges::verifiedStatus($car));
        $this->assertCount(1, self::logEntries());
    }

    public function test_verifiedStatus_nullOwnerLastUpdated_isNullAndLogsOnce(): void
    {
        $this->assertNull(CarBadges::verifiedStatus(self::car(['owner_last_updated' => null])));
        $this->assertCount(1, self::logEntries());
    }

    /** @return array<string, array{mixed}> */
    public static function wrongTypedSolddateProvider(): array
    {
        return [
            'int'   => [20250314],
            'array' => [['2025-03-14']],
        ];
    }

    #[DataProvider('wrongTypedSolddateProvider')]
    public function test_verifiedStatus_wrongTypedSolddate_countsAsSoldIsNullWithoutLog(mixed $solddate): void
    {
        // isSold() treats any non-null, non-empty value as sold, so the car is
        // sold before the freshness dates are read.
        $car = self::car([
            'solddate'           => $solddate,
            'last_verified'      => self::daysAgo(2),
            'owner_last_updated' => self::daysAgo(2),
        ]);

        $this->assertTrue(CarBadges::isSold($solddate));
        $this->assertNull(CarBadges::verifiedStatus($car));
        $this->assertSame([], self::logEntries());
    }

    public function test_verifiedStatus_emptySolddate_isNotSold(): void
    {
        $status = CarBadges::verifiedStatus(self::car([
            'solddate'           => '',
            'owner_last_updated' => self::daysAgo(2),
        ]));

        $this->assertNotNull($status);
        $this->assertSame('current', $status['source']);
    }

    /**
     * Values with more than one `T` between the date and the time.
     *
     * CarRepository::parseTimestamp() changes each `T` to a space, and a space
     * in its format matches more than one whitespace character. So it accepts
     * these values, and isFresh() calls the car fresh. `new DateTimeImmutable()`
     * does not accept them. The date part is 30 days ago, so the values stay
     * inside the window on any run date.
     *
     * @return array<string, array{string, string, string}> [column, raw value, validated value]
     */
    public static function repeatedTSeparatorProvider(): array
    {
        $day = date('Y-m-d', strtotime('-30 days'));

        return [
            'last_verified TT'       => ['last_verified', "{$day}TT15:10:11", "{$day} 15:10:11"],
            'last_verified TTT'      => ['last_verified', "{$day}TTT10:00:00", "{$day} 10:00:00"],
            'owner_last_updated TT'  => ['owner_last_updated', "{$day}TT15:10:11", "{$day} 15:10:11"],
            'owner_last_updated TTT' => ['owner_last_updated', "{$day}TTT10:00:00", "{$day} 10:00:00"],
        ];
    }

    /**
     * The parser that isFresh() uses accepts these values, so the car is
     * fresh and forCar() shows the Verified badge. The card must agree with
     * the badge and show the date that the parser validated. It must not
     * throw (a 500 on the details, account, and vericode pages).
     */
    #[DataProvider('repeatedTSeparatorProvider')]
    public function test_verifiedStatus_repeatedTSeparator_showsValidatedDateWithoutException(
        string $column,
        string $raw,
        string $validated
    ): void {
        $car = $column === 'last_verified'
            ? self::car(['last_verified' => $raw])
            : self::car(['owner_last_updated' => $raw]);

        $status = CarBadges::verifiedStatus($car);

        $this->assertNotNull($status);
        $this->assertSame($column === 'last_verified' ? 'confirmed' : 'current', $status['source']);
        $this->assertSame($validated, $status['date']->format('Y-m-d H:i:s'));
        $this->assertSame(['verified'], CarBadges::forCar($car));
        $this->assertSame([], self::logEntries());
    }

    // ------------------------------------------------------------------
    // forCar() — wrong-typed or malformed freshness input
    // ------------------------------------------------------------------

    /** @return array<string, array{array<string, mixed>}> */
    public static function badOwnerLastUpdatedProvider(): array
    {
        return [
            'missing' => [['__unset' => 'owner_last_updated']],
            'null'    => [['owner_last_updated' => null]],
            'int'     => [['owner_last_updated' => 1700000000]],
            'DateTime' => [['owner_last_updated' => new DateTime('-1 day')]],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('badOwnerLastUpdatedProvider')]
    public function test_forCar_badOwnerLastUpdated_noVerifiedAndLogsOnce(array $override): void
    {
        $car = self::car(['last_verified' => self::daysAgo(1)]);
        if (isset($override['__unset'])) {
            unset($car->{$override['__unset']});
        } else {
            foreach ($override as $field => $value) {
                $car->{$field} = $value;
            }
        }

        $this->assertSame([], CarBadges::forCar($car));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
    }

    /** @return array<string, array{mixed}> */
    public static function badLastVerifiedProvider(): array
    {
        return [
            'garbage string' => ['garbage'],
            'zero date'      => ['0000-00-00 00:00:00'],
            'empty string'   => [''],
            'int'            => [1700000000],
            'DateTime'       => [new DateTime('-1 day')],
        ];
    }

    #[DataProvider('badLastVerifiedProvider')]
    public function test_forCar_badLastVerified_noVerifiedAndLogsOnce(mixed $lastVerified): void
    {
        // A fresh owner date must not hide the bad last_verified: the PHP rule
        // validates both operands, so the badge is not shown and the log has one entry.
        $car = self::car([
            'last_verified'      => $lastVerified,
            'owner_last_updated' => self::daysAgo(1),
        ]);

        $this->assertSame([], CarBadges::forCar($car));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
        $this->assertStringContainsString('501', self::logEntries()[0]['message']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedDateProvider(): array
    {
        return [
            'owner null'          => [['owner_last_updated' => null]],
            'owner int'           => [['owner_last_updated' => 5]],
            'last_verified junk'  => [['last_verified' => 'garbage']],
            'last_verified zero'  => [['last_verified' => '0000-00-00 00:00:00']],
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('malformedDateProvider')]
    public function test_forCar_soldWithMalformedDate_stillReturnsSold(array $override): void
    {
        $car = self::car(array_merge(['solddate' => '2025-03-14'], $override));

        $this->assertSame(['sold'], CarBadges::forCar($car));
        $this->assertCount(1, self::logEntries());
    }

    public function test_forCar_validDates_doNotLog(): void
    {
        CarBadges::forCar(self::car(['last_verified' => self::daysAgo(5)]));
        CarBadges::forCar(self::car());

        $this->assertSame([], self::logEntries());
    }

    public function test_forCar_missingId_logsWithUnknownCarId(): void
    {
        $car = self::car(['owner_last_updated' => null]);
        unset($car->id);

        CarBadges::forCar($car);

        $this->assertCount(1, self::logEntries());
        $this->assertStringContainsString('car unknown', self::logEntries()[0]['message']);
    }

    // ------------------------------------------------------------------
    // decorateRows()
    // ------------------------------------------------------------------

    public function test_decorateRows_objectRows_setsBadgesListAndRemovesIsFresh(): void
    {
        $rows = [
            (object) ['id' => 1, 'solddate' => null, 'is_fresh' => 1],
            (object) ['id' => 2, 'solddate' => '2025-01-01', 'is_fresh' => 1],
            (object) ['id' => 3, 'solddate' => null, 'is_fresh' => 0],
            (object) ['id' => 4, 'solddate' => '2025-01-01', 'is_fresh' => 0],
        ];

        $result = CarBadges::decorateRows($rows, [4]);

        $this->assertCount(4, $result);
        $this->assertSame(['verified'], self::asObject($result[0])->badges);
        $this->assertSame(['sold'], self::asObject($result[1])->badges);
        $this->assertSame([], self::asObject($result[2])->badges);
        $this->assertSame(['new', 'sold'], self::asObject($result[3])->badges);
        foreach ($result as $item) {
            $row = self::asObject($item);
            $this->assertTrue(array_is_list($row->badges));
            $this->assertObjectNotHasProperty('is_fresh', $row);
            $this->assertObjectHasProperty('id', $row);
            $this->assertObjectHasProperty('solddate', $row);
        }
    }

    public function test_decorateRows_objectRows_doesNotMutateInput(): void
    {
        $rows = [(object) ['id' => 1, 'solddate' => null, 'is_fresh' => 1]];

        CarBadges::decorateRows($rows, []);

        $this->assertObjectHasProperty('is_fresh', $rows[0]);
        $this->assertObjectNotHasProperty('badges', $rows[0]);
    }

    public function test_decorateRows_isFreshStrings_arePdoShaped(): void
    {
        $rows = [
            (object) ['id' => 1, 'solddate' => null, 'is_fresh' => '1'],
            (object) ['id' => 2, 'solddate' => null, 'is_fresh' => '0'],
        ];

        $result = CarBadges::decorateRows($rows, []);

        $this->assertSame(['verified'], self::asObject($result[0])->badges);
        $this->assertSame([], self::asObject($result[1])->badges);
    }

    /** @return array<string, array{mixed, list<string>}> */
    public static function malformedIsFreshProvider(): array
    {
        // Documented behavior: only an integer value of exactly 1 means fresh.
        // The expression is boolean in MySQL, so 0 and 1 are the only real values.
        return [
            'null'          => [null, []],
            'empty string'  => ['', []],
            'garbage'       => ['garbage', []],
            'true string'   => ['true', []],
            'two'           => [2, []],
            'two string'    => ['2', []],
            'bool true'     => [true, ['verified']],
            'bool false'    => [false, []],
            'padded one'    => [' 1', ['verified']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('malformedIsFreshProvider')]
    public function test_decorateRows_malformedIsFresh_followsIntCastEqualsOne(mixed $isFresh, array $expected): void
    {
        $rows = [(object) ['id' => 1, 'solddate' => null, 'is_fresh' => $isFresh]];

        $result = CarBadges::decorateRows($rows, []);

        $this->assertSame($expected, self::asObject($result[0])->badges);
        $this->assertObjectNotHasProperty('is_fresh', $result[0]);
    }

    public function test_decorateRows_missingIsFresh_isNotFresh(): void
    {
        $result = CarBadges::decorateRows([(object) ['id' => 1, 'solddate' => null]], []);

        $this->assertSame([], self::asObject($result[0])->badges);
        $this->assertCount(1, self::logEntries());
    }

    public function test_decorateRows_missingIsFreshOnManyRows_logsOncePerCall(): void
    {
        $rows = [
            (object) ['id' => 1, 'solddate' => null],
            (object) ['id' => 2, 'solddate' => null],
            (object) ['id' => 3, 'solddate' => null],
            (object) ['id' => 4, 'solddate' => null, 'is_fresh' => 1],
        ];

        $result = CarBadges::decorateRows($rows, []);

        $this->assertSame([], self::asObject($result[0])->badges);
        $this->assertSame([], self::asObject($result[1])->badges);
        $this->assertSame([], self::asObject($result[2])->badges);
        $this->assertSame(['verified'], self::asObject($result[3])->badges);
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
        $this->assertStringContainsString('is_fresh', self::logEntries()[0]['message']);
    }

    public function test_decorateRows_badgesHtml_isWrappedFlatHtmlOrEmpty(): void
    {
        $rows = [
            (object) ['id' => 1, 'solddate' => '2025-01-01', 'is_fresh' => 0],
            (object) ['id' => 2, 'solddate' => null, 'is_fresh' => 0],
            (object) ['id' => 3, 'solddate' => '2025-01-01', 'is_fresh' => 1],
            (object) ['id' => 4, 'solddate' => null, 'is_fresh' => 0],
        ];

        $result = CarBadges::decorateRows($rows, [1]);

        $wrapper = '<div class="er-badges d-flex flex-wrap gap-1 mt-1">';
        $this->assertSame(
            $wrapper . self::NEW_FLAT . self::SOLD_FLAT . '</div>',
            self::asObject($result[0])->badges_html
        );
        $this->assertSame('', self::asObject($result[1])->badges_html);
        $this->assertSame($wrapper . self::SOLD_FLAT . '</div>', self::asObject($result[2])->badges_html);
        $this->assertSame('', self::asObject($result[3])->badges_html);
    }

    // ------------------------------------------------------------------
    // isSold() and soldDate()
    // ------------------------------------------------------------------

    /** @return array<string, array{mixed, bool}> */
    public static function isSoldProvider(): array
    {
        return [
            'null'         => [null, false],
            'empty string' => ['', false],
            'date'         => ['2024-02-29', true],
        ];
    }

    #[DataProvider('isSoldProvider')]
    public function test_isSold_followsNonEmptySolddate(mixed $solddate, bool $expected): void
    {
        $this->assertSame($expected, CarBadges::isSold($solddate));
        $this->assertSame($expected ? ['sold'] : [], CarBadges::forCar(self::car(['solddate' => $solddate])));
        $result = CarBadges::decorateRows([(object) ['id' => 1, 'solddate' => $solddate, 'is_fresh' => 0]], []);
        $this->assertSame($expected ? ['sold'] : [], self::asObject($result[0])->badges);
        $this->assertSame([], self::logEntries());
    }

    public function test_missingSolddate_isNotSold(): void
    {
        $car = self::car();
        unset($car->solddate);

        $this->assertSame([], CarBadges::forCar($car));
    }

    public function test_soldDate_validDate_returnsThatDateAtMidnight(): void
    {
        $parsed = CarBadges::soldDate('2024-02-29');

        $this->assertNotNull($parsed);
        $this->assertSame('2024-02-29 00:00:00', $parsed->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array{mixed}> */
    public static function notSoldProvider(): array
    {
        return [
            'null'         => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('notSoldProvider')]
    public function test_soldDate_notSold_returnsNullWithoutLog(mixed $solddate): void
    {
        $this->assertNull(CarBadges::soldDate($solddate));
        $this->assertSame([], self::logEntries());
    }

    /** @return array<string, array{mixed}> */
    public static function badSoldDateProvider(): array
    {
        return [
            'not a date'      => ['garbage'],
            'not a string'    => [20250314],
            'zero date'       => ['0000-00-00'],
            'rolls over'      => ['2024-02-30'],
            'month 13'        => ['2024-13-01'],
            'has a time part' => ['2024-01-15 00:00:00'],
        ];
    }

    #[DataProvider('badSoldDateProvider')]
    public function test_soldDate_badValue_returnsNullAndLogsOnce(mixed $solddate): void
    {
        $this->assertTrue(CarBadges::isSold($solddate));
        $this->assertNull(CarBadges::soldDate($solddate));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
    }

    public function test_soldDate_badValueWithCarId_logsCarId(): void
    {
        $this->assertNull(CarBadges::soldDate('0000-00-00', 732));

        $this->assertCount(1, self::logEntries());
        $this->assertStringContainsString('car 732 ', self::logEntries()[0]['message']);
        $this->assertStringContainsString("'0000-00-00'", self::logEntries()[0]['message']);
    }

    public function test_soldDate_badValueWithoutCarId_logsUnknownCarId(): void
    {
        $this->assertNull(CarBadges::soldDate('garbage'));

        $this->assertCount(1, self::logEntries());
        $this->assertStringContainsString('car unknown ', self::logEntries()[0]['message']);
    }

    public function test_decorateRows_stringId_matchesNewIds(): void
    {
        // PDO may return ids as strings. They must still match the int newIds.
        $rows = [(object) ['id' => '12', 'solddate' => null, 'is_fresh' => '0']];

        $result = CarBadges::decorateRows($rows, [12]);

        $this->assertSame(['new'], self::asObject($result[0])->badges);
    }

    /** @return array<string, array{mixed}> */
    public static function nonNumericIdProvider(): array
    {
        return [
            'text'    => ['abc'],
            'empty'   => [''],
            'null'    => [null],
            'array'   => [[]],
        ];
    }

    #[DataProvider('nonNumericIdProvider')]
    public function test_decorateRows_nonNumericId_isNotNew(mixed $id): void
    {
        $rows = [(object) ['id' => $id, 'solddate' => null, 'is_fresh' => 1]];

        // Documented behavior: the id casts to 0, which is never in $newIds,
        // so the row is not new and keeps its Verified badge.
        $result = CarBadges::decorateRows($rows, [12, 13]);

        $this->assertSame(['verified'], self::asObject($result[0])->badges);
    }

    public function test_decorateRows_missingId_isNotNew(): void
    {
        $result = CarBadges::decorateRows([(object) ['solddate' => null, 'is_fresh' => 1]], [12]);

        $this->assertSame(['verified'], self::asObject($result[0])->badges);
    }

    public function test_decorateRows_emptyRows_returnsEmptyArray(): void
    {
        $this->assertSame([], CarBadges::decorateRows([], [1, 2]));
    }

    public function test_decorateRows_preservesRowKeys(): void
    {
        $rows = [7 => (object) ['id' => 1, 'solddate' => null, 'is_fresh' => 0]];

        $result = CarBadges::decorateRows($rows, []);

        $this->assertSame([7], array_keys($result));
    }

    public function test_decorateRows_neverLogs(): void
    {
        CarBadges::decorateRows([(object) ['id' => 1, 'solddate' => null, 'is_fresh' => 'garbage']], []);

        $this->assertSame([], self::logEntries());
    }
}
