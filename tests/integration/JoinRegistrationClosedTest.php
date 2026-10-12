<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\UserscPageClient;

/**
 * usersc/join.php must process a registration POST only when
 * `settings.registration` is 1. A direct POST with a valid CSRF token must not
 * create a user while registration is closed.
 *
 * Uses Tests\Support\UserscPageClient, as LoginFailureLoggingTest does. The
 * token comes from a GET while registration is open. The test then closes
 * registration and sends the POST on the same session. The closed page does
 * not render a token, so there is no other way to get one.
 *
 * @see usersc/join.php
 */
#[Group('integration')]
final class JoinRegistrationClosedTest extends IntegrationTestCase
{
    use UserscPageClient;

    /** A fixed REMOTE_ADDR gives this class its own 'ip' rate-limit bucket. */
    private const TEST_IP = '203.0.113.10';

    private ?int $originalRegistration = null;
    private ?int $originalEmailAct = null;
    private string $email = '';
    /** @var list<string> Tables where this test inserted the id = 1 row that join.php reads. */
    private array $seededTables = [];

    public static function tearDownAfterClass(): void
    {
        self::stopPageServer();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->startPageClient('join.php', self::TEST_IP);

        $row = $this->db->query('SELECT registration FROM settings WHERE id = 1')->first();
        $this->originalRegistration = (int) $row->registration;

        $this->seedRowJoinNeeds('us_password_strength', 'enforce_rules, meter_active', '0, 0');
        // email_act = 0 skips the verification email, so no mail leaves the test.
        // An existing row may have email_act = 1, so set it here and restore it in tearDown().
        $this->seedRowJoinNeeds('email', 'email_act', '0');
        $this->originalEmailAct = (int) $this->db->query('SELECT email_act FROM email WHERE id = 1')->first()->email_act;
        $this->db->query('UPDATE email SET email_act = 0 WHERE id = 1');

        $this->email = 'join-closed-' . uniqid() . '@example.com';
        $this->clearRegistrationRateLimits();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            if ($this->originalRegistration !== null) {
                $this->setRegistration($this->originalRegistration);
            }
            if ($this->originalEmailAct !== null) {
                $this->db->query('UPDATE email SET email_act = ? WHERE id = 1', [$this->originalEmailAct]);
            }
            $this->clearRegistrationRateLimits();
            foreach ($this->seededTables as $table) {
                $this->db->query("DELETE FROM {$table} WHERE id = 1");
            }
            if ($this->email !== '') {
                $this->deleteRegisteredUser();
            }
        }

        $this->stopPageClient();

