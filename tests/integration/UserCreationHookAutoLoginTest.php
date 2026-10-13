<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PassThroughDatabase;

/**
 * usersc/scripts/during_user_creation.php: the automatic sign-in after
 * sign-up (#2378) and the profile insert failure logs (#2393 gap 5).
 *
 * The hook signs the new user in only when `email_act` is not 1. It must
 * not set a remember-me token, because the user did not ask for one.
 *
 * @see https://github.com/elan-registry/registry/issues/2378
 * @see https://github.com/elan-registry/registry/issues/2393
 */
#[Group('integration')]
final class UserCreationHookAutoLoginTest extends IntegrationTestCase
{
    private ?int $originalEmailAct = null;
    private bool $seededEmailRow = false;

    /** @var list<int> */
    private array $hookedUserIds = [];

    /** @var array<string, mixed> */
    private array $savedPost = [];

    /** @var array<string, mixed>|null */
    private ?array $savedSession = null;

    /** @var list<array<string, mixed>> */
    private array $savedIpRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->hookedUserIds = [];
        $this->savedPost = $_POST;
        $this->savedSession = $_SESSION ?? null;
        // A sign-in writes the us_ip_list row for this IP: it updates the row
        // if one exists, or inserts one. Save the rows so that tearDown() can
        // restore them.
        $this->savedIpRows = $this->db->query('SELECT * FROM us_ip_list WHERE ip = ?', [ipCheck()])->results(true);

