<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * app/api/cars/save.php stores car comments as the plain text that the owner
 * sent, with no HTML entities (#838, #2738).
 *
 * #838: updateComments() read \Input::get('comments'), which encodes on read,
 * so the database held encoded text and the page encoded it again. The
 * Playwright spec mocks save.php and checks only the POST body. This test runs
 * the real endpoint and reads the stored row.
 */
#[Group('integration')]
#[Group('car')]
final class CarSaveCommentsEncodingTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/cars/save.php';

    /**
     * \Input::get() changes & ' " < > into entities. The non-ASCII characters
     * check that UTF-8 text stays unchanged.
     */
    private const COMMENTS = "it´s — é & ñ 'single' \"double\" <b>bold</b> > end";

    /** @var int|null Owner this test created. Every logs row with this user_id is the test's own, so tearDown() needs no id floor */
    private ?int $ownerId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected && $this->ownerId !== null) {
            $this->db->query('DELETE FROM logs WHERE user_id = ?', [$this->ownerId]);
            if ($this->db->error()) {
                fwrite(STDERR, "NOTE: tearDown() cleanup failed for logs of user ID {$this->ownerId}: {$this->db->errorString()}\n");
            }
        }

        parent::tearDown();
    }

    public function testUpdateCarStoresCommentsWithSpecialCharactersAsPlainText(): void
    {
        $this->ownerId = $this->createTestUser();
        $this->grantTestPermission($this->ownerId, 1); // 1 = User: the owner branch of the guard, not the admin bypass

        // CarValidator accepts only a model that has a car_models row.
        // ChassisValidator accepts a 4-digit chassis for a production car
        // before 1970 with no override. An override would append an audit
        // note to the comments. No check refuses a duplicate chassis.
        $year = 1966;
        $model = 'S3|FHC|36';
        $chassis = '1234';
        $carId = $this->createTestCar($this->ownerId, [
            'year'    => $year,
            'model'   => $model,
            'series'  => 'S3',
            'variant' => 'FHC',
            'type'    => '36',
            'chassis' => $chassis,
        ]);

        $result = $this->invokeEndpoint(self::ENDPOINT, [
            'action'   => 'updateCar',
            'car_id'   => (string) $carId,
            'year'     => (string) $year,
            'model'    => $model,
            'chassis'  => $chassis,
            'color'    => 'Red',
            'comments' => self::COMMENTS,
        ], $this->ownerId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertTrue($result['status'], $result['raw']);
        $this->assertSame('Car updated successfully', $result['message']);
        $this->assertNoPhpErrorText($result['raw']);

        $row = $this->db->query('SELECT comments FROM cars WHERE id = ?', [$carId])->first();
        $this->assertFalse($this->db->error(), 'Failed to read the car row: ' . $this->db->errorString());
        $this->assertIsObject($row, "Car {$carId} must still exist");
        $this->assertSame(self::COMMENTS, $row->comments, 'cars.comments must hold the plain text, not HTML entities');
    }
}