        parent::tearDown();
    }

    /**
     * join.php reads `us_password_strength` and `email` row id = 1 and fatals on
     * an empty table. A freshly provisioned test schema has neither row, so the
     * test adds the row when it is missing and removes only what it added.
     * $table and $columns are fixed literals from this class.
     */
    private function seedRowJoinNeeds(string $table, string $columns, string $values): void
    {
        $exists = (int) $this->db->query("SELECT COUNT(*) AS cnt FROM {$table} WHERE id = 1")->first()->cnt;
        if ($exists === 0) {
            $this->db->query("INSERT INTO {$table} (id, {$columns}) VALUES (1, {$values})");
            $this->seededTables[] = $table;
        }
    }

    private function setRegistration(int $value): void
    {
        $this->db->query('UPDATE settings SET registration = ? WHERE id = 1', [$value]);
    }

    /** Deletes only this test's IP bucket. identifier_key matches RateLimit::buildIdentifierKey(). */
    private function clearRegistrationRateLimits(): void
    {
        $this->db->query(
            "DELETE FROM us_rate_limits WHERE action LIKE 'registration%' AND identifier_key = ?",
            [hash('sha256', 'ip::' . self::TEST_IP)]
        );
    }

    /**
     * The open-registration case creates a user and, through
     * usersc/scripts/during_user_creation.php, its profile, a remember-me
     * session, an IP-list row, and log rows. Two of those log rows use
     * user_id 0. The test schema has no foreign keys, so nothing cascades.
     */
    private function deleteRegisteredUser(): void
    {
        // first() returns an empty array, not null, when no row matches.
        $row = $this->db->query('SELECT id FROM users WHERE email = ?', [$this->email])->first(true);
        if (!is_array($row) || !isset($row['id'])) {
            return;
        }
        $id = (int) $row['id'];
        foreach (['user_permission_matches', 'profiles', 'users_session', 'us_ip_list', 'logs'] as $table) {
            $this->db->query("DELETE FROM {$table} WHERE user_id = ?", [$id]);
        }
        $this->db->query(
            'DELETE FROM logs WHERE user_id = 0 AND (lognote LIKE ? OR lognote LIKE ?)',
            ["New user {$id}:%", "%user_id={$id}"]
        );
        $this->db->query('DELETE FROM users WHERE id = ?', [$id]);
    }

    private function countUsersWithEmail(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS cnt FROM users WHERE email = ?', [$this->email])->first()->cnt;
    }

    private function countRegistrationLogs(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS cnt FROM logs WHERE lognote LIKE 'join.php: Registration%'")->first()->cnt;
    }

    /** Fresh session while registration is open; returns the session-bound CSRF token. */
    private function fetchCsrfToken(): string
    {
        $this->setRegistration(1);
        $result = $this->request('GET', null);
        $this->assertSame(200, $result['status'], 'GET join.php must succeed to obtain a CSRF token');

        $matched = preg_match('/name="csrf"/', $result['body']) === 1
            ? preg_match('/value="([0-9a-f]{64})"\s+name="csrf"|name="csrf"\s+value="([0-9a-f]{64})"/', $result['body'], $m)
            : 0;
        $this->assertSame(1, $matched, 'Could not find a 64-char hex csrf hidden field on the open join page');

        $token = $m[1] !== '' ? $m[1] : $m[2];
        $this->assertNotSame('', $token);

        return $token;
    }

    /**
     * @return array{status: int, body: string}
     */
    private function postValidRegistration(string $csrf): array
    {
        return $this->request('POST', http_build_query([
            'csrf' => $csrf,
            'username' => 'joinclosed' . substr(uniqid(), -8),
            'fname' => 'Join',
            'lname' => 'Closed',
            'email' => $this->email,
            'password' => 'Str0ng-Passw0rd-xyz',
            'confirm' => 'Str0ng-Passw0rd-xyz',
        ]));
    }

    public function testValidPostWithValidCsrfCreatesNoUserWhileRegistrationIsClosed(): void
    {
        $csrf = $this->fetchCsrfToken();
        $this->setRegistration(0);
        $logsBefore = $this->countRegistrationLogs();

        $result = $this->postValidRegistration($csrf);

        // Without these, a fatal or an early redirect would also leave the user count at 0.
        $this->assertSame(200, $result['status'], 'A closed registration must render the closed page, not redirect');
        // The built-in server may render the lang() key instead of the English text.
        $this->assertMatchesRegularExpression(
            '/JOIN_CLOSED|registration is disabled/',
            $result['body'],
            'A closed registration must render the closed-registration page'
        );
        $this->assertSame(0, $this->countUsersWithEmail(), 'A closed registration must not create a user');
        $this->assertSame(
            $logsBefore,
            $this->countRegistrationLogs(),
            'A closed registration must not reach the CSRF, rate-limit or validation logs'
        );
    }

    public function testValidPostWithValidCsrfCreatesUserWhileRegistrationIsOpen(): void
    {
        $csrf = $this->fetchCsrfToken();

        $this->postValidRegistration($csrf);

        $this->assertSame(
            1,
            $this->countUsersWithEmail(),
            'Control case: the same POST must create a user when registration is open'
        );
    }
}
