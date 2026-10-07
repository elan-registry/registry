<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Issue #1499: stored XSS through the admin User Manager column renderer.
 */
#[Group('security')]
#[Group('user-manager-columns')]
class UserManagerColumnsXssTest extends TestCase
{
    /**
     * The parameters become variables that the closure captures from this scope.
     *
     * @return callable(object, string): (string|null)
     */
    private function loadColumnDataClosure(int $uCount = 0, int $maxUsers = 100, int $act = 0): callable
    {
        $file = dirname(__DIR__, 3) . '/usersc/includes/user_manager_columns.php';

        $this->assertFileExists($file, 'user_manager_columns.php is missing — XSS escaping cannot be verified');

        // require, not require_once — each test needs the closure redefined in its own scope.
        require $file;

        /** @var callable(object, string): (string|null) $user_manager_column_data */
        return $user_manager_column_data;
    }

    public function testEmailColumnIsEscaped(): void
    {
        $columnData = $this->loadColumnDataClosure();

        $user = (object) ['email' => '<script>alert(1)</script>'];
        $result = $columnData($user, 'email');

        $this->assertSame(
            htmlspecialchars('<script>alert(1)</script>', ENT_QUOTES, 'UTF-8'),
            $result
        );
        $this->assertStringNotContainsString('<script>', (string) $result);
    }

    public function testUsernameColumnIsEscaped(): void
    {
        $columnData = $this->loadColumnDataClosure();

        $user = (object) ['username' => '<script>alert(1)</script>'];
        $result = $columnData($user, 'username');

        $this->assertSame(
            htmlspecialchars('<script>alert(1)</script>', ENT_QUOTES, 'UTF-8'),
            $result
        );
        $this->assertStringNotContainsString('<script>', (string) $result);
    }

    public function testPermsColumnIsEscaped(): void
    {
        $columnData = $this->loadColumnDataClosure(uCount: 0, maxUsers: 100);

        $user = (object) ['perms' => '<img src=x onerror=alert(1)>'];
        $result = $columnData($user, 'perms');

        $this->assertSame(
            htmlspecialchars('<img src=x onerror=alert(1)>', ENT_QUOTES, 'UTF-8'),
            $result
        );
        $this->assertStringNotContainsString('<img', (string) $result);
    }

    public function testPermsColumnHandlesNullWithoutError(): void
    {
        $columnData = $this->loadColumnDataClosure(uCount: 0, maxUsers: 100);

        $user = (object) ['perms' => null];
        $result = $columnData($user, 'perms');

        $this->assertSame('', $result);
    }

    public function testPermsColumnReturnsNullWhenUserCountExceedsMax(): void
    {
        $columnData = $this->loadColumnDataClosure(uCount: 500, maxUsers: 100);

        $user = (object) ['perms' => 'Admin'];
        $result = $columnData($user, 'perms');

        $this->assertNull($result);
    }

    public function testDefaultColumnHandlesNullWithoutError(): void
    {
        $columnData = $this->loadColumnDataClosure();

        $user = (object) ['email' => null];
        $result = $columnData($user, 'email');

        $this->assertSame('', $result);
    }

    public function testDefaultColumnHandlesNonStringValue(): void
    {
        $columnData = $this->loadColumnDataClosure();

        // A custom column added per the file's CUSTOMIZATION EXAMPLES can be non-string.
        $user = (object) ['phone' => 5551234];
        $result = $columnData($user, 'phone');

        $this->assertSame('5551234', $result);
    }
}
