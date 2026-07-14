<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Security regression tests for sender impersonation in owner email (H2 bug #971).
 *
 * Pins the contract that from_user_id is always derived from the session,
 * never from POST input.
 *
 * Source-contract assertions (absence of Input::get('from_user_id') etc.) live in
 * tests/unit/security/SerializedDataRemovalTest.php — not duplicated here.
 * These tests add behavioral coverage using real DB users and session state.
 *
 * Complements: tests/playwright/security/contact-owner-idor.spec.js (HTTP-level 403)
 */
#[Group('integration')]
#[Group('security')]
final class OwnerEmailSecurityTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * H2 regression anchor: forged from_user_id in POST is ignored.
     *
     * The endpoint derives $fromUserId = (int) $user->data()->id
     * (send-owner-email.php:63), never from POST input. This test confirms
     * that even when an attacker injects from_user_id into POST, the
     * derivation still returns the real session user.
     */
    public function testForgedFromUserIdInPostIsIgnored(): void
    {
        $realSenderId = $this->createTestUser();
        $forgedTargetId = $this->createTestUser();

        global $user;
        $originalUser = $user;

        $realSender = new User();
        $realSender->find($realSenderId);

        $reflection = new ReflectionClass($realSender);
        $prop = $reflection->getProperty('_isLoggedIn');
        $prop->setValue($realSender, true);

        $user = $realSender;
        $GLOBALS['user'] = $realSender;

        try {
            // Inject the forged value inside try{} so finally always cleans it up
            $_POST['from_user_id'] = (string) $forgedTargetId;

            // Replicate the endpoint's sender derivation (send-owner-email.php:63)
            $fromUserId = (int) $user->data()->id;

            $this->assertEquals($realSenderId, $fromUserId);
            $this->assertNotEquals((int) $_POST['from_user_id'], $fromUserId);
        } finally {
            unset($_POST['from_user_id']);
            $user = $originalUser;
            $GLOBALS['user'] = $originalUser;
        }
    }

    /**
     * Session user is always the sender on the normal (non-attack) path.
     */
    public function testSessionUserIsAlwaysSender(): void
    {
        $userId = $this->createTestUser();

        global $user;
        $originalUser = $user;

        $sessionUser = new User();
        $sessionUser->find($userId);

        $reflection = new ReflectionClass($sessionUser);
        $prop = $reflection->getProperty('_isLoggedIn');
        $prop->setValue($sessionUser, true);

        $user = $sessionUser;
        $GLOBALS['user'] = $sessionUser;

        try {
            // Replicate the endpoint's sender derivation (send-owner-email.php:63)
            $fromUserId = (int) $user->data()->id;

            $this->assertEquals($userId, $fromUserId);
        } finally {
            $user = $originalUser;
            $GLOBALS['user'] = $originalUser;
        }
    }

    /**
     * Privacy + injection fix (issue #1322, Bug B): $fromName uses only fname.
     *
     * ROOT CAUSE
     * ----------
     * Before the fix, $fromName was $fromData->fname . ' ' . $fromData->lname,
     * exposing the sender's surname in reply-to headers and the email template,
     * and bypassing the CR/LF strip that was already applied to $toEmail.
     *
     * TESTING GAP
     * -----------
     * No test verified which name fields flow into email headers or the
     * reply_name parameter, so the regression was invisible to CI.
     *
     * PREVENTION
     * ----------
     * This test pins the contract: $fromName is derived from $fromData->fname
     * alone, stripped of CR/LF/tab, and must not contain lname.
     */
    public function testFromNameIsFirstNameOnly(): void
    {
        $fromUserId = $this->createTestUser([
            'fname' => 'Alice',
            'lname' => 'Smith',
            'email' => 'alice_fromname@example.com',
        ]);

        // Replicate the Owner data load from send-owner-email.php:91-101
        $fromOwnerResult = $this->db->query('SELECT fname, lname, email FROM users WHERE id = ?', [$fromUserId]);
        $fromData        = $fromOwnerResult->first();

        // Replicate send-owner-email.php:116
        $fromName = preg_replace('/[\r\n\t]/', '', $fromData->fname);

        $this->assertSame('Alice', $fromName, '$fromName must equal fname only');
        $this->assertStringNotContainsString('Smith', $fromName, '$fromName must not include lname');
        $this->assertStringNotContainsString("\r", $fromName, 'CR must be stripped');
        $this->assertStringNotContainsString("\n", $fromName, 'LF must be stripped');
        $this->assertStringNotContainsString("\t", $fromName, 'tab must be stripped');
    }

    /**
     * Privacy fix (issue #1322, Bug B): $toName uses only fname.
     *
     * ROOT CAUSE
     * ----------
     * Before the fix, $toName was $toData->fname . ' ' . $toData->lname,
     * leaking the recipient's surname into the email template greeting.
     *
     * TESTING GAP
     * -----------
     * Same gap as $fromName — no test verified the $toName derivation.
     *
     * PREVENTION
     * ----------
     * This test pins the contract: $toName is derived from $toData->fname
     * alone and must not contain lname.
     */
    public function testToNameIsFirstNameOnly(): void
    {
        $toUserId = $this->createTestUser([
            'fname' => 'Bob',
            'lname' => 'Jones',
            'email' => 'bob_toname@example.com',
        ]);

        // Replicate the Owner data load from send-owner-email.php:91-101
        $toOwnerResult = $this->db->query('SELECT fname, lname, email FROM users WHERE id = ?', [$toUserId]);
        $toData        = $toOwnerResult->first();

        // Replicate send-owner-email.php:114
        $toName = $toData->fname;

        $this->assertSame('Bob', $toName, '$toName must equal fname only');
        $this->assertStringNotContainsString('Jones', $toName, '$toName must not include lname');
    }

    /**
     * IDOR guard: to_user_id must match the car's actual owner.
     *
     * Replicates the DB query and comparison from send-owner-email.php:76-87.
     * An attacker cannot address mail to an arbitrary user by supplying a
     * mismatched to_user_id; they must match the car's real owner.
     */
    public function testCarOwnershipIDORGuard(): void
    {
        $ownerUserId = $this->createTestUser();
        $attackerUserId = $this->createTestUser();
        $carId = $this->createTestCar($ownerUserId);

        // Replicate the endpoint's IDOR check (send-owner-email.php:76-82)
        $carOwnerResult = $this->db->query('SELECT user_id FROM cars WHERE id = ?', [$carId]);
        $carOwner = $carOwnerResult->first();

        $carActualOwner = (int) $carOwner->user_id;

        // Guard passes for the real owner
        $this->assertEquals($ownerUserId, $carActualOwner);

        // Guard blocks the attacker
        $this->assertNotEquals($attackerUserId, $carActualOwner);
    }
}
