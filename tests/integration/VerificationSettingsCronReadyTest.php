<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\VerificationSettings;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1974: VerificationSettings cronReady()/lastCronRequestAt()/
 * recordCronRequest() with a real `datetime` round trip.
 */
#[Group('integration')]
final class VerificationSettingsCronReadyTest extends IntegrationTestCase
{
    /** Original last_cron_request_at value, restored in tearDown() so this test doesn't leak state. */
    private ?string $originalValue = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->db->query('SELECT last_cron_request_at FROM er_verification_settings WHERE id = 1');
        $this->assertFalse(
            $this->db->error(),
            'Failed to read original last_cron_request_at value: ' . $this->db->errorString()
                . ' — likely means this migration has not been applied to the test schema'
        );
        $row = $this->db->first();
        $this->originalValue = (is_object($row) && !empty($row->last_cron_request_at))
            ? (string) $row->last_cron_request_at
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query(
                'UPDATE er_verification_settings SET last_cron_request_at = ? WHERE id = 1',
                [$this->originalValue]
            );
        }

        parent::tearDown();
    }

    private function setLastCronRequestAt(?string $datetime): void
    {
        $this->db->query(
            'UPDATE er_verification_settings SET last_cron_request_at = ? WHERE id = 1',
            [$datetime]
        );
        $this->assertFalse($this->db->error(), 'Failed to set last_cron_request_at fixture: ' . $this->db->errorString());
    }

    public function testLastCronRequestAtReturnsNullWhenColumnNeverSet(): void
    {
        $this->setLastCronRequestAt(null);

        $settings = new VerificationSettings($this->db);

        $this->assertNull($settings->lastCronRequestAt());
        $this->assertFalse($settings->cronReady());
    }

    public function testLastCronRequestAtRoundTripsARealTimestamp(): void
    {
        $recentTimestamp = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->setLastCronRequestAt($recentTimestamp);

        $settings = new VerificationSettings($this->db);

        $lastRequestAt = $settings->lastCronRequestAt();
        $this->assertNotNull($lastRequestAt, 'A recorded timestamp must be read back');
        $this->assertSame($recentTimestamp, $lastRequestAt->format('Y-m-d H:i:s'));
        $this->assertTrue($settings->cronReady());
    }

    public function testCronReadyFalseForAStaleTimestamp(): void
    {
        $staleTimestamp = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->setLastCronRequestAt($staleTimestamp);

        $settings = new VerificationSettings($this->db);

        $this->assertFalse($settings->cronReady());
    }

    /** recordCronRequest()'s UPDATE is valid SQL against the real schema. */
    public function testRecordCronRequestUpdatesTheRealColumn(): void
    {
        $this->setLastCronRequestAt(null);

        $settings = new VerificationSettings($this->db);
        $before = new DateTimeImmutable('now');

        $this->assertTrue($settings->recordCronRequest());

        $this->db->query('SELECT last_cron_request_at FROM er_verification_settings WHERE id = 1');
        $row = $this->db->first();
        $this->assertIsObject($row);
        $this->assertNotEmpty($row->last_cron_request_at);

        $recorded = new DateTimeImmutable((string) $row->last_cron_request_at);
        $this->assertGreaterThanOrEqual(
            $before->getTimestamp(),
            $recorded->getTimestamp(),
            'recordCronRequest() must write a timestamp at or after the moment it was called'
        );
    }
}
