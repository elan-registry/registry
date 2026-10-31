<?php

declare(strict_types=1);

use ElanRegistry\Car\VerificationTabStatus;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\SendVerificationBatchJob;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\CronJobRunsReaderFakeDatabase;

/**
 * FakeDatabase for VerificationTabStatus::read().
 *
 * It adds three things to the cron fake: the settings row, the cron failure
 * log count, and a throw on request. A throw is the only way to reach the
 * catch blocks in read(), because the readers return a fault value for an
 * error() result.
 */
class VerificationTabStatusProbeFakeDatabase extends CronJobRunsReaderFakeDatabase
{
    /** @var array<string, mixed> Row for every er_verification_settings query */
    public array $settingsRow = [];

    /** Count for the cron failure log query. Null makes the count unreadable. */
    public ?int $failureCount = 0;

    /** When true, every er_verification_settings and plg_sendinblue query throws. */
    public bool $throwOnSettings = false;

    /** When true, only the plg_sendinblue query throws, after the switch read worked. */
    public bool $throwOnBrevoPlugin = false;

    /** First bound parameter for which error() throws. Null means never. */
    public ?string $throwOnErrorForParam = null;

    private bool $lastWasSettings = false;
    private bool $lastWasFailureCount = false;
    private ?string $lastParam = null;

    public function query(string $sql, array $params = []): self
    {
        $this->lastWasSettings     = stripos($sql, 'er_verification_settings') !== false;
        $this->lastWasFailureCount = stripos($sql, 'COUNT(*) AS cnt FROM logs') !== false;
        $this->lastParam           = isset($params[0]) ? (string) $params[0] : null;

        $isBrevoPlugin = stripos($sql, 'plg_sendinblue') !== false;
        if (($this->throwOnSettings && ($this->lastWasSettings || $isBrevoPlugin))
            || ($this->throwOnBrevoPlugin && $isBrevoPlugin)) {
            throw new RuntimeException('simulated settings outage');
        }

        parent::query($sql, $params);

        return $this;
    }

    public function error(): bool
    {
        if ($this->throwOnErrorForParam !== null && $this->lastParam === $this->throwOnErrorForParam) {
            throw new RuntimeException('simulated reader outage');
        }

        return parent::error();
    }

    public function first(bool $assoc = false): array|object
    {
        if ($this->lastWasSettings) {
            return $assoc ? $this->settingsRow : (object) $this->settingsRow;
        }
        if ($this->lastWasFailureCount && $this->failureCount !== null) {
            return ['cnt' => $this->failureCount];
        }

        return parent::first($assoc);
    }
}

/**
 * Tests for ElanRegistry\Car\VerificationTabStatus (#2330, #2364)
 *
 * Every status rule of the Verification tab is checked here by a direct call.
 * Expected texts are written out as literals, not computed from the code.
 *
 * @issue 2330
 */
