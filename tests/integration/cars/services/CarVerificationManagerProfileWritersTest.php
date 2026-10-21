<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\ClearBlockOutcome;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Email\BrevoUnblockResult;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeBrevoBlocklistClient;
use Tests\Support\RecordingPassThroughDatabase;

/**
 * #2101: the owner-level flag writers write one profile row, log the change and
 * never touch a car. The delivery-event path (EmailEventApplier) sets the block
 * and bounce fields and honours the Clear Bounced time. All checks run against
 * the real database.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationManagerProfileWritersTest extends IntegrationTestCase
{
    private const OLD_MTIME = '2020-01-01 00:00:00';
    private const ADMIN_ID = 777001;

    private CarRepository $repo;
    private CarVerificationManager $manager;
    private EmailEventApplier $applier;

    /** @var list<int> Owners whose flag-change log rows tearDown deletes */
    private array $loggedOwnerIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
        $this->manager = new CarVerificationManager($this->repo);
        $this->applier = new EmailEventApplier($this->repo, $this->manager);
        $this->loggedOwnerIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Clear Block logs a successful Brevo unblock under the fake admin ID.
            $this->db->query(
                'DELETE FROM logs WHERE logtype = ? AND user_id = ? AND lognote LIKE ?',
                [LogCategories::LOG_CATEGORY_CAR_VERIFICATION, self::ADMIN_ID, 'Verification: Clear Block Brevo unblock done for owner %']
            );
            // The base class deletes the er_email_events rows of the test users (#2358).
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
     * @return array{owner: int, cars: list<int>}
     */
    /**
     * Apply a webhook-style event, matched to the owner, with the event time as the message time
     *
     * @return bool True when the call wrote a new event row
     */
    private function applyEvent(
        int $ownerId,
        string $address,
        string $event,
        ?string $reason,
        string $messageId,
        string $occurredAt,
        string $source
    ): bool {
        return $this->applier->apply(
            EmailEventMatch::matched($ownerId, null, $occurredAt),
            $address,
            $event,
            $reason,
            $messageId,
            $occurredAt,
            $source,
            EmailEventApplier::MODE_EVENT
        )->eventRowWritten;
    }

    private function createOwnerWithTwoCars(string $emailA, string $emailB): array
    {
        $ownerId = $this->createTestUser(['email' => $emailA], true);
        $this->loggedOwnerIds[] = $ownerId;
        // A soft bounce before email_address_since does not count (#2358), and the
        // events in these tests are in the past. A new profile has since = now.
        $this->db->query('UPDATE profiles SET email_address_since = ? WHERE user_id = ?', ['2020-01-01 00:00:00', $ownerId]);
        // The event step skips a message sent before join_date (#2359), so move it back too.
        $this->db->query('UPDATE users SET join_date = ? WHERE id = ?', ['2020-01-01 00:00:00', $ownerId]);
        $cars = [];
        foreach ([$emailA, $emailB] as $email) {
            $carId = $this->createTestCar($ownerId, [
                'email' => $email,
                'mtime' => self::OLD_MTIME,
                'last_verified' => null,
                'owner_last_updated' => '2020-01-01 00:00:00',
            ]);
            $cars[] = $carId;
        }

        return ['owner' => $ownerId, 'cars' => $cars];
    }

    /**
     * @param list<int> $carIds
     * @return array{cars: list<array<string, mixed>>, hist: int}
     */
    private function carSnapshot(array $carIds): array
    {
        $this->assertNotEmpty($carIds, 'Test setup: no cars to snapshot');
        $in = implode(',', array_fill(0, count($carIds), '?'));
        $rows = $this->db->query("SELECT * FROM cars WHERE id IN ({$in}) ORDER BY id", $carIds)->results();
        $this->assertCount(count($carIds), $rows);
        $hist = (int) $this->db->query("SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id IN ({$in})", $carIds)->first()->cnt;

        return ['cars' => array_map(static fn (object $r): array => (array) $r, $rows), 'hist' => $hist];
    }

    /**
     * @return list<array{user_id: int, lognote: string, metadata: array<string, mixed>}>
     */
    private function flagLogRows(int $ownerId): array
    {
        $rows = $this->db->query(
            "SELECT id, user_id, lognote, CONVERT(metadata USING utf8mb4) AS metadata_json
               FROM logs
              WHERE logtype = ?
                AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)
              ORDER BY id",
            [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, (string) $ownerId]
        )->results();

        return array_map(static fn (object $r): array => [
            'user_id' => (int) $r->user_id,
            'lognote' => (string) $r->lognote,
            'metadata' => json_decode((string) $r->metadata_json, true, 512, JSON_THROW_ON_ERROR),
        ], $rows);
    }

    private function profileRowCount(int $ownerId): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$ownerId])->first()->cnt;
    }

    private function futureOrPast(string $modifier): string
    {
        return date('Y-m-d H:i:s', strtotime($modifier));
    }

    // ---- one profile row, zero car rows -------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function writerProvider(): array
    {
        return [
            'owner opt-out'          => ['optOut'],
            'Mark Bounced'           => ['markBounced'],
            'Clear Bounced'          => ['clearBounced'],
            'Clear Block'            => ['clearBlock'],
            'Clear Opt-out'          => ['clearOptOut'],
            'Resume'                 => ['resume'],
        ];
    }

    /**
     * Write profile columns directly, as another request or an import would.
     *
     * @param array<string, mixed> $columns Column name to new value
     */
    private function setProfileColumns(int $ownerId, array $columns): void
    {
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($columns)));
        $result = $this->db->query("UPDATE profiles SET {$set} WHERE user_id = ?", [...array_values($columns), $ownerId]);
        $this->assertFalse($result->error(), 'Test setup: profile update failed: ' . $result->errorString());
    }

    private function brevoClient(?\Closure $onUnblock = null, ?BrevoUnblockResult $result = null): FakeBrevoBlocklistClient
    {
        return new FakeBrevoBlocklistClient($result ?? BrevoUnblockResult::removed(), $onUnblock);
    }

    private function runWriter(string $writer, int $ownerId): bool
    {
        return match ($writer) {
            'optOut'           => $this->manager->setSuppressedForOwner($ownerId),
            'markBounced'      => $this->manager->setBouncedForOwner($ownerId, 'writer-a@example.com', self::ADMIN_ID),
            'clearBounced'     => $this->manager->clearBouncedForOwner($ownerId, self::ADMIN_ID),
            'clearBlock'       => $this->manager->clearBlockedForOwner($ownerId, self::ADMIN_ID, $this->brevoClient())->isCleared(),
            'clearOptOut'      => $this->manager->clearOptedOutForOwner($ownerId, self::ADMIN_ID),
            'resume'           => $this->manager->clearSuppressedForOwnerByOwner($ownerId),
            default            => $this->fail("Test setup: unknown writer {$writer}"),
        };
    }

    /**
     * Put the owner in a state in which the writer has a change to make.
     */
    private function prepareForWriter(string $writer, int $ownerId): void
    {
        switch ($writer) {
            case 'clearBounced':
                $this->setOwnerBounced($ownerId, 'writer-a@example.com');
                break;
            case 'clearBlock':
                $this->setOwnerBlocked($ownerId, 'writer-a@example.com');
                break;
            case 'clearOptOut':
                $this->setOwnerOptedOut($ownerId);
                break;
            case 'resume':
                $this->setOwnerOptedOut($ownerId);
                break;
            default:
                break;
        }
    }

    #[DataProvider('writerProvider')]
    public function testWriterChangesOnlyTheOwnersProfileRowAndNoCar(string $writer): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');
        $other = $this->createOwnerWithTwoCars('other-a@example.com', 'other-b@example.com');
        $this->prepareForWriter($writer, $fixture['owner']);
        $profileBefore = $this->getOwnerEmailFlags($fixture['owner']);
        $otherProfileBefore = $this->getOwnerEmailFlags($other['owner']);
        $carsBefore = $this->carSnapshot(array_merge($fixture['cars'], $other['cars']));

        $changed = $this->runWriter($writer, $fixture['owner']);

        $this->assertTrue($changed, 'Test setup: the writer must have a change to make');
        $this->assertNotSame($profileBefore, $this->getOwnerEmailFlags($fixture['owner']), 'The owner profile row must change');
        $this->assertSame($otherProfileBefore, $this->getOwnerEmailFlags($other['owner']), 'Another owner profile must not change');
        $this->assertSame(1, $this->profileRowCount($fixture['owner']), 'The writer must not add a profile row');
        $this->assertSame(
            $carsBefore,
            $this->carSnapshot(array_merge($fixture['cars'], $other['cars'])),
            'No car row, no mtime and no cars_hist row may change (no fan-out)'
        );
    }

    #[DataProvider('writerProvider')]
    public function testWriterLogsOneAuditRowPerChangedFlagWithNoAddress(string $writer): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');
        $this->prepareForWriter($writer, $fixture['owner']);
        $this->assertSame([], $this->flagLogRows($fixture['owner']), 'Test setup: no log rows before the writer');

        $this->runWriter($writer, $fixture['owner']);

        $expected = [
            'optOut'           => [[$fixture['owner'], 'email_opted_out', 0, 1, 'owner']],
            'markBounced'      => [[self::ADMIN_ID, 'email_bounced', 0, 1, 'admin']],
            'clearBounced'     => [[self::ADMIN_ID, 'email_bounced', 1, 0, 'admin']],
            'clearBlock'       => [[self::ADMIN_ID, 'email_blocked', 1, 0, 'admin']],
            'clearOptOut'      => [[self::ADMIN_ID, 'email_opted_out', 1, 0, 'admin']],
            'resume'           => [[$fixture['owner'], 'email_opted_out', 1, 0, 'owner']],
        ][$writer];

        $rows = $this->flagLogRows($fixture['owner']);
        $this->assertCount(count($expected), $rows);
        foreach ($expected as $i => [$actingUser, $flag, $old, $new, $source]) {
            $this->assertSame($actingUser, $rows[$i]['user_id'], 'logs.user_id is the acting user');
            $metadata = $rows[$i]['metadata'];
            if (in_array($writer, ['clearBlock', 'clearOptOut'], true)) {
                // #2357: the two admin clears also record the owner's request, and Clear Block its time.
                $this->assertSame(1, $metadata['owner_requested']);
                $this->assertSame($writer === 'clearBlock', array_key_exists('cleared_at', $metadata));
                unset($metadata['owner_requested'], $metadata['cleared_at']);
            }
            $this->assertSame(
                ['owner_id' => $fixture['owner'], 'flag' => $flag, 'old' => $old, 'new' => $new, 'source' => $source],
                $metadata,
                'Metadata holds exactly these keys, with owner_id as an integer'
            );
            $this->assertStringContainsString($flag, $rows[$i]['lognote']);
            $this->assertStringNotContainsString('@', $rows[$i]['lognote'], 'The lognote has no address');
            $this->assertStringNotContainsString('@', json_encode($rows[$i]['metadata'], JSON_THROW_ON_ERROR));
        }
    }

    public function testRepeatWriterCallLogsNothingAndChangesNothing(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');
        $this->assertTrue($this->manager->setSuppressedForOwner($fixture['owner']));
        $flagsAfterFirst = $this->getOwnerEmailFlags($fixture['owner']);

        $this->assertFalse($this->manager->setSuppressedForOwner($fixture['owner']), 'A repeat opt-out is not a change');

        $this->assertSame($flagsAfterFirst, $this->getOwnerEmailFlags($fixture['owner']), 'The first opted_out_at stays');
        $this->assertCount(1, $this->flagLogRows($fixture['owner']), 'A repeat writes no second audit row');
    }

    public function testWriterOnAnOwnerWithNoProfileThrowsAndWritesNothing(): void
    {
        $ownerId = $this->createTestUser();
        $this->loggedOwnerIds[] = $ownerId;

        try {
            $this->manager->setBouncedForOwner($ownerId, 'noprofile@example.com', self::ADMIN_ID);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertNotSame('', $e->getUserMessage());
        }

        $this->assertSame(0, $this->profileRowCount($ownerId), 'The writer must not create a profile');
        $this->assertSame([], $this->flagLogRows($ownerId));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ownerKeyedEventSetterProvider(): array
    {
        return [
            'setBouncedFromEvent'    => ['setBouncedFromEvent'],
            'setSuppressedFromEvent' => ['setSuppressedFromEvent'],
        ];
    }

    #[DataProvider('ownerKeyedEventSetterProvider')]
    public function testOwnerKeyedEventSetterWithNoProfileRowReturnsFalseLogsAndWritesNothing(string $method): void
    {
        $ownerId = $this->createTestUser();
        $this->loggedOwnerIds[] = $ownerId;
        $logsBefore = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
            "CarVerificationManager::{$method}: event for owner {$ownerId} not applied to a profile%"
        );

        $changed = $method === 'setBouncedFromEvent'
            ? $this->manager->setBouncedFromEvent($ownerId, 'noprofile@example.com', '2026-04-04 04:04:04', 'webhook')
            : $this->manager->setSuppressedFromEvent($ownerId, 'noprofile@example.com', 'spam', '2026-04-04 04:04:04', 'webhook');

        $this->assertFalse($changed);
        $this->assertSame(0, $this->profileRowCount($ownerId), 'The setter must not create a profile');
        $this->assertSame([], $this->flagLogRows($ownerId), 'No flag-change row');
        $this->assertSame(
            $logsBefore + 1,
            $this->countMatchingLogs(
                LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
                "CarVerificationManager::{$method}: event for owner {$ownerId} not applied to a profile%"
            ),
            'The skip is logged once'
        );
        $this->db->query('DELETE FROM logs WHERE lognote LIKE ?', ["CarVerificationManager::{$method}: event for owner {$ownerId} %"]);
    }

    public function testOwnerKeyedSettersWriteTheOwnerProfileWithoutACar(): void
    {
        $fixture = $this->createOwnerWithTwoCars('keyed-a@example.com', 'keyed-b@example.com');
        $carsBefore = $this->carSnapshot($fixture['cars']);

        $this->assertTrue($this->manager->setBouncedFromEvent($fixture['owner'], 'keyed-a@example.com', '2026-04-04 04:04:04', 'webhook'));
        $this->assertTrue($this->manager->setSuppressedFromEvent($fixture['owner'], 'keyed-a@example.com', 'spam', '2026-04-05 04:04:04', 'import'));

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('1', (string) $flags['email_bounced']);
        $this->assertSame('1', (string) $flags['email_blocked']);
        $this->assertSame($carsBefore, $this->carSnapshot($fixture['cars']), 'The owner-keyed setters write no car row');
    }

    public function testMarkBouncedWithAnEmptyAddressThrowsValidationException(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');

        $this->expectException(CarValidationException::class);
        $this->manager->setBouncedForOwner($fixture['owner'], '   ', self::ADMIN_ID);
    }

    public function testSetProfileBlockedWithAnUnknownCauseThrowsValidationException(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');

        $this->expectException(CarValidationException::class);
        $this->repo->setProfileBlocked($fixture['owner'], 'writer-a@example.com', 'weird-cause', '2026-04-04 04:04:04');
    }

    public function testSetProfileBouncedWithAnInvalidTimeThrowsValidationException(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');

        $this->expectException(CarValidationException::class);
        $this->repo->setProfileBounced($fixture['owner'], 'writer-a@example.com', 'last tuesday');
    }

    // ---- Mark Bounced and Clear Bounced details -------------------------------------------------

    public function testMarkBouncedRepeatOnTheSameAddressKeepsTheFirstTime(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');
        $this->setOwnerBounced($fixture['owner'], 'writer-a@example.com', '2026-01-02 03:04:05');

        $changed = $this->manager->setBouncedForOwner($fixture['owner'], 'Writer-A@Example.com ', self::ADMIN_ID);

        $this->assertFalse($changed, 'The same address under the matching rule is not a change');
        $this->assertSame('2026-01-02 03:04:05', $this->getOwnerEmailFlags($fixture['owner'])['email_bounced_at']);
        $this->assertSame([], $this->flagLogRows($fixture['owner']));
    }

    public function testMarkBouncedStoresALowerCaseAddress(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');

        $this->manager->setBouncedForOwner($fixture['owner'], ' Writer-A@Example.COM ', self::ADMIN_ID);

        $this->assertSame('writer-a@example.com', $this->getOwnerEmailFlags($fixture['owner'])['email_bounced_address']);
    }

    public function testClearBouncedSetsTheClearedTimeAndClearsAddressAndTime(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');
        $this->setOwnerBounced($fixture['owner'], 'writer-a@example.com');
        $this->assertNull($this->getOwnerEmailFlags($fixture['owner'])['email_bounce_cleared_at'], 'Test setup');

        $this->manager->clearBouncedForOwner($fixture['owner'], self::ADMIN_ID);

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('0', (string) $flags['email_bounced']);
        $this->assertNull($flags['email_bounced_address']);
        $this->assertNull($flags['email_bounced_at']);
        $this->assertNotNull($flags['email_bounce_cleared_at']);
        $this->assertEqualsWithDelta(time(), strtotime((string) $flags['email_bounce_cleared_at']), 5);
    }

    public function testClearOptedOutClearsOnlyTheOptOutAndKeepsBlockAndBounce(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');
        $this->setOwnerBlocked($fixture['owner'], 'writer-a@example.com', '2026-02-03 04:05:06', 'unsubscribed');
        $this->setOwnerOptedOut($fixture['owner']);
        $this->setOwnerBounced($fixture['owner'], 'writer-a@example.com', '2026-02-03 04:05:07');

        $this->assertTrue($this->manager->clearOptedOutForOwner($fixture['owner'], self::ADMIN_ID));

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('0', (string) $flags['email_opted_out']);
        $this->assertNull($flags['email_opted_out_at']);
        $this->assertSame('1', (string) $flags['email_blocked']);
        $this->assertSame('writer-a@example.com', $flags['email_blocked_address']);
        $this->assertSame('2026-02-03 04:05:06', $flags['email_blocked_at']);
        $this->assertSame('unsubscribed', $flags['email_blocked_cause']);
        $this->assertSame('1', (string) $flags['email_bounced']);
        $this->assertSame('2026-02-03 04:05:07', $flags['email_bounced_at']);
        $rows = $this->flagLogRows($fixture['owner']);
        $this->assertCount(1, $rows, 'Only the opt-out changed, so one row');
        $this->assertSame(
            ['owner_id' => $fixture['owner'], 'flag' => 'email_opted_out', 'old' => 1, 'new' => 0, 'source' => 'admin', 'owner_requested' => 1],
            $rows[0]['metadata']
        );
    }

    // ---- Clear Block (#2357) -----------------------------------------------------------------

    /**
     * @return array{owner: int, cars: list<int>}
     */
    private function blockedOwner(string $blockedAddress = 'writer-a@example.com'): array
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');
        $this->setOwnerBlocked($fixture['owner'], $blockedAddress, '2026-02-03 04:05:06', 'unsubscribed');

        return $fixture;
    }

    private function assertBlockCleared(int $ownerId): void
    {
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('0', (string) $flags['email_blocked']);
        $this->assertNull($flags['email_blocked_address']);
        $this->assertNull($flags['email_blocked_at']);
        $this->assertNull($flags['email_blocked_cause']);
    }

    public function testClearBlockedMatchingAddressCallsBrevoAndClearsFlagAddressDateAndCause(): void
    {
        $fixture = $this->blockedOwner();
        $this->setOwnerOptedOut($fixture['owner']);
        $client = $this->brevoClient();

        $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
        $this->assertSame(['writer-a@example.com'], $client->calls);
        $this->assertBlockCleared($fixture['owner']);
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($fixture['owner'])['email_opted_out'], 'Clear Block keeps the opt-out');
    }

    public function testClearBlockedBrevo404CountsAsSuccess(): void
    {
        $fixture = $this->blockedOwner();

        $outcome = $this->manager->clearBlockedForOwner(
            $fixture['owner'],
            self::ADMIN_ID,
            $this->brevoClient(null, BrevoUnblockResult::notBlocked())
        );

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
        $this->assertBlockCleared($fixture['owner']);
    }

    public function testClearBlockedLogRowHasTheOwnerRequestedMetadataAndLatestFlagClearAtReturnsClearedAt(): void
    {
        $fixture = $this->blockedOwner();
        $before = time();

        $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $this->brevoClient());

        $rows = $this->flagLogRows($fixture['owner']);
        $this->assertCount(1, $rows);
        $this->assertSame(self::ADMIN_ID, $rows[0]['user_id']);
        $metadata = $rows[0]['metadata'];
        $this->assertArrayHasKey('cleared_at', $metadata);
        $clearedAt = $metadata['cleared_at'];
        $this->assertIsString($clearedAt);
        $expected = [
            'owner_id' => $fixture['owner'],
            'flag' => 'email_blocked',
            'old' => 1,
            'new' => 0,
            'source' => 'admin',
            'owner_requested' => 1,
            'cleared_at' => $clearedAt,
        ];
        ksort($expected);
        ksort($metadata);
        $this->assertSame($expected, $metadata, 'Exactly these keys and value types');
        $this->assertGreaterThanOrEqual($before - 1, strtotime($clearedAt));
        $this->assertLessThanOrEqual(time(), strtotime($clearedAt));
        $this->assertSame($clearedAt, $this->repo->latestFlagClearAt($fixture['owner'], 'email_blocked', 'admin'));
        $this->assertStringNotContainsString('@', $rows[0]['lognote']);
    }

    public function testClearBlockedRecordsTheClearTimeBeforeTheBrevoCall(): void
    {
        $fixture = $this->blockedOwner();
        $before = time();

        $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $this->brevoClient(static function (): void {
            sleep(2);
        }));

        $clearedAt = strtotime((string) $this->flagLogRows($fixture['owner'])[0]['metadata']['cleared_at']);
        $this->assertLessThanOrEqual($before + 1, $clearedAt, 'cleared_at is the time before the 2 s Brevo call');
    }

    public function testClearBlockedAddressMovedClearsTheFlagWithNoBrevoCall(): void
    {
        $fixture = $this->blockedOwner('old-address@example.com');
        $client = $this->brevoClient();

        $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED_ADDRESS_MOVED, $outcome->kind);
        $this->assertSame([], $client->calls);
        $this->assertBlockCleared($fixture['owner']);
        $this->assertCount(1, $this->flagLogRows($fixture['owner']));
    }

    public function testClearBlockedSharedAddressClearsTheFlagWithNoBrevoCall(): void
    {
        $fixture = $this->blockedOwner();
        // Another account has the same address under the matching rule (case and outer spaces).
        $otherId = $this->createTestUser(['email' => 'writer-a@example.com']);
        $this->setUserEmailRaw($otherId, ' Writer-A@Example.com ');
        $client = $this->brevoClient();

        $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED_SHARED_ADDRESS, $outcome->kind);
        $this->assertSame([], $client->calls, 'No Brevo call: the other account may rely on the block');
        $this->assertBlockCleared($fixture['owner']);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function emptyBlockedAddressProvider(): array
    {
        return [
            'NULL'         => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('emptyBlockedAddressProvider')]
    public function testClearBlockedEmptyBlockedAddressUsesTheUsersEmail(?string $stored): void
    {
        $fixture = $this->blockedOwner();
        $this->setProfileColumns($fixture['owner'], ['email_blocked_address' => $stored]);
        $client = $this->brevoClient();

        $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
        $this->assertSame(['writer-a@example.com'], $client->calls);
        $this->assertBlockCleared($fixture['owner']);
    }

    public function testClearBlockedWithNoAddressAnywhereClearsTheFlagWithNoBrevoCall(): void
    {
        $fixture = $this->blockedOwner();
        $this->setProfileColumns($fixture['owner'], ['email_blocked_address' => null]);
        $this->setUserEmailRaw($fixture['owner'], '');
        $client = $this->brevoClient();

        $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED_NO_ADDRESS, $outcome->kind);
        $this->assertSame([], $client->calls);
        $this->assertBlockCleared($fixture['owner']);
    }

    public function testClearBlockedBrevoFailureWritesNothing(): void
    {
        $fixture = $this->blockedOwner();
        $flagsBefore = $this->getOwnerEmailFlags($fixture['owner']);
        $client = $this->brevoClient(null, BrevoUnblockResult::failed(BrevoUnblockResult::REASON_HTTP, 503));

        try {
            $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $client);
        } finally {
            $this->db->query(
                'DELETE FROM logs WHERE logtype = ? AND user_id = ? AND lognote LIKE ?',
                [LogCategories::LOG_CATEGORY_CAR_VERIFICATION, self::ADMIN_ID, 'Verification: Clear Block Brevo unblock failed for owner ' . $fixture['owner'] . ' %']
            );
        }

        $this->assertSame(ClearBlockOutcome::BREVO_FAILED, $outcome->kind);
        $this->assertSame(['writer-a@example.com'], $client->calls);
        $this->assertSame($flagsBefore, $this->getOwnerEmailFlags($fixture['owner']), 'The flag and its detail stay');
        $this->assertSame([], $this->flagLogRows($fixture['owner']));
    }

    public function testClearBlockedForOwnerAddressChangedDuringCallClearsNothing(): void
    {
        $fixture = $this->blockedOwner();
        $ownerId = $fixture['owner'];
        $client = $this->brevoClient(function () use ($ownerId): void {
            $this->setProfileColumns($ownerId, ['email_blocked_address' => 'changed-during-call@example.com']);
        });

        $outcome = $this->manager->clearBlockedForOwner($ownerId, self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::ADDRESS_CHANGED, $outcome->kind);
        $this->assertFalse($outcome->isCleared());
        $this->assertCount(1, $client->calls);
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('1', (string) $flags['email_blocked'], 'The new block stays');
        $this->assertSame('changed-during-call@example.com', $flags['email_blocked_address']);
        $this->assertSame('2026-02-03 04:05:06', $flags['email_blocked_at']);
        $this->assertSame('unsubscribed', $flags['email_blocked_cause']);
        $this->assertSame([], $this->flagLogRows($ownerId), 'No flag-change row');
        $this->assertFalse($this->db->inTransaction(), 'The transaction ended');
    }

    public function testClearBlockedForOwnerFlagClearedDuringCallWritesNoRow(): void
    {
        $fixture = $this->blockedOwner();
        $ownerId = $fixture['owner'];
        $client = $this->brevoClient(function () use ($ownerId): void {
            $this->setProfileColumns($ownerId, ['email_blocked' => 0]);
        });

        $outcome = $this->manager->clearBlockedForOwner($ownerId, self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::NOT_BLOCKED, $outcome->kind);
        $this->assertCount(1, $client->calls);
        $this->assertSame([], $this->flagLogRows($ownerId), 'Another request cleared the flag: no flag-change row from this one');
        $this->assertFalse($this->db->inTransaction(), 'The transaction ended');
    }

    public function testClearBlockedWhenFlagIsZeroMakesNoBrevoCall(): void
    {
        $fixture = $this->createOwnerWithTwoCars('writer-a@example.com', 'writer-b@example.com');
        $client = $this->brevoClient();

        $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::NOT_BLOCKED, $outcome->kind);
        $this->assertSame([], $client->calls);
        $this->assertSame([], $this->flagLogRows($fixture['owner']));
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function stillBouncedProvider(): array
    {
        return [
            'bounce on the current address'  => ['writer-a@example.com', true],
            'bounce with no stored address'  => [null, true],
            'bounce on an old address'       => ['old-address@example.com', false],
        ];
    }

    #[DataProvider('stillBouncedProvider')]
    public function testClearBlockedSetsStillBouncedFromTheBounceUnderTheLock(?string $bouncedAddress, bool $expected): void
    {
        $fixture = $this->blockedOwner();
        $this->setProfileColumns($fixture['owner'], [
            'email_bounced' => 1,
            'email_bounced_address' => $bouncedAddress,
            'email_bounced_at' => '2026-02-03 04:05:07',
        ]);

        $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $this->brevoClient());

        $this->assertSame($expected, $outcome->stillBounced);
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($fixture['owner'])['email_bounced'], 'Clear Block keeps the bounce');
    }

    public function testClearBlockedNotBouncedLeavesStillBouncedFalse(): void
    {
        $fixture = $this->blockedOwner();

        $outcome = $this->manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $this->brevoClient());

        $this->assertFalse($outcome->stillBounced);
    }

    /**
     * The Brevo call comes before the transaction, the profile lock comes
     * before the first write, and the read before the call takes no lock.
     */
    public function testClearBlockedForOwnerCallsBrevoBeforeTheLockAndLocksBeforeItWrites(): void
    {
        $fixture = $this->blockedOwner();
        $db = new RecordingPassThroughDatabase($this->db);
        $manager = new CarVerificationManager(new CarRepository($db));
        $client = $this->brevoClient(static function () use ($db): void {
            $db->events[] = 'brevo-call';
        });

        $outcome = $manager->clearBlockedForOwner($fixture['owner'], self::ADMIN_ID, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
        $events = $db->events;
        $call = array_search('brevo-call', $events, true);
        $begin = array_search('begin', $events, true);
        $this->assertIsInt($call);
        $this->assertIsInt($begin);
        $this->assertLessThan($begin, $call, 'Brevo is called before the transaction begins');
        $lockIndexes = array_keys(array_filter($events, static fn (string $e): bool => str_ends_with($e, 'FOR UPDATE OF p, u')));
        $this->assertCount(1, $lockIndexes, 'One locking read of the profile and users rows');
        $this->assertGreaterThan($begin, $lockIndexes[0], 'The lock is taken inside the transaction, after the Brevo call');
        $writes = array_keys(array_filter($events, static fn (string $e): bool => str_starts_with($e, 'query: UPDATE') || str_starts_with($e, 'update: ') || str_starts_with($e, 'insert: ')));
        $this->assertNotEmpty($writes, 'Test setup: the clear must write');
        $this->assertLessThan(min($writes), $lockIndexes[0], 'The lock comes before the first write');
        $this->assertSame('commit', end($events));
    }

    // ---- delivery events ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function blockEventProvider(): array
    {
        return [
            'spam complaint via webhook'            => ['spam', 'spam', 'webhook'],
            'unsubscribe via suppression import'    => ['unsubscribed', 'unsubscribed', 'import'],
        ];
    }

    #[DataProvider('blockEventProvider')]
    public function testBlockEventSetsAddressTimeAndCauseOnTheProfile(string $event, string $expectedCause, string $source): void
    {
        $fixture = $this->createOwnerWithTwoCars('Block-A@Example.com', 'block-b@example.com');

        $this->applyEvent($fixture['owner'], 'Block-A@Example.com', $event, null, 'msg-block-1', '2026-04-04 04:04:04', $source);

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('1', (string) $flags['email_blocked']);
        $this->assertSame('block-a@example.com', $flags['email_blocked_address']);
        $this->assertSame('2026-04-04 04:04:04', $flags['email_blocked_at']);
        $this->assertSame($expectedCause, $flags['email_blocked_cause']);

        $rows = $this->flagLogRows($fixture['owner']);
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['user_id'], 'An event has acting user 0');
        $this->assertSame($source, $rows[0]['metadata']['source']);
        $this->assertSame('email_blocked', $rows[0]['metadata']['flag']);
    }

    public function testBlockEventRepeatOnTheSameAddressKeepsTheFirstTimeAndCause(): void
    {
        $fixture = $this->createOwnerWithTwoCars('block-a@example.com', 'block-b@example.com');
        $this->applyEvent($fixture['owner'], 'block-a@example.com', 'spam', null, 'msg-1', '2026-04-04 04:04:04', 'webhook');

        $this->applyEvent($fixture['owner'], 'block-a@example.com', 'unsubscribed', null, 'msg-2', '2026-05-05 05:05:05', 'webhook');

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('2026-04-04 04:04:04', $flags['email_blocked_at']);
        $this->assertSame('spam', $flags['email_blocked_cause']);
        $this->assertCount(1, $this->flagLogRows($fixture['owner']));
    }

    public function testBlockEventForAnotherAddressRewritesAddressTimeAndCause(): void
    {
        $fixture = $this->createOwnerWithTwoCars('block-a@example.com', 'block-b@example.com');
        $this->applyEvent($fixture['owner'], 'block-a@example.com', 'spam', null, 'msg-1', '2026-04-04 04:04:04', 'webhook');
        // An event flags only the current address (#2359), so the owner moves to B first.
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', ['block-b@example.com', $fixture['owner']]);

        $this->applyEvent($fixture['owner'], 'block-b@example.com', 'unsubscribed', null, 'msg-2', '2026-05-05 05:05:05', 'webhook');

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('block-b@example.com', $flags['email_blocked_address']);
        $this->assertSame('2026-05-05 05:05:05', $flags['email_blocked_at']);
        $this->assertSame('unsubscribed', $flags['email_blocked_cause']);
    }

    public function testBlockEventForOneOwnerBlocksThatOwnerNotTheOtherOwner(): void
    {
        $fixture = $this->createOwnerWithTwoCars('block-a@example.com', 'block-b@example.com');
        $other = $this->createOwnerWithTwoCars('other-a@example.com', 'other-b@example.com');
        $carsBefore = $this->carSnapshot(array_merge($fixture['cars'], $other['cars']));

        $this->applyEvent($fixture['owner'], 'block-a@example.com', 'spam', null, 'msg-1', '2026-04-04 04:04:04', 'webhook');

        $this->assertSame('1', (string) $this->getOwnerEmailFlags($fixture['owner'])['email_blocked']);
        $this->assertSame('0', (string) $this->getOwnerEmailFlags($other['owner'])['email_blocked']);
        $this->assertSame($carsBefore, $this->carSnapshot(array_merge($fixture['cars'], $other['cars'])), 'An event writes no car row');
    }

    public function testHardBounceEventSetsBouncedAddressAndTimeOnTheProfile(): void
    {
        $fixture = $this->createOwnerWithTwoCars('bounce-a@example.com', 'bounce-b@example.com');

        $this->applyEvent($fixture['owner'], 'bounce-a@example.com', 'hard_bounce', null, 'msg-1', '2026-06-06 06:06:06', 'reconciliation');

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('1', (string) $flags['email_bounced']);
        $this->assertSame('bounce-a@example.com', $flags['email_bounced_address']);
        $this->assertSame('2026-06-06 06:06:06', $flags['email_bounced_at']);
        $this->assertSame('reconciliation', $this->flagLogRows($fixture['owner'])[0]['metadata']['source']);
    }

    public function testSoftBounceEscalationSetsBouncedAtToTheEscalatingEventTime(): void
    {
        $soft = 'soft-a-' . uniqid() . '@example.com';
        $fixture = $this->createOwnerWithTwoCars($soft, 'soft-b-' . uniqid() . '@example.com');

        $this->applyEvent($fixture['owner'], $soft, 'soft_bounce', null, 'soft-1', '2026-07-01 00:00:00', 'webhook');
        $this->applyEvent($fixture['owner'], $soft, 'soft_bounce', null, 'soft-2', '2026-07-02 00:00:00', 'webhook');
        $this->assertSame('0', (string) $this->getOwnerEmailFlags($fixture['owner'])['email_bounced'], 'Test setup: two cycles do not escalate');
        $this->applyEvent($fixture['owner'], $soft, 'soft_bounce', null, 'soft-3', '2026-07-03 12:13:14', 'webhook');

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('1', (string) $flags['email_bounced']);
        $this->assertSame($soft, $flags['email_bounced_address']);
        $this->assertSame('2026-07-03 12:13:14', $flags['email_bounced_at'], 'Escalation sets email_bounced_at (#2320)');
    }

    public function testOneSoftBounceAfterAClearDoesNotEscalate(): void
    {
        $soft = 'soft-a-' . uniqid() . '@example.com';
        $fixture = $this->createOwnerWithTwoCars($soft, 'soft-b-' . uniqid() . '@example.com');
        $this->setOwnerBounced($fixture['owner'], $soft);
        $this->applyEvent($fixture['owner'], $soft, 'soft_bounce', null, 'soft-1', '2026-07-01 00:00:00', 'webhook');
        $this->applyEvent($fixture['owner'], $soft, 'soft_bounce', null, 'soft-2', '2026-07-02 00:00:00', 'webhook');
        $this->manager->clearBouncedForOwner($fixture['owner'], self::ADMIN_ID);
        $this->assertSame('0', (string) $this->getOwnerEmailFlags($fixture['owner'])['email_bounced'], 'Test setup: bounce cleared');

        $this->applyEvent($fixture['owner'], $soft, 'soft_bounce', null, 'soft-3', $this->futureOrPast('+1 minute'), 'webhook');

        $this->assertSame(
            '0',
            (string) $this->getOwnerEmailFlags($fixture['owner'])['email_bounced'],
            'The two soft bounces before the clear must not count: 1 cycle is below the threshold of 3'
        );
        $this->assertSame(1, $this->repo->countSoftBouncesSinceLastDelivered($fixture['owner'], $soft));
    }

    // ---- a flag stored for an old address -----------------------------------------------------

    /**
     * Each case writes a flag for the new address B. A stored flag for the old
     * address A does not apply to B, so the write is a change and must log.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oldAddressFlagProvider(): array
    {
        return [
            'bounce via the event applier'       => ['email_bounced', 'applier'],
            'bounce via setBouncedFromEvent()'   => ['email_bounced', 'setBouncedFromEvent'],
            'bounce via setBouncedForOwner'      => ['email_bounced', 'setBouncedForOwner'],
            'block via the event applier'        => ['email_blocked', 'applier'],
            'block via setSuppressedFromEvent()' => ['email_blocked', 'setSuppressedFromEvent'],
        ];
    }

    #[DataProvider('oldAddressFlagProvider')]
    public function testFlagForANewAddressLogsAChangeWhenTheStoredFlagIsForAnOldAddress(string $flag, string $path): void
    {
        $fixture = $this->createOwnerWithTwoCars('old-a@example.com', 'new-b@example.com');
        if ($flag === 'email_bounced') {
            $this->setOwnerBounced($fixture['owner'], 'old-a@example.com', '2026-01-01 00:00:00');
        } else {
            $this->setOwnerBlocked($fixture['owner'], 'old-a@example.com', '2026-01-01 00:00:00');
        }
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', ['new-b@example.com', $fixture['owner']]);
        $this->assertFalse(
            $flag === 'email_bounced'
                ? $this->repo->effectiveEmailFlags($fixture['owner'])->bounced
                : $this->repo->effectiveEmailFlags($fixture['owner'])->blocked,
            'Test setup: the flag for the old address must not apply to the new address'
        );
        $at = '2026-08-08 08:08:08';

        if ($path === 'applier') {
            // apply() returns nothing, so this path checks only the row and the log.
            $event = $flag === 'email_bounced' ? 'hard_bounce' : 'spam';
            $this->applyEvent($fixture['owner'], 'new-b@example.com', $event, null, 'msg-new-b', $at, 'webhook');
        } else {
            $changed = match ($path) {
                'setBouncedFromEvent'    => $this->manager->setBouncedFromEvent($fixture['owner'], 'new-b@example.com', $at, 'webhook'),
                'setSuppressedFromEvent' => $this->manager->setSuppressedFromEvent($fixture['owner'], 'new-b@example.com', 'spam', $at, 'webhook'),
                'setBouncedForOwner'     => $this->manager->setBouncedForOwner($fixture['owner'], 'new-b@example.com', self::ADMIN_ID),
                default                  => $this->fail("Test setup: unknown path {$path}"),
            };
            $this->assertTrue($changed, 'The flag now applies where it did not before, so the call reports a change');
        }

        $flags = $this->getOwnerEmailFlags($fixture['owner']);
        $this->assertSame('1', (string) $flags[$flag]);
        $this->assertSame('new-b@example.com', $flags[$flag . '_address'], 'The stored address is the new address');
        $rows = $this->flagLogRows($fixture['owner']);
        $this->assertCount(1, $rows, 'The change writes one flag-change log row');
        $this->assertSame($flag, $rows[0]['metadata']['flag']);
        $this->assertSame(0, $rows[0]['metadata']['old'], 'The old value is the flag that applied before: none');
        $this->assertSame(1, $rows[0]['metadata']['new']);
    }

    // ---- row lock -----------------------------------------------------------------------------

    /**
     * Each case is a writer call that reads the row FOR UPDATE and then writes
     * nothing, so only the SELECT ... FOR UPDATE can hold the lock.
     *
     * @return array<string, array{0: string}>
     */
    public static function lockingWriterProvider(): array
    {
        return [
            'Mark Bounced'      => ['markBounced'],
            'Clear Bounced'     => ['clearBounced'],
            'Clear Block'       => ['clearBlocked'],
            'Clear Block if address' => ['clearBlockedIfAddress'],
            'Clear Opt-out'     => ['clearOptedOut'],
            'Set Block'         => ['setBlocked'],
            'Owner opt-out'     => ['optOut'],
        ];
    }

    private function runNoWriteWriter(string $writer, int $ownerId): void
    {
        match ($writer) {
            'markBounced'  => $this->manager->setBouncedForOwner($ownerId, 'lock-a@example.com', self::ADMIN_ID),
            'clearBounced' => $this->manager->clearBouncedForOwner($ownerId, self::ADMIN_ID),
            'clearBlocked' => $this->repo->clearProfileBlocked($ownerId),
            'clearBlockedIfAddress' => $this->repo->clearProfileBlockedIfAddress($ownerId, null),
            'clearOptedOut' => $this->manager->clearOptedOutForOwner($ownerId, self::ADMIN_ID),
            'setBlocked'   => $this->repo->setProfileBlocked($ownerId, 'lock-a@example.com', 'spam', '2026-01-01 00:00:00'),
            'optOut'       => $this->manager->setSuppressedForOwner($ownerId),
            default        => $this->fail("Test setup: unknown writer {$writer}"),
        };
    }

    private function prepareNoWriteState(string $writer, int $ownerId): void
    {
        match ($writer) {
            'markBounced'  => $this->setOwnerBounced($ownerId, 'lock-a@example.com'),
            'setBlocked'   => $this->setOwnerBlocked($ownerId, 'lock-a@example.com'),
            'optOut'       => $this->setOwnerOptedOut($ownerId),
            default        => null,
        };
    }

    #[DataProvider('lockingWriterProvider')]
    public function testWriterHoldsTheProfileRowLockUntilTheCallerCommits(string $writer): void
    {
        $fixture = $this->createOwnerWithTwoCars('lock-a@example.com', 'lock-b@example.com');
        $this->prepareNoWriteState($writer, $fixture['owner']);
        $flagsBefore = $this->getOwnerEmailFlags($fixture['owner']);
        $second = $this->secondConnection();

        $this->repo->beginTransaction();
        try {
            $this->runNoWriteWriter($writer, $fixture['owner']);
            $this->assertSame($flagsBefore, $this->getOwnerEmailFlags($fixture['owner']), 'Test setup: the writer must write nothing');

            $this->assertSame(
                1205,
                $this->lockWaitErrorCode($second, $fixture['owner']),
                'A second connection must time out on the row lock while the first transaction is open'
            );
        } finally {
            $this->repo->rollback();
        }

        $this->assertNull(
            $this->lockWaitErrorCode($second, $fixture['owner']),
            'After the first transaction ends the same lock request must succeed'
        );
    }

    public function testAPlainReadDoesNotHoldTheProfileRowLock(): void
    {
        // Negative control: the lock test above must be able to tell a locking read from a plain one.
        $fixture = $this->createOwnerWithTwoCars('lock-a@example.com', 'lock-b@example.com');
        $second = $this->secondConnection();

        $this->repo->beginTransaction();
        try {
            $this->assertIsObject($this->repo->findProfileEmailState($fixture['owner'], false));

            $this->assertNull($this->lockWaitErrorCode($second, $fixture['owner']), 'A plain SELECT takes no row lock');
        } finally {
            $this->repo->rollback();
        }
    }

    /**
     * Ask a second connection for the row lock, with a 1-second wait.
     *
     * @return int|null MySQL error code 1205 on a lock wait timeout, null when the lock was granted
     */
    private function lockWaitErrorCode(PDO $connection, int $ownerId): ?int
    {
        $connection->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $connection->beginTransaction();
        try {
            $stmt = $connection->prepare('SELECT id FROM profiles WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$ownerId]);
            $stmt->fetchAll();

            return null;
        } catch (PDOException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        } finally {
            $connection->rollBack();
        }
    }

    private function secondConnection(): PDO
    {
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST'));
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306;
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));
        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name}",
            (string) ($_ENV['DB_USER'] ?? getenv('DB_USER')),
            (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS')),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
