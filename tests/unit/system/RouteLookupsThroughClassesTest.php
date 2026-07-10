<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression tests for Issue #962: three action files must route car/user
 * lookups through the Car and Owner domain classes rather than issuing raw
 * SELECTs against the cars/users tables.
 *
 * These are source-inspection tests — the files call ApiResponse::send()
 * (which exit()s) so they cannot be include()d in a unit test. Guarding the
 * source code shape catches accidental regressions to raw SQL.
 */
#[Group('system')]
#[Group('refactor')]
final class RouteLookupsThroughClassesTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 3);
    }

    /**
     * process-car-details.php: car lookup goes through Car, not raw SQL.
     */
    public function testProcessCarDetailsUsesCarClass(): void
    {
        $content = (string) file_get_contents($this->rootDir . '/app/admin/includes/process-car-details.php');

        $this->assertStringContainsString('new Car(', $content,
            'process-car-details.php must instantiate the Car class for the lookup (#962)');
        $this->assertStringNotContainsString('FROM cars', $content,
            'process-car-details.php must not query the cars table directly (#962)');
    }

    /**
     * send-owner-email.php: Owner class handles user lookups; Car class handles
     * the template-context car lookup.
     *
     * Note: the IDOR ownership check at the top of the file is intentionally left
     * as raw SQL (`SELECT user_id FROM cars WHERE id = ?`) to preserve the
     * DB-error-vs-access-denial distinction guarded by Issue1014RegressionTest.
     * That single raw query is not asserted-against here.
     */
    public function testSendOwnerEmailUsesDomainClasses(): void
    {
        $content = (string) file_get_contents($this->rootDir . '/app/api/contact/send-owner-email.php');

        $this->assertStringContainsString('new Owner(', $content,
            'send-owner-email.php must instantiate Owner for sender/recipient lookups (#962)');
        $this->assertStringContainsString('new Car(', $content,
            'send-owner-email.php must instantiate Car for the template-context car lookup (#962)');
        $this->assertStringNotContainsString('FROM users', $content,
            'send-owner-email.php must not query the users table directly (#962)');
        // Only the #1014 IDOR check may query cars directly. Any other cars SELECT
        // (e.g. the old chassis/year lookup) must go through Car.
        $carsSelectCount = substr_count($content, 'FROM cars');
        $this->assertSame(1, $carsSelectCount,
            'send-owner-email.php should contain exactly one raw cars SELECT — the #1014 IDOR guard (#962)');
    }

    /**
     * process-admin-contact.php: admin, owner, and car lookups all go
     * through the domain classes.
     */
    public function testProcessAdminContactUsesDomainClasses(): void
    {
        $content = (string) file_get_contents($this->rootDir . '/app/admin/includes/process-admin-contact.php');

        $this->assertStringContainsString('new Owner(', $content,
            'process-admin-contact.php must instantiate Owner for admin/owner lookups (#962)');
        $this->assertStringContainsString('new Car(', $content,
            'process-admin-contact.php must instantiate Car for the car context lookup (#962)');
        $this->assertStringNotContainsString('FROM users', $content,
            'process-admin-contact.php must not query the users table directly (#962)');
        $this->assertStringNotContainsString('FROM cars', $content,
            'process-admin-contact.php must not query the cars table directly (#962)');
    }

    // -----------------------------------------------------------------------
    // Issue #1148 regression guards — four additional callsites migrated
    // from getUserWithProfile() to new Owner(...)->data()
    // -----------------------------------------------------------------------

    /**
     * process-transfer-approve.php: target user lookup goes through Owner.
     */
    public function testProcessTransferApproveUsesOwnerClass(): void
    {
        $content = (string) file_get_contents($this->rootDir . '/app/admin/includes/process-transfer-approve.php');

        $this->assertStringContainsString('new Owner(', $content,
            'process-transfer-approve.php must instantiate Owner for user lookups (#1148)');
        $this->assertStringNotContainsString('getUserWithProfile', $content,
            'process-transfer-approve.php must not call deleted getUserWithProfile() (#1148)');
    }

    /**
     * process-user-details.php: user lookup goes through Owner.
     */
    public function testProcessUserDetailsUsesOwnerClass(): void
    {
        $content = (string) file_get_contents($this->rootDir . '/app/admin/includes/process-user-details.php');

        $this->assertStringContainsString('new Owner(', $content,
            'process-user-details.php must instantiate Owner for user lookups (#1148)');
        $this->assertStringNotContainsString('getUserWithProfile', $content,
            'process-user-details.php must not call deleted getUserWithProfile() (#1148)');
    }

    /**
     * app/admin/index.php: transfer target user lookup goes through Owner.
     */
    public function testAdminIndexUsesOwnerClass(): void
    {
        $content = (string) file_get_contents($this->rootDir . '/app/admin/index.php');

        $this->assertStringContainsString('new Owner(', $content,
            'app/admin/index.php must instantiate Owner for user lookups (#1148)');
        $this->assertStringNotContainsString('getUserWithProfile', $content,
            'app/admin/index.php must not call deleted getUserWithProfile() (#1148)');
    }

    /**
     * CarAdministrationService: new-owner lookup goes through Owner.
     */
    public function testCarAdministrationServiceUsesOwnerClass(): void
    {
        $content = (string) file_get_contents($this->rootDir . '/usersc/classes/Car/CarAdministrationService.php');

        $this->assertStringContainsString('new Owner(', $content,
            'CarAdministrationService must instantiate Owner for user lookups (#1148)');
        $this->assertStringNotContainsString('getUserWithProfile', $content,
            'CarAdministrationService must not call deleted getUserWithProfile() (#1148)');
    }
}
