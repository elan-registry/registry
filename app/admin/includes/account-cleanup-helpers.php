<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Migration\DeliverabilityMigrationQueries;

/**
 * Return unverified (email_verified=0) accounts with no car associations older than $days.
 *
 * @param DatabaseInterface $db   UserSpice DB instance
 * @param int               $days Minimum age in days; caller must pass ≥30
 * @return array<object> Matching user rows, ordered by join_date ASC
 */
function findUnverifiedOwnerlessAccounts(DatabaseInterface $db, int $days): array
{
    return $db->query(
        "SELECT u.id, u.email, u.fname, u.lname, u.join_date,
                DATEDIFF(NOW(), u.join_date) AS age_days,
                p.city, p.state, p.country
         FROM users u
         LEFT JOIN profiles p ON u.id = p.user_id
         WHERE u.email_verified = 0
           AND u.active = 1
           AND u.protected = 0
           AND u.id != 1
           AND u.username != 'noowner'
           AND NOT EXISTS (SELECT 1 FROM cars c WHERE c.user_id = u.id)
           AND NOT EXISTS (SELECT 1 FROM cars_hist ch WHERE ch.user_id = u.id)
           AND NOT EXISTS (SELECT 1 FROM car_transfer_requests ctr WHERE ctr.requested_by_user_id = u.id AND ctr.status = 'pending')
           AND DATEDIFF(NOW(), u.join_date) >= ?
         ORDER BY u.join_date ASC",
        [$days]
    )->results();
}

/**
 * Return verified (email_verified=1) accounts with no car associations whose last_login
 * is older than $days (or never logged in).
 *
 * @param DatabaseInterface $db   UserSpice DB instance
 * @param int               $days Inactivity threshold in days; caller must pass ≥1
 * @return array<object> Matching user rows, ordered by last_login ASC, join_date ASC
 */
function findVerifiedOwnerlessAccounts(DatabaseInterface $db, int $days): array
{
    return $db->query(
        "SELECT u.id, u.email, u.fname, u.lname, u.join_date,
                u.logins, u.last_login,
                p.city, p.state, p.country
         FROM users u
         LEFT JOIN profiles p ON u.id = p.user_id
         WHERE u.email_verified = 1
           AND u.active = 1
           AND u.protected = 0
           AND u.id != 1
           AND u.username != 'noowner'
           AND NOT EXISTS (SELECT 1 FROM cars c WHERE c.user_id = u.id)
           AND NOT EXISTS (SELECT 1 FROM cars_hist ch WHERE ch.user_id = u.id)
           AND NOT EXISTS (SELECT 1 FROM car_transfer_requests ctr WHERE ctr.requested_by_user_id = u.id AND ctr.status = 'pending')
           AND (
               u.last_login IS NULL
               OR u.last_login = '0000-00-00 00:00:00'
               OR DATEDIFF(NOW(), u.last_login) >= ?
           )
         ORDER BY u.last_login ASC, u.join_date ASC",
        [$days]
    )->results();
}

/**
 * Snapshot a set of user accounts into deleted_accounts_archive before deletion.
 *
 * The snapshot keeps the profile opt-out, so that a restore does not enable mail
 * to an owner who opted out.
 *
 * @param DatabaseInterface $db            UserSpice DB instance
 * @param int[]             $userIds       IDs to archive
 * @param int               $deletedBy     Admin user ID performing the deletion
 * @param string            $deletionType  'unverified' or 'verified'
 */
