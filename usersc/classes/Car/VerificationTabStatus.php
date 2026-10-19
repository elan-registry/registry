<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateInterval;
use DateTimeImmutable;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobFailureLogReader;
use ElanRegistry\Cron\CronJobRunsReader;
use ElanRegistry\Cron\SendVerificationBatchJob;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * VerificationTabStatus - Status rules for the admin Verification tab
 *
 * Holds the probe results that the tab shows in its "System Status & Feature
 * Switch" panel and its Automatic Sending card. It gives the panel rows, the
 * one healthy flag, the auto-send state and the queue Status chip.
 *
 * {@see self::read()} runs each probe in its own fault domain, so one failure
 * does not stop the other probes. A failed settings probe shows "Not ready"
 * on the Brevo row and "Unavailable" on the unmatched row; the cron row then
 * keeps its default "Stalled". {@see self::allHealthy()} lists which probe
 * failures make the tab unhealthy. An unreadable value is never shown as a
 * green zero, because a zero tells an admin to stop looking.
 *
 * The class does not check permissions. The tab is admin and editor only,
 * and the toggle endpoint checks its own permission.
 *
 * @package ElanRegistry\Car
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2330
 * @see https://github.com/elan-registry/registry/issues/2364
 */
final readonly class VerificationTabStatus
{
    /** Date and time format for every time the tab shows */
    public const TIME_FORMAT = 'M j, Y g:i A';

    private const STATUS_UNREADABLE = [
        'state'         => CronJobEnabledState::UNREADABLE,
        'lastRunAt'     => null,
        'lastFailureAt' => null,
    ];

    /**
     * @param bool                   $probeFailed         True when the VerificationSettings probe threw
     * @param bool                   $enabled             The site-wide verification switch
     * @param string|null            $brevoNotReadyReason Admin-facing reason Brevo is not ready, or null when ready
     * @param bool                   $cronReady           True when cron ran recently
     * @param DateTimeImmutable|null $lastCronAt          When cron was last seen, or null if never
     * @param int|null               $unmatchedCount      Unmatched-recipient counter, or null when unreadable
     * @param array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable} $reconciliation
     *        Reconciliation job status from CronJobRunsReader::status()
     * @param array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable} $suppressionSync
     *        Suppression sync job status from CronJobRunsReader::status()
     * @param array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable} $autoSend
     *        Automatic send job status from CronJobRunsReader::status()
     * @param array{counts: array{sent: int, skipped: int, failed: int}|null, unreadable: bool}|null $autoSendOutcome
     *        CronJobRunsReader::lastOutcomeCounts() result, or null when the probe threw
     * @param array{count: int|null, recent: list<array{loggedAt: string, message: string}>}|null $failureLog
     *        CronJobFailureLogReader::recentFailures() result, or null when the probe threw
     */
    public function __construct(
        private bool $probeFailed,
        private bool $enabled,
        private ?string $brevoNotReadyReason,
        private bool $cronReady,
        private ?DateTimeImmutable $lastCronAt,
        private ?int $unmatchedCount,
        private array $reconciliation,
        private array $suppressionSync,
        private array $autoSend,
        private ?array $autoSendOutcome,
        private ?array $failureLog
    ) {}

    /**
     * Run every status probe and keep the results
     *
     * Each probe has its own try/catch. One failure is logged; the other
     * probes still read real values.
     *
     * @param DatabaseInterface $db     Database for the probes
     * @param int               $userId Acting user, for the log entries
     * @return self The probe results
     */
    public static function read(DatabaseInterface $db, int $userId): self
    {
        $probeFailed    = false;
        $enabled        = false;
        $brevoReason    = null;
        $cronReady      = false;
        $lastCronAt     = null;
        $unmatchedCount = null;

        try {
            $settings       = new VerificationSettings($db);
            $enabled        = $settings->isEnabled();
            $brevoReason    = $settings->brevoNotReadyReason();
            $cronReady      = $settings->cronReady();
            $lastCronAt     = $settings->lastCronRequestAt();
            // Null means the counter could not be read. The method has
            // already logged why under VerificationConfigWarning.
            $unmatchedCount = $settings->unmatchedRecipientCount();
        } catch (\Throwable $e) {
            $probeFailed = true;
            logger($userId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
                sprintf('Verification tab status probe failed [%s]: %s', get_class($e), $e->getMessage()));
        }

        // The constructor only stores the handle, so it cannot throw.
        $runsReader = new CronJobRunsReader($db);

        $reconciliation = self::STATUS_UNREADABLE;
        try {
            $reconciliation = $runsReader->status(BrevoEventReconciliationJob::JOB_NAME);
        } catch (\Throwable $e) {
            logger($userId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
                sprintf('Reconciliation status probe failed [%s]: %s', get_class($e), $e->getMessage()));
        }

        $suppressionSync = self::STATUS_UNREADABLE;
        try {
            $suppressionSync = $runsReader->status(BrevoSuppressionSyncJob::JOB_NAME);
        } catch (\Throwable $e) {
            logger($userId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
                sprintf('Suppression sync status probe failed [%s]: %s', get_class($e), $e->getMessage()));
        }

        $autoSend        = self::STATUS_UNREADABLE;
        $autoSendOutcome = null;
        try {
            $autoSend        = $runsReader->status(SendVerificationBatchJob::JOB_NAME);
            $autoSendOutcome = $runsReader->lastOutcomeCounts(SendVerificationBatchJob::JOB_NAME);
        } catch (\Throwable $e) {
            logger($userId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
                sprintf('Automatic send status or outcome counts probe failed [%s]: %s',
                    get_class($e), $e->getMessage()));
        }

        $failureLog = null;
        try {
            $failureLog = (new CronJobFailureLogReader($db))->recentFailures();
        } catch (\Throwable $e) {
            logger($userId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
                sprintf('Cron failure log summary probe failed [%s]: %s', get_class($e), $e->getMessage()));
        }

        return new self(
            $probeFailed,
            $enabled,
            $brevoReason,
            $cronReady,
            $lastCronAt,
            $unmatchedCount,
            $reconciliation,
            $suppressionSync,
            $autoSend,
            $autoSendOutcome,
            $failureLog
        );
    }

    /**
     * True when the VerificationSettings probe threw
     */
    public function probeFailed(): bool
    {
        return $this->probeFailed;
    }

    /**
     * True when Brevo is ready to send tagged mail
     *
     * A failed probe never gave a reason, so it is not ready.
     */
    public function brevoReady(): bool
    {
        return !$this->probeFailed && $this->brevoNotReadyReason === null;
    }

    /**
     * Give the panel rows in render order
     *
     * `badgeClass` is the full class list of the badge span. `icon` and
     * `detailIcon` are Font Awesome names, or '' for none. `detail` and
     * `recent` are plain text; the caller escapes them.
     *
     * @return list<array{key: string, label: string, badgeText: string, badgeClass: string, icon: string,
     *     detail: string, detailIcon: string, recent: list<array{loggedAt: string, message: string}>}>
     */
    public function rows(): array
    {
        return [
            $this->brevoRow(),
            $this->cronRow(),
            self::row(
                'webhook',
                'Webhook receiver',
                'Live',
                'badge text-bg-success',
                'fa-check-circle',
                'Bounce and suppression events from Brevo are recorded in real time'
                    . ' (app/api/webhooks/brevo.php); the nightly reconciliation'
                    . ' job below catches anything the webhook missed.'
            ),
            $this->unmatchedRow(),
            $this->jobRow('reconciliation', 'Last reconciliation run', $this->reconciliation),
            $this->jobRow('suppression_sync', 'Last suppression sync run', $this->suppressionSync),
            $this->cronFailuresRow(),
        ];
    }

    /**
     * True when the settings probe worked, the switch is on, Brevo is ready,
     * cron runs, and the unmatched and cron failure counts are 0
     *
     * A failed settings probe, an unreadable unmatched counter and an
     * unreadable cron failure log are not healthy. The reconciliation,
     * suppression sync and automatic send job states are not part of it.
     */
    public function allHealthy(): bool
    {
        return !$this->probeFailed
            && $this->enabled
            && $this->brevoReady()
            && $this->cronReady
            && $this->unmatchedCount === 0
            && $this->cronFailureCount() === 0;
    }

    /**
     * Give the feature switch state for the panel
     *
     * Turning on is blocked while Brevo is not ready. Turning off is never
     * blocked, so an admin can always stop verification in an incident.
     *
     * @param bool $canToggle True when the viewer is an admin
     * @return array{checked: bool, disabled: bool, disabledReason: string}
     *         `disabledReason` is '' when the switch has no reason to show
     */
    public function featureSwitch(bool $canToggle): array
    {
        $disabled = VerificationSettings::toggleShouldBeDisabled($canToggle, $this->enabled, $this->brevoReady());

        $reason = '';
        if (!$canToggle) {
            $reason = 'Only administrators can change this setting.';
        } elseif ($disabled) {
            $reason = 'Verification cannot be enabled until Brevo is ready. '
                . $this->brevoReasonText()
                . ' Fix this, then reload this page.';
        }

        return ['checked' => $this->enabled, 'disabled' => $disabled, 'disabledReason' => $reason];
    }

    /**
     * The automatic send job's enabled state
     */
    public function autoSendState(): CronJobEnabledState
    {
        return $this->autoSend['state'];
    }

    /**
     * When the automatic send job last claimed a run, or null if never
     */
    public function autoSendLastRunAt(): ?DateTimeImmutable
    {
        return $this->autoSend['lastRunAt'];
    }

    /**
     * Give the automatic send job's badge
     *
     * @return array{badgeClass: string, icon: string, text: string}
     */
    public function autoSendBadge(): array
    {
        return CronJobRunsReader::badgeFor(
            $this->autoSend['state'],
            $this->autoSend['lastRunAt'],
            $this->autoSend['lastFailureAt']
        );
    }

    /**
     * True when an admin paused the automatic send job
     */
    public function autoSendPaused(): bool
    {
        return $this->autoSend['state'] === CronJobEnabledState::DISABLED;
    }

    /**
     * True when the most recent claimed run is the one that threw
     *
     * The counts are written only at the end of a successful run. In this
     * state they belong to an earlier run, and the card must say so.
     */
    public function autoSendLastRunFailed(): bool
    {
        $lastRunAt     = $this->autoSend['lastRunAt'];
        $lastFailureAt = $this->autoSend['lastFailureAt'];

        return $this->autoSend['state'] === CronJobEnabledState::ENABLED
            && $lastFailureAt !== null
            && ($lastRunAt === null || $lastFailureAt >= $lastRunAt);
    }

    /**
     * Give the last run's outcome counts
     *
     * @return array{sent: int, skipped: int, failed: int}|null Null when the
     *         job never ran or the counts are unreadable; see
     *         {@see self::autoSendCountsUnreadable()}
     */
    public function autoSendCounts(): ?array
    {
        return $this->autoSendOutcome['counts'] ?? null;
    }

    /**
     * True when the counts read failed
     *
     * False with null counts is the routine "job never ran" case.
     */
    public function autoSendCountsUnreadable(): bool
    {
        return $this->autoSendOutcome === null || $this->autoSendOutcome['unreadable'];
    }

    /**
     * Give the earliest time the guard allows the next automatic run
     *
     * The real send time is the first cron heartbeat after it.
     *
     * @return DateTimeImmutable|null Null when the job never ran
     */
    public function autoSendNextEligibleAt(): ?DateTimeImmutable
    {
        return $this->autoSend['lastRunAt']?->add(
            new DateInterval('PT' . SendVerificationBatchJob::GUARD_INTERVAL_HOURS . 'H')
        );
    }

    /**
     * Give the state the Pause/Resume button asks for
     *
     * Anything that is not ENABLED offers Resume. That action can recover a
     * paused, missing or unreadable row, and it is idempotent.
     *
     * @return 'disable'|'enable'
     */
    public function autoSendDesiredState(): string
    {
        return $this->autoSend['state'] === CronJobEnabledState::ENABLED ? 'disable' : 'enable';
    }

    /**
     * Give the Status chip for one queue row
     *
     * The owner's profile state comes first, because it stops all
     * verification email to the owner (#2101): no profiles row, then a
     * block, then an opt-out. Otherwise the chip shows the car's latest
     * email event, as chosen by
     * CarRepository::findLatestEmailEventPerCarWithPrecedence().
     *
     * A bounce that applies also stops email, but the chip does not test it.
     * The chip reports what happened to the last email, and a hard-bounce
     * event already shows "Bounced" through the event path. The Bounced pill
     * and the row action read the bounce flag. So the pill and the chip can
     * differ, as EMAIL_SYSTEM.md describes. `$blocked` means the block
     * applies to the owner's current address (#2356).
     *
     * @param object|null $event        The chip event row, or null if the car has none
     * @param bool        $hasProfile   False when the owner has no profiles row
     * @param bool        $blocked      True when the owner's `profiles.email_blocked` applies to the current address
     * @param string|null $blockedCause The owner's `profiles.email_blocked_cause`, or null when unknown
     * @param bool        $optedOut     True when the owner's `profiles.email_opted_out` is set
     * @return array{kind: string, label: string, class: string, reason: string}
     *         `kind` is '' when there is no event to show
     */
    public static function chip(
        ?object $event,
        bool $hasProfile = true,
        bool $blocked = false,
        ?string $blockedCause = null,
        bool $optedOut = false
    ): array {
        $name   = isset($event->event) ? (string) $event->event : null;
        $reason = trim((string) ($event->reason ?? ''));

        $optedOutChip = ['kind' => 'opted_out', 'label' => 'Opted out', 'class' => 'text-bg-secondary', 'reason' => ''];

        if (!$hasProfile) {
            return ['kind' => 'no_profile', 'label' => 'No profile', 'class' => 'text-bg-warning', 'reason' => ''];
        }
        if ($blocked) {
            return ['kind' => 'blocked', 'label' => 'Blocked', 'class' => 'text-bg-danger',
                    'reason' => $blockedCause ?? 'cause unknown'];
        }
        if ($optedOut) {
            return $optedOutChip;
        }

        if ($name === null) {
            return ['kind' => '', 'label' => '', 'class' => '', 'reason' => ''];
        }
        if (in_array($name, EmailEventApplier::HARD_BOUNCE_EVENTS, true)) {
            return ['kind' => 'bounced', 'label' => 'Bounced', 'class' => 'text-bg-danger', 'reason' => $reason];
        }

        return match ($name) {
            'sent'         => ['kind' => 'sent', 'label' => 'Sent', 'class' => 'text-bg-secondary', 'reason' => ''],
            // An open or a click proves the message arrived.
            'delivered', 'unique_opened', 'opened', 'click'
                           => ['kind' => 'delivered', 'label' => 'Delivered', 'class' => 'text-bg-success', 'reason' => ''],
            'soft_bounce'  => ['kind' => 'soft_bounce', 'label' => 'Soft bounce', 'class' => 'text-bg-warning', 'reason' => $reason],
            'spam'         => ['kind' => 'spam', 'label' => 'Spam complaint', 'class' => 'text-bg-danger', 'reason' => ''],
            'unsubscribed' => $optedOutChip,
            // Brevo can add event names. Show the stored name, not a guess.
            default        => ['kind' => 'other', 'label' => ucfirst(str_replace('_', ' ', $name)),
                               'class' => 'text-bg-light', 'reason' => $reason],
        };
    }

    /**
     * The Brevo reason to show, with a fallback when the probe threw
     */
    private function brevoReasonText(): string
    {
        return $this->brevoNotReadyReason ?? 'The Brevo status could not be checked.';
    }

    /**
     * The failure log count, or null when it could not be read
     */
    private function cronFailureCount(): ?int
    {
        return $this->failureLog['count'] ?? null;
    }

    /**
     * @return array{key: string, label: string, badgeText: string, badgeClass: string, icon: string,
     *     detail: string, detailIcon: string, recent: list<array{loggedAt: string, message: string}>}
     */
    private function brevoRow(): array
    {
        if ($this->brevoReady()) {
            return self::row('brevo', 'Brevo ready', 'Ready', 'badge text-bg-success', 'fa-check-circle');
        }
        // A failed probe does not know the switch state, so it must not say
        // "switch is off".
        if ($this->probeFailed) {
            return self::row('brevo', 'Brevo ready', 'Not ready', 'badge text-bg-danger', 'fa-exclamation-circle',
                'The Brevo status could not be checked. Check the system log for VerificationConfigWarning.');
        }
        if (!$this->enabled) {
            return self::row('brevo', 'Brevo ready', 'Not checked (switch is off)', 'text-muted', 'fa-circle');
        }

        return self::row('brevo', 'Brevo ready', 'Not ready', 'badge text-bg-danger', 'fa-exclamation-circle',
            $this->brevoReasonText());
    }

    /**
     * @return array{key: string, label: string, badgeText: string, badgeClass: string, icon: string,
     *     detail: string, detailIcon: string, recent: list<array{loggedAt: string, message: string}>}
     */
    private function cronRow(): array
    {
        $lastSeen = $this->lastCronAt !== null ? 'last seen ' . $this->lastCronAt->format(self::TIME_FORMAT) : null;

        return self::row(
            'cron',
            'Cron running',
            $this->cronReady ? 'Running' : 'Stalled',
            $this->cronReady ? 'badge text-bg-success' : 'badge text-bg-warning',
            $this->cronReady ? 'fa-check-circle' : 'fa-exclamation-triangle',
            $lastSeen ?? ($this->cronReady ? '' : 'no cron request has ever been logged'),
            $lastSeen !== null ? 'fa-clock' : ''
        );
    }

    /**
     * @return array{key: string, label: string, badgeText: string, badgeClass: string, icon: string,
     *     detail: string, detailIcon: string, recent: list<array{loggedAt: string, message: string}>}
     */
    private function unmatchedRow(): array
    {
        // A rising count is this row's only signal, so an unreadable counter
        // must not look like a healthy zero.
        if ($this->unmatchedCount === null) {
            return self::row('unmatched', 'Unmatched recipients', 'Unavailable', 'badge text-bg-danger',
                'fa-exclamation-circle',
                'The unmatched-recipient counter could not be read. Check the system'
                    . ' log for VerificationConfigWarning entries.');
        }

        return self::row(
            'unmatched',
            'Unmatched recipients',
            (string) $this->unmatchedCount,
            $this->unmatchedCount > 0 ? 'badge text-bg-warning' : 'badge text-bg-success',
            '',
            'Verification-tagged Brevo events of our mail whose recipient matched no'
                . ' owner\'s account email, from the webhook receiver and the nightly'
                . ' reconciliation. The suppression sync does not count. A rising count'
                . ' usually means an owner changed their account email after mail went'
                . ' to the old address.'
        );
    }

    /**
     * @param array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable} $status
     * @return array{key: string, label: string, badgeText: string, badgeClass: string, icon: string,
     *     detail: string, detailIcon: string, recent: list<array{loggedAt: string, message: string}>}
     */
    private function jobRow(string $key, string $label, array $status): array
    {
        $badge = CronJobRunsReader::badgeFor($status['state'], $status['lastRunAt'], $status['lastFailureAt']);
        $lastRunAt = $status['lastRunAt'];

        return self::row(
            $key,
            $label,
            $badge['text'],
            $badge['badgeClass'],
            $badge['icon'],
            $lastRunAt !== null ? 'last ran ' . $lastRunAt->format(self::TIME_FORMAT) : '',
            $lastRunAt !== null ? 'fa-clock' : ''
        );
    }

    /**
     * @return array{key: string, label: string, badgeText: string, badgeClass: string, icon: string,
     *     detail: string, detailIcon: string, recent: list<array{loggedAt: string, message: string}>}
     */
    private function cronFailuresRow(): array
    {
        $count = $this->cronFailureCount();

        // This row is the fault channel itself. "No failures" is the one
        // reading that tells an admin to stop looking, so unreadable is never 0.
        if ($count === null) {
            return self::row('cron_failures', 'Cron job failures', 'Unavailable', 'badge text-bg-danger',
                'fa-exclamation-circle',
                'The cron failure log could not be read. Check the system log for'
                    . ' CronJobFailure entries directly.');
        }

        return self::row(
            'cron_failures',
            'Cron job failures',
            (string) $count,
            $count > 0 ? 'badge text-bg-danger' : 'badge text-bg-success',
            '',
            'CronJobFailure log entries in the last ' . CronJobFailureLogReader::LOOKBACK_DAYS . ' days, across every'
                . ' verification cron job. A repeating count here means an unattended job is'
                . ' failing every night — the per-job badges above only report each job\'s most'
                . ' recent run.',
            '',
            $this->failureLog['recent'] ?? []
        );
    }

    /**
     * @param list<array{loggedAt: string, message: string}> $recent
     * @return array{key: string, label: string, badgeText: string, badgeClass: string, icon: string,
     *     detail: string, detailIcon: string, recent: list<array{loggedAt: string, message: string}>}
     */
    private static function row(
        string $key,
        string $label,
        string $badgeText,
        string $badgeClass,
        string $icon,
        string $detail = '',
        string $detailIcon = '',
        array $recent = []
    ): array {
        return [
            'key'        => $key,
            'label'      => $label,
            'badgeText'  => $badgeText,
            'badgeClass' => $badgeClass,
            'icon'       => $icon,
            'detail'     => $detail,
            'detailIcon' => $detailIcon,
            'recent'     => $recent,
        ];
    }
}
