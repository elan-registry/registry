<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/FakeBrevoBlockedContact.php';
require_once __DIR__ . '/../Support/FakeBrevoSuppressionSyncClient.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeBrevoBlockedContact;
use Tests\Support\FakeBrevoSuppressionSyncClient;

/**
 * #2357, #2426: the Clear Block and Clear Opt-out handlers in app/admin/index.php.
 *
 * Each request runs the real page in its own `php -r` subprocess, as
 * AdminDeliverabilityActionsAuthTest does. Two seams control the Brevo call:
 *
 * - `http://mock-brevo:8080/v3` gives a real 204 or 404 from the mock.
 *   The test seeds a block with `POST /mock-status/accounts/{apiKey}/blocked`
 *   and reads it back through the `/v3` list, so "Brevo was called" and "Brevo
 *   was not called" are both visible in the mock's state.
 * - `http://127.0.0.1:9/v3` gives a connection failure. The Guzzle message of
 *   that failure holds the request URL, with the address in it. This is the
 *   real path for a leak into a log line.
 *
 * Every address holds a token that is unique to the test. The log checks look
 * for the token, so they also catch an encoded form of the address (`%40`).
 */
#[Group('integration')]
#[Group('admin')]
final class AdminClearBlockAndOptOutTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private const PERMISSION_ADMIN = 2;

    private const MOCK_BASE = 'http://mock-brevo:8080';
    private const MOCK_API_HOST = 'http://mock-brevo:8080/v3';
    private const DEAD_API_HOST = 'http://127.0.0.1:9/v3';

    /**
     * The key that saveBrevoTestKey() writes. The integration bootstrap deletes a row with this key
     * after a crash. Other tests share the mock account, so this class removes only its own blocks.
     */
    private const BREVO_KEY = 'sib-test-key';

    private const ERROR_NOT_REQUESTED = 'Confirm that the owner asked for this change.';
    private const TEXT_CLEARED = 'cleared of the block. Brevo no longer blocks this address.';
    private const TEXT_MOVED = "The blocked address is no longer this owner's address. The Brevo block stays.";
    private const TEXT_SHARED = 'Another account uses this address. The Brevo block stays.';
    private const ERROR_BREVO_FAILED = 'Brevo did not remove the block. Try again later.';
    private const ERROR_BREVO_NOT_SET_UP = 'Brevo is not set up on this site, so the block was not removed. The registry flag did not change.';
    private const TEXT_STILL_BOUNCED = "This owner is also marked bounced. Use Clear Bounced if the owner's mailbox works.";

    /** @var array<string, string|false> DB env values from before the test */
    private array $savedEnv = [];

    private string $token = '';
    private int $adminId = 0;
    private int $ownerId = 0;
    private int $carId = 0;
    private string $ownerEmail = '';
    private int $logIdFloor = 0;

    /** @var list<int> users created by this test, besides the base-class list, whose logs tearDown deletes */
    private array $loggedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->token = 'cb' . bin2hex(random_bytes(6));
        $this->logIdFloor = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) AS m FROM logs')->first()->m;

        $this->saveBrevoTestKey();
        $this->mockRemoveOwnBlocks();

        // securePage() treats `users.permissions = 0` as a banned account.
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->adminId, self::PERMISSION_ADMIN);

        $this->ownerEmail = $this->address('owner');
        $this->ownerId = $this->createTestUser(['email' => $this->ownerEmail], true);
        $this->carId = $this->createTestCar($this->ownerId, ['email' => $this->ownerEmail]);
        $this->loggedUserIds = [$this->adminId, $this->ownerId];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }
        $this->savedEnv = [];

        if ($this->databaseConnected) {
            $this->mockRemoveOwnBlocks();
            $this->deleteOwnLogs();
        }
        parent::tearDown();
    }

    // --- Fixtures ----------------------------------------------------------

    /** An address that holds this test's token. */
    private function address(string $label): string
    {
        return "{$this->token}-{$label}@example.org";
    }

    /**
     * Remove the log rows that this test wrote or caused. The match is by the test's
     * users and by the token, so a parallel run keeps its rows.
     */
    private function deleteOwnLogs(): void
    {
        $users = array_values(array_unique($this->loggedUserIds));
        $marks = implode(',', array_fill(0, count($users), '?'));
        $this->db->query(
            "DELETE FROM logs WHERE id > ? AND (user_id IN ({$marks})
                OR LOCATE(?, lognote) > 0
                OR LOCATE(?, CONVERT(metadata USING utf8mb4)) > 0
                OR lognote LIKE ?)",
            [$this->logIdFloor, ...$users, $this->token, $this->token, '%owner ' . $this->ownerId . ' %']
        );
        foreach ($users as $userId) {
            $this->db->query('DELETE FROM logs WHERE id > ? AND lognote LIKE ?', [$this->logIdFloor, "%owner {$userId} %"]);
        }
    }

    /** Put the owner in the state that Clear Block acts on. */
    private function seedBlocked(?string $blockedAddress, string $cause = 'spam'): void
    {
        $this->updateBlockedColumns($blockedAddress, $cause);
    }

    private function updateBlockedColumns(?string $address, ?string $cause): void
    {
        $result = $this->db->query(
            'UPDATE profiles SET email_blocked = 1, email_blocked_address = ?, email_blocked_at = ?, email_blocked_cause = ?
              WHERE user_id = ?',
            [$address, '2026-01-02 03:04:05', $cause, $this->ownerId]
        );
        $this->assertFalse($result->error(), 'Test setup: blocked flag: ' . $result->errorString());
    }

    // --- mock-brevo --------------------------------------------------------

    /**
     * @param array<string, mixed>|null $json
     * @return array{status: int, body: string}
     */
    private function mockRequest(string $method, string $path, ?array $json = null): array
    {
        $headers = "api-key: " . self::BREVO_KEY . "\r\nAccept: application/json\r\n";
        $content = '';
        if ($json !== null) {
            $headers .= "Content-Type: application/json\r\n";
            $content = (string) json_encode($json);
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $body = @file_get_contents(self::MOCK_BASE . $path, false, $context);
        if ($body === false || !isset($http_response_header[0])) {
            $this->fail("mock-brevo is not reachable at {$path}. Run this test in the app container (docker compose up -d).");
        }
        preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $match);

        return ['status' => (int) ($match[1] ?? 0), 'body' => $body];
    }

    /** @return list<string> The addresses of this test that the mock account blocks */
    private function mockBlockedEmails(): array
    {
        return array_values(array_filter(
            $this->mockAllBlockedEmails(),
            fn (string $address): bool => str_contains($address, $this->token)
        ));
    }

    /** @return list<string> Every address that the mock account blocks */
    private function mockAllBlockedEmails(): array
    {
        $emails = [];
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $response = $this->mockRequest('GET', "/v3/smtp/blockedContacts?limit=100&offset={$offset}");
            $this->assertSame(200, $response['status'], 'mock-brevo list: ' . $response['body']);
            $decoded = json_decode($response['body'], true);
            $this->assertIsArray($decoded);
            $page = array_values(array_map(
                static fn (array $contact): string => (string) $contact['email'],
                (array) ($decoded['contacts'] ?? [])
            ));
            $emails = [...$emails, ...$page];
            if (count($page) < 100) {
                break;
            }
        }

        return $emails;
    }

    private function mockSeedBlock(string $address): void
    {
        // The account exists after the first list call.
        $this->mockBlockedEmails();
        $response = $this->mockRequest(
            'POST',
            '/mock-status/accounts/' . self::BREVO_KEY . '/blocked',
            ['email' => $address, 'reason' => 'adminBlocked']
        );
        $this->assertSame(201, $response['status'], 'Test setup: seed Brevo block: ' . $response['body']);
        $this->assertContains($address, $this->mockBlockedEmails(), 'Test setup: the seeded block must be on the list');
    }

    private function mockRemoveOwnBlocks(): void
    {
        foreach ($this->mockBlockedEmails() as $address) {
            $this->mockRequest('DELETE', '/v3/smtp/blockedContacts/' . rawurlencode($address));
        }
    }

    // --- Handler subprocess ------------------------------------------------

    /**
     * Run app/admin/index.php as the admin and return the output and the session messages.
     *
     * @param string|null $ownerRequested Value of `owner_requested`, or null to leave the field out
     * @return array{output: string, exitCode: int, errors: list<string>, successes: list<string>}
     */
    private function requestCommand(string $command, ?string $ownerRequested = '1', string $apiHost = self::MOCK_API_HOST): array
    {
        $projectRoot = dirname(__DIR__, 2);
        $page = $projectRoot . '/app/admin/index.php';
        $this->assertFileExists($page);

        $this->exposeDbEnv();

        $fieldsPhp = sprintf(
            '$fields = ["command" => %s, "car_id" => %s, "csrf" => \Token::generate()]; ',
            var_export($command, true),
            var_export((string) $this->carId, true)
        );
        if ($ownerRequested !== null) {
            $fieldsPhp .= sprintf('$fields["owner_requested"] = %s; ', var_export($ownerRequested, true));
        }

        // The page footer prints the session messages and clears them, so this
        // test defines usError() and usSuccess() before UserSpice loads. UserSpice
        // defines both only when they do not exist. A shutdown function prints
        // the recorded messages as base64 JSON, so message text cannot collide
        // with the marker.
        $hookPhp = 'function usError($msg) { $GLOBALS["__flash"]["error"][] = (string) $msg; } ' .
            'function usSuccess($msg) { $GLOBALS["__flash"]["success"][] = (string) $msg; } ' .
            '$GLOBALS["__flash"] = ["error" => [], "success" => []]; ';
        $flashPhp = 'register_shutdown_function(static function (): void { ' .
            'echo "___FLASH:" . base64_encode(json_encode($GLOBALS["__flash"], JSON_INVALID_UTF8_SUBSTITUTE)) . "___END"; });';

        $script = sprintf(
            'require %s; %s ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            // Under CLI, Server::get('PHP_SELF') ignores $_SERVER['PHP_SELF'] and
            // derives the web path from SCRIPT_FILENAME. Set SCRIPT_FILENAME so
            // securePage($php_self) checks the real admin page.
            '$_SERVER["SCRIPT_FILENAME"] = %s; ' .
            '$_SERVER["REQUEST_METHOD"] = "POST"; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '$loggedInUser = new User(); $loggedInUser->find(%d); ' .
            '$reflection = new ReflectionClass($loggedInUser); ' .
            '$prop = $reflection->getProperty("_isLoggedIn"); $prop->setValue($loggedInUser, true); ' .
            'global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser; ' .
            '%s $_POST = $fields; $_REQUEST = $fields; %s ' .
            'require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            $hookPhp,
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export($page, true),
            var_export(dirname($page), true),
            var_export($projectRoot . '/users/init.php', true),
            $this->adminId,
            $fieldsPhp,
            $flashPhp,
            var_export($page, true)
        );

        $command = sprintf(
            'env US_ENVIRONMENT=development BREVO_API_HOST=%s php -d display_errors=stderr -d error_reporting=-1 -r %s 2>&1; echo "___EXIT:$?"',
            escapeshellarg($apiHost),
            escapeshellarg($script)
        );
        $raw = (string) shell_exec($command);
        [$output, $exitMarker] = array_pad(explode('___EXIT:', $raw), 2, '-1');

        $flash = ['error' => [], 'success' => []];
        if (preg_match('/___FLASH:([A-Za-z0-9+\/=]*)___END/', $output, $match) === 1) {
            $decoded = json_decode((string) base64_decode($match[1], true), true);
            if (is_array($decoded)) {
                $flash = $decoded;
            }
        }

        $result = [
            'output' => $output,
            'exitCode' => (int) trim($exitMarker),
            'errors' => array_values(array_map('strval', $flash['error'] ?? [])),
            'successes' => array_values(array_map('strval', $flash['success'] ?? [])),
        ];
        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($output, 0, 800));
        // Not assertNoPhpErrorText(): the page output holds JavaScript with the word TypeError.
        $this->assertDoesNotMatchRegularExpression(
            '/(Fatal error|Parse error|Warning|Notice|Deprecated|Uncaught [\w\\\\]+).*\bon line \d+/s',
            $output,
            'PHP error text in the page output'
        );
        $this->assertNoAddressInLogs();

        return $result;
    }

    private function exposeDbEnv(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            if (!array_key_exists($var, $this->savedEnv)) {
                $this->savedEnv[$var] = getenv($var);
            }
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    /**
     * Run the real suppression import, with the production Brevo client, against mock-brevo.
     *
     * A subprocess, because FakeBrevoSuppressionSyncClient.php declares a stand-in for the SDK page
     * model in this process, and the SDK cannot build its real model under that name.
     *
     * @return array<string, mixed> The summary of the run
     */
    private function runFullBackfillAgainstMock(): array
    {
        $projectRoot = dirname(__DIR__, 2);
        $this->exposeDbEnv();
        $script = sprintf(
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; $_SERVER["REQUEST_METHOD"] = "GET"; chdir(%s); ' .
            'require_once %s; ' .
            '$repo = new \ElanRegistry\Car\CarRepository(dbi()); ' .
            '$summary = (new \ElanRegistry\Cron\BrevoSuppressionSyncJob(dbi(), $repo, ' .
            'new \ElanRegistry\Car\EmailEventMatcher($repo), ' .
            'new \ElanRegistry\Car\EmailEventApplier($repo, new \ElanRegistry\Car\CarVerificationManager($repo)), ' .
            'new \ElanRegistry\Cron\BrevoSuppressionSyncClient(dbi())))->runFullBackfill(); ' .
            'echo "___SUMMARY:" . json_encode($summary) . "___END";',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export($projectRoot . '/app/admin', true),
            var_export($projectRoot . '/users/init.php', true)
        );
        $raw = (string) shell_exec(sprintf(
            'env US_ENVIRONMENT=development BREVO_API_HOST=%s php -d display_errors=stderr -d error_reporting=-1 -r %s 2>&1',
            escapeshellarg(self::MOCK_API_HOST),
            escapeshellarg($script)
        ));
        $this->assertSame(1, preg_match('/___SUMMARY:(\{.*\})___END/s', $raw, $match), 'The import must run to the end: ' . substr($raw, 0, 800));
        $summary = json_decode($match[1], true);
        $this->assertIsArray($summary);
        $this->assertFalse($summary['pollFailed'], 'The poll of mock-brevo must succeed: ' . $match[1]);

        return $summary;
    }

    // --- Assertions and readers -------------------------------------------

    /** No log row since setUp(), from any user, holds this test's token in its note or metadata. */
    private function assertNoAddressInLogs(): void
    {
        $rows = $this->db->query(
            'SELECT id, logtype, lognote FROM logs
              WHERE id > ? AND (LOCATE(?, lognote) > 0 OR LOCATE(?, CONVERT(metadata USING utf8mb4)) > 0)',
            [$this->logIdFloor, $this->token, $this->token]
        )->results();
        $this->assertSame([], $rows, 'No log row may hold an address: ' . json_encode($rows));
    }

    /** @param list<string> $messages */
    private function assertAnyContains(string $needle, array $messages, string $what): void
    {
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail("{$what}: expected a message with '{$needle}', got " . json_encode($messages));
    }

    /**
     * @return list<array{id: int, user_id: int, lognote: string, meta: array<string, mixed>}>
     */
    private function flagChangeRows(): array
    {
        $rows = $this->db->query(
            "SELECT id, user_id, lognote, CONVERT(metadata USING utf8mb4) AS meta FROM logs
              WHERE id > ? AND logtype = ? AND user_id IN (?, ?)
              ORDER BY id",
            [$this->logIdFloor, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $this->adminId, $this->ownerId]
        )->results();

        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) $row->meta, true);
            $this->assertIsArray($meta, 'The flag-change row must hold JSON metadata');
            if ((int) ($meta['owner_id'] ?? 0) === $this->ownerId) {
                $out[] = ['id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'lognote' => (string) $row->lognote, 'meta' => $meta];
            }
        }

        return $out;
    }

    /** @return list<string> The notes of the CarVerification log rows that the admin wrote */
    private function verificationLogNotes(): array
    {
        $rows = $this->db->query(
            'SELECT lognote FROM logs WHERE id > ? AND logtype = ? AND user_id = ? ORDER BY id',
            [$this->logIdFloor, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $this->adminId]
        )->results();

        return array_map(static fn (object $row): string => (string) $row->lognote, $rows);
    }

    private function assertBlockCleared(): void
    {
        $flags = $this->getOwnerEmailFlags($this->ownerId);
        $this->assertSame('0', (string) $flags['email_blocked']);
        $this->assertNull($flags['email_blocked_address']);
        $this->assertNull($flags['email_blocked_at']);
        $this->assertNull($flags['email_blocked_cause']);
    }

    // --- Without owner_requested ------------------------------------------

    /** @return array<string, array{string, string|null}> */
    public static function missingConfirmProvider(): array
    {
        $cases = [];
        foreach (['clear_block', 'clear_opt_out'] as $command) {
            $cases["{$command}, field absent"] = [$command, null];
            $cases["{$command}, field 0"] = [$command, '0'];
            $cases["{$command}, field true"] = [$command, 'true'];
        }

        return $cases;
    }

    #[DataProvider('missingConfirmProvider')]
    public function test_withoutOwnerRequested_refusedWithNoChangeAndNoBrevoCall(string $command, ?string $ownerRequested): void
    {
        $this->seedBlocked($this->ownerEmail);
        $this->setOwnerOptedOut($this->ownerId, true, '2026-01-02 03:04:06');
        $this->mockSeedBlock($this->ownerEmail);
        $before = $this->getOwnerEmailFlags($this->ownerId);

        $result = $this->requestCommand($command, $ownerRequested);

        $this->assertSame([self::ERROR_NOT_REQUESTED], $result['errors']);
        $this->assertSame([], $result['successes']);
        $this->assertSame($before, $this->getOwnerEmailFlags($this->ownerId), 'A refused request must not change the profile');
        $this->assertSame([], $this->flagChangeRows(), 'A refused request writes no flag-change row');
        $this->assertSame([$this->ownerEmail], $this->mockBlockedEmails(), 'A refused request makes no Brevo call');
    }

    /** A form from a page loaded before the deploy still posts the removed command. */
    public function test_removedClearSuppressionCommand_isRefusedWithAMessageAndALogLine(): void
    {
        $this->seedBlocked($this->ownerEmail);
        $this->setOwnerOptedOut($this->ownerId, true, '2026-01-02 03:04:06');
        $this->mockSeedBlock($this->ownerEmail);
        $before = $this->getOwnerEmailFlags($this->ownerId);

        $result = $this->requestCommand('clear_suppression');

        $this->assertSame(['That action is no longer available. Reload the page and try again.'], $result['errors']);
        $this->assertSame([], $result['successes']);
        $this->assertSame($before, $this->getOwnerEmailFlags($this->ownerId), 'A removed command must not change the profile');
        $this->assertSame([], $this->flagChangeRows(), 'A removed command writes no flag-change row');
        $this->assertSame([$this->ownerEmail], $this->mockBlockedEmails(), 'A removed command makes no Brevo call');

        $note = $this->db->query(
            'SELECT lognote FROM logs WHERE id > ? AND logtype = ? AND user_id = ? ORDER BY id DESC',
            [$this->logIdFloor, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, $this->adminId]
        )->first();
        $this->assertNotNull($note, 'The refused command must be logged');
        $this->assertSame('Registry Management: unknown command "clear_suppression" refused', (string) $note->lognote);
    }

    // --- Clear Block: the address rule ------------------------------------

    public function test_clearBlock_matchingAddress_removesBrevoBlockAndClearsTheRegistryFlag(): void
    {
        $other = $this->address('unrelated');
        $this->seedBlocked($this->ownerEmail, 'blocklist');
        $this->setOwnerOptedOut($this->ownerId, true, '2026-01-02 03:04:06');
        $this->mockSeedBlock($this->ownerEmail);
        $this->mockSeedBlock($other);

        $result = $this->requestCommand('clear_block');

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains(self::TEXT_CLEARED, $result['successes'], 'Success message');
        $this->assertSame([$other], $this->mockBlockedEmails(), 'Brevo no longer blocks the address, and only that address');
        $this->assertBlockCleared();
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($this->ownerId)['email_opted_out'], 'Clear Block leaves the opt-out');
        $this->assertCount(1, $this->flagChangeRows());
    }

    public function test_clearBlock_addressNoLongerTheOwners_clearsRegistryOnlyAndKeepsTheBrevoBlock(): void
    {
        $oldAddress = $this->address('old');
        $this->seedBlocked($oldAddress);
        $this->mockSeedBlock($oldAddress);
        $this->mockSeedBlock($this->ownerEmail);

        $result = $this->requestCommand('clear_block');

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains(self::TEXT_MOVED, $result['successes'], 'Success message');
        $this->assertEqualsCanonicalizing([$oldAddress, $this->ownerEmail], $this->mockBlockedEmails(), 'No Brevo call: both blocks stay');
        $this->assertBlockCleared();
        $this->assertCount(1, $this->flagChangeRows());
    }

    public function test_clearBlock_anotherAccountUsesTheAddress_clearsRegistryOnlyAndKeepsTheBrevoBlock(): void
    {
        // Same address under the matching rule: other case.
        $sharer = $this->createTestUser(['email' => strtoupper($this->ownerEmail)], true);
        $this->loggedUserIds[] = $sharer;
        $this->seedBlocked($this->ownerEmail);
        $this->mockSeedBlock($this->ownerEmail);

        $result = $this->requestCommand('clear_block');

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains(self::TEXT_SHARED, $result['successes'], 'Success message');
        $this->assertSame([$this->ownerEmail], $this->mockBlockedEmails(), 'No Brevo call: the block stays');
        $this->assertBlockCleared();
        $this->assertCount(1, $this->flagChangeRows());
    }

    public function test_clearBlock_emptyBlockedAddress_usesTheUsersEmail(): void
    {
        $this->seedBlocked(null);
        $this->mockSeedBlock($this->ownerEmail);

        $result = $this->requestCommand('clear_block');

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains(self::TEXT_CLEARED, $result['successes'], 'Success message');
        $this->assertSame([], $this->mockBlockedEmails(), 'The users.email block is gone from Brevo');
        $this->assertBlockCleared();
    }

    public function test_clearBlock_noAddressAnywhere_clearsTheFlagWithoutABrevoCall(): void
    {
        $this->setUserEmailRaw($this->ownerId, '');
        $this->seedBlocked(null);

        // A call would fail on the dead port and leave the flag set.
        $result = $this->requestCommand('clear_block', '1', self::DEAD_API_HOST);

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains('No address is on record, so Brevo was not called.', $result['successes'], 'Success message');
        $this->assertBlockCleared();
        $this->assertCount(1, $this->flagChangeRows());
        $this->assertSame([], array_filter(
            $this->verificationLogNotes(),
            static fn (string $note): bool => str_contains($note, 'unblock failed')
        ), 'No Brevo call, so no failure line');
    }

    // --- Clear Block: the Brevo answer -------------------------------------

    public function test_clearBlock_brevoAnswers404_isASuccess(): void
    {
        $this->seedBlocked($this->ownerEmail);
        $this->assertSame([], $this->mockBlockedEmails(), 'Test setup: the mock does not block the address');

        $result = $this->requestCommand('clear_block');

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains(self::TEXT_CLEARED, $result['successes'], 'Success message');
        $this->assertBlockCleared();
        $this->assertContains(
            sprintf('Verification: Clear Block Brevo unblock done for owner %d (outcome notBlocked)', $this->ownerId),
            $this->verificationLogNotes(),
            'The Brevo result is logged before the registry write'
        );
    }

    public function test_clearBlock_brevoUnreachable_keepsTheFlagAndLogsOwnerAndReasonWithoutTheAddress(): void
    {
        $this->seedBlocked($this->ownerEmail, 'blocklist');
        $before = $this->getOwnerEmailFlags($this->ownerId);

        $result = $this->requestCommand('clear_block', '1', self::DEAD_API_HOST);

        $this->assertSame([self::ERROR_BREVO_FAILED], $result['errors']);
        $this->assertSame([], $result['successes']);
        $this->assertSame($before, $this->getOwnerEmailFlags($this->ownerId), 'A failed Brevo call leaves the flag set');
        $this->assertSame([], $this->flagChangeRows(), 'A failed call writes no flag-change row');

        $notes = $this->verificationLogNotes();
        $prefix = sprintf('Verification: Clear Block Brevo unblock failed for owner %d (reason transport, HTTP none, detail ', $this->ownerId);
        $this->assertCount(
            1,
            array_filter($notes, static fn (string $note): bool => str_starts_with($note, $prefix)),
            'The owner ID, the reason and the exception summary are logged'
        );
        $this->assertContains(
            sprintf('Verification: Clear Block for owner %d, result brevo_failed (owner_requested=1)', $this->ownerId),
            $notes
        );
        // requestCommand() also checked that no log row holds the token. The Guzzle
        // message of this failure holds the request URL, so a logged message would hold it.
        $this->assertSame(0, (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE id > ? AND (lognote LIKE ? OR lognote LIKE ?)',
            [$this->logIdFloor, '%127.0.0.1%', '%cURL error%']
        )->first()->cnt, 'No exception text reaches a log row');
    }

    public function test_clearBlock_noBrevoKey_showsTheNotSetUpTextAndKeepsTheFlag(): void
    {
        $this->seedBlocked($this->ownerEmail, 'blocklist');
        $this->mockSeedBlock($this->ownerEmail);
        $before = $this->getOwnerEmailFlags($this->ownerId);
        // tearDown restores the key row that setUp saved.
        $this->db->query("UPDATE plg_sendinblue SET `key` = '' WHERE `key` = ?", [self::BREVO_KEY]);
        $this->assertFalse($this->db->error(), 'Test setup: clear the Brevo key: ' . $this->db->errorString());

        $result = $this->requestCommand('clear_block');

        $this->assertSame([self::ERROR_BREVO_NOT_SET_UP], $result['errors']);
        $this->assertSame([], $result['successes']);
        $this->assertSame($before, $this->getOwnerEmailFlags($this->ownerId), 'No key leaves the flag set');
        $this->assertSame([], $this->flagChangeRows(), 'No key writes no flag-change row');
        $this->assertContains($this->ownerEmail, $this->mockBlockedEmails(), 'No call: the Brevo block stays');
        $this->assertContains(
            sprintf('Verification: Clear Block Brevo unblock failed for owner %d (reason no_api_key, HTTP none, detail key_not_set)', $this->ownerId),
            $this->verificationLogNotes()
        );
    }

    // --- Clear Block: the audit row ----------------------------------------

    public function test_clearBlock_flagChangeRow_holdsTheContractAndLatestFlagClearAtReadsIt(): void
    {
        $this->seedBlocked($this->ownerEmail);
        $this->mockSeedBlock($this->ownerEmail);
        $startedAt = time();

        $result = $this->requestCommand('clear_block');

        $this->assertSame([], $result['errors']);
        $rows = $this->flagChangeRows();
        $this->assertCount(1, $rows, 'One flag change writes one row');
        $row = $rows[0];
        $this->assertSame($this->adminId, $row['user_id'], 'The row names the acting admin');
        $meta = $row['meta'];
        $this->assertSame($this->ownerId, $meta['owner_id']);
        $this->assertSame('email_blocked', $meta['flag']);
        $this->assertSame(1, $meta['old']);
        $this->assertSame(0, $meta['new']);
        $this->assertSame('admin', $meta['source']);
        $this->assertSame(1, $meta['owner_requested']);
        $this->assertIsString($meta['cleared_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $meta['cleared_at']);
        $clearedTs = strtotime($meta['cleared_at']);
        $this->assertGreaterThanOrEqual($startedAt - 1, $clearedTs);
        $this->assertLessThanOrEqual(time() + 1, $clearedTs);
        $this->assertEqualsCanonicalizing(
            ['owner_id', 'flag', 'old', 'new', 'source', 'cleared_at', 'owner_requested'],
            array_keys($meta),
            'No other key, so no address'
        );
        $this->assertSame(
            $meta['cleared_at'],
            (new CarRepository($this->db))->latestFlagClearAt($this->ownerId, DeliverabilityFlagLog::FLAG_BLOCKED, DeliverabilityFlagLog::SOURCE_ADMIN)
        );
    }

    public function test_clearBlock_ownerAlsoBounced_showsTheStillBouncedMessageAndKeepsTheBounce(): void
    {
        $this->seedBlocked($this->ownerEmail);
        $this->setOwnerBounced($this->ownerId, $this->ownerEmail);
        $this->mockSeedBlock($this->ownerEmail);

        $result = $this->requestCommand('clear_block');

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains(self::TEXT_CLEARED, $result['successes'], 'Success message');
        $this->assertAnyContains(self::TEXT_STILL_BOUNCED, $result['successes'], 'Bounce notice');
        $this->assertBlockCleared();
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($this->ownerId)['email_bounced'], 'Clear Block leaves the bounce');
    }

    public function test_clearBlock_ownerAlsoBouncedAndOptedOut_showsNoStillBouncedMessage(): void
    {
        $this->seedBlocked($this->ownerEmail);
        $this->setOwnerBounced($this->ownerId, $this->ownerEmail);
        $this->setOwnerOptedOut($this->ownerId, true, '2026-01-02 03:04:06');
        $this->mockSeedBlock($this->ownerEmail);

        $result = $this->requestCommand('clear_block');

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains(self::TEXT_CLEARED, $result['successes'], 'Test setup: the request succeeded');
        // The row shows Clear Opt-out next, so a hint that names Clear Bounced would be false.
        foreach ($result['successes'] as $message) {
            $this->assertStringNotContainsString(self::TEXT_STILL_BOUNCED, $message);
        }
        $this->assertBlockCleared();
        $flags = $this->getOwnerEmailFlags($this->ownerId);
        $this->assertSame('1', (string) $flags['email_bounced'], 'Clear Block leaves the bounce');
        $this->assertSame('1', (string) $flags['email_opted_out'], 'Clear Block leaves the opt-out');
    }

    public function test_clearBlock_ownerNotBounced_showsNoStillBouncedMessage(): void
    {
        $this->seedBlocked($this->ownerEmail);
        $this->mockSeedBlock($this->ownerEmail);

        $result = $this->requestCommand('clear_block');

        foreach ($result['successes'] as $message) {
            $this->assertStringNotContainsString('marked bounced', $message);
        }
        $this->assertNotSame([], $result['successes'], 'Test setup: the request succeeded');
    }

    // --- Clear Block and the suppression import ---------------------------

    private function makeJob(FakeBrevoSuppressionSyncClient $client, ?DateTimeImmutable $now = null): BrevoSuppressionSyncJob
    {
        $repo = new CarRepository($this->db);

        return new BrevoSuppressionSyncJob(
            $this->db,
            $repo,
            new EmailEventMatcher($repo),
            new EmailEventApplier($repo, new CarVerificationManager($repo)),
            $client,
            $now ?? new DateTimeImmutable()
        );
    }

    /**
     * An owner whose date guards pass for an event shortly after now: the address and the join date are old.
     *
     * @return array{0: int, 1: string} The owner ID and the address
     */
    private function createImportOwner(string $label, bool $blocked): array
    {
        $email = $this->address($label);
        $userId = $this->createTestUser(['email' => $email, 'join_date' => '2020-01-01 00:00:00'], true);
        $this->loggedUserIds[] = $userId;
        $this->setEmailAddressSinceSeen($userId, $email, '2020-01-01 00:00:00');
        if ($blocked) {
            $this->setOwnerBlocked($userId, $email, '2026-01-02 03:04:05', 'spam');
        }

        return [$userId, $email];
    }

    /** @return array<string, array{?int, int}> Seconds from cleared_at (null: no blockedAt), and the expected email_blocked */
    public static function importOffsetProvider(): array
    {
        return [
            '60 s before cleared_at' => [-60, 0],
            'at cleared_at' => [0, 0],
            '60 s after cleared_at, before logdate' => [60, 1],
            // #2426: an entry with no blockedAt does not undo the real Clear Block.
            'no blockedAt' => [null, 0],
        ];
    }

    #[DataProvider('importOffsetProvider')]
    public function test_clearBlock_thenImportEntry_setsTheFlagOnlyForAnEntryAfterClearedAt(?int $offsetSeconds, int $expectedBlocked): void
    {
        $this->ownerId = $this->createImportOwner('cleared', true)[0];
        $ownerEmail = (string) $this->db->query('SELECT email FROM users WHERE id = ?', [$this->ownerId])->first()->email;
        $this->carId = $this->createTestCar($this->ownerId, ['email' => $ownerEmail]);
        $this->mockSeedBlock($ownerEmail);

        $result = $this->requestCommand('clear_block');
        $this->assertSame([], $result['errors']);
        $this->assertBlockCleared();

        $rows = $this->flagChangeRows();
        $this->assertCount(1, $rows);
        $clearedAt = (string) $rows[0]['meta']['cleared_at'];
        // logdate is later than cleared_at: an import must compare with cleared_at.
        $this->db->query(
            'UPDATE logs SET logdate = ? WHERE id = ?',
            [date('Y-m-d H:i:s', strtotime($clearedAt) + 120), $rows[0]['id']]
        );
        $this->assertFalse($this->db->error(), 'Test setup: logdate: ' . $this->db->errorString());
        $blockedAt = $offsetSeconds === null ? null : gmdate('Y-m-d\TH:i:s.000\Z', strtotime($clearedAt) + $offsetSeconds);
        // A no-date entry takes the run time. Run 60 s after cleared_at, so
        // only the #2426 guard, not the time compare, keeps the flag clear.
        $runAt = $offsetSeconds === null ? (new DateTimeImmutable())->setTimestamp(strtotime($clearedAt) + 60) : null;

        // Control: an owner with no clear on record takes the same entry.
        [$controlId, $controlEmail] = $this->createImportOwner('control', false);

        $this->makeJob(new FakeBrevoSuppressionSyncClient([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($ownerEmail, 'contactFlaggedAsSpam', null, $blockedAt),
                FakeBrevoBlockedContact::withReason($controlEmail, 'contactFlaggedAsSpam', null, $blockedAt),
            ]),
        ]), $runAt)->runFullBackfill();

        $this->assertSame(1, (int) $this->getOwnerEmailFlags($controlId)['email_blocked'], 'Control: with no clear on record the entry sets the flag');
        $this->assertSame($expectedBlocked, (int) $this->getOwnerEmailFlags($this->ownerId)['email_blocked']);
    }

    public function test_clearBlock_thenFullBackfillAgainstMockBrevo_keepsTheFlagClear(): void
    {
        $this->seedBlocked($this->ownerEmail);
        $this->mockSeedBlock($this->ownerEmail);
        [$otherId, $otherEmail] = $this->createImportOwner('other', false);
        $this->mockSeedBlock($otherEmail);

        $result = $this->requestCommand('clear_block');
        $this->assertSame([], $result['errors']);
        $this->assertBlockCleared();
        $this->assertSame([$otherEmail], $this->mockBlockedEmails());

        $summary = $this->runFullBackfillAgainstMock();

        $this->assertSame(1, $summary['matchedCount'], 'Control: the import read the mock and matched the other owner only');
        $this->assertSame(1, (int) $this->getOwnerEmailFlags($otherId)['email_blocked'], 'Control: the import set the other owner');
        $this->assertBlockCleared();
        $this->assertCount(1, $this->flagChangeRows(), 'The backfill writes no new flag-change row for the cleared owner');
    }

    // --- Clear Opt-out ----------------------------------------------------

    public function test_clearOptOut_changesOnlyTheOptOutColumnsAndMakesNoBrevoCall(): void
    {
        $this->seedBlocked($this->ownerEmail, 'spam');
        $this->setOwnerBounced($this->ownerId, $this->ownerEmail, '2026-01-02 03:04:07');
        $this->setOwnerOptedOut($this->ownerId, true, '2026-01-02 03:04:06');
        $before = $this->getOwnerEmailFlags($this->ownerId);
        $this->mockSeedBlock($this->ownerEmail);

        // A Brevo call would fail on the dead port and show an error.
        $result = $this->requestCommand('clear_opt_out', '1', self::DEAD_API_HOST);

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains('cleared of the opt-out', $result['successes'], 'Success message');
        $after = $this->getOwnerEmailFlags($this->ownerId);
        $this->assertSame('0', (string) $after['email_opted_out']);
        $this->assertNull($after['email_opted_out_at']);
        $expected = $before;
        $expected['email_opted_out'] = $after['email_opted_out'];
        $expected['email_opted_out_at'] = null;
        $this->assertSame($expected, $after, 'Only the opt-out columns change');
        $this->assertSame([$this->ownerEmail], $this->mockBlockedEmails(), 'The Brevo block stays');

        $rows = $this->flagChangeRows();
        $this->assertCount(1, $rows);
        $this->assertSame('email_opted_out', $rows[0]['meta']['flag']);
        $this->assertSame(1, $rows[0]['meta']['old']);
        $this->assertSame(0, $rows[0]['meta']['new']);
        $this->assertSame('admin', $rows[0]['meta']['source']);
        $this->assertSame(1, $rows[0]['meta']['owner_requested']);
        $this->assertArrayNotHasKey('cleared_at', $rows[0]['meta'], 'Only Clear Block records cleared_at');
        $this->assertSame($this->adminId, $rows[0]['user_id']);
    }

    public function test_clearOptOut_ownerNotOptedOut_reportsNoChangeAndWritesNoRow(): void
    {
        $result = $this->requestCommand('clear_opt_out');

        $this->assertSame([], $result['errors']);
        $this->assertAnyContains('no opt-out to clear', $result['successes'], 'Success message');
        $this->assertSame([], $this->flagChangeRows());
    }
}
