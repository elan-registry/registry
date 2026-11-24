<?php

declare(strict_types=1);

use ElanRegistry\Admin\PagePermissionClassifier;
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
            // login/join are now PUBLIC (moved to PUBLIC_USERSC_PAGES, mirroring users/ installer defaults)
            'join page'                        => ['usersc/join.php',          false],
            'login page'                       => ['usersc/login.php',         false],
            // #795: user_settings must NOT be in this list
            'user_settings is not special'     => ['usersc/user_settings.php', false],
            'other usersc page'                => ['usersc/profile.php',       false],
            'admin page'                       => ['app/admin/manage.php',     false],
            'public car listing'               => ['app/owner/cars/index.php',       false],
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
            'admin tab include'                 => ['app/admin/includes/tab-cars.php',                          true],
            // #795: user_settings follows the owner tier, not the admin tier
            'user settings (owner page)'        => ['usersc/user_settings.php',                                 false],
            'car listing (public)'              => ['app/owner/cars/index.php',                                       false],
            'car actions (owner)'               => ['app/api/cars/save.php',                                   false],
            'api send-feedback (not admin)'     => ['app/api/contact/send-feedback.php',                       false],
            'api send-owner-email (not admin)'  => ['app/api/contact/send-owner-email.php',                    false],
            'api statistics (public)'           => ['app/api/shared/statistics.php',                           false],
            // login/join are PUBLIC (PUBLIC_USERSC_PAGES)
            'login page'                        => ['usersc/login.php',                                         false],
            'join page'                         => ['usersc/join.php',                                          false],
            'contact form (owner)'              => ['app/owner/contact/index.php',                              false],
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
            // Admin-only: scripts directory (#797)
            'maintenance script'                => ['app/admin/scripts/maintenance/21-Fix-Page-Permissions.php', true],
            'fix script'                        => ['app/admin/scripts/fix/01-something.php',                   true],
            'scripts root'                      => ['app/admin/scripts/index.php',                              true],
            // Admin-only: maintenance portal pages, not admin+editor (#797)
            'maintenance'                       => ['app/admin/maintenance.php',                         true],
            // Admin+Editor: general admin panel (including design-system)
            'design-system'                     => ['app/admin/design-system.php',                              false],
            'manage-consolidated'               => ['app/admin/manage-consolidated.php',                        false],
            'admin index (dashboard)'           => ['app/admin/index.php',                                      false],
            'tab-cars'                          => ['app/admin/includes/tab-cars.php',                          false],
            'docs admin'                        => ['docs/admin/guide.php',                                     false],
            'non-admin page'                    => ['app/owner/cars/index.php',                                       false],
            'api send-feedback (owner)'         => ['app/api/contact/send-feedback.php',                        false],
            'api send-owner-email (owner)'      => ['app/api/contact/send-owner-email.php',                     false],
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
            // statistics.php does not call securePage(), so it is never registered in the
            // pages table. The classifier marks app/api/* private, but the Fix Page
            // Permissions script processes only registered pages, so it never applies this.
            'api statistics (unregistered)'     => ['app/api/shared/statistics.php',      true],
            'contact page'                      => ['app/owner/contact/index.php',        true],
            'contact owner page'                => ['app/owner/contact/owner.php',        true],
            'privacy page (public)'             => ['app/owner/privacy.php',              false],
            'edit page'                         => ['app/owner/cars/edit.php',             true],
            // #795: user_settings is private (usersc/* pattern)
            'usersc page'                       => ['usersc/user_settings.php',           true],
            // login/join are PUBLIC (PUBLIC_USERSC_PAGES), mirroring users/login.php and users/join.php
            'login page'                        => ['usersc/login.php',                   false],
            'join page'                         => ['usersc/join.php',                    false],
            // Public pages
            'car listing'                       => ['app/owner/cars/index.php',                 false],
            'car details'                       => ['app/owner/cars/details.php',               false],
            'statistics'                        => ['app/owner/reports/statistics.php',         false],
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
}
