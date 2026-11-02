<?php
declare(strict_types=1);

namespace ElanRegistry;

/**
 * Outcome of AccountDeletionPrivacyStep::run().
 *
 * `outcome` is one {@see AccountDeletionPrivacyOutcome} case. `failedStage`
 * names the stage that failed for a rolled-back or commit-unknown result, and
 * is null for the other two outcomes.
 *
 * Only the four named constructors build a result, so the outcome and the
 * stage cannot contradict each other. The counts are 0 when the run did not
 * commit. The result holds no address, so a caller can show it on a page or
 * write it to a log.
 *
 * @author Jim Boone
 * @since v2.30.10
 * @see https://github.com/elan-registry/registry/issues/2419
 */
final readonly class AccountDeletionPrivacyResult
{
    /**
     * @param AccountDeletionPrivacyOutcome $outcome                   Outcome of the run
     * @param string|null                   $failedStage               Stage that failed for RolledBack and CommitUnknown, else null
     * @param int                           $deletedEmailEventCount    er_email_events rows deleted
     * @param int                           $deletedPluginMessageCount plg_sendinblue_messages rows deleted
     * @param int                           $deletedHoldingRowCount    er_deleted_user_addresses rows deleted (0 or 1)
     * @param bool                          $pluginTableAbsent         True when plg_sendinblue_messages does not exist
     * @throws \InvalidArgumentException If a count is negative
     */
    private function __construct(
        public AccountDeletionPrivacyOutcome $outcome,
        public ?string $failedStage = null,
        public int $deletedEmailEventCount = 0,
        public int $deletedPluginMessageCount = 0,
        public int $deletedHoldingRowCount = 0,
        public bool $pluginTableAbsent = false
    ) {
        if ($deletedEmailEventCount < 0 || $deletedPluginMessageCount < 0 || $deletedHoldingRowCount < 0) {
            throw new \InvalidArgumentException(sprintf(
                'AccountDeletionPrivacyResult: counts must not be negative (email events %d, plugin messages %d, holding rows %d)',
                $deletedEmailEventCount,
                $deletedPluginMessageCount,
                $deletedHoldingRowCount
            ));
        }
    }

    /**
     * Result of a run that deleted the rows and committed.
     *
     * @param int  $deletedEmailEventCount    er_email_events rows deleted
     * @param int  $deletedPluginMessageCount plg_sendinblue_messages rows deleted
     * @param int  $deletedHoldingRowCount    er_deleted_user_addresses rows deleted (0 or 1)
     * @param bool $pluginTableAbsent         True when plg_sendinblue_messages does not exist
     * @return self
     * @throws \InvalidArgumentException If a count is negative
     */
    public static function committed(
        int $deletedEmailEventCount,
        int $deletedPluginMessageCount,
        int $deletedHoldingRowCount,
        bool $pluginTableAbsent
    ): self {
        return new self(
            outcome: AccountDeletionPrivacyOutcome::Committed,
            deletedEmailEventCount: $deletedEmailEventCount,
            deletedPluginMessageCount: $deletedPluginMessageCount,
            deletedHoldingRowCount: $deletedHoldingRowCount,
            pluginTableAbsent: $pluginTableAbsent
        );
    }

    /**
     * Result of a run that a guard stopped before any delete.
     *
     * @return self
     */
    public static function refused(): self
    {
        return new self(AccountDeletionPrivacyOutcome::Refused);
    }

    /**
     * Result of a run that failed at a stage and rolled back.
     *
     * @param string $stage Stage that failed
     * @return self
     */
    public static function rolledBack(string $stage): self
    {
        return new self(AccountDeletionPrivacyOutcome::RolledBack, failedStage: $stage);
    }

    /**
     * Result of a run whose commit threw.
     *
     * The server may have committed before the driver threw, so the caller
     * cannot tell whether the personal data remains. The stage is `commit`
     * and the counts are 0, because the step cannot prove the commit.
     *
     * @return self
     */
    public static function commitUnknown(): self
    {
        return new self(AccountDeletionPrivacyOutcome::CommitUnknown, failedStage: 'commit');
    }
}
