<?php
declare(strict_types=1);

namespace ElanRegistry;

/**
 * Outcome of AccountDeletionPrivacyStep::run().
 *
 * A run has one of four outcomes:
 * - committed: the step deleted the rows and committed,
 * - rolled back: a stage failed, so the personal data remains,
 * - commit unknown: the commit threw, so the server may or may not have
 *   committed the deletes,
 * - refused: a guard stopped the run before any delete.
 *
 * Only the four named constructors build a result, so the flags cannot
 * contradict each other. The counts are 0 when the run did not commit. The
 * result holds no address, so a caller can show it on a page or write it to a
 * log.
 *
 * @author Jim Boone
 */
final class AccountDeletionPrivacyResult
{
    /**
     * @param bool        $committed                 True when the step committed
     * @param bool        $refused                   True when a guard stopped the run before any delete
     * @param string|null $failedStage               Stage that failed when the step did not commit, else null
     * @param bool        $commitOutcomeUnknown      True when the commit threw, so the deletes may have landed
     * @param int         $deletedEmailEventCount    er_email_events rows deleted
     * @param int         $deletedPluginMessageCount plg_sendinblue_messages rows deleted
     * @param int         $deletedHoldingRowCount    er_deleted_user_addresses rows deleted (0 or 1)
     * @param bool        $pluginTableAbsent         True when plg_sendinblue_messages does not exist
     */
    private function __construct(
        public readonly bool $committed,
        public readonly bool $refused = false,
        public readonly ?string $failedStage = null,
        public readonly bool $commitOutcomeUnknown = false,
        public readonly int $deletedEmailEventCount = 0,
        public readonly int $deletedPluginMessageCount = 0,
        public readonly int $deletedHoldingRowCount = 0,
        public readonly bool $pluginTableAbsent = false
    ) {
    }

    /**
     * Result of a run that deleted the rows and committed.
     *
     * @param int  $deletedEmailEventCount    er_email_events rows deleted
     * @param int  $deletedPluginMessageCount plg_sendinblue_messages rows deleted
     * @param int  $deletedHoldingRowCount    er_deleted_user_addresses rows deleted (0 or 1)
     * @param bool $pluginTableAbsent         True when plg_sendinblue_messages does not exist
     * @return self
     */
    public static function committed(
        int $deletedEmailEventCount,
        int $deletedPluginMessageCount,
        int $deletedHoldingRowCount,
        bool $pluginTableAbsent
    ): self {
        return new self(
            committed: true,
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
        return new self(committed: false, refused: true);
    }

    /**
     * Result of a run that failed at a stage and rolled back.
     *
     * @param string $stage Stage that failed
     * @return self
     */
    public static function rolledBack(string $stage): self
    {
        return new self(committed: false, failedStage: $stage);
    }

    /**
     * Result of a run whose commit threw.
     *
     * The server may have committed before the driver threw, so the caller
     * cannot tell whether the personal data remains. `committed` is false,
     * because the step cannot prove the commit, and the counts are 0.
     *
     * @return self
     */
    public static function commitUnknown(): self
    {
        return new self(committed: false, failedStage: 'commit', commitOutcomeUnknown: true);
    }
}