#[Group('fast')]
final class VerificationTabStatusTest extends TestCase
{
    private const RECONCILIATION = BrevoEventReconciliationJob::JOB_NAME;
    private const SUPPRESSION    = BrevoSuppressionSyncJob::JOB_NAME;
    private const AUTO_SEND      = SendVerificationBatchJob::JOB_NAME;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['mockLogEntries'] = [];
    }

    /**
     * @return array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable}
     */
    private static function job(
        CronJobEnabledState $state = CronJobEnabledState::ENABLED,
        ?string $ranAt = '2026-10-18 02:00:00',
        ?string $failedAt = null
    ): array {
        return [
            'state'         => $state,
            'lastRunAt'     => $ranAt !== null ? new DateTimeImmutable($ranAt) : null,
            'lastFailureAt' => $failedAt !== null ? new DateTimeImmutable($failedAt) : null,
        ];
    }

    /**
     * A fully healthy status, with any value replaced by $o.
     *
     * @param array<string, mixed> $o
     */
    private static function makeStatus(array $o = []): VerificationTabStatus
    {
        $v = $o + [
            'probeFailed'     => false,
            'enabled'         => true,
            'brevoReason'     => null,
            'cronReady'       => true,
            'lastCronAt'      => new DateTimeImmutable('2026-10-19 14:05:00'),
            'unmatched'       => 0,
            'reconciliation'  => self::job(),
            'suppressionSync' => self::job(),
            'autoSend'        => self::job(),
            'autoSendOutcome' => ['counts' => ['sent' => 3, 'skipped' => 2, 'failed' => 1], 'unreadable' => false],
            'failureLog'      => ['count' => 0, 'recent' => []],
        ];

        return new VerificationTabStatus(
            $v['probeFailed'],
            $v['enabled'],
            $v['brevoReason'],
            $v['cronReady'],
            $v['lastCronAt'],
            $v['unmatched'],
            $v['reconciliation'],
            $v['suppressionSync'],
            $v['autoSend'],
            $v['autoSendOutcome'],
            $v['failureLog']
        );
    }

    /**
     * @param array<string, mixed> $o
     * @return array{key: string, label: string, badgeText: string, badgeClass: string, icon: string,
     *     detail: string, detailIcon: string, recent: list<array{loggedAt: string, message: string}>}
     */
    private static function row(string $key, array $o = []): array
    {
        foreach (self::makeStatus($o)->rows() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        self::fail("No row with key {$key}");
    }

    // ---- row order and shape ----

    #[Group('regression')]
    public function test_rows_always_come_in_the_documented_order(): void
    {
        $keys = array_column(self::makeStatus()->rows(), 'key');

        $this->assertSame(
            ['brevo', 'cron', 'webhook', 'unmatched', 'reconciliation', 'suppression_sync', 'cron_failures'],
            $keys
        );
        $this->assertSame(
            ['Brevo ready', 'Cron running', 'Webhook receiver', 'Unmatched recipients',
                'Last reconciliation run', 'Last suppression sync run', 'Cron job failures'],
            array_column(self::makeStatus()->rows(), 'label')
        );
    }

    public function test_rows_everyRowHasEveryKey(): void
    {
        $rows = self::makeStatus()->rows();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(
                ['key', 'label', 'badgeText', 'badgeClass', 'icon', 'detail', 'detailIcon', 'recent'],
                array_keys($row)
            );
        }
    }

    // ---- Brevo row ----

    public function test_brevoRow_ready_showsReadyWithNoDetail(): void
    {
        $row = self::row('brevo');

        $this->assertSame('Ready', $row['badgeText']);
        $this->assertSame('badge text-bg-success', $row['badgeClass']);
        $this->assertSame('', $row['detail']);
    }

    public function test_brevoRow_notReadyWithSwitchOn_showsTheReasonText(): void
    {
        $reason = 'The Brevo plugin is version 1.7.0. Version 1.8.0 or later is required, because older versions drop the verification tag.';

        $row = self::row('brevo', ['brevoReason' => $reason]);

        $this->assertSame('Not ready', $row['badgeText']);
        $this->assertSame('badge text-bg-danger', $row['badgeClass']);
        $this->assertSame($reason, $row['detail']);
    }

    public function test_brevoRow_notReadyWithSwitchOff_isNotChecked(): void
    {
        $row = self::row('brevo', ['enabled' => false, 'brevoReason' => 'No API key is saved in the Brevo plugin.']);

        $this->assertSame('Not checked (switch is off)', $row['badgeText']);
        $this->assertSame('text-muted', $row['badgeClass']);
        $this->assertSame('', $row['detail']);
    }

    public function test_brevoRow_readyWithSwitchOff_stillShowsReady(): void
    {
        $this->assertSame('Ready', self::row('brevo', ['enabled' => false])['badgeText']);
    }

    /** @return array<string, array{?bool}> */
    public static function switchStateProvider(): array
    {
        return ['switch value on' => [true], 'switch value off' => [false], 'switch value not read' => [null]];
    }

    /**
     * Approved deviation: a thrown settings probe is "not ready", not "ready".
     * The switch state may be unknown then, so it never says "switch is off".
     */
    #[DataProvider('switchStateProvider')]
    public function test_brevoRow_settingsProbeThrew_isNotReadyWithTheCouldNotBeCheckedReason(?bool $enabled): void
    {
        $status = self::makeStatus(['probeFailed' => true, 'enabled' => $enabled]);
        $row    = self::row('brevo', ['probeFailed' => true, 'enabled' => $enabled]);

        $this->assertFalse($status->brevoReady());
        $this->assertSame('Not ready', $row['badgeText']);
        $this->assertSame(
            'The Brevo status could not be checked. Check the system log for VerificationConfigWarning.',
            $row['detail']
        );
    }

    public function test_brevoReady_trueOnlyWhenNoProbeFailureAndNoReason(): void
    {
        $this->assertTrue(self::makeStatus()->brevoReady());
        $this->assertFalse(self::makeStatus(['brevoReason' => 'No API key is saved in the Brevo plugin.'])->brevoReady());
        $this->assertFalse(self::makeStatus(['probeFailed' => true])->brevoReady());
    }

    // ---- Cron running row ----

    public function test_cronRow_ready_showsRunningWithLastSeenTime(): void
    {
        $row = self::row('cron');

        $this->assertSame('Running', $row['badgeText']);
        $this->assertSame('badge text-bg-success', $row['badgeClass']);
        $this->assertSame('last seen Oct 19, 2026 2:05 PM', $row['detail']);
        $this->assertSame('fa-clock', $row['detailIcon']);
    }

    public function test_cronRow_readyWithNoTimestamp_hasNoDetail(): void
    {
        $row = self::row('cron', ['lastCronAt' => null]);

        $this->assertSame('Running', $row['badgeText']);
        $this->assertSame('', $row['detail']);
        $this->assertSame('', $row['detailIcon']);
    }

    public function test_cronRow_stalled_showsWarningWithLastSeenTime(): void
    {
        $row = self::row('cron', ['cronReady' => false]);

        $this->assertSame('Stalled', $row['badgeText']);
        $this->assertSame('badge text-bg-warning', $row['badgeClass']);
        $this->assertSame('last seen Oct 19, 2026 2:05 PM', $row['detail']);
    }

    public function test_cronRow_stalledAndNeverSeen_saysNoCronRequestWasLogged(): void
    {
        $row = self::row('cron', ['cronReady' => false, 'lastCronAt' => null]);

        $this->assertSame('Stalled', $row['badgeText']);
        $this->assertSame('no cron request has ever been logged', $row['detail']);
        $this->assertSame('', $row['detailIcon']);
    }

    /** A failed probe did not read the cron state, so "Stalled" would be a guess. */
    #[DataProvider('switchStateProvider')]
    public function test_cronRow_settingsProbeThrew_isUnavailableWithAReason(?bool $enabled): void
    {
        $row = self::row('cron', ['probeFailed' => true, 'enabled' => $enabled, 'cronReady' => null, 'lastCronAt' => null]);

        $this->assertSame('Unavailable', $row['badgeText']);
        $this->assertSame('badge text-bg-danger', $row['badgeClass']);
        $this->assertSame('fa-exclamation-circle', $row['icon']);
        $this->assertSame(
            'The cron state could not be read. Check the system log for VerificationConfigWarning entries.',
            $row['detail']
        );
        $this->assertSame('', $row['detailIcon']);
    }

    /** A cron state that was read before a later read threw is real, not a guess. */
    #[Group('regression')]
    public function test_cronRow_probeFailedAfterTheCronStateWasRead_showsRunning(): void
    {
        $row = self::row('cron', ['probeFailed' => true, 'cronReady' => true]);

        $this->assertSame('Running', $row['badgeText']);
        $this->assertSame('badge text-bg-success', $row['badgeClass']);
        $this->assertSame('last seen Oct 19, 2026 2:05 PM', $row['detail']);
    }

    // ---- Webhook row ----

    public function test_webhookRow_isAlwaysLive(): void
    {
        $row = self::row('webhook', ['probeFailed' => true, 'enabled' => false, 'cronReady' => false]);

        $this->assertSame('Live', $row['badgeText']);
        $this->assertSame('badge text-bg-success', $row['badgeClass']);
        $this->assertStringContainsString('app/api/webhooks/brevo.php', $row['detail']);
    }

    // ---- Unmatched recipients row (#2085) ----

    #[Group('regression')]
    public function test_unmatchedRow_zero_isSuccess(): void
    {
        $row = self::row('unmatched', ['unmatched' => 0]);

        $this->assertSame('0', $row['badgeText']);
        $this->assertSame('badge text-bg-success', $row['badgeClass']);
    }

    /** The boundary: one is already a warning. */
    #[Group('regression')]
    public function test_unmatchedRow_one_isWarning(): void
    {
        $row = self::row('unmatched', ['unmatched' => 1]);

        $this->assertSame('1', $row['badgeText']);
        $this->assertSame('badge text-bg-warning', $row['badgeClass']);
    }

    #[Group('regression')]
    public function test_unmatchedRow_many_isWarning(): void
    {
        $row = self::row('unmatched', ['unmatched' => 250]);

        $this->assertSame('250', $row['badgeText']);
        $this->assertSame('badge text-bg-warning', $row['badgeClass']);
    }

    #[Group('regression')]
    public function test_unmatchedRow_null_isUnavailableNotAGreenZero(): void
    {
        $row = self::row('unmatched', ['unmatched' => null]);

        $this->assertSame('Unavailable', $row['badgeText']);
        $this->assertSame('badge text-bg-danger', $row['badgeClass']);
        $this->assertStringContainsString('could not be read', $row['detail']);
    }

    // ---- Per-job rows (#2054, #2086, #2148) ----

    /** @return array<string, array{string, string}> */
    public static function jobRowProvider(): array
    {
        return [
            'reconciliation'   => ['reconciliation', 'reconciliation'],
            'suppression sync' => ['suppression_sync', 'suppressionSync'],
        ];
    }

    #[DataProvider('jobRowProvider')]
    public function test_jobRow_ranWithoutFailure_showsRanAndTheTime(string $key, string $option): void
    {
        $row = self::row($key, [$option => self::job(CronJobEnabledState::ENABLED, '2026-10-18 02:00:00')]);

        $this->assertSame('Ran', $row['badgeText']);
        $this->assertSame('badge text-bg-success', $row['badgeClass']);
        $this->assertSame('last ran Oct 18, 2026 2:00 AM', $row['detail']);
        $this->assertSame('fa-clock', $row['detailIcon']);
    }

    /** A never-run job has no "last ran" line, so the template cannot print a null time. */
    #[DataProvider('jobRowProvider')]
    #[Group('regression')]
    public function test_jobRow_neverRan_showsNeverRunWithNoLastRanLine(string $key, string $option): void
    {
        $row = self::row($key, [$option => self::job(CronJobEnabledState::ENABLED, null)]);

        $this->assertSame('Never run', $row['badgeText']);
        $this->assertSame('', $row['detail']);
        $this->assertSame('', $row['detailIcon']);
    }

    #[DataProvider('jobRowProvider')]
    #[Group('regression')]
    public function test_jobRow_lastRunFailed_showsLastRunFailed(string $key, string $option): void
    {
        $row = self::row($key, [
            $option => self::job(CronJobEnabledState::ENABLED, '2026-10-18 02:00:00', '2026-10-18 02:00:05'),
        ]);

        $this->assertSame('Last run failed', $row['badgeText']);
        $this->assertSame('badge text-bg-danger', $row['badgeClass']);
        $this->assertSame('last ran Oct 18, 2026 2:00 AM', $row['detail']);
    }

    /** Same-second run and failure is a failure: the badge compares with >=. */
    #[DataProvider('jobRowProvider')]
    #[Group('regression')]
    public function test_jobRow_failureInTheSameSecondAsTheRun_stillShowsLastRunFailed(string $key, string $option): void
    {
        $row = self::row($key, [
            $option => self::job(CronJobEnabledState::ENABLED, '2026-10-18 02:00:00', '2026-10-18 02:00:00'),
        ]);

        $this->assertSame('Last run failed', $row['badgeText']);
    }

    #[DataProvider('jobRowProvider')]
    public function test_jobRow_failureOlderThanTheRun_showsRan(string $key, string $option): void
    {
        $row = self::row($key, [
            $option => self::job(CronJobEnabledState::ENABLED, '2026-10-18 02:00:00', '2026-10-17 02:00:00'),
        ]);

        $this->assertSame('Ran', $row['badgeText']);
    }

    /** @return array<string, array{CronJobEnabledState, string, string}> */
    public static function nonEnabledStateProvider(): array
    {
        return [
            'disabled'   => [CronJobEnabledState::DISABLED, 'Paused', 'badge text-bg-secondary'],
            'missing'    => [CronJobEnabledState::MISSING, 'Status unavailable', 'badge text-bg-danger'],
            'unreadable' => [CronJobEnabledState::UNREADABLE, 'Status unavailable', 'badge text-bg-danger'],
        ];
    }

    #[DataProvider('nonEnabledStateProvider')]
    public function test_jobRow_nonEnabledState_showsItsOwnBadge(CronJobEnabledState $state, string $text, string $class): void
    {
        $row = self::row('reconciliation', ['reconciliation' => self::job($state, null)]);

        $this->assertSame($text, $row['badgeText']);
        $this->assertSame($class, $row['badgeClass']);
    }

    /** One job's state must not leak into another job's row. */
    #[Group('regression')]
    public function test_rows_eachJobShowsItsOwnStatus(): void
    {
        $status = self::makeStatus([
            'reconciliation'  => self::job(CronJobEnabledState::ENABLED, '2026-10-18 02:00:00'),
            'suppressionSync' => self::job(CronJobEnabledState::DISABLED, null),
            'autoSend'        => self::job(CronJobEnabledState::ENABLED, '2026-10-18 03:00:00', '2026-10-18 03:00:01'),
        ]);

        $texts = array_column($status->rows(), 'badgeText', 'key');

        $this->assertSame('Ran', $texts['reconciliation']);
        $this->assertSame('Paused', $texts['suppression_sync']);
        $this->assertSame('Last run failed', $status->autoSendBadge()['text']);
    }

    public function test_autoSendBadge_followsTheAutoSendJobOnly(): void
    {
        $status = self::makeStatus(['autoSend' => self::job(CronJobEnabledState::DISABLED, null)]);

        $this->assertSame(
            ['badgeClass' => 'badge text-bg-secondary', 'icon' => 'fa-pause-circle', 'text' => 'Paused'],
            $status->autoSendBadge()
        );
    }

    // ---- Cron job failures row (#2148) ----

    #[Group('regression')]
    public function test_cronFailuresRow_zero_isSuccessWithNoEntries(): void
    {
        $row = self::row('cron_failures');

        $this->assertSame('0', $row['badgeText']);
        $this->assertSame('badge text-bg-success', $row['badgeClass']);
        $this->assertSame([], $row['recent']);
        $this->assertStringContainsString('in the last 7 days', $row['detail']);
    }

    #[Group('regression')]
    public function test_cronFailuresRow_positive_isDangerAndKeepsTheRecentEntries(): void
    {
        $recent = [
            ['loggedAt' => '2026-10-19 02:00:00', 'message' => "Cron job 'brevo_reconciliation' failed"],
            ['loggedAt' => '2026-10-18 02:00:00', 'message' => "Cron job 'brevo_reconciliation' failed"],
        ];

        $row = self::row('cron_failures', ['failureLog' => ['count' => 2, 'recent' => $recent]]);

        $this->assertSame('2', $row['badgeText']);
        $this->assertSame('badge text-bg-danger', $row['badgeClass']);
        $this->assertSame($recent, $row['recent']);
    }

    public function test_cronFailuresRow_lookbackWindowMatchesTheReader(): void
    {
        $this->assertStringContainsString('last 7 days', self::row('cron_failures')['detail']);
    }

    /** "No failures" tells an admin to stop looking, so an unreadable log is never 0. */
    #[Group('regression')]
    public function test_cronFailuresRow_nullLog_isUnavailableNotAGreenZero(): void
    {
        foreach ([null, ['count' => null, 'recent' => []]] as $log) {
            $row = self::row('cron_failures', ['failureLog' => $log]);

            $this->assertSame('Unavailable', $row['badgeText']);
            $this->assertSame('badge text-bg-danger', $row['badgeClass']);
            $this->assertSame([], $row['recent']);
            $this->assertStringContainsString('could not be read', $row['detail']);
        }
    }

    // ---- allHealthy ----

    public function test_allHealthy_everyConditionTrue_isHealthy(): void
    {
        $this->assertTrue(self::makeStatus()->allHealthy());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function oneConditionFalseProvider(): array
    {
        return [
            'probe failed'         => [['probeFailed' => true]],
            'switch off'           => [['enabled' => false]],
            'switch not read'      => [['enabled' => null]],
            'cron not read'        => [['cronReady' => null]],
            'brevo not ready'      => [['brevoReason' => 'No API key is saved in the Brevo plugin.']],
            'cron stalled'         => [['cronReady' => false]],
            'unmatched positive'   => [['unmatched' => 1]],
            'unmatched unreadable' => [['unmatched' => null]],
            'failures positive'    => [['failureLog' => ['count' => 1, 'recent' => []]]],
            'failure count null'   => [['failureLog' => ['count' => null, 'recent' => []]]],
            'failure log failed'   => [['failureLog' => null]],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('oneConditionFalseProvider')]
    public function test_allHealthy_oneConditionFalse_isNotHealthy(array $override): void
    {
        $this->assertFalse(self::makeStatus($override)->allHealthy());
    }

    /**
     * A failed last run counts through the cron failure log, a pause is an
     * admin decision, and the automatic send job has its own card.
     */
    public function test_allHealthy_failedPausedOrAutoSendJobStatesDoNotChangeIt(): void
    {
        $status = self::makeStatus([
            'reconciliation'  => self::job(CronJobEnabledState::ENABLED, '2026-10-18 02:00:00', '2026-10-18 02:00:01'),
            'suppressionSync' => self::job(CronJobEnabledState::DISABLED, null),
            'autoSend'        => self::job(CronJobEnabledState::UNREADABLE, null),
        ]);

        $this->assertTrue($status->allHealthy());
    }

    /** @return array<string, array{string, CronJobEnabledState}> */
    public static function unknownJobStateProvider(): array
    {
        return [
            'reconciliation unreadable'   => ['reconciliation', CronJobEnabledState::UNREADABLE],
            'reconciliation missing'      => ['reconciliation', CronJobEnabledState::MISSING],
            'suppression sync unreadable' => ['suppressionSync', CronJobEnabledState::UNREADABLE],
            'suppression sync missing'    => ['suppressionSync', CronJobEnabledState::MISSING],
        ];
    }

    #[DataProvider('unknownJobStateProvider')]
    #[Group('regression')]
    public function test_allHealthy_jobStateUnknown_isNotHealthy(string $job, CronJobEnabledState $state): void
    {
        $this->assertFalse(self::makeStatus([$job => self::job($state, null)])->allHealthy());
    }

    /** @return array<string, array{string}> */
    public static function healthJobProvider(): array
    {
        return [
            'reconciliation'   => ['reconciliation'],
            'suppression sync' => ['suppressionSync'],
        ];
    }

    #[DataProvider('healthJobProvider')]
    public function test_allHealthy_pausedJob_staysHealthy(string $job): void
    {
        $this->assertTrue(self::makeStatus([$job => self::job(CronJobEnabledState::DISABLED, null)])->allHealthy());
    }

    // ---- Feature switch ----

    public function test_featureSwitch_adminWithBrevoReady_isEnabledAndHasNoReason(): void
    {
        $this->assertSame(
            ['checked' => true, 'disabled' => false, 'disabledReason' => '', 'unavailable' => false],
            self::makeStatus()->featureSwitch(true)
        );
    }

    public function test_featureSwitch_viewerWhoCannotToggle_isDisabledWithTheAdminOnlyReason(): void
    {
        $this->assertSame(
            [
                'checked'        => true,
                'disabled'       => true,
                'disabledReason' => 'Only administrators can change this setting.',
                'unavailable'    => false,
            ],
            self::makeStatus()->featureSwitch(false)
        );
    }

    public function test_featureSwitch_offAndBrevoNotReady_isBlockedWithTheBrevoReason(): void
    {
        $switch = self::makeStatus(['enabled' => false, 'brevoReason' => 'No API key is saved in the Brevo plugin.'])
            ->featureSwitch(true);

        $this->assertFalse($switch['checked']);
        $this->assertTrue($switch['disabled']);
        $this->assertSame(
            'Verification cannot be enabled until Brevo is ready. No API key is saved in the Brevo plugin.'
                . ' Fix this, then reload this page.',
            $switch['disabledReason']
        );
    }

    /** Turning off is never blocked, so an admin can stop verification in an incident. */
    public function test_featureSwitch_onAndBrevoNotReady_canStillBeTurnedOff(): void
    {
        $switch = self::makeStatus(['brevoReason' => 'No API key is saved in the Brevo plugin.'])->featureSwitch(true);

        $this->assertTrue($switch['checked']);
        $this->assertFalse($switch['disabled']);
        $this->assertSame('', $switch['disabledReason']);
    }

    /** @return array<string, array{bool, ?string}> */
    public static function probeFailedSwitchProvider(): array
    {
        return [
            'admin, no Brevo reason'   => [true, null],
            'admin, Brevo reason set'  => [true, 'No API key is saved in the Brevo plugin.'],
            'editor, no Brevo reason'  => [false, null],
            'editor, Brevo reason set' => [false, 'No API key is saved in the Brevo plugin.'],
        ];
    }

    /**
     * The switch state was not read, so no viewer sees a guessed state, the
     * admin-only reason or a Brevo reason.
     */
    #[DataProvider('probeFailedSwitchProvider')]
    #[Group('regression')]
    public function test_featureSwitch_switchStateNotRead_isUnavailableWithTheUnreadableReason(bool $canToggle, ?string $brevoReason): void
    {
        $this->assertSame(
            [
                'checked'        => false,
                'disabled'       => true,
                'disabledReason' => 'The switch state could not be read. Check the system log for'
                    . ' VerificationConfigWarning entries.',
                'unavailable'    => true,
            ],
            self::makeStatus(['probeFailed' => true, 'enabled' => null, 'brevoReason' => $brevoReason, 'cronReady' => null])
                ->featureSwitch($canToggle)
        );
    }

    /** An admin must always be able to turn verification off, even after a later read threw. */
    #[Group('regression')]
    public function test_featureSwitch_probeFailedAfterSwitchOnWasRead_adminCanTurnItOff(): void
    {
        $this->assertSame(
            ['checked' => true, 'disabled' => false, 'disabledReason' => '', 'unavailable' => false],
            self::makeStatus(['probeFailed' => true, 'enabled' => true, 'cronReady' => null])->featureSwitch(true)
        );
    }

    #[Group('regression')]
    public function test_featureSwitch_probeFailedAfterSwitchOnWasRead_editorSeesTheAdminOnlyReason(): void
    {
        $this->assertSame(
            [
                'checked'        => true,
                'disabled'       => true,
                'disabledReason' => 'Only administrators can change this setting.',
                'unavailable'    => false,
            ],
            self::makeStatus(['probeFailed' => true, 'enabled' => true, 'cronReady' => null])->featureSwitch(false)
        );
    }

    /** Turning on stays blocked, because the probe did not check the Brevo status. */
    #[Group('regression')]
    public function test_featureSwitch_probeFailedAfterSwitchOffWasRead_blocksTurningOnWithTheNotCheckedReason(): void
    {
        $switch = self::makeStatus(['probeFailed' => true, 'enabled' => false, 'cronReady' => null])
            ->featureSwitch(true);

        $this->assertFalse($switch['checked']);
        $this->assertTrue($switch['disabled']);
        $this->assertFalse($switch['unavailable']);
        $this->assertStringContainsString('Some verification settings could not be read.', $switch['disabledReason']);
    }

    // ---- Automatic Sending card state (#1885, #2148) ----

    public function test_autoSendCounts_returnedWhenTheJobRecordedThem(): void
    {
        $status = self::makeStatus();

        $this->assertSame(['sent' => 3, 'skipped' => 2, 'failed' => 1], $status->autoSendCounts());
        $this->assertFalse($status->autoSendCountsUnreadable());
    }

    /** The routine "job never ran" case: no counts and no fault. */
    public function test_autoSendCounts_neverRan_hasNullCountsAndIsNotUnreadable(): void
    {
        $status = self::makeStatus(['autoSendOutcome' => ['counts' => null, 'unreadable' => false]]);

        $this->assertNull($status->autoSendCounts());
        $this->assertFalse($status->autoSendCountsUnreadable());
    }

    /** The template's "Unavailable" branch. */
    public function test_autoSendCounts_unreadable_hasNullCountsAndIsUnreadable(): void
    {
        $status = self::makeStatus(['autoSendOutcome' => ['counts' => null, 'unreadable' => true]]);

        $this->assertNull($status->autoSendCounts());
        $this->assertTrue($status->autoSendCountsUnreadable());
    }

    public function test_autoSendCounts_probeThrew_isUnreadable(): void
    {
        $status = self::makeStatus(['autoSendOutcome' => null]);

        $this->assertNull($status->autoSendCounts());
        $this->assertTrue($status->autoSendCountsUnreadable());
    }

    /**
     * The template's "From an earlier successful run" branch: counts are
     * written only after a successful run, so when the latest run failed
     * the counts belong to an earlier run.
     */
    #[Group('regression')]
    public function test_autoSendLastRunFailed_latestRunThrew_isTrueAndCountsStayAvailable(): void
    {
        $status = self::makeStatus([
            'autoSend' => self::job(CronJobEnabledState::ENABLED, '2026-10-18 03:00:00', '2026-10-18 03:00:02'),
        ]);

        $this->assertTrue($status->autoSendLastRunFailed());
        $this->assertSame(['sent' => 3, 'skipped' => 2, 'failed' => 1], $status->autoSendCounts());
    }

    /** @return array<string, array{array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable}, bool}> */
    public static function lastRunFailedProvider(): array
    {
        return [
            'failure after run'     => [self::job(CronJobEnabledState::ENABLED, '2026-10-18 03:00:00', '2026-10-18 03:00:02'), true],
            'failure same second'   => [self::job(CronJobEnabledState::ENABLED, '2026-10-18 03:00:00', '2026-10-18 03:00:00'), true],
            'failure with no run'   => [self::job(CronJobEnabledState::ENABLED, null, '2026-10-18 03:00:00'), true],
            'later run recovered'   => [self::job(CronJobEnabledState::ENABLED, '2026-10-18 03:00:00', '2026-10-17 03:00:00'), false],
            'never failed'          => [self::job(CronJobEnabledState::ENABLED, '2026-10-18 03:00:00', null), false],
            'paused after failure'  => [self::job(CronJobEnabledState::DISABLED, '2026-10-18 03:00:00', '2026-10-18 03:00:02'), false],
            'unreadable'            => [self::job(CronJobEnabledState::UNREADABLE, null, null), false],
        ];
    }

    /** @param array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable} $autoSend */
    #[DataProvider('lastRunFailedProvider')]
    #[Group('regression')]
    public function test_autoSendLastRunFailed_followsTheTimestampsAndState(array $autoSend, bool $expected): void
    {
        $this->assertSame($expected, self::makeStatus(['autoSend' => $autoSend])->autoSendLastRunFailed());
    }

    public function test_autoSendPausedAndDesiredState_followTheJobState(): void
    {
        $enabled = self::makeStatus();
        $this->assertFalse($enabled->autoSendPaused());
        $this->assertSame('disable', $enabled->autoSendDesiredState());
        $this->assertSame(CronJobEnabledState::ENABLED, $enabled->autoSendState());

        $paused = self::makeStatus(['autoSend' => self::job(CronJobEnabledState::DISABLED, null)]);
        $this->assertTrue($paused->autoSendPaused());
        $this->assertSame('enable', $paused->autoSendDesiredState());
    }

    /** Resume can recover a missing or unreadable row, so every non-ENABLED state offers it. */
    #[DataProvider('resumeStateProvider')]
    public function test_autoSendDesiredState_anyNonEnabledState_offersResume(CronJobEnabledState $state): void
    {
        $status = self::makeStatus(['autoSend' => self::job($state, null)]);

        $this->assertSame('enable', $status->autoSendDesiredState());
        $this->assertFalse($status->autoSendLastRunFailed());
    }

    /** @return array<string, array{CronJobEnabledState}> */
    public static function resumeStateProvider(): array
    {
        return [
            'disabled'   => [CronJobEnabledState::DISABLED],
            'missing'    => [CronJobEnabledState::MISSING],
            'unreadable' => [CronJobEnabledState::UNREADABLE],
        ];
    }

    public function test_autoSendNextEligibleAt_isTwentyHoursAfterTheLastRun(): void
    {
        $status = self::makeStatus(['autoSend' => self::job(CronJobEnabledState::ENABLED, '2026-10-18 08:30:00')]);

        $next = $status->autoSendNextEligibleAt();

        $this->assertNotNull($next);
        $this->assertSame('2026-10-19 04:30:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-18 08:30:00', $status->autoSendLastRunAt()?->format('Y-m-d H:i:s'));
    }

    public function test_autoSendNextEligibleAt_neverRan_isNull(): void
    {
        $status = self::makeStatus(['autoSend' => self::job(CronJobEnabledState::ENABLED, null)]);

        $this->assertNull($status->autoSendNextEligibleAt());
        $this->assertNull($status->autoSendLastRunAt());
    }

    public function test_probeFailedAndEnabled_reportTheConstructorValues(): void
    {
        $this->assertFalse(self::makeStatus()->probeFailed());
        $this->assertTrue(self::makeStatus(['probeFailed' => true])->probeFailed());
        $this->assertTrue(self::makeStatus()->featureSwitch(true)['checked']);
        $this->assertFalse(self::makeStatus(['enabled' => false])->featureSwitch(true)['checked']);
    }

    // ---- read(): fault domains (#2086, #2054, #2085, #2148) ----

    private static function healthyJobsDb(): VerificationTabStatusProbeFakeDatabase
    {
        $db = new VerificationTabStatusProbeFakeDatabase();
        $db->withRow(self::RECONCILIATION, true, '2026-10-18 02:00:00');
        $db->withRow(self::SUPPRESSION, true, '2026-10-18 02:30:00');
        $db->withRow(self::AUTO_SEND, true, '2026-10-18 03:00:00');
        $db->withOutcomeCounts(self::AUTO_SEND, 4, 5, 6);

        return $db;
    }

    /**
     * @return array<string, array{badgeText: string, badgeClass: string}>
     */
    private static function badgesByKey(VerificationTabStatus $status): array
    {
        $out = [];
        foreach ($status->rows() as $row) {
            $out[$row['key']] = ['badgeText' => $row['badgeText'], 'badgeClass' => $row['badgeClass']];
        }

        return $out;
    }

    /**
     * @param array<string, array{badgeText: string, badgeClass: string}> $a
     * @param array<string, array{badgeText: string, badgeClass: string}> $b
     * @return list<string>
     */
    private static function differingKeys(array $a, array $b): array
    {
        return array_keys(array_filter($a, static fn (array $badge, string $key): bool => $badge !== $b[$key], ARRAY_FILTER_USE_BOTH));
    }

    #[Group('regression')]
    public function test_read_readsEachJobsOwnRow(): void
    {
        $db = self::healthyJobsDb();
        $db->withRow(self::RECONCILIATION, true, '2026-10-18 02:00:00');
        $db->withRow(self::SUPPRESSION, false);

        $status = VerificationTabStatus::read($db, 7);

        $badges = self::badgesByKey($status);
        $this->assertSame('Ran', $badges['reconciliation']['badgeText']);
        $this->assertSame('Paused', $badges['suppression_sync']['badgeText']);
        $this->assertSame(CronJobEnabledState::ENABLED, $status->autoSendState());
        $this->assertSame(['sent' => 4, 'skipped' => 5, 'failed' => 6], $status->autoSendCounts());
    }

    /** @return array<string, array{string, string}> */
    public static function jobFailureProvider(): array
    {
        return [
            'reconciliation'   => [self::RECONCILIATION, 'reconciliation'],
            'suppression sync' => [self::SUPPRESSION, 'suppression_sync'],
        ];
    }

    #[DataProvider('jobFailureProvider')]
    #[Group('regression')]
    public function test_read_failureTimestampReachesTheBadge(string $jobName, string $rowKey): void
    {
        $db = self::healthyJobsDb();
        $db->withRow($jobName, true, '2026-10-18 02:00:00', '2026-10-18 02:00:03');

        $badges = self::badgesByKey(VerificationTabStatus::read($db, 7));

        $this->assertSame('Last run failed', $badges[$rowKey]['badgeText']);
    }

    #[Group('regression')]
    public function test_read_autoSendFailureTimestampReachesTheCardState(): void
    {
        $db = self::healthyJobsDb();
        $db->withRow(self::AUTO_SEND, true, '2026-10-18 03:00:00', '2026-10-18 03:00:04');

        $status = VerificationTabStatus::read($db, 7);

        $this->assertTrue($status->autoSendLastRunFailed());
        $this->assertSame('Last run failed', $status->autoSendBadge()['text']);
    }

    public function test_read_settingsValuesReachTheRows(): void
    {
        $db = self::healthyJobsDb();
        $db->settingsRow = [
            'enabled'                   => 1,
            'unmatched_recipient_count' => 4,
            'last_cron_request_at'      => date('Y-m-d H:i:s'),
        ];

        $status = VerificationTabStatus::read($db, 7);
        $badges = self::badgesByKey($status);

        $this->assertTrue($status->featureSwitch(true)['checked']);
        $this->assertFalse($status->probeFailed());
        $this->assertSame('4', $badges['unmatched']['badgeText']);
        $this->assertSame('badge text-bg-warning', $badges['unmatched']['badgeClass']);
        $this->assertSame('Running', $badges['cron']['badgeText']);
    }

    /** Only the webhook receiver and the reconciliation increment the counter. */
    public function test_unmatchedRow_detailNamesOnlyTheSourcesThatCount(): void
    {
        $this->assertSame(
            'Verification-tagged Brevo events of our mail whose recipient matched no owner\'s account email,'
                . ' from the webhook receiver and the nightly reconciliation. The suppression sync does not'
                . ' count. A rising count usually means an owner changed their account email after mail went'
                . ' to the old address.',
            self::row('unmatched', ['unmatched' => 2])['detail']
        );
    }

    #[Group('regression')]
    public function test_read_cronFailureCountReachesTheRow(): void
    {
        $db = self::healthyJobsDb();
        $db->failureCount = 3;

        $badges = self::badgesByKey(VerificationTabStatus::read($db, 7));

        $this->assertSame('3', $badges['cron_failures']['badgeText']);
        $this->assertSame('badge text-bg-danger', $badges['cron_failures']['badgeClass']);
    }

    /** @return array<string, array{string, string}> */
    public static function failedJobRowProvider(): array
    {
        return [
            'reconciliation'   => [self::RECONCILIATION, 'reconciliation'],
            'suppression sync' => [self::SUPPRESSION, 'suppression_sync'],
        ];
    }

    /**
     * One failed job read marks only that job's row Unavailable
     *
     * test_allHealthy_jobStateUnknown_isNotHealthy pins the allHealthy() side.
     */
    #[DataProvider('failedJobRowProvider')]
    #[Group('regression')]
    public function test_read_oneJobReadFails_marksOnlyThatRowUnavailable(string $jobName, string $rowKey): void
    {
        $baseline = self::badgesByKey(VerificationTabStatus::read(self::healthyJobsDb(), 7));

        $db = self::healthyJobsDb();
        $db->withError($jobName);
        $status = VerificationTabStatus::read($db, 7);

        $badges = self::badgesByKey($status);
        $this->assertSame([$rowKey], self::differingKeys($badges, $baseline));
        $this->assertSame('Status unavailable', $badges[$rowKey]['badgeText']);
        $this->assertSame('badge text-bg-danger', $badges[$rowKey]['badgeClass']);
    }

    public function test_read_autoSendJobReadFails_isUnreadableAndOffersResume(): void
    {
        $baseline = self::badgesByKey(VerificationTabStatus::read(self::healthyJobsDb(), 7));

        $db = self::healthyJobsDb();
        $db->withError(self::AUTO_SEND);
        $status = VerificationTabStatus::read($db, 7);

        $this->assertSame(CronJobEnabledState::UNREADABLE, $status->autoSendState());
        $this->assertSame('enable', $status->autoSendDesiredState());
        $this->assertSame('Status unavailable', $status->autoSendBadge()['text']);
        $this->assertSame([], self::differingKeys(self::badgesByKey($status), $baseline));
    }

    public function test_read_autoSendCountsReadFails_marksOnlyTheCountsUnreadable(): void
    {
        $db = self::healthyJobsDb();
        $db->withOutcomeCountsError(self::AUTO_SEND);

        $status = VerificationTabStatus::read($db, 7);

        $this->assertNull($status->autoSendCounts());
        $this->assertTrue($status->autoSendCountsUnreadable());
        $this->assertSame(CronJobEnabledState::ENABLED, $status->autoSendState());
    }

    public function test_read_autoSendNeverRecordedCounts_isNotUnreadable(): void
    {
        $db = self::healthyJobsDb();
        $db->withOutcomeCounts(self::AUTO_SEND, null);

        $status = VerificationTabStatus::read($db, 7);

        $this->assertNull($status->autoSendCounts());
        $this->assertFalse($status->autoSendCountsUnreadable());
    }

    #[Group('regression')]
    public function test_read_failureLogReadFails_marksOnlyTheFailuresRowUnavailable(): void
    {
        $baseline = self::badgesByKey(VerificationTabStatus::read(self::healthyJobsDb(), 7));

        $db = self::healthyJobsDb();
        $db->withError(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE);
        $status = VerificationTabStatus::read($db, 7);

        $badges = self::badgesByKey($status);
        $this->assertSame(['cron_failures'], self::differingKeys($badges, $baseline));
        $this->assertSame('Unavailable', $badges['cron_failures']['badgeText']);
        $this->assertFalse($status->allHealthy());
    }

    #[Group('regression')]
    public function test_read_suppressionSyncProbeThrows_isUnreadableAndLeavesReconciliationAlone(): void
    {
        $db = self::healthyJobsDb();
        $db->throwOnErrorForParam = self::SUPPRESSION;

        $status = VerificationTabStatus::read($db, 7);

        $badges = self::badgesByKey($status);
        $this->assertSame('Ran', $badges['reconciliation']['badgeText']);
        $this->assertSame('Status unavailable', $badges['suppression_sync']['badgeText']);
        $this->assertSame('0', $badges['cron_failures']['badgeText']);
        $this->assertSame(CronJobEnabledState::ENABLED, $status->autoSendState());
        $this->assertLogged(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, 'Suppression sync status probe failed [RuntimeException]: simulated reader outage');
    }

    #[Group('regression')]
    public function test_read_reconciliationProbeThrows_isUnreadableAndLeavesTheOtherRowsAlone(): void
    {
        $db = self::healthyJobsDb();
        $db->throwOnErrorForParam = self::RECONCILIATION;

        $status = VerificationTabStatus::read($db, 7);

        $badges = self::badgesByKey($status);
        $this->assertSame('Status unavailable', $badges['reconciliation']['badgeText']);
        $this->assertSame('Ran', $badges['suppression_sync']['badgeText']);
        $this->assertSame(CronJobEnabledState::ENABLED, $status->autoSendState());
        $this->assertLogged(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, 'Reconciliation status probe failed [RuntimeException]: simulated reader outage');
    }

    public function test_read_autoSendProbeThrows_isUnreadableWithUnreadableCounts(): void
    {
        $db = self::healthyJobsDb();
        $db->throwOnErrorForParam = self::AUTO_SEND;

        $status = VerificationTabStatus::read($db, 7);

        $this->assertSame(CronJobEnabledState::UNREADABLE, $status->autoSendState());
        $this->assertTrue($status->autoSendCountsUnreadable());
        $this->assertSame('Ran', self::badgesByKey($status)['reconciliation']['badgeText']);
        $this->assertLogged(
            LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
            'Automatic send status or outcome counts probe failed [RuntimeException]: simulated reader outage'
        );
    }

    #[Group('regression')]
    public function test_read_failureLogProbeThrows_marksOnlyTheFailuresRowUnavailable(): void
    {
        $db = self::healthyJobsDb();
        $db->throwOnErrorForParam = LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE;

        $status = VerificationTabStatus::read($db, 7);

        $badges = self::badgesByKey($status);
        $this->assertSame('Unavailable', $badges['cron_failures']['badgeText']);
        $this->assertSame('Ran', $badges['reconciliation']['badgeText']);
        $this->assertLogged(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, 'Cron failure log summary probe failed [RuntimeException]: simulated reader outage');
    }

    /** The settings fault must not blank the cron rows (#2086 fault domains). */
    #[Group('regression')]
    public function test_read_settingsProbeThrows_isNotHealthyAndNotBrevoReadyButJobRowsStillRead(): void
    {
        $db = self::healthyJobsDb();
        $db->throwOnSettings = true;

        $status = VerificationTabStatus::read($db, 7);

        $this->assertTrue($status->probeFailed());
        $this->assertFalse($status->brevoReady());
        $this->assertFalse($status->allHealthy());
        $badges = self::badgesByKey($status);
        $this->assertSame('Ran', $badges['reconciliation']['badgeText']);
        $this->assertSame('Ran', $badges['suppression_sync']['badgeText']);
        $this->assertSame('0', $badges['cron_failures']['badgeText']);
        $this->assertLogged(
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            'Verification tab status probe failed [RuntimeException]: simulated settings outage'
        );
    }

    /**
     * read() leaves the switch value null when the settings probe throws first.
     * The real switch state is unknown, so the row must not say "switch is off",
     * and the switch and cron row must not show a guessed value.
     */
    #[Group('regression')]
    public function test_read_settingsProbeThrows_brevoRowIsNotReadyAndSwitchAndCronAreUnavailable(): void
    {
        $db = self::healthyJobsDb();
        $db->throwOnSettings = true;

        $status = VerificationTabStatus::read($db, 7);
        $brevo  = $status->rows()[0];

        $this->assertSame('brevo', $brevo['key']);
        $this->assertSame('Not ready', $brevo['badgeText']);
        $this->assertSame('badge text-bg-danger', $brevo['badgeClass']);
        $this->assertSame(
            'The Brevo status could not be checked. Check the system log for VerificationConfigWarning.',
            $brevo['detail']
        );

        $switch = $status->featureSwitch(true);
        $this->assertTrue($switch['unavailable']);
        $this->assertFalse($switch['checked']);
        $this->assertTrue($switch['disabled']);
        $this->assertSame(
            'The switch state could not be read. Check the system log for VerificationConfigWarning entries.',
            $switch['disabledReason']
        );

        $this->assertSame('Unavailable', self::badgesByKey($status)['cron']['badgeText']);
    }

    /**
     * The switch read worked and the later Brevo read threw. The admin keeps
     * a real, usable switch, so verification can be turned off in an incident.
     */
    #[Group('regression')]
    public function test_read_brevoReadThrowsAfterSwitchOnWasRead_adminGetsACheckedUsableSwitch(): void
    {
        $db = self::healthyJobsDb();
        $db->settingsRow        = ['enabled' => 1];
        $db->throwOnBrevoPlugin = true;

        $status = VerificationTabStatus::read($db, 7);

        $this->assertTrue($status->probeFailed());
        $this->assertSame(
            ['checked' => true, 'disabled' => false, 'disabledReason' => '', 'unavailable' => false],
            $status->featureSwitch(true)
        );
        $this->assertSame('Unavailable', self::badgesByKey($status)['cron']['badgeText']);
    }

    public function test_read_logsAreAttributedToTheActingUser(): void
    {
        $db = self::healthyJobsDb();
        $db->throwOnSettings = true;

        VerificationTabStatus::read($db, 42);

        $this->assertSame(42, $GLOBALS['mockLogEntries'][0]['user_id']);
    }

    private function assertLogged(string $category, string $messagePrefix): void
    {
        $entries = $GLOBALS['mockLogEntries'] ?? [];
        $this->assertNotEmpty($entries, 'Nothing was logged');
        foreach ($entries as $entry) {
            if ($entry['category'] === $category && str_starts_with((string) $entry['message'], $messagePrefix)) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail("No log entry in {$category} starting with: {$messagePrefix}");
    }
}