        // A failed read here would make tearDown() delete the email settings
        // row or write 0 back, so stop the test instead.
        $countRow = $this->db->query('SELECT COUNT(*) AS cnt FROM email WHERE id = 1')->first();
        $this->assertIsObject($countRow, 'Cannot read the email settings row: ' . $this->db->errorString());
        if ((int) $countRow->cnt === 0) {
            $this->assertFalse(
                $this->db->query('INSERT INTO email (id, email_act) VALUES (1, 1)')->error(),
                'Cannot seed the email settings row: ' . $this->db->errorString()
            );
            $this->seededEmailRow = true;
        } else {
            $emailRow = $this->db->query('SELECT email_act FROM email WHERE id = 1')->first();
            $this->assertIsObject($emailRow, 'Cannot read email_act: ' . $this->db->errorString());
            $this->originalEmailAct = (int) $emailRow->email_act;
        }
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }

        if ($this->databaseConnected) {
            if ($this->seededEmailRow) {
                $this->db->query('DELETE FROM email WHERE id = 1');
            } elseif ($this->originalEmailAct !== null) {
                $this->db->query('UPDATE email SET email_act = ? WHERE id = 1', [$this->originalEmailAct]);
            }
            $this->db->query('DELETE FROM us_ip_list WHERE ip = ?', [ipCheck()]);
            foreach ($this->savedIpRows as $row) {
                $this->db->insert('us_ip_list', $row);
            }
            foreach ($this->hookedUserIds as $userId) {
                $this->db->query('DELETE FROM us_ip_list WHERE user_id = ?', [$userId]);
                // profiles.user_id has no foreign key, so a hook run for a
                // user id with no users row can leave an orphan profile.
                $this->db->query('DELETE FROM profiles WHERE user_id = ?', [$userId]);
                $this->db->query('DELETE FROM users_session WHERE user_id = ?', [$userId]);
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
                $this->db->query('DELETE FROM logs WHERE lognote LIKE ?', ["New user {$userId}: no coordinates%"]);
                // Match the id with its delimiter, so user_id=12 does not
                // also delete the rows of user_id=123.
                $this->db->query(
                    'DELETE FROM logs WHERE logtype = ? AND (lognote LIKE ? OR lognote LIKE ? OR lognote LIKE ?)',
                    [
                        LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                        "%user_id={$userId}",
                        "%user_id={$userId};%",
                        "%user_id={$userId}:%",
                    ]
                );
            }
        }
        parent::tearDown();
    }

    private function setEmailAct(int $value): void
    {
        $this->db->query('UPDATE email SET email_act = ? WHERE id = 1', [$value]);
    }

    /**
     * Run the hook the way join.php runs it: in process, after the users row exists.
     *
     * @param DatabaseInterface|null $db A stand-in for UserSpice's DB, or null for the real one.
     */
    private function runUserCreationHook(int $theNewId, ?DatabaseInterface $db = null): void
    {
        $this->hookedUserIds[] = $theNewId;
        $db = $db ?? DB::getInstance();
        $hookPath = dirname(__DIR__, 2) . '/usersc/scripts/during_user_creation.php';
        $this->assertFileExists($hookPath);

        include $hookPath;
    }

    private function rememberTokenCount(int $userId): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS cnt FROM users_session WHERE user_id = ?', [$userId])->first()->cnt;
    }

    public function testAutoLoginAfterSignUpSetsNoRememberMeToken(): void
    {
        $email = 'auto-login-' . uniqid() . '@example.com';
        $userId = $this->createTestUser(['email' => $email]);
        $this->setEmailAct(0);
        $_POST['email'] = $email;
        $_POST['password'] = 'testpass123';

        // A failed sign-in in the hook calls Redirect::to(), which exits, so
        // tearDown() does not run. Prove the credentials first, so that a bad
        // fixture fails here as an assertion instead.
        $this->assertTrue((new User())->loginEmail($email, 'testpass123', false, null, false), 'The test user must be able to sign in');
        unset($_SESSION[Config::get('session/session_name')]);

        $this->runUserCreationHook($userId);

        $this->assertSame(
            $userId,
            (int) ($_SESSION[Config::get('session/session_name')] ?? 0),
            'With email_act = 0 the hook signs the new user in'
        );
        $this->assertSame(0, $this->rememberTokenCount($userId), 'The sign-up sign-in must not write a users_session row');
    }

    /**
     * The fix must change only the argument, not loginEmail() itself: a true
     * $remember still writes a token. No current page passes true
     * (usersc/login.php sets $remember = false), so the test calls the method.
     */
    public function testLoginEmailWithRememberTrueStillWritesAToken(): void
    {
        $email = 'remember-me-' . uniqid() . '@example.com';
        $userId = $this->createTestUser(['email' => $email]);
        $this->hookedUserIds[] = $userId;

        // users_session stores the browser's user agent. The CLI sends none.
        $savedUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (PHPUnit)';

        // Cookie::put() calls setcookie() after PHPUnit output starts, which
        // warns "headers already sent". The users_session row is the evidence.
        // Pass any other warning to the previous handler (PHPUnit's), because
        // a false return goes to PHP's built-in handler instead.
        $previous = null;
        $previous = set_error_handler(
            static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$previous): bool {
                if ($errno === E_WARNING && str_contains($errstr, 'headers already sent')) {
                    return true;
                }
                return $previous !== null && (bool) $previous($errno, $errstr, $errfile, $errline);
            }
        );
        try {
            $login = (new User())->loginEmail($email, 'testpass123', true);
        } finally {
            restore_error_handler();
            if ($savedUserAgent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $savedUserAgent;
            }
        }

        $this->assertTrue($login);
        $this->assertSame(1, $this->rememberTokenCount($userId));
    }

    public function testMissingUsersRowLogsAnErrorAndCreatesNoProfile(): void
    {
        $this->setEmailAct(1);
        // Above every users id and every profiles user_id, so the hook takes
        // its no-profile branch and the users read finds no row.
        $missingId = (int) $this->db->query(
            'SELECT GREATEST(COALESCE(MAX(id), 0), (SELECT COALESCE(MAX(user_id), 0) FROM profiles)) + 100000 AS id FROM users'
        )->first()->id;

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING | E_NOTICE | E_DEPRECATED);
        try {
            $this->runUserCreationHook($missingId);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'The hook must check the users read, not read properties on null');
        $this->assertSame(1, $this->countMatchingLogs(LogCategories::LOG_CATEGORY_DATABASE_ERROR, "%user_id={$missingId};%"));
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$missingId])->first()->cnt
        );
    }

    public function testFailedProfileInsertLogsAnError(): void
    {
        $this->setEmailAct(1);
        $userId = $this->createTestUser();
        $failingDb = new class ($this->db) extends PassThroughDatabase {
            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                if ($table !== 'profiles') {
                    return parent::insert($table, $fields, $update);
                }
                $this->simulateFailure('simulated profiles insert failure');
                return false;
            }
        };

        $this->runUserCreationHook($userId, $failingDb);

        $this->assertSame(1, $this->countMatchingLogs(LogCategories::LOG_CATEGORY_DATABASE_ERROR, "%user_id={$userId}:%"));
        $note = (string) $this->db->query(
            'SELECT lognote FROM logs WHERE logtype = ? AND lognote LIKE ?',
            [LogCategories::LOG_CATEGORY_DATABASE_ERROR, "%user_id={$userId}:%"]
        )->first()->lognote;
        $this->assertStringContainsString('simulated profiles insert failure', $note);
    }
}
