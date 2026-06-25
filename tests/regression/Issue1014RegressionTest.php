<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #1014: validate to_user_id matches car owner in send-owner-email.php
 *
 * Ensures that the IDOR guard cannot be accidentally removed or restructured in future
 * refactors. Without the ownership check, any authenticated user could send an unsolicited
 * contact email to any registered user by crafting a POST with an arbitrary to_user_id.
 *
 * @issue 1014
 * @link https://github.com/unibrain1/elanregistry/issues/1014
 * @category regression
 *
 * Root cause: send-owner-email.php accepted to_user_id from POST without verifying that
 * the supplied user owns the car referenced by car_id. The car_id hidden field was only
 * consumed for the post-send redirect, never for authorization.
 *
 * Fix: after reading to_user_id and car_id, query cars.user_id for the supplied car_id
 * and reject (with LOG_CATEGORY_ACCESS_DENIED logging) if it does not match to_user_id.
 * Added a prior positive-integer guard that logs and rejects zero or negative IDs.
 * Added a DB-error guard that logs LOG_CATEGORY_DATABASE_ERROR before the ownership
 * comparison to prevent DB failures from being misclassified as IDOR attempts.
 */
final class Issue1014RegressionTest extends TestCase
{
    /** @var string Absolute path to the project root */
    private string $projectRoot;

    /** @var string Absolute path to the target file */
    private string $targetFile;

    protected function setUp(): void
    {
        // tests/regression/ is two levels below the project root
        $this->projectRoot = dirname(__DIR__, 2);
        $this->targetFile  = $this->projectRoot . '/app/contact/send-owner-email.php';
    }

    /**
     * The ownership query must exist in send-owner-email.php.
     *
     * If removed, the IDOR vulnerability (#1014) is reintroduced: any authenticated
     * user can send email to an arbitrary registered user.
     */
    public function testOwnershipQueryExists(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);

        $this->assertStringContainsString(
            'SELECT user_id FROM cars WHERE id = ?',
            $content,
            'send-owner-email.php must query cars.user_id to verify the recipient owns the car (#1014)'
        );
    }

    /**
     * The ownership query must execute before the user data lookup.
     *
     * Guards against a structural regression where the ownership check is moved after
     * the user lookup — an attacker with a valid to_user_id that does not own the car
     * would still receive the email if the check runs too late.
     */
    public function testOwnershipCheckPrecedesUserLookup(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);

        $ownershipPos  = strpos($content, 'SELECT user_id FROM cars WHERE id = ?');
        $userLookupPos = strpos($content, 'SELECT id, email, fname, lname FROM users WHERE id = ?');

        $this->assertNotFalse($ownershipPos,  'Ownership query string not found in send-owner-email.php');
        $this->assertNotFalse($userLookupPos, 'User lookup query string not found in send-owner-email.php');
        $this->assertLessThan(
            $userLookupPos,
            $ownershipPos,
            'Ownership check must execute before user data lookup (#1014)'
        );
    }

    /**
     * LOG_CATEGORY_ACCESS_DENIED must be referenced for the ownership mismatch path.
     *
     * Guards the audit trail: removing the constant reference silently removes the
     * access-denied log entry that records IDOR attempts.
     */
    public function testAccessDeniedIsLoggedOnOwnerMismatch(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);

        $this->assertStringContainsString(
            'LOG_CATEGORY_ACCESS_DENIED',
            $content,
            'send-owner-email.php must log LOG_CATEGORY_ACCESS_DENIED when to_user_id does not match car owner (#1014)'
        );
    }

    /**
     * A positive-integer guard must reject zero or negative IDs before the DB query.
     *
     * Guards the defense-in-depth boundary check: a missing or zero car_id must be
     * rejected before the ownership query runs, preventing reliance on the schema
     * accident of no car with id=0.
     */
    public function testZeroOrNegativeIdGuardExists(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);

        $this->assertMatchesRegularExpression(
            '/\$toUserId\s*<=\s*0\s*\|\|\s*\$carId\s*<=\s*0/',
            $content,
            'send-owner-email.php must reject zero/negative IDs before the ownership query (#1014)'
        );
    }

    /**
     * A DB error check must precede the ownership comparison.
     *
     * Guards against DB failures being silently misclassified as IDOR access violations:
     * if the query errors, the file must log LOG_CATEGORY_DATABASE_ERROR (not ACCESS_DENIED)
     * and exit before reaching the ownership comparison.
     */
    public function testDbErrorCheckPrecedesOwnershipComparison(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);

        $this->assertStringContainsString(
            'LOG_CATEGORY_DATABASE_ERROR',
            $content,
            'send-owner-email.php must check for DB errors before the ownership comparison (#1014)'
        );

        $dbErrorPos    = strpos($content, 'LOG_CATEGORY_DATABASE_ERROR');
        $accessDeniedPos = strrpos($content, 'LOG_CATEGORY_ACCESS_DENIED');

        $this->assertNotFalse($dbErrorPos,     'LOG_CATEGORY_DATABASE_ERROR not found');
        $this->assertNotFalse($accessDeniedPos, 'LOG_CATEGORY_ACCESS_DENIED not found');
        $this->assertLessThan(
            $accessDeniedPos,
            $dbErrorPos,
            'DB error log must appear before the ownership-mismatch ACCESS_DENIED log (#1014)'
        );
    }
}
