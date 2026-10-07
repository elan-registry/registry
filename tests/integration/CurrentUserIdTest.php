<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #1599: the real session-coupled currentUserId(). The unit bootstrap has only a stand-in.
 */
#[Group('integration')]
final class CurrentUserIdTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * A silent wrong ID (e.g. 0) is worse than a loud failure.
     */
    public function test_currentUserId_noSession_throwsRuntimeException(): void
    {
        global $user;
        $previousUser = $GLOBALS['user'] ?? null;
        $GLOBALS['user'] = null;
        $user = null;

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No user is currently logged in');
            currentUserId();
        } finally {
            $GLOBALS['user'] = $previousUser;
            $user = $previousUser;
        }
    }

    /**
     * The !isLoggedIn() branch is the one that fires in production; init.php always sets $user.
     */
    public function test_currentUserId_userPresentButNotLoggedIn_throws(): void
    {
        global $user;
        $previousUser = $GLOBALS['user'] ?? null;
        $user = new User();
        $GLOBALS['user'] = $user;

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No user is currently logged in');
            currentUserId();
        } finally {
            $GLOBALS['user'] = $previousUser;
            $user = $previousUser;
        }
    }

    /**
     * UserSpice returns data()->id as a string; the function must cast it.
     */
    public function test_currentUserId_loggedIn_returnsUserId(): void
    {
        $userId = $this->createTestUser();
        $this->loginAsTestUser($userId);

        $this->assertSame($userId, currentUserId());
    }
}
