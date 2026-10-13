<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * CarVerificationManager::clearBouncedForEmailChange() against real SQL (#2101).
 *
 * Both email-change paths call this method: the confirmed change
 * (sync_owner_email_on_verify.php) and the direct change in user_settings.php.
 * UserSettingsWiringTest checks that both paths call it.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationManagerClearBouncedForEmailChangeTest extends IntegrationTestCase
{
    private CarVerificationManager $manager;

    /** @var list<int> Owners whose flag-change log rows tearDown deletes */
    private array $loggedOwnerIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->manager = new CarVerificationManager(new CarRepository($this->db));
        $this->loggedOwnerIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->loggedOwnerIds as $ownerId) {
                $this->db->query(
                    "DELETE FROM logs
                      WHERE logtype = ?
                        AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                        AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)",
                    [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, (string) $ownerId]
                );
            }
        }
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>> The flag-change metadata rows for the owner
     */
    private function flagLogMetadata(int $ownerId): array
    {
        $rows = $this->db->query(
            "SELECT CONVERT(metadata USING utf8mb4) AS metadata_json
               FROM logs
              WHERE logtype = ?
                AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)
              ORDER BY id",
            [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, (string) $ownerId]
        )->results();

        return array_map(
            static fn (object $r): array => json_decode((string) $r->metadata_json, true, 512, JSON_THROW_ON_ERROR),
            $rows
        );
    }

    private function createBouncedOwner(string $bouncedAddress): int
    {
        $ownerId = $this->createTestUser(['email' => 'new-address@example.com'], true);
        $this->loggedOwnerIds[] = $ownerId;
        $this->setOwnerBounced($ownerId, $bouncedAddress, '2026-02-03 04:05:06');

        return $ownerId;
    }

    public function testDirectEmailChangeClearsABounceOnTheOldAddressAndWritesOneAuditRow(): void
    {
        $ownerId = $this->createBouncedOwner('old-address@example.com');

        $cleared = $this->manager->clearBouncedForEmailChange($ownerId, $ownerId, 'new-address@example.com');

        $this->assertTrue($cleared);
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('0', (string) $flags['email_bounced']);
        $this->assertNull($flags['email_bounced_address']);
        $this->assertSame(
            [[
                'owner_id' => $ownerId,
                'flag' => DeliverabilityFlagLog::FLAG_BOUNCED,
                'old' => 1,
                'new' => 0,
                'source' => DeliverabilityFlagLog::SOURCE_EMAIL_CHANGE,
            ]],
            $this->flagLogMetadata($ownerId)
        );
        $this->assertFalse($this->db->inTransaction(), 'The method must commit its own transaction');
    }

    public function testABounceOnTheNewAddressStaysAndWritesNoAuditRow(): void
    {
        $ownerId = $this->createBouncedOwner('new-address@example.com');

        $cleared = $this->manager->clearBouncedForEmailChange($ownerId, $ownerId, 'New-Address@example.com');

        $this->assertFalse($cleared);
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($ownerId)['email_bounced']);
        $this->assertSame([], $this->flagLogMetadata($ownerId));
        $this->assertFalse($this->db->inTransaction(), 'The method must commit its own transaction');
    }
}