function archiveAccounts(DatabaseInterface $db, array $userIds, int $deletedBy, string $deletionType): void
{
    if (empty($userIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    // phpcs:disable - False positive: Using prepared statements correctly
    $rows = $db->query(
        "SELECT u.id, u.email, u.username, u.fname, u.lname,
                u.join_date, u.last_login, u.logins, u.email_verified,
                p.city, p.state, p.country, p.bio, p.website,
                p.email_opted_out, p.email_opted_out_at
         FROM users u
         LEFT JOIN profiles p ON p.user_id = u.id
         WHERE u.id IN (" . $placeholders . ")",
        $userIds
    )->results();
    // phpcs:enable

    if ($db->error()) {
        throw new RuntimeException('archiveAccounts: query failed — ' . $db->errorString());
    }

    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        foreach ($rows as $r) {
            $ll = ($r->last_login && $r->last_login !== '0000-00-00 00:00:00')
                ? $r->last_login
                : null;

            $ok = $db->insert('deleted_accounts_archive', [
                'original_user_id' => (int) $r->id,
                'email'            => $r->email,
                'username'         => $r->username,
                'fname'            => $r->fname,
                'lname'            => $r->lname,
                'join_date'        => $r->join_date,
                'last_login'       => $ll,
                'logins'           => (int) $r->logins,
                'email_verified'   => (int) $r->email_verified,
                'city'             => $r->city    ?? null,
                'state'            => $r->state   ?? null,
                'country'          => $r->country ?? null,
                'bio'              => $r->bio     ?? null,
                'website'          => $r->website ?? null,
                'email_opted_out'    => (int) ($r->email_opted_out ?? 0),
                'email_opted_out_at' => $r->email_opted_out_at ?? null,
                'deleted_by'       => $deletedBy,
                'deleted_at'       => $now,
                'deletion_type'    => $deletionType,
            ]);

            if (!$ok) {
                throw new RuntimeException(
                    "archiveAccounts: failed to archive user id={$r->id} — " . $db->errorString()
                );
            }
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Restore an archived account: re-insert into users + profiles, mark archive row restored.
 *
 * The new profile gets the archived opt-out. It never gets a block or bounce flag.
 *
 * Returns the new user ID on success, or throws on failure.
 *
 * @param DatabaseInterface $db         UserSpice DB instance
 * @param int               $archiveId  Row ID in deleted_accounts_archive
 * @param int               $restoredBy Admin user ID performing the restore
 *
 * @throws RuntimeException if the archive row is not found, already restored, or any write fails
 */
function restoreArchivedAccount(DatabaseInterface $db, int $archiveId, int $restoredBy): int
{
    $row = $db->query(
        "SELECT * FROM deleted_accounts_archive WHERE id = ? AND restored_at IS NULL LIMIT 1",
        [$archiveId]
    )->first();

    // HIGH-1: Check DB error before interpreting null as "not found" — a query failure also returns null.
    if ($db->error()) {
        throw new RuntimeException(
            "DB error reading archive row #{$archiveId}: " . $db->errorString()
        );
    }

    if (!$row) {
        throw new RuntimeException("Archive row #{$archiveId} not found or already restored.");
    }

    $db->beginTransaction();
    try {
        // Re-insert user (new auto-increment ID; original ID may have been reused).
        // Password is not archived — restored account requires a password reset via forgot-password.
        $ok = $db->insert('users', [
            'email'          => $row->email,
            'username'       => $row->username ?? $row->email,
            'password'       => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
            'fname'          => $row->fname,
            'lname'          => $row->lname,
            'join_date'      => $row->join_date,
            'last_login'     => $row->last_login ?? '0000-00-00 00:00:00',
            'logins'         => (int) $row->logins,
            'email_verified' => 0,  // require re-verification on restore
            'active'         => 1,
            'protected'      => 0,
        ]);
        if (!$ok) {
            throw new RuntimeException('Failed to re-insert user — ' . $db->errorString());
        }

        $newUserId = (int) $db->lastId();
        if ($newUserId === 0) {
            throw new RuntimeException('User insert succeeded but returned no ID.');
        }

        // Recreate the base member permission (permission_id=1), as deleteUsers() removes it
        $ok = $db->insert('user_permission_matches', [
            'user_id'       => $newUserId,
            'permission_id' => 1,
        ]);
        if (!$ok) {
            throw new RuntimeException(
                "User id={$newUserId} restored but permission insert failed — " . $db->errorString()
            );
        }

        // Always insert a profile: the deliverability record lives on it, and the
        // text columns are NOT NULL with no default under strict mode (#2101).
        // The block and bounce flags are not archived. Brevo keeps its own block
        // on the address, so a send to it sets the bounce flag again.
        // The address is new to this account at restore time, not at join_date:
        // a bounce on mail sent before the deletion must not match it. The
        // matching step that reads email_address_since comes in #2359.
        $ok = $db->insert('profiles', [
            'user_id'             => $newUserId,
            'city'                => $row->city    ?? '',
            'state'               => $row->state   ?? '',
            'country'             => $row->country ?? '',
            'bio'                 => $row->bio     ?? '',
            'website'             => $row->website ?? null,
            'email_opted_out'     => (int) $row->email_opted_out,
            'email_opted_out_at'  => $row->email_opted_out_at,
            'email_address_since' => date('Y-m-d H:i:s'),
            'email_address_seen'  => DeliverabilityMigrationQueries::normalizeAddress($row->email),
        ]);
        if (!$ok) {
            throw new RuntimeException(
                "User id={$newUserId} restored but profile insert failed — " . $db->errorString()
            );
        }

        // Mark as restored — keep the archive row as permanent audit trail
        $db->query(
            "UPDATE deleted_accounts_archive SET restored_at = NOW(), restored_by = ? WHERE id = ?",
            [$restoredBy, $archiveId]
        );
        if ($db->error()) {
            throw new RuntimeException(
                "User id={$newUserId} restored but archive update failed — " . $db->errorString()
            );
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $newUserId;
}

/**
 * Return all archive rows for the DataTables endpoint.
 *
 * @param DatabaseInterface $db UserSpice DB instance
 * @return array<object> All deleted_accounts_archive rows, ordered by deleted_at DESC
 */
function findArchivedAccounts(DatabaseInterface $db): array
{
    return $db->query(
        "SELECT a.id, a.original_user_id, a.email, a.fname, a.lname,
                a.deletion_type, a.deleted_at,
                a.city, a.state, a.country,
                a.restored_at,
                rb.fname AS restored_by_fname, rb.lname AS restored_by_lname
         FROM deleted_accounts_archive a
         LEFT JOIN users rb ON rb.id = a.restored_by
         ORDER BY a.deleted_at DESC",
        []
    )->results();
}
