<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Establish the three baseline `permissions` rows every ElanRegistry install
 * depends on: `1 = User`, `2 = Administrator`, `3 = Editor`.
 *
 * The vendored structure dump (`database/vendor/userspice-6.1.7-base.sql`,
 * the 6.1.4 structure, unchanged through 6.1.7) is DDL only — it creates the
 * `permissions` table but inserts no rows, so a freshly provisioned schema
 * starts with none of them. On dev/prod, rows 1 and 2 were created by
 * UserSpice's install wizard and row 3 ("Editor", an ElanRegistry-specific
 * tier) by hand, all outside any tracked migration or seed.
 * `scripts/provision-schema.sh` runs neither the wizard nor any manual
 * step, so without this migration a fresh install has an empty table while
 * `PagePermissionClassifier`,
 * `app/admin/scripts/maintenance/21-Fix-Page-Permissions.php`, and
 * `PageRegistrationSeed` all hardcode `permission_id` 2 and 3 as though the
 * rows exist. `PageRegistrationSeed` fails loudly on the missing id rather
 * than writing orphaned `permission_page_matches` rows — that abort is what
 * surfaced this gap.
 *
 * Each `id` is forced explicitly rather than left to AUTO_INCREMENT, because
 * every consumer looks these rows up by literal id, not by name. This is the
 * inverse of the noowner account (see `RegisterNoownerAccount`), which
 * deliberately lets its id fall to AUTO_INCREMENT precisely because it is
 * resolved by username.
 *
 * Converted from `database/seeds/BaselinePermissionsSeed.php` (removed) to a
 * migration: this row is part of the registry's base configuration and
 * should never need to be replayed. As a migration it also structurally
 * guarantees ordering ahead of `PageRegistrationSeed` — migrations always
 * run before seeds — replacing the previous alphabetical-filename ordering
 * trick the seed relied on (`provision-schema.sh` enumerates
 * `database/seeds/*.php` in filesystem order; the class was deliberately
 * named to sort ahead of `PageRegistrationSeed`).
 *
 * Idempotent per row: an id that already exists is left exactly as-is. Names
 * and descriptions are deliberately NOT forced to match — an environment that
 * renamed a role did so on purpose, and every consumer keys off the id, not
 * the name. Only a missing row is a defect worth correcting.
 *
 * down() deletes only the rows it would have inserted, and only when no user
 * still holds that permission — dropping a referenced row would leave users
 * pointing at a permission that no longer exists.
 */
final class RegisterBaselinePermissions extends AbstractMigration
{
    /**
     * id => [name, descrip]. Rows 1 and 2 reproduce the values UserSpice's
     * install wizard writes, copied from the dev database rather than
     * invented, so a provisioned schema matches a wizard-installed one. Row 3
     * is the ElanRegistry-specific Editor tier — dev's copy carries an empty
     * descrip (hand-created), and this is the intended value going forward.
     *
     * @var array<int, array{string, string}>
     */
    private const BASELINE = [
        1 => ['User', 'Standard User'],
        2 => ['Administrator', 'UserSpice Administrator'],
        3 => ['Editor', 'Elanregistry Editor'],
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        foreach (self::BASELINE as $id => [$name, $descrip]) {
            if ($this->fetchRow("SELECT id FROM `permissions` WHERE id = {$id}") !== false) {
                continue;
            }

            $this->execute(
                "INSERT INTO `permissions` (id, name, descrip) VALUES ({$id}, '{$name}', '{$descrip}')"
            );
        }

        // Verified after the loop rather than per-insert: every id must exist
        // before any page can be registered against it, and a single pass says
        // so unambiguously whether the row was pre-existing or just written.
        $ids = implode(', ', array_keys(self::BASELINE));
        $present = $this->fetchRow("SELECT COUNT(*) AS c FROM `permissions` WHERE id IN ({$ids})");
        $found = $present !== false ? (int) $present['c'] : 0;

        if ($found !== count(self::BASELINE)) {
            $adapter->rollbackTransaction();
            throw new RuntimeException(
                'RegisterBaselinePermissions: expected permissions rows ' . $ids . " to exist, found {$found}. " .
                'Pages cannot be registered against a missing permission id — investigate before continuing.'
            );
        }

        $adapter->commitTransaction();
    }

    public function down(): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        foreach (self::BASELINE as $id => [$name, $_descrip]) {
            // A permission still granted to someone must not be deleted: the
            // users row would keep the id and resolve to nothing.
            $held = $this->fetchRow(
                "SELECT COUNT(*) AS c FROM `users` WHERE `permissions` = {$id}"
            );
            if ($held !== false && (int) $held['c'] > 0) {
                continue;
            }

            // Same reasoning for page-level grants. PageRegistrationSeed writes
            // a permission_page_matches row per Admin/Editor page, and
            // permission_page_matches.permission_id carries no FK constraint,
            // so deleting the permissions row here would orphan those matches
            // silently rather than erroring. Checked separately from `users`
            // because the two are independent: the baseline ids routinely have
            // no users holding them while still being referenced by every
            // registered admin page.
            $matched = $this->fetchRow(
                "SELECT COUNT(*) AS c FROM `permission_page_matches` WHERE `permission_id` = {$id}"
            );
            if ($matched !== false && (int) $matched['c'] > 0) {
                continue;
            }

            $this->execute(
                "DELETE FROM `permissions` WHERE id = {$id} AND name = '{$name}'"
            );
        }

        $adapter->commitTransaction();
    }
}
