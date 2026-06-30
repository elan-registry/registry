<?php

declare(strict_types=1);

/**
 * PagePermissionClassifier
 *
 * Pure classification logic for the Fix Page Permissions maintenance script.
 * Determines which permission tier each page path belongs to based on pattern
 * matching against the application's page structure.
 *
 * Permission tiers (in priority order):
 * - Special-no-perms: PRIVATE with no permission entries (login, join)
 * - Admin-only: PRIVATE with Administrator only (maintenance scripts, portal)
 * - Admin+Editor: PRIVATE with Administrator + Editor (general admin panel)
 * - Owner: PRIVATE with User permission (usersc/*, edit pages, app/api/contact/*)
 * - Public: private=0, no permissions (everything else)
 */
class PagePermissionClassifier
{
    /**
     * Pages that should be PRIVATE with NO permissions (special case).
     * These are pages that must be accessible without a permission check
     * (e.g. the login page itself), but should not be publicly indexed.
     */
    private const SPECIAL_NO_PERMS_PAGES = [
        'usersc/join.php',
        'usersc/login.php',
    ];

    /**
     * Pages within the admin hierarchy that should be restricted to
     * Administrator only (no Editor access).
     */
    private const ADMIN_ONLY_PAGES = [
        'app/admin/maintenance.php',
        'app/admin/includes/tab-health.php',
        'app/admin/includes/tab-maintenance.php',
    ];

    /**
     * Check if a page should be PRIVATE with NO permissions (special case).
     *
     * @param  string $pagePath The page path to classify
     * @return bool   True if the page should be private with no permission entries
     */
    public static function shouldBePrivateNoPermissions(string $pagePath): bool
    {
        return in_array($pagePath, self::SPECIAL_NO_PERMS_PAGES);
    }

    /**
     * Check if a page is an ADMIN page (should have at least Admin permission).
     *
     * Returns true for any page that should be restricted to admin-tier roles.
     * Use shouldBeAdminOnly() to further distinguish between admin-only and
     * admin+editor tiers.
     *
     * @param  string $pagePath The page path to classify
     * @return bool   True if the page requires at least Administrator permission
     */
    public static function shouldHaveAdminPermissions(string $pagePath): bool
    {
        if (strpos($pagePath, 'app/admin/scripts/') === 0) {
            return true;
        }

        if (strpos($pagePath, 'admin') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Check if an admin page should be ADMIN-ONLY (Administrator permission only,
     * no Editor permission). All other admin pages get Admin+Editor.
     *
     * @param  string $pagePath The page path to check
     * @return bool   True if the page should be admin-only, false if admin+editor
     */
    public static function shouldBeAdminOnly(string $pagePath): bool
    {
        if (strpos($pagePath, 'app/admin/scripts/') === 0) {
            return true;
        }

        return in_array($pagePath, self::ADMIN_ONLY_PAGES);
    }

    /**
     * Determine if a page should be PRIVATE based on pattern matching.
     *
     * @param  string $pagePath The page path to classify
     * @return bool   True if the page should be private (any permission tier), false if public
     */
    public static function shouldBePrivate(string $pagePath): bool
    {
        // Error pages (404.php, 403.php, etc.) in root should be PUBLIC
        if (preg_match('#^40\d\.php$#', $pagePath)) {
            return false;
        }

        // docs/* pages should generally be PUBLIC
        // EXCEPT docs/admin/* (and any path containing "admin") which should be PRIVATE-ADMIN
        if (strpos($pagePath, 'docs/') === 0) {
            return strpos($pagePath, 'admin') !== false;
        }

        $patterns = [
            '#^app/admin/scripts/#',             // app/admin/scripts/* maintenance & fix scripts
            '#^app/admin/#',                     // app/admin/* pages
            '#admin#',                           // Any path containing "admin"
            '#^app/api/#',                       // app/api/* endpoints registered via securePage() are owner-tier private
                                             //   (currently only app/api/contact/*). Endpoints that enforce auth inline
                                             //   without securePage() (e.g. app/api/cars/*) and purely public endpoints
                                             //   (e.g. app/api/shared/statistics.php) must NOT call securePage() —
                                             //   they are never registered and never reach here.
            '#^app/contact/#',                   // app/contact/* pages
            '#edit#',                            // Any path containing "edit"
            '#^usersc/#'                         // usersc/* pages
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $pagePath)) {
                return true;
            }
        }

        return false;
    }
}
