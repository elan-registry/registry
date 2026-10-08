<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use PHPUnit\Framework\Attributes\Group;

/**
 * CarDataTablesService embeds car_id in elan_factory_info rows with a
 * correlated subquery, so the factory page needs no separate lookups.
 */
#[Group('integration')]
#[Group('factories')]
final class FactoryRegistryLinkIntegrationTest extends IntegrationTestCase
{
    private int $testUserId;
    private int $testCarId;
    private string $testChassis;
    private int $testFactoryRowId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // varchar(5); non-numeric, so it cannot collide with real (numeric) serials.
        $this->testChassis = 'X' . substr(uniqid(), -4);

        $this->testUserId = $this->createTestUser();
        $this->testCarId = $this->createTestCar($this->testUserId, [
            'chassis' => $this->testChassis,
        ]);

        $inserted = $this->db->insert('elan_factory_info', [
            'year'         => '1973',
            'month'        => '01',
            'batch'        => '001',
            'type'         => '',
            'serial'       => $this->testChassis,
            'suffix'       => '',
            'engineletter' => '',
            'enginenumber' => '',
            'gearbox'      => '',
            'color'        => '',
            'builddate'    => '1973-01-01',
            'note'         => '',
        ]);

        if (!$inserted) {
            $this->markTestSkipped('Could not insert test factory row');
        }

        $this->testFactoryRowId = (int) $this->db->lastId();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected && $this->testFactoryRowId > 0) {
            $this->db->delete('elan_factory_info', ['id', '=', $this->testFactoryRowId]);
        }

        parent::tearDown();
    }

    public function testFactoryRowContainsCarIdWhenChassisMatches(): void
    {
        $car = new Car();

        $request = [
            'draw'    => 1,
            'start'   => 0,
            'length'  => 5,
            'search'  => ['value' => ''],
            'order'   => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                [
                    'data'        => 'id',
                    'searchable'  => 'true',
                    'orderable'   => 'true',
                    'search'      => ['value' => (string) $this->testFactoryRowId],
                ],
                ['data' => 'serial', 'searchable' => 'false', 'orderable' => 'true'],
            ],
        ];

        $result = $car->getDataTablesData($request, 'factory');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);

        $matchingRow = null;
        foreach ($result['data'] as $row) {
            if ((int) $row->id === $this->testFactoryRowId) {
                $matchingRow = $row;
                break;
            }
        }

        $this->assertNotNull($matchingRow, 'Factory row with matching id must appear in results');
        $this->assertEquals($this->testCarId, (int) $matchingRow->car_id);
    }

    public function testFactoryRowCarIdIsNullWhenNoChassisMatch(): void
    {
        // A Y-prefix serial cannot match any real car chassis.
        $unmatchedSerial = 'Y' . substr(uniqid(), -4);

        $inserted = $this->db->insert('elan_factory_info', [
            'year'         => '1965',
            'month'        => '01',
            'batch'        => '001',
            'type'         => '',
            'serial'       => $unmatchedSerial,
            'suffix'       => '',
            'engineletter' => '',
            'enginenumber' => '',
            'gearbox'      => '',
            'color'        => '',
            'builddate'    => '1965-01-01',
            'note'         => '',
        ]);

        if (!$inserted) {
            $this->markTestSkipped('Could not insert unmatched test factory row');
        }

        $unmatchedRowId = (int) $this->db->lastId();

        try {
            $car = new Car();

            $request = [
                'draw'    => 1,
                'start'   => 0,
                'length'  => 5,
                'search'  => ['value' => ''],
                'order'   => [['column' => 0, 'dir' => 'asc']],
                'columns' => [
                    [
                        'data'        => 'id',
                        'searchable'  => 'true',
                        'orderable'   => 'true',
                        'search'      => ['value' => (string) $unmatchedRowId],
                    ],
                    ['data' => 'serial', 'searchable' => 'false', 'orderable' => 'true'],
                ],
            ];

            $result = $car->getDataTablesData($request, 'factory');

            $this->assertIsArray($result);
            $this->assertArrayHasKey('data', $result);

            $matchingRow = null;
            foreach ($result['data'] as $row) {
                if ((int) $row->id === $unmatchedRowId) {
                    $matchingRow = $row;
                    break;
                }
            }

            $this->assertNotNull($matchingRow, 'Factory row with unmatched serial must appear in results');
            $this->assertNull($matchingRow->car_id);
        } finally {
            $this->db->delete('elan_factory_info', ['id', '=', $unmatchedRowId]);
        }
    }

    public function testFactoryDataTablesResponseIncludesCarIdField(): void
    {
        $car = new Car();

        $request = [
            'draw'    => 1,
            'start'   => 0,
            'length'  => 25,
            'search'  => ['value' => ''],
            'order'   => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'id',   'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'year', 'searchable' => 'true', 'orderable' => 'true'],
            ],
        ];

        $result = $car->getDataTablesData($request, 'factory');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertNotEmpty($result['data'], 'Factory data must be non-empty — setUp inserted at least one row');

        foreach ($result['data'] as $row) {
            $this->assertTrue(
                property_exists($row, 'car_id'),
                'Every factory row must have a car_id property'
            );
        }
    }
}
