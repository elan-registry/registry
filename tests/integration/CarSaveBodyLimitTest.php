<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * The request body checks at the top of app/api/cars/save.php (#2647).
 *
 * A body above post_max_size reaches the script with $_POST and $_FILES empty.
 * Only the declared CONTENT_LENGTH tells it apart from a POST with no data.
 * Both checks run before the login check, so these requests are logged out.
 */
#[Group('integration')]
#[Group('security')]
final class CarSaveBodyLimitTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/cars/save.php';

    private const TOO_LARGE_PREFIX = 'The photos are too large to save together.';

    /** @var int|null Highest logs.id before the test, so tearDown() deletes only rows written after it */
    private ?int $logIdFloor = null;

    /** @var string|null Exact note of the 413 log row, so tearDown() deletes only that row */
    private ?string $tooLargeNote = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $max = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM logs')->first();
        $this->assertFalse($this->db->error(), 'Failed to read logs MAX(id) for test setup: ' . $this->db->errorString());
        $this->assertIsObject($max, 'logs MAX(id) query returned no row');
        $this->logIdFloor = (int) $max->max_id;
    }

    protected function tearDown(): void
    {
        // The endpoint writes the 413 row with user_id 0, so the exact note is
        // the only way to find this test's row.
        if ($this->databaseConnected && $this->logIdFloor !== null && $this->tooLargeNote !== null) {
            $this->db->query(
                'DELETE FROM logs WHERE id > ? AND user_id = 0 AND lognote = ?',
                [$this->logIdFloor, $this->tooLargeNote]
            );
            if ($this->db->error()) {
                fwrite(STDERR, "NOTE: tearDown() cleanup failed for the 413 logs row: {$this->db->errorString()}\n");
            }
        }

        parent::tearDown();
    }

    public function testBodyAbovePostMaxSizeIsRefusedWith413(): void
    {
        // The subprocess runs the same php binary with no -d post_max_size flag,
        // so it reads the same limit as this process.
        $postMaxSize = (string) ini_get('post_max_size');
        $limit = ini_parse_quantity($postMaxSize);
        $this->assertGreaterThan(
            0,
            $limit,
            "CLI post_max_size is '{$postMaxSize}'. save.php skips the 413 check when the limit is 0 or less. Set a positive post_max_size for the CLI."
        );
        $contentLength = $limit + 1;
        $this->tooLargeNote = "save.php: body of {$contentLength} bytes exceeds post_max_size {$postMaxSize}";

        $result = $this->invokeEndpoint(self::ENDPOINT, [], null, 'omit', [], 'POST', ['CONTENT_LENGTH' => (string) $contentLength]);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(413, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertStringStartsWith(self::TOO_LARGE_PREFIX, $result['message'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
        // Count only rows above the floor: a row left by an earlier run has
        // the same note, because the limit and the body size do not change.
        $count = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE id > ? AND user_id = 0 AND logtype = ? AND lognote = ?',
            [$this->logIdFloor, LogCategories::LOG_CATEGORY_FILE_ERROR, $this->tooLargeNote]
        );
        $this->assertFalse($this->db->error(), 'Failed to count the file-error log rows: ' . $this->db->errorString());
        $this->assertSame(1, (int) $count->first()->cnt, 'The refusal must write one file-error log row with the body size');
    }

    public function testEmptyPostIsRefusedWith400(): void
    {
        $result = $this->invokeEndpoint(self::ENDPOINT, [], null, 'omit');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(400, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame('No data received', $result['message'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
    }
}
