<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral tests for sendinblueRecordEvent() (#2355).
 *
 * The installed sendinblue plugin does not have this function yet. The
 * function source is the added hunk in tests/fixtures/sendinblue/record-event.patch.
 * The tests load that hunk, so they test the exact code that goes upstream,
 * unless the installed plugin already defines the function. Then the tests
 * run against the plugin's version, not the patch.
 */
#[Group('integration')]
final class SendinblueRecordEventTest extends IntegrationTestCase
{
    private const PATCH_FILE = __DIR__ . '/../fixtures/sendinblue/record-event.patch';
    private const PATCH_TARGET = '+++ b/src/sendinblue/functions.php';
    private const SDK_EVENTS_FILE = __DIR__
        . '/../../usersc/plugins/sendinblue/vendor/getbrevo/brevo-php/lib/Model/GetEmailEventReportEvents.php';

    /** Webhook and SDK event names, mapped to the stored status. Copied from the patch. */
    private const EVENT_STATUS_MAP = [
        'request' => 'requests', 'requests' => 'requests',
        'delivered' => 'delivered',
        'hard_bounce' => 'hardBounces', 'hardBounces' => 'hardBounces',
        'soft_bounce' => 'softBounces', 'softBounces' => 'softBounces',
        'blocked' => 'blocked',
        'invalid_email' => 'invalid', 'invalid' => 'invalid',
        'deferred' => 'deferred',
        'spam' => 'spam',
        'unsubscribed' => 'unsubscribed',
        'opened' => 'opened', 'unique_opened' => 'opened',
        'click' => 'clicks', 'clicks' => 'clicks',
        'proxy_open' => 'loadedByProxy', 'unique_proxy_open' => 'loadedByProxy', 'loadedByProxy' => 'loadedByProxy',
        'error' => 'error',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        self::loadRecordEventFunction();

        // The function uses `global $db`. A TEMPORARY table is visible only on
        // the connection that made it, so both handles must be one connection.
        $this->assertSame(DB::getInstance(), $GLOBALS['db'] ?? null, 'Global $db is not DB::getInstance()');

        // Equivalent to the table after plugin migrate.php updates 00006-00008 (sendinblue 1.8.0).
        // A TEMPORARY table hides a real table of the same name, MySQL drops it at
        // disconnect, and no test row can get into a real table.
        $create = DB::getInstance()->query(
            'CREATE TEMPORARY TABLE plg_sendinblue_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                message_id VARCHAR(191) NOT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                sender_email VARCHAR(255) NOT NULL,
                kind VARCHAR(32) NOT NULL,
                tags VARCHAR(255) NULL,
                status VARCHAR(32) NOT NULL,
                accepted_at DATETIME NOT NULL,
                delivered_at DATETIME NULL,
                last_event_at DATETIME NULL,
                last_checked_at DATETIME NULL,
                last_reason VARCHAR(255) NULL,
                UNIQUE KEY message_id (message_id),
                KEY recipient_accepted (recipient_email(128), accepted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        if ($create->error()) {
            $this->fail('Could not create the temporary plg_sendinblue_messages table: ' . $create->errorString());
        }
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            DB::getInstance()->query('DROP TEMPORARY TABLE IF EXISTS plg_sendinblue_messages');
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Input validation
    // ------------------------------------------------------------------

    public function testUnknownEventNameReturnsFalseAndLeavesRowUnchanged(): void
    {
        $messageId = $this->insertMessageRow();

        $this->assertRejectedUnchanged($messageId, $messageId, 'not_an_event', time() - 60);
    }

    /**
     * @return array<string, array{mixed, mixed, mixed, mixed}>
     */
    public static function wrongTypeArgumentsProvider(): array
    {
        // A null message ID means "use the row's message ID".
        return [
            'event is int' => [null, 5, null, ''],
            'event is null' => [null, null, null, ''],
            'message ID is int' => [12345, 'delivered', null, ''],
            'message ID is array' => [['x'], 'delivered', null, ''],
            'reason is int' => [null, 'delivered', null, 42],
            'reason is array' => [null, 'delivered', null, ['bounced']],
            // Each time is near now, so only its type can cause the false result.
            'time is float' => [null, 'delivered', (float)(time() - 60), ''],
            'time is array' => [null, 'delivered', [time() - 60], ''],
            'time is object' => [null, 'delivered', new DateTimeImmutable('-1 minute'), ''],
            'time is bool' => [null, 'delivered', true, ''],
        ];
    }

    /**
     * @param mixed $messageId Null to use the row's message ID.
     * @param mixed $event Event name argument.
     * @param mixed $time Time argument.
     * @param mixed $reason Reason argument.
     */
    #[DataProvider('wrongTypeArgumentsProvider')]
    public function testWrongTypeArgumentReturnsFalseWithoutWarning(
        mixed $messageId,
        mixed $event,
        mixed $time,
        mixed $reason
    ): void {
        $rowMessageId = $this->insertMessageRow();
        $before = $this->fetchRow($rowMessageId);

        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        try {
            $result = $this->recordEvent($messageId ?? $rowMessageId, $event, $time, $reason);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertSame($before, $this->fetchRow($rowMessageId));
    }

    public function testUnknownMessageIdReturnsFalse(): void
    {
        $messageId = $this->insertMessageRow();

        $this->assertRejectedUnchanged($messageId, $this->uniqueMessageId(), 'delivered', time() - 60);
    }

    // ------------------------------------------------------------------
    // Event order and delivered_at
    // ------------------------------------------------------------------

    public function testOlderEventDoesNotReplaceNewerEvent(): void
    {
        $now = time();
        $messageId = $this->insertMessageRow();

        $this->assertTrue($this->recordEvent($messageId, 'opened', $now - 60));
        $before = $this->fetchRow($messageId);
        $this->assertSame('opened', $before['status']);
        $this->assertSame($this->localTime($now - 60), $before['last_event_at']);

        // The UPDATE matches no row, which is not an error, so the function returns true.
        $this->assertTrue($this->recordEvent($messageId, 'deferred', $now - 120, 'older event'));
        $this->assertSame($before, $this->fetchRow($messageId));
    }

    public function testDeliveredAtIsSetOnceAndNotChangedByLaterDeliveredEvent(): void
    {
        $now = time();
        $messageId = $this->insertMessageRow();

        $this->assertTrue($this->recordEvent($messageId, 'delivered', $now - 120));
        $first = $this->fetchRow($messageId);
        $this->assertSame('delivered', $first['status']);
        $this->assertSame($this->localTime($now - 120), $first['delivered_at']);
        $this->assertSame($this->localTime($now - 120), $first['last_event_at']);

        $this->assertTrue($this->recordEvent($messageId, 'delivered', $now - 60));
        $second = $this->fetchRow($messageId);
        $this->assertSame('delivered', $second['status']);
        $this->assertSame($this->localTime($now - 120), $second['delivered_at']);
        $this->assertSame($this->localTime($now - 60), $second['last_event_at']);
    }

    /**
     * Webhooks can arrive out of order, so a "delivered" event can be older than
     * the stored event. It does not replace the status, but it still records
     * the delivery time.
     */
    public function testLateDeliveredEventSetsDeliveredAtButKeepsNewerStatus(): void
    {
        $now = time();
        $messageId = $this->insertMessageRow();

        $this->assertTrue($this->recordEvent($messageId, 'opened', $now - 60));
        $this->assertTrue($this->recordEvent($messageId, 'delivered', $now - 120));

        $row = $this->fetchRow($messageId);
        $this->assertSame('opened', $row['status']);
        $this->assertSame($this->localTime($now - 60), $row['last_event_at']);
        $this->assertSame($this->localTime($now - 120), $row['delivered_at']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function eventStatusProvider(): array
    {
        $cases = [];
        foreach (self::EVENT_STATUS_MAP as $event => $status) {
            $cases[$event] = [$event, $status];
        }

        return $cases;
    }

    #[DataProvider('eventStatusProvider')]
    public function testEventNameIsStoredAsSdkStatus(string $event, string $expectedStatus): void
    {
        $now = time();
        $messageId = $this->insertMessageRow();

        $this->assertTrue($this->recordEvent($messageId, $event, $now - 60, 'reason text'));

        $this->assertContains($expectedStatus, self::sdkEventNames(), 'Not a GetEmailEventReportEvents::EVENT_* value');
        $row = $this->fetchRow($messageId);
        $this->assertSame($expectedStatus, $row['status']);
        $this->assertSame($this->localTime($now - 60), $row['last_event_at']);
        $this->assertSame('reason text', $row['last_reason']);
        $this->assertSame(
            $expectedStatus === 'delivered' ? $this->localTime($now - 60) : null,
            $row['delivered_at']
        );
    }

    // ------------------------------------------------------------------
    // Time handling
    // ------------------------------------------------------------------

    public function testNumericStringTimeAndIntegerTimeGiveSameLastEventAt(): void
    {
        $now = time();
        $at = $now - 90;
        $intRow = $this->insertMessageRow();
        $stringRow = $this->insertMessageRow();

        $this->assertTrue($this->recordEvent($intRow, 'opened', $at));
        $this->assertTrue($this->recordEvent($stringRow, 'opened', (string)$at));

        $this->assertSame($this->localTime($at), $this->fetchRow($intRow)['last_event_at']);
        $this->assertSame($this->localTime($at), $this->fetchRow($stringRow)['last_event_at']);
    }

    public function testTimeMoreThanFiveMinutesAheadReturnsFalseAndChangesNothing(): void
    {
        $messageId = $this->insertMessageRow();

        $this->assertRejectedUnchanged($messageId, $messageId, 'delivered', time() + 360);
    }

    /**
     * @return array<string, array{int|string, bool}>
     */
    public static function rejectedTimeProvider(): array
    {
        $cases = [];
        $times = [
            'yesterday' => 'yesterday',
            'minus five' => '-5',
            'integer one' => 1,
            'relative suffix' => '2026-10-11 12:00 -1 day',
            // A valid recent time, so only the final newline can cause the false result.
            'trailing newline' => date('Y-m-d H:i:s', time() - 60) . "\n",
        ];
        foreach ($times as $label => $time) {
            $cases["{$label}, accepted_at set"] = [$time, false];
            $cases["{$label}, accepted_at NULL"] = [$time, true];
        }

        return $cases;
    }

    #[DataProvider('rejectedTimeProvider')]
    public function testRelativeOrMalformedTimeReturnsFalse(int|string $time, bool $nullAcceptedAt): void
    {
        if ($nullAcceptedAt) {
            $this->allowNullAcceptedAt();
        }
        $messageId = $this->insertMessageRow($nullAcceptedAt ? null : false);

        $this->assertRejectedUnchanged($messageId, $messageId, 'delivered', $time);
    }

    public function testTimeMoreThanOneDayBeforeAcceptedAtReturnsFalse(): void
    {
        $acceptedAt = time() - 3600;
        $messageId = $this->insertMessageRow($this->localTime($acceptedAt));

        $this->assertRejectedUnchanged($messageId, $messageId, 'delivered', $acceptedAt - 86400 - 60);
    }

    /**
     * Documents a known limitation: the row stores local wall-clock time, so in
     * the repeated hour at the end of DST a later event can look older.
     */
    public function testDstFallBackLaterEventInRepeatedHourDoesNotReplaceRow(): void
    {
        // The local times below are correct only in this zone. users/init.php sets it.
        $this->assertSame('America/Los_Angeles', date_default_timezone_get());

        // The 2025 fall-back hour. A past date, because the function rejects a time in the future.
        $messageId = $this->insertMessageRow('2025-11-02 01:00:00');

        $this->assertTrue($this->recordEvent($messageId, 'opened', '2025-11-02T01:45:00-07:00'));
        $afterA = $this->fetchRow($messageId);
        $this->assertSame('opened', $afterA['status']);
        $this->assertSame('2025-11-02 01:45:00', $afterA['last_event_at']);

        // Event B is 45 minutes after A in real time, but its local time is 01:30.
        $this->assertSame(2700, strtotime('2025-11-02T01:30:00-08:00') - strtotime('2025-11-02T01:45:00-07:00'));
        $this->assertTrue($this->recordEvent($messageId, 'clicks', '2025-11-02T01:30:00-08:00'));
        $this->assertSame($afterA, $this->fetchRow($messageId));
    }

    // ------------------------------------------------------------------
    // Reason
    // ------------------------------------------------------------------

    public function testLongMultibyteReasonIsStoredAs255ValidCharacters(): void
    {
        $now = time();
        $messageId = $this->insertMessageRow();
        $reason = str_repeat('é漢', 150); // 300 characters

        // UserSpice sets sql_mode '' for the session, and then MySQL cuts a long
        // value itself. Strict mode makes the write fail unless the function cuts it.
        $previousMode = $this->sessionSqlMode();
        DB::getInstance()->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        try {
            $this->assertStringContainsString('STRICT_ALL_TABLES', $this->sessionSqlMode());
            $this->assertTrue($this->recordEvent($messageId, 'hard_bounce', $now - 60, $reason));
        } finally {
            DB::getInstance()->query('SET SESSION sql_mode = ?', [$previousMode]);
        }
        $this->assertSame($previousMode, $this->sessionSqlMode());

        $row = $this->fetchRow($messageId);
        $this->assertSame('hardBounces', $row['status']);
        // 255 characters of valid UTF-8, cut at a character boundary.
        $this->assertSame(mb_substr($reason, 0, 255), $row['last_reason']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Load sendinblueRecordEvent() from the patch hunk, once per process.
     * The installed plugin's version wins when it already has the function.
     */
    private static function loadRecordEventFunction(): void
    {
        if (function_exists('sendinblueRecordEvent')) {
            return;
        }

        $patch = file(self::PATCH_FILE, FILE_IGNORE_NEW_LINES);
        if ($patch === false) {
            self::fail('Could not read ' . self::PATCH_FILE);
        }

        $added = [];
        $inTarget = false;
        foreach ($patch as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                $inTarget = false;
            } elseif ($line === self::PATCH_TARGET) {
                $inTarget = true;
            } elseif ($inTarget && str_starts_with($line, '+')) {
                $added[] = substr($line, 1);
            }
        }
        if ($added === []) {
            self::fail('No added lines for src/sendinblue/functions.php in ' . self::PATCH_FILE);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'sendinblue-record-event-');
        if ($tempFile === false) {
            self::fail('Could not create a temporary file for the patch hunk');
        }
        try {
            file_put_contents($tempFile, "<?php\n" . implode("\n", $added) . "\n");
            require_once $tempFile;
        } finally {
            unlink($tempFile);
        }

        if (!function_exists('sendinblueRecordEvent')) {
            self::fail('The patch hunk did not define sendinblueRecordEvent()');
        }
    }

    private function recordEvent(mixed ...$args): mixed
    {
        // PHPStan cannot see a function that only the patch defines. It does not
        // scan usersc/plugins/, so only a stub in stubs/ would let the ignore go.
        return sendinblueRecordEvent(...$args); // @phpstan-ignore function.notFound
    }

    /**
     * Assert that the call returns false and leaves the row as it was.
     */
    private function assertRejectedUnchanged(string $messageId, mixed ...$args): void
    {
        $before = $this->fetchRow($messageId);
        $this->assertFalse($this->recordEvent(...$args));
        $this->assertSame($before, $this->fetchRow($messageId));
    }

    /**
     * The real column is NOT NULL. The function still handles NULL (for
     * example a manual insert), so this test table allows it.
     */
    private function allowNullAcceptedAt(): void
    {
        $alter = DB::getInstance()->query('ALTER TABLE plg_sendinblue_messages MODIFY accepted_at DATETIME NULL');
        if ($alter->error()) {
            $this->fail('Could not make accepted_at nullable: ' . $alter->errorString());
        }
    }

    /**
     * @param string|null|false $acceptedAt False for one hour ago. Null only after allowNullAcceptedAt().
     */
    private function insertMessageRow(string|null|false $acceptedAt = false): string
    {
        if ($acceptedAt === false) {
            $acceptedAt = $this->localTime(time() - 3600);
        }
        $messageId = $this->uniqueMessageId();
        $insert = DB::getInstance()->query(
            "INSERT INTO plg_sendinblue_messages
                (message_id, recipient_email, sender_email, kind, tags, status, accepted_at)
             VALUES (?, 'owner@example.com', 'registry@example.com', 'verification', 'verification', 'accepted', ?)",
            [$messageId, $acceptedAt]
        );
        if ($insert->error()) {
            $this->fail('Could not insert a message row: ' . $insert->errorString());
        }

        return $messageId;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(string $messageId): array
    {
        $result = DB::getInstance()->query(
            'SELECT * FROM plg_sendinblue_messages WHERE message_id = ?',
            [$messageId]
        );
        $this->assertFalse($result->error(), 'Could not read the message row');
        $this->assertSame(1, $result->count(), "No row for {$messageId}");

        return (array)$result->first();
    }

    /**
     * The GetEmailEventReportEvents::EVENT_* values from the plugin's Brevo SDK.
     * Read from the source, because the plugin's autoloader is not loaded here.
     *
     * @return list<string>
     */
    private static function sdkEventNames(): array
    {
        // The plugin is installed per environment (git ignores it), as the
        // database is, so a missing SDK is an environment gap, not a failure.
        if (!is_file(self::SDK_EVENTS_FILE)) {
            self::markTestSkipped('The sendinblue plugin Brevo SDK is not installed: ' . self::SDK_EVENTS_FILE);
        }
        $source = file_get_contents(self::SDK_EVENTS_FILE);
        if ($source === false) {
            self::fail('Could not read ' . self::SDK_EVENTS_FILE);
        }
        preg_match_all("/const EVENT_\\w+ = '([^']+)';/", $source, $matches);
        if ($matches[1] === []) {
            self::fail('No EVENT_* constants in ' . self::SDK_EVENTS_FILE);
        }

        return $matches[1];
    }

    private function sessionSqlMode(): string
    {
        return (string)DB::getInstance()->query('SELECT @@SESSION.sql_mode AS m')->first()->m;
    }

    private function uniqueMessageId(): string
    {
        return '<test-' . bin2hex(random_bytes(8)) . '@example.com>';
    }

    private function localTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
