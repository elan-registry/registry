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

    /**
     * Narrow a decorated row to an array row.
     *
     * @param object|array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function asArray(object|array $row): array
    {
        self::assertIsArray($row);

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
        $this->assertLessThanOrEqual(2, count($result));
    }

    // ------------------------------------------------------------------
    // BADGES structure
    // ------------------------------------------------------------------

    public function test_badges_structuralInvariants(): void
    {
        $constant = new ReflectionClassConstant(CarBadges::class, 'BADGES');
        $badges = $constant->getValue();
        $this->assertIsArray($badges);
        $keys = array_keys($badges);

        $priorities = [];
        foreach ($badges as $key => $badge) {
            $this->assertIsArray($badge);
            $this->assertIsArray($badge['suppressedBy'], "{$key} suppressedBy");
            foreach ($badge['suppressedBy'] as $suppressor) {
                $this->assertContains($suppressor, $keys, "{$key} is suppressed by an unknown key");
                $this->assertNotSame($key, $suppressor, "{$key} suppresses itself");
            }
            $group = $badge['group'];
            $this->assertTrue(
                $group === null || (is_string($group) && $group !== ''),
                "{$key} group must be null or a non-empty string"
            );
            $priorities[] = $badge['priority'];
        }
        $this->assertSame($priorities, array_unique($priorities), 'Badge priorities must be unique');

        $union = [];
        foreach ([false, true] as $sold) {
            foreach ([false, true] as $fresh) {
                foreach ([false, true] as $isNew) {
                    $union = array_merge($union, CarBadges::resolve($sold, $fresh, $isNew));
                }
            }
        }
        $union = array_unique($union);
        sort($union);
        $sortedKeys = $keys;
        sort($sortedKeys);
        $this->assertSame($sortedKeys, $union, 'Every badge must be reachable through resolve()');

        $this->assertGreaterThanOrEqual(1, CarBadges::MAX_BADGES);
    }

    // ------------------------------------------------------------------
    // definitions()
    // ------------------------------------------------------------------

    public function test_definitions_everyKeyHasLabelTooltipAndTone(): void
    {
        $definitions = CarBadges::definitions();

        $this->assertSame(['new', 'sold', 'verified'], array_keys($definitions));
        foreach ($definitions as $key => $definition) {
            $this->assertNotSame('', $definition['label'], "{$key} label");
            $this->assertNotSame('', $definition['tooltip'], "{$key} tooltip");
            $this->assertNotSame('', $definition['tone'], "{$key} tone");
            $this->assertArrayHasKey('icon', $definition, "{$key} icon");
        }
    }

    public function test_definitions_tooltipsMatchAcceptanceCriteriaWording(): void
    {
        $definitions = CarBadges::definitions();

        // Wording from the AC of issue #1900. Do not read these from the class.
        $this->assertSame(
            "The owner confirmed this car's details within the last year.",
            $definitions['verified']['tooltip']
        );
        $this->assertSame(
            'Reported sold by the owner — the car and its history stay in the registry.',
            $definitions['sold']['tooltip']
        );
        $this->assertSame(
            'Added to the registry in the last 90 days, or one of the 5 newest cars.',
            $definitions['new']['tooltip']
        );
    }

    public function test_definitions_labelsAndIconsAreLiteral(): void
    {
        $definitions = CarBadges::definitions();

        $this->assertSame('New', $definitions['new']['label']);
        $this->assertSame('Sold', $definitions['sold']['label']);
        $this->assertSame('Verified', $definitions['verified']['label']);
        $this->assertNull($definitions['new']['icon']);
        $this->assertNull($definitions['sold']['icon']);
        $this->assertSame('✓', $definitions['verified']['icon']);
    }

    public function test_definitions_doesNotExposePrecedenceFields(): void
    {
        foreach (CarBadges::definitions() as $key => $definition) {
            $this->assertArrayNotHasKey('priority', $definition, $key);
            $this->assertArrayNotHasKey('group', $definition, $key);
            $this->assertArrayNotHasKey('suppressedBy', $definition, $key);
        }
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

    public function test_forCar_isNewAndFresh_suppressesVerified(): void
    {
        $car = self::car(['owner_last_updated' => self::daysAgo(10)]);

        $this->assertSame(['new'], CarBadges::forCar($car, true));
    }

    public function test_forCar_isNewAndSold_returnsNewThenSold(): void
    {
        $car = self::car(['solddate' => '2025-03-14']);

        $this->assertSame(['new', 'sold'], CarBadges::forCar($car, true));
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

    public function test_decorateRows_arrayRows_setsBadgesListAndRemovesIsFresh(): void
    {
        $rows = [
            ['id' => 1, 'solddate' => null, 'is_fresh' => 1],
            ['id' => 2, 'solddate' => '2025-01-01', 'is_fresh' => 1],
            ['id' => 3, 'solddate' => null, 'is_fresh' => 1],
            ['id' => 4, 'solddate' => null, 'is_fresh' => 0],
        ];

        $result = CarBadges::decorateRows($rows, [3]);

        $this->assertSame(['verified'], self::asArray($result[0])['badges']);
        $this->assertSame(['sold'], self::asArray($result[1])['badges']);
        $this->assertSame(['new'], self::asArray($result[2])['badges']);
        $this->assertSame([], self::asArray($result[3])['badges']);
        foreach ($result as $row) {
            $this->assertIsArray($row);
            $this->assertArrayNotHasKey('is_fresh', $row);
        }
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
            ['id' => 2, 'solddate' => null],
            (object) ['id' => 3, 'solddate' => null],
            (object) ['id' => 4, 'solddate' => null, 'is_fresh' => 1],
        ];

        $result = CarBadges::decorateRows($rows, []);

        $this->assertSame([], self::asObject($result[0])->badges);
        $this->assertSame([], self::asArray($result[1])['badges']);
        $this->assertSame([], self::asObject($result[2])->badges);
        $this->assertSame(['verified'], self::asObject($result[3])->badges);
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
        $this->assertStringContainsString('is_fresh', self::logEntries()[0]['message']);
    }

    // ------------------------------------------------------------------
    // solddate validation (parseSoldDate) through forCar() and decorateRows()
    // ------------------------------------------------------------------

    /** @return array<string, array{mixed}> */
    public static function badSolddateProvider(): array
    {
        return [
            'zero date'        => ['0000-00-00'],
            'garbage'          => ['garbage'],
            'impossible date'  => ['2025-02-30'],
            'int'              => [20250314],
        ];
    }

    #[DataProvider('badSolddateProvider')]
    public function test_forCar_badSolddate_noSoldAndLogsOnce(mixed $solddate): void
    {
        $car = self::car(['solddate' => $solddate]);

        $this->assertSame([], CarBadges::forCar($car));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
        $this->assertStringContainsString('solddate', self::logEntries()[0]['message']);
    }

    #[DataProvider('badSolddateProvider')]
    public function test_decorateRows_badSolddate_noSoldAndLogsOnce(mixed $solddate): void
    {
        $rows = [(object) ['id' => 1, 'solddate' => $solddate, 'is_fresh' => 0]];

        $result = CarBadges::decorateRows($rows, []);

        $this->assertSame([], self::asObject($result[0])->badges);
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
    }

    public function test_decorateRows_badSolddateOnManyRows_logsOncePerCallWithCount(): void
    {
        $rows = [
            (object) ['id' => 11, 'solddate' => '0000-00-00', 'is_fresh' => 0],
            ['id' => 12, 'solddate' => '2025-02-30', 'is_fresh' => 0],
            (object) ['id' => 13, 'solddate' => 'garbage', 'is_fresh' => 0],
            (object) ['id' => 14, 'solddate' => '2025-03-14', 'is_fresh' => 0],
        ];

        $result = CarBadges::decorateRows($rows, []);

        $this->assertSame([], self::asObject($result[0])->badges);
        $this->assertSame([], self::asArray($result[1])['badges']);
        $this->assertSame([], self::asObject($result[2])->badges);
        $this->assertSame(['sold'], self::asObject($result[3])->badges);
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
        $message = self::logEntries()[0]['message'];
        $this->assertIsString($message);
        $this->assertStringContainsString('3 of 4 rows have a bad solddate', $message);
        $this->assertStringContainsString('11, 12, 13', $message);
    }

    public function test_decorateRows_badSolddateOnMoreThanTenRows_capsLoggedIds(): void
    {
        $rows = [];
        for ($id = 1; $id <= 12; $id++) {
            $rows[] = (object) ['id' => $id, 'solddate' => '0000-00-00', 'is_fresh' => 0];
        }

        CarBadges::decorateRows($rows, []);

        $this->assertCount(1, self::logEntries());
        $message = self::logEntries()[0]['message'];
        $this->assertIsString($message);
        $this->assertStringContainsString('12 of 12 rows', $message);
        $this->assertStringContainsString('1, 2, 3, 4, 5, 6, 7, 8, 9, 10, ...', $message);
        $this->assertStringNotContainsString('11', $message);
    }

    // ------------------------------------------------------------------
    // parseSoldDate()
    // ------------------------------------------------------------------

    public function test_parseSoldDate_validDate_returnsThatDateWithoutLog(): void
    {
        $parsed = CarBadges::parseSoldDate('2024-02-29', 501);

        $this->assertNotNull($parsed);
        $this->assertSame('2024-02-29 00:00:00', $parsed->format('Y-m-d H:i:s'));
        $this->assertSame([], self::logEntries());
    }

    #[DataProvider('emptySolddateProvider')]
    public function test_parseSoldDate_empty_returnsNullWithoutLog(mixed $solddate): void
    {
        $this->assertNull(CarBadges::parseSoldDate($solddate, 501));
        $this->assertSame([], self::logEntries());
    }

    #[DataProvider('badSolddateProvider')]
    public function test_parseSoldDate_badValue_returnsNullAndLogsOnce(mixed $solddate): void
    {
        $this->assertNull(CarBadges::parseSoldDate($solddate, 501));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ERRORS, self::logEntries()[0]['category']);
        $message = self::logEntries()[0]['message'];
        $this->assertIsString($message);
        $this->assertStringContainsString('car 501 has a bad solddate', $message);
    }

    #[DataProvider('badSolddateProvider')]
    public function test_parseSoldDate_badValueWithLogInvalidFalse_returnsNullWithoutLog(mixed $solddate): void
    {
        $this->assertNull(CarBadges::parseSoldDate($solddate, 501, false));
        $this->assertCount(0, self::logEntries());
    }

    public function test_parseSoldDate_validDateWithLogInvalidFalse_returnsThatDate(): void
    {
        $parsed = CarBadges::parseSoldDate('2024-02-29', 501, false);
        $this->assertNotNull($parsed);
        $this->assertSame('2024-02-29', $parsed->format('Y-m-d'));
    }

    public function test_parseSoldDate_withoutCarId_logsUnknownCarId(): void
    {
        $this->assertNull(CarBadges::parseSoldDate('0000-00-00'));
        $this->assertCount(1, self::logEntries());
        $message = self::logEntries()[0]['message'];
        $this->assertIsString($message);
        $this->assertStringContainsString('car unknown has a bad solddate', $message);
    }

    public function test_validSolddate_isSoldWithoutLog(): void
    {
        $this->assertSame(['sold'], CarBadges::forCar(self::car(['solddate' => '2024-02-29'])));
        $result = CarBadges::decorateRows([['id' => 1, 'solddate' => '2025-03-14', 'is_fresh' => 0]], []);
        $this->assertSame(['sold'], self::asArray($result[0])['badges']);

        $this->assertSame([], self::logEntries());
    }

    /** @return array<string, array{mixed}> */
    public static function emptySolddateProvider(): array
    {
        return [
            'null'         => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('emptySolddateProvider')]
    public function test_emptySolddate_isNotSoldWithoutLog(mixed $solddate): void
    {
        $this->assertSame([], CarBadges::forCar(self::car(['solddate' => $solddate])));
        $result = CarBadges::decorateRows([(object) ['id' => 1, 'solddate' => $solddate, 'is_fresh' => 0]], []);
        $this->assertSame([], self::asObject($result[0])->badges);

        $this->assertSame([], self::logEntries());
    }

    public function test_missingSolddate_isNotSoldWithoutLog(): void
    {
        $car = self::car();
        unset($car->solddate);

        $this->assertSame([], CarBadges::forCar($car));
        $this->assertSame([], self::logEntries());
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
