<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarBadges;
use ElanRegistry\Car\CarDataTablesService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live-DB tests for the is_fresh column that CarDataTablesService appends to
 * the cars SELECT, and for CarBadges::decorateRows() on that real result
 * (issue #1900).
 *
 * Four fixture cars have a known freshness. Each is found by a unique chassis
 * prefix, so other cars in the test database do not change the expected
 * counts. Fixture dates come from MySQL's clock, because freshnessSql()
 * compares against MySQL's NOW() (see CarFreshnessSqlLiveQueryTest).
 */
#[Group('integration')]
#[Group('car-badges')]
final class CarDataTablesIsFreshTest extends IntegrationTestCase
{
    private const MARKER = 'ZB1900';

    /** Public columns of the cars table, as the service lists them. */
    private const SORT_COLUMNS = [
        'id', 'ctime', 'mtime', 'model', 'series', 'variant', 'year', 'type',
        'chassis', 'color', 'engine', 'purchasedate', 'solddate', 'comments',
        'image', 'fname', 'join_date', 'city', 'state', 'country', 'website',
    ];

    private int $userId;

    /** @var array<string, int> Fixture car ids by name (A, B, C, D) */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->userId = $this->createTestUser();
        $this->loginAsTestUser($this->userId);

        $recent = $this->mysqlDatetime('NOW() - INTERVAL 30 DAY');
        $old    = $this->mysqlDatetime('NOW() - INTERVAL 5 YEAR');

        // A: fresh by owner_last_updated.
        $this->ids['A'] = $this->createTestCar($this->userId, [
            'chassis' => self::MARKER . 'A', 'year' => 1963, 'color' => 'Aqua',
            'last_verified' => null, 'owner_last_updated' => $recent,
        ]);
        // B: fresh by last_verified only.
        $this->ids['B'] = $this->createTestCar($this->userId, [
            'chassis' => self::MARKER . 'B', 'year' => 1964, 'color' => 'Blue',
            'last_verified' => $recent, 'owner_last_updated' => $old,
        ]);
        // C: stale, and sold.
        $this->ids['C'] = $this->createTestCar($this->userId, [
            'chassis' => self::MARKER . 'C', 'year' => 1965, 'color' => 'Cream',
            'last_verified' => $old, 'owner_last_updated' => $old,
            'solddate' => '2025-01-01',
        ]);
        // D: never verified, and stale.
        $this->ids['D'] = $this->createTestCar($this->userId, [
            'chassis' => self::MARKER . 'D', 'year' => 1966, 'color' => 'Dune',
            'last_verified' => null, 'owner_last_updated' => $old,
        ]);
    }

    /**
     * Build a DataTables request for the cars table, filtered to the fixtures.
     *
     * @return array<string, mixed>
     */
    private function request(string $orderColumn, string $dir = 'asc', string $search = self::MARKER): array
    {
        $columns = [];
        foreach (self::SORT_COLUMNS as $name) {
            $columns[] = ['data' => $name, 'searchable' => $name === 'chassis' ? 'true' : 'false', 'orderable' => 'true'];
        }

        return [
            'draw'    => 3,
            'start'   => 0,
            'length'  => 100,
            'search'  => ['value' => $search],
            'order'   => [['column' => (int) array_search($orderColumn, self::SORT_COLUMNS, true), 'dir' => $dir]],
            'columns' => $columns,
        ];
    }

    /**
     * Run the real service and return the response.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function fetch(array $request): array
    {
        $response = (new CarDataTablesService($this->db))->getDataTablesData($request, 'cars');
        $this->assertFalse($this->db->error(), 'The cars query must run without a SQL error: ' . $this->db->errorString());

        return $response;
    }

    /**
     * Map chassis letter to the is_fresh value of each fixture row in a response.
     *
     * @param array<string, mixed> $response
     * @return array<string, int>
     */
    private function freshByLetter(array $response): array
    {
        $this->assertIsArray($response['data']);
        $this->assertNotEmpty($response['data'], 'The fixture search must return rows');

        $map = [];
        foreach ($response['data'] as $row) {
            $this->assertIsObject($row);
            $this->assertObjectHasProperty('is_fresh', $row);
            $this->assertContains($row->is_fresh, [0, 1, '0', '1'], 'is_fresh must be a plain 0 or 1');
            $map[substr((string) $row->chassis, strlen(self::MARKER))] = (int) $row->is_fresh;
        }
        ksort($map);

        return $map;
    }

    private const EXPECTED_FRESH = ['A' => 1, 'B' => 1, 'C' => 0, 'D' => 0];

    // ------------------------------------------------------------------

    #[Group('fast')]
    public function testEachFixtureCarHasItsLiteralIsFreshValue(): void
    {
        $response = $this->fetch($this->request('id'));

        $this->assertSame(self::EXPECTED_FRESH, $this->freshByLetter($response));
        $this->assertSame(4, (int) $response['recordsFiltered']);
        $this->assertSame(3, $response['draw']);
    }

    #[Group('fast')]
    public function testSortingOnEveryColumnKeepsFilterCountAndIsFreshValues(): void
    {
        foreach (self::SORT_COLUMNS as $column) {
            foreach (['asc', 'desc'] as $dir) {
                $response = $this->fetch($this->request($column, $dir));

                $this->assertSame(
                    self::EXPECTED_FRESH,
                    $this->freshByLetter($response),
                    "is_fresh per row must not change when sorting by {$column} {$dir}"
                );
                $this->assertSame(4, (int) $response['recordsFiltered'], "recordsFiltered when sorting by {$column} {$dir}");
                $this->assertGreaterThanOrEqual(4, (int) $response['recordsTotal']);
            }
        }
    }

    #[Group('fast')]
    public function testSortingOnYearAndChassisOrdersTheFixtureRows(): void
    {
        $letters = static fn(array $response): array => array_map(
            static fn($row): string => substr((string) $row->chassis, strlen(self::MARKER)),
            $response['data']
        );

        $this->assertSame(['A', 'B', 'C', 'D'], $letters($this->fetch($this->request('year', 'asc'))));
        $this->assertSame(['D', 'C', 'B', 'A'], $letters($this->fetch($this->request('year', 'desc'))));
        $this->assertSame(['A', 'B', 'C', 'D'], $letters($this->fetch($this->request('chassis', 'asc'))));
        $this->assertSame(['D', 'C', 'B', 'A'], $letters($this->fetch($this->request('chassis', 'desc'))));
    }

    #[Group('fast')]
    public function testSearchNarrowsRecordsFilteredAndKeepsIsFresh(): void
    {
        $response = $this->fetch($this->request('id', 'asc', self::MARKER . 'B'));

        $this->assertSame(['B' => 1], $this->freshByLetter($response));
        $this->assertSame(1, (int) $response['recordsFiltered']);

        $none = $this->fetch($this->request('id', 'asc', self::MARKER . 'NOPE'));
        $this->assertSame([], $none['data']);
        $this->assertSame(0, (int) $none['recordsFiltered']);
    }

    #[Group('fast')]
    public function testIsFreshColumnCannotBeUsedToSortOrSearch(): void
    {
        $request = $this->request('id');
        $request['columns'][] = ['data' => 'is_fresh', 'searchable' => 'true', 'orderable' => 'true'];
        $request['order'] = [['column' => count($request['columns']) - 1, 'dir' => 'desc']];

        $response = $this->fetch($request);

        // The request is accepted, and is_fresh adds no ORDER BY or LIKE term, so the
        // fixture search still returns all four rows in the default id order.
        $this->assertSame(self::EXPECTED_FRESH, $this->freshByLetter($response));
        $ids = array_map(static fn($row): int => (int) $row->id, $response['data']);
        $this->assertSame(array_values($this->ids), $ids);
    }

    #[Group('fast')]
    public function testIsFreshValuesAreUnchangedUnderEmptySqlMode(): void
    {
        $previous = (string) $this->db->query('SELECT @@SESSION.sql_mode AS m')->first()->m;
        $this->db->query("SET SESSION sql_mode = ''");
        try {
            $response = $this->fetch($this->request('year', 'desc'));
            $this->assertSame(self::EXPECTED_FRESH, $this->freshByLetter($response));
            $this->assertSame(4, (int) $response['recordsFiltered']);
        } finally {
            $this->db->query("SET SESSION sql_mode = '" . addslashes($previous) . "'");
        }
    }

    #[Group('fast')]
    public function testDecorateRowsOnRealResultRemovesIsFreshAndSetsBadges(): void
    {
        $response = $this->fetch($this->request('id'));
        $this->assertIsArray($response['data']);
        $this->assertCount(4, $response['data']);

        // Car A is in the NEW set: NEW replaces Verified. B is fresh. C is sold. D has none.
        $decorated = CarBadges::decorateRows($response['data'], [$this->ids['A']]);

        $this->assertCount(4, $decorated);
        $badges = [];
        foreach ($decorated as $row) {
            $this->assertIsObject($row);
            $this->assertObjectNotHasProperty('is_fresh', $row);
            $this->assertObjectHasProperty('badges', $row);
            $this->assertIsArray($row->badges);
            $this->assertTrue(array_is_list($row->badges));
            $badges[substr((string) $row->chassis, strlen(self::MARKER))] = $row->badges;
        }
        ksort($badges);

        $this->assertSame(
            ['A' => ['new'], 'B' => ['verified'], 'C' => ['sold'], 'D' => []],
            $badges
        );
        $this->assertStringNotContainsString('is_fresh', (string) json_encode($decorated));
    }
}
