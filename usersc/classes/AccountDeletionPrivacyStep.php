<?php
declare(strict_types=1);

namespace ElanRegistry;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\OwnerDatabaseException;

/**
 * Account-deletion privacy step (#2358, #2419).
 *
 * Deletes the personal data of a deleted user that does not go with the
 * `users` row: the plugin mail rows, the email events, the profile, and the
 * holding row that the `users_delete_capture_addresses` trigger wrote.
 *
 * `usersc/scripts/after_user_deletion.php` runs this after upstream
 * `deleteUsers()` has deleted the `users` row. The rerun script
 * `app/admin/scripts/maintenance/30-Rerun-Deletion-Privacy-Step.php` runs it
 * again for one user ID when the CRITICAL "privacy step failed" line appears.
 *
 * All stages run in one transaction. The event step (EmailEventApplier) takes
 * the same profile lock first, so for a user with a profiles row no event row
 * can arrive between the address read and the deletes. A user with no
 * profiles row gets only a gap lock (see
 * CarRepository::lockProfileAndFindAddresses()). The plugin delete reads the
 * user's event message IDs, so it runs before the event delete. The holding
 * row goes last: on a failure it stays, so a rerun has the addresses.
 *
 * Log lines name the user ID, the stage, the exception class and code only,
 * because an exception message can contain an address.
 *
 * @author Jim Boone
 */
final class AccountDeletionPrivacyStep
{
    private readonly CarRepository $repo;

    /**
     * @param DatabaseInterface  $db   Database connection
     * @param CarRepository|null $repo Repository on the same connection; null creates one
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        ?CarRepository $repo = null
    ) {
        $this->repo = $repo ?? new CarRepository($db);
    }

    /**
     * Run the privacy step for one deleted user.
     *
     * Refuses a user ID below 1, and an ID that still has a `users` row,
     * because the step would then delete a live owner's profile. A refusal
     * writes a CRITICAL log line and deletes nothing. A failure rolls back
     * every stage and writes a CRITICAL log line. It does not throw, because
     * the caller must continue with the car cleanup.
     *
     * @param int $userId ID of the deleted user
     * @return AccountDeletionPrivacyResult Outcome and row counts, no address
     */
    public function run(int $userId): AccountDeletionPrivacyResult
    {
        if ($userId < 1) {
            logger($userId, LogCategories::LOG_CATEGORY_USER_DELETION, sprintf(
                'CRITICAL: privacy step refused user ID %d: the ID is below 1, no statement run',
                $userId
            ));
            return AccountDeletionPrivacyResult::refused();
        }

        $liveUser = $this->liveUserExists($userId);
        if ($liveUser !== false) {
            logger($userId, LogCategories::LOG_CATEGORY_USER_DELETION, sprintf(
                $liveUser === null
                    ? 'CRITICAL: privacy step refused user %d: the users row check failed, no row deleted'
                    : 'CRITICAL: privacy step refused user %d: a users row still exists, no row deleted; if an account deletion ran, check the trigger users_delete_capture_addresses and the user permission rows',
                $userId
            ));
            return AccountDeletionPrivacyResult::refused();
        }

        $result = $this->deleteInTransaction($userId);

        // The log rows are written after the transaction ends (commit or rollback), so a rollback cannot remove them.
        // Only a committed result sets pluginTableAbsent.
        if ($result->pluginTableAbsent) {
            logger($userId, LogCategories::LOG_CATEGORY_USER_DELETION, "Privacy step: plg_sendinblue_messages is absent, no plugin rows to delete for user $userId");
        }
        logger($userId, LogCategories::LOG_CATEGORY_USER_DELETION, sprintf(
            'Privacy step for user %d: %s, deleted %d er_email_events rows and %d plg_sendinblue_messages rows',
            $userId,
            $result->committed ? 'committed' : 'rolled back',
            $result->deletedEmailEventCount,
            $result->deletedPluginMessageCount
        ));

        return $result;
    }

    /**
     * Run every delete stage in one transaction.
     *
     * @param int $userId ID of the deleted user (1 or more)
     * @return AccountDeletionPrivacyResult
     */
    private function deleteInTransaction(int $userId): AccountDeletionPrivacyResult
    {
        $pluginTableAbsent = false;
        $stage = 'begin';
        try {
            $this->repo->beginTransaction();
            $stage = 'lock and read addresses';
            $addresses = $this->repo->lockProfileAndFindAddresses($userId);
            $stage = 'plugin delete';
            $pluginRows = 0;
            if ($this->repo->sendinblueMessagesTableExists()) {
                $pluginRows = $this->repo->deleteSendinblueMessagesForUser($userId, $addresses);
            } else {
                $pluginTableAbsent = true;
            }
            $stage = 'event delete';
            $eventRows = $this->repo->deleteEmailEventsForUser($userId);
            $stage = 'profile delete';
            $this->db->query('DELETE FROM profiles WHERE user_id = ?', [$userId]);
            if ($this->db->error()) {
                throw new OwnerDatabaseException("Failed to delete profile for user $userId");
            }
            $stage = 'holding row delete';
            $holdingRows = $this->repo->deleteDeletedUserAddresses($userId);
            $stage = 'commit';
            $this->repo->commit();
        } catch (\Throwable $e) {
            // The users row is already gone. The caller must still run the
            // car cleanup, so this logs and returns. A rollback that throws (for
            // example, a lost connection) must not stop the CRITICAL line or
            // escape to the caller.
            $rollbackOutcome = 'rolled back';
            try {
                $this->repo->rollback();
            } catch (\Throwable $rollbackError) {
                $rollbackOutcome = sprintf(
                    'rollback failed: %s (code %s)',
                    get_class($rollbackError),
                    (string) $rollbackError->getCode()
                );
            }
            logger($userId, LogCategories::LOG_CATEGORY_USER_DELETION, sprintf(
                'CRITICAL: privacy step failed for user %d at stage %s, %s, personal data remains: %s (code %s)',
                $userId,
                $stage,
                $rollbackOutcome,
                get_class($e),
                (string) $e->getCode()
            ));
            return AccountDeletionPrivacyResult::rolledBack($stage);
        }

        return AccountDeletionPrivacyResult::committed(
            deletedEmailEventCount: $eventRows,
            deletedPluginMessageCount: $pluginRows,
            deletedHoldingRowCount: $holdingRows,
            pluginTableAbsent: $pluginTableAbsent
        );
    }

    /**
     * Tell whether a `users` row exists for the ID.
     *
     * @param int $userId User ID
     * @return bool|null True when the row exists, false when it does not,
     *                   null when the query failed
     */
    private function liveUserExists(int $userId): ?bool
    {
        $result = $this->db->query('SELECT id FROM users WHERE id = ?', [$userId]);
        if ($this->db->error()) {
            return null;
        }

        return $result->count() > 0;
    }
}
