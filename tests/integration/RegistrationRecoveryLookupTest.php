<?php

declare(strict_types=1);

use ElanRegistry\RegistrationRecoveryNotifier;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1442: join.php's `new \User($email, 'forceEmail')` must match users.email
 * only, never a username. The unit test uses a stand-in User, so it cannot
 * catch a wiring regression.
 */
#[Group('database')]
final class RegistrationRecoveryLookupTest extends IntegrationTestCase
{
    public function testForceEmailLookupIgnoresUsernameCollision(): void
    {
        $this->requireDatabase();

        $collidingUsername = 'collide_' . uniqid('', true);
        $this->createTestUser([
            'username' => $collidingUsername,
            'email' => 'owner_' . uniqid('', true) . '@example.com',
        ]);

        $submittedEmail = 'attacker_' . uniqid('', true) . '@example.com';
        $fuser = new User($submittedEmail, 'forceEmail');

        $this->assertFalse(
            $fuser->exists(),
            'forceEmail lookup must not match a user by username — a username-only collision '
                . '(#1442) must resolve to "no account exists" for the submitted email'
        );

        $notifier = new RegistrationRecoveryNotifier($this->db);
        $result = $notifier->notifyIfAccountExists($fuser, $submittedEmail, (object) ['reset_vericode_expiry' => 60]);

        $this->assertFalse($result, 'No recovery notification should be sent for a username-only collision');
    }

    public function testForceEmailLookupMatchesRealAccountByEmail(): void
    {
        $this->requireDatabase();

        $email = 'existing_' . uniqid('', true) . '@example.com';
        $userId = $this->createTestUser(['email' => $email]);

        $fuser = new User($email, 'forceEmail');

        $this->assertTrue($fuser->exists(), 'forceEmail lookup must match an existing account by its email column');
        $this->assertSame($userId, (int) $fuser->data()->id);
    }
}
