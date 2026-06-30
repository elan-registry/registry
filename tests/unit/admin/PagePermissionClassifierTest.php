<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for PagePermissionClassifier
 *
 * Covers the four classification methods that determine which permission tier
 * a page path belongs to. These classifications control the Fix Page Permissions
 * maintenance script (issues #795 and #797).
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class PagePermissionClassifierTest extends TestCase
{
    // -------------------------------------------------------------------------
    // shouldBePrivateNoPermissions
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function specialNoPermsProvider(): array
    {
        return [
            'join page'                        => ['usersc/join.php',          true],
            'login page'                       => ['usersc/login.php',         true],
            // #795: user_settings must NOT be in this list
            'user_settings is not special'     => ['usersc/user_settings.php', false],
            'other usersc page'                => ['usersc/profile.php',       false],
            'admin page'                       => ['app/admin/manage.php',     false],
            'public car listing'               => ['app/cars/index.php',       false],
        ];
    }

    #[DataProvider('specialNoPermsProvider')]
    public function testShouldBePrivateNoPermissions(string $path, bool $expected): void
    {
        $this->assertSame($expected, PagePermissionClassifier::shouldBePrivateNoPermissions($path));
    }

    // -------------------------------------------------------------------------
    // shouldHaveAdminPermissions
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function adminPermissionsProvider(): array
    {
        return [
            'admin scripts prefix'              => ['app/admin/scripts/maintenance/21-Fix-Page-Permissions.php', true],
            'admin scripts fix'                 => ['app/admin/scripts/fix/01-something.php',                   true],
            'admin manage page'                 => ['app/admin/manage-consolidated.php',                        true],
            'admin index (dashboard)'           => ['app/admin/index.php',                                       true],
            'admin maintenance page'            => ['app/admin/maintenance.php',                         true],
            'admin design-system page'          => ['app/admin/design-system.php',                               true],
            'docs admin'                        => ['docs/admin/guide.php',                                     true],
            'user settings (owner page)'        => ['usersc/user_settings.php',                                 false],
            'car listing (public)'              => ['app/cars/index.php',                                       false],
            'car actions (owner)'               => ['app/api/cars/save.php',                                   false],
            'api send-feedback (not admin)'     => ['app/api/contact/send-feedback.php',                       false],
            'contact form (owner)'              => ['app/contact/form.php',                                     false],
            'error page'                        => ['404.php',                                                  false],
        ];
    }

    #[DataProvider('adminPermissionsProvider')]
    public function testShouldHaveAdminPermissions(string $path, bool $expected): void
    {
        $this->assertSame($expected, PagePermissionClassifier::shouldHaveAdminPermissions($path));
    }

    // -------------------------------------------------------------------------
    // shouldBeAdminOnly
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function adminOnlyProvider(): array
    {
        return [
            // Admin-only: scripts directory
            'maintenance script'                => ['app/admin/scripts/maintenance/21-Fix-Page-Permissions.php', true],
            'fix script'                        => ['app/admin/scripts/fix/01-something.php',                   true],
            'scripts root'                      => ['app/admin/scripts/index.php',                              true],
            // Admin-only: maintenance portal pages
            'maintenance'                       => ['app/admin/maintenance.php',                         true],
            'tab-health'                        => ['app/admin/includes/tab-health.php',                        true],
            'tab-maintenance'                   => ['app/admin/includes/tab-maintenance.php',                   true],
            // Admin+Editor: general admin panel (including design-system)
            'design-system'                     => ['app/admin/design-system.php',                              false],
            'manage-consolidated'               => ['app/admin/manage-consolidated.php',                        false],
            'admin index (dashboard)'           => ['app/admin/index.php',                                      false],
            'tab-cars'                          => ['app/admin/includes/tab-cars.php',                          false],
            'docs admin'                        => ['docs/admin/guide.php',                                     false],
            'non-admin page'                    => ['app/cars/index.php',                                       false],
        ];
    }

    #[DataProvider('adminOnlyProvider')]
    public function testShouldBeAdminOnly(string $path, bool $expected): void
    {
        $this->assertSame($expected, PagePermissionClassifier::shouldBeAdminOnly($path));
    }

    // -------------------------------------------------------------------------
    // shouldBePrivate
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function privateProvider(): array
    {
        return [
            // Admin pages are private
            'admin index (dashboard)'           => ['app/admin/index.php',                true],
            'admin maintenance'                 => ['app/admin/maintenance.php',           true],
            'admin design-system'               => ['app/admin/design-system.php',        true],
            'admin script'                      => ['app/admin/scripts/maintenance/21-Fix-Page-Permissions.php', true],
            'docs admin'                        => ['docs/admin/guide.php',               true],
            // Owner pages are private
            'car action'                        => ['app/api/cars/save.php',              true],
            'api send-feedback (owner)'         => ['app/api/contact/send-feedback.php',    true],
            'api send-owner-email (owner)'      => ['app/api/contact/send-owner-email.php', true],
            'contact page'                      => ['app/contact/form.php',               true],
            'edit page'                         => ['app/cars/edit-car.php',              true],
            'usersc page'                       => ['usersc/user_settings.php',           true],
            'login page'                        => ['usersc/login.php',                   true],
            'join page'                         => ['usersc/join.php',                    true],
            // Public pages
            'car listing'                       => ['app/cars/index.php',                 false],
            'car details'                       => ['app/cars/details.php',               false],
            'statistics'                        => ['app/reports/statistics.php',         false],
            'docs guide'                        => ['docs/guides/how-to.php',             false],
            'error 404'                         => ['404.php',                            false],
            'error 403'                         => ['403.php',                            false],
        ];
    }

    #[DataProvider('privateProvider')]
    public function testShouldBePrivate(string $path, bool $expected): void
    {
        $this->assertSame($expected, PagePermissionClassifier::shouldBePrivate($path));
    }

    // -------------------------------------------------------------------------
    // Invariant: no special-no-perms page is also an admin page
    // (the conflict guard in analyzePermissions() relies on this never occurring
    // with valid configuration)
    // -------------------------------------------------------------------------

    public function testNoSpecialNoPermsPageIsAlsoAdminPage(): void
    {
        $specialPages = ['usersc/join.php', 'usersc/login.php'];

        foreach ($specialPages as $page) {
            $this->assertFalse(
                PagePermissionClassifier::shouldHaveAdminPermissions($page),
                "'{$page}' must not match shouldHaveAdminPermissions — the conflict guard would skip it"
            );
        }
    }

    // -------------------------------------------------------------------------
    // #795 regression: user_settings.php must follow the owner tier
    // -------------------------------------------------------------------------

    public function testUserSettingsFollowsOwnerTier(): void
    {
        $path = 'usersc/user_settings.php';

        $this->assertFalse(
            PagePermissionClassifier::shouldBePrivateNoPermissions($path),
            'user_settings.php must NOT be a special-no-perms page (issue #795)'
        );
        $this->assertFalse(
            PagePermissionClassifier::shouldHaveAdminPermissions($path),
            'user_settings.php must NOT be an admin page'
        );
        $this->assertTrue(
            PagePermissionClassifier::shouldBePrivate($path),
            'user_settings.php must be private (usersc/* pattern)'
        );
    }

    // -------------------------------------------------------------------------
    // #797 regression: maintenance portal pages must be admin-only
    // -------------------------------------------------------------------------

    public function testMaintenancePortalPagesAreAdminOnly(): void
    {
        $maintenancePages = [
            'app/admin/maintenance.php',
            'app/admin/includes/tab-health.php',
            'app/admin/includes/tab-maintenance.php',
        ];

        foreach ($maintenancePages as $page) {
            $this->assertTrue(
                PagePermissionClassifier::shouldHaveAdminPermissions($page),
                "'{$page}' must be an admin page (issue #797)"
            );
            $this->assertTrue(
                PagePermissionClassifier::shouldBeAdminOnly($page),
                "'{$page}' must be admin-only, not admin+editor (issue #797)"
            );
        }
    }

    public function testAdminScriptsAreAdminOnly(): void
    {
        $scriptPaths = [
            'app/admin/scripts/maintenance/21-Fix-Page-Permissions.php',
            'app/admin/scripts/fix/01-something.php',
        ];

        foreach ($scriptPaths as $page) {
            $this->assertTrue(
                PagePermissionClassifier::shouldBeAdminOnly($page),
                "'{$page}' must be admin-only (issue #797)"
            );
        }
    }

    public function testDesignSystemPageIsEditorLevel(): void
    {
        $path = 'app/admin/design-system.php';

        $this->assertTrue(
            PagePermissionClassifier::shouldHaveAdminPermissions($path),
            'design-system.php must require admin permission'
        );
        $this->assertFalse(
            PagePermissionClassifier::shouldBeAdminOnly($path),
            'design-system.php must be admin+editor, not admin-only'
        );
    }

    public function testContactApiEndpointsAreOwnerTier(): void
    {
        $contactEndpoints = [
            'app/api/contact/send-feedback.php',
            'app/api/contact/send-owner-email.php',
        ];

        foreach ($contactEndpoints as $path) {
            $this->assertTrue(
                PagePermissionClassifier::shouldBePrivate($path),
                "'{$path}' must be owner-tier private"
            );
            $this->assertFalse(
                PagePermissionClassifier::shouldHaveAdminPermissions($path),
                "'{$path}' must not require admin permission (owner-tier, not admin-tier)"
            );
            $this->assertFalse(
                PagePermissionClassifier::shouldBeAdminOnly($path),
                "'{$path}' must not be admin-only"
            );
        }
    }

    public function testPublicApiEndpointClassifierResultIsIrrelevantWhenNotRegistered(): void
    {
        // statistics.php does not call securePage() and is therefore never registered
        // in the pages table. The classifier marks app/api/* paths as private (matching
        // #^app/api/#), but that result is never applied — the Fix Page Permissions script
        // only processes pages that are registered. Public API endpoints must NOT call
        // securePage() precisely to stay out of the pages table.
        $this->assertTrue(
            PagePermissionClassifier::shouldBePrivate('app/api/shared/statistics.php'),
            'classifier marks app/api/* private, but unregistered paths are never processed'
        );
        $this->assertFalse(
            PagePermissionClassifier::shouldHaveAdminPermissions('app/api/shared/statistics.php'),
            'public statistics endpoint must not require admin permission'
        );
    }

    public function testGeneralAdminPagesAreNotAdminOnly(): void
    {
        $adminEditorPages = [
            'app/admin/design-system.php',
            'app/admin/index.php',
            'app/admin/includes/tab-cars.php',
            'docs/admin/guide.php',
        ];

        foreach ($adminEditorPages as $page) {
            $this->assertTrue(
                PagePermissionClassifier::shouldHaveAdminPermissions($page),
                "'{$page}' must require admin permission"
            );
            $this->assertFalse(
                PagePermissionClassifier::shouldBeAdminOnly($page),
                "'{$page}' must be admin+editor (not admin-only)"
            );
        }
    }
}
