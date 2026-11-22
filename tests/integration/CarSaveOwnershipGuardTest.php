<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The ownership guard in app/api/cars/save.php at the HTTP layer (#970, #2362).
 *
 * updateCar, fetchImages, and removeImages each refuse a user who is not the
 * car's owner and has no admin or editor permission (hasPerm([2, 3])).
 *
 * Each request sends a valid CSRF token, because the CSRF check also answers
 * 403. The car exists and has an owner, because the same `if` also refuses a
 * car that is not found. Thus only the ownership branch can give the 403.
 */
#[Group('integration')]
#[Group('security')]
final class CarSaveOwnershipGuardTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/cars/save.php';

    /** @var int|null Highest logs.id before the test, so tearDown() deletes only rows written after it */
    private ?int $logIdFloor = null;

    /** @var int[] Users this class created, whose new logs rows tearDown() deletes */
    private array $guardUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $max = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM logs')->first();
        $this->assertFalse($this->db->error(), 'Failed to read logs MAX(id) for test setup: ' . $this->db->errorString());
        $this->logIdFloor = (int) ($max->max_id ?? 0);
    }

    protected function tearDown(): void
    {
        // A null floor means setUp() did not take the snapshot. Deleting then
        // would remove rows that other runs wrote.
        if ($this->databaseConnected && $this->logIdFloor !== null) {
            foreach ($this->guardUserIds as $userId) {
                $this->db->query('DELETE FROM logs WHERE id > ? AND user_id = ?', [$this->logIdFloor, $userId]);
                if ($this->db->error()) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for logs of user ID {$userId}: {$this->db->errorString()}\n");
                }
            }
        }

        parent::tearDown();
    }

    private function createGuardUser(): int
    {
        $userId = $this->createTestUser();
        $this->grantTestPermission($userId, 1); // 1 = User: no admin or editor bypass
        $this->guardUserIds[] = $userId;

        return $userId;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function guardedActionProvider(): array
    {
        return [
            'updateCar'    => ['updateCar', 'car_id'],
            'fetchImages'  => ['fetchImages', 'carID'],
            'removeImages' => ['removeImages', 'carID'],
        ];
    }

    #[DataProvider('guardedActionProvider')]
    public function testNonOwnerIsRefusedWith403AndCarIsUnchanged(string $action, string $carIdField): void
    {
        $ownerId = $this->createGuardUser();
        $nonOwnerId = $this->createGuardUser();
        $carId = $this->createTestCar($ownerId, ['color' => 'Red', 'image' => '["owner-photo.jpg"]']);
        $deniedNote = "{$action}: unauthorized for car {$carId}";

        $result = $this->invokeEndpoint(self::ENDPOINT, [
            'action'    => $action,
            $carIdField => (string) $carId,
            'color'     => 'Blue',
            'file'      => 'owner-photo.jpg',
        ], $nonOwnerId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame('Unauthorized', $result['message'], 'The refusal must come from the ownership guard, not the CSRF check');
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame(
            1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, $deniedNote, $nonOwnerId),
            'Exactly one access-denied log row must name the action and car'
        );

        $row = $this->db->query('SELECT user_id, color, image FROM cars WHERE id = ?', [$carId])->first();
        $this->assertFalse($this->db->error(), 'Failed to read the car row: ' . $this->db->errorString());
        $this->assertNotNull($row, "Car {$carId} must still exist");
        $this->assertSame($ownerId, (int) $row->user_id);
        $this->assertSame('Red', $row->color);
        $this->assertSame('["owner-photo.jpg"]', $row->image);
    }

    public function testOwnerPassesTheOwnershipGuard(): void
    {
        $ownerId = $this->createGuardUser();
        $carId = $this->createTestCar($ownerId);

        $result = $this->invokeEndpoint(self::ENDPOINT, ['action' => 'fetchImages', 'carID' => (string) $carId], $ownerId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertTrue($result['status'], $result['raw']);
        $this->assertSame('Images retrieved successfully', $result['message']);
        $this->assertNoPhpErrorText($result['raw']);
        // The success row proves that logging works, so the zero count of
        // access-denied rows below means something.
        $this->assertSame(
            1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Images fetched for car: {$carId}", $ownerId),
            'The owner request must reach fetchImages() and log one success row'
        );
        $this->assertSame(
            0,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, "fetchImages: unauthorized for car {$carId}", $ownerId),
            'The owner must not get an access-denied log row'
        );
    }
}
