<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use DateTimeImmutable;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\ExceptionLogSummary;
use ElanRegistry\Exceptions\RetentionPurgeException;
use ElanRegistry\LogCategories;
use ElanRegistry\Retention\RetentionPolicy;
use ElanRegistry\Retention\RetentionRule;

/**
 * RetentionPurgeJob - Runs each data retention rule once a day (#2384)
 *
 * The rules come from {@see RetentionPolicy::rules()}. Each rule deletes or
 * clears the rows of one table that are older than its window.
 *
 * RUNS WITH THE VERIFICATION SWITCH OFF. The job does not override
 * {@see AbstractCronJob::refusesWhileSwitchOff()}. The retention windows are
 * privacy promises, and they apply whether or not verification mail goes out.
 *
 * ONE RULE FAILS, THE OTHERS STILL RUN. Each rule runs in its own
 * `try/catch`. A rule result or failure gets one line under
 * `LOG_CATEGORY_RETENTION_PURGE`. After all rules ran, the job throws
 * {@see RetentionPurgeException} if one or more rules failed. Then
 * {@see AbstractCronJob::run()} stamps `last_failure_at` and pings `/fail`.
 *
 * Log lines hold rule names and counts only, never an address. A failure line
 * uses {@see ExceptionLogSummary}, not the exception message.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.10
 * @see https://github.com/elan-registry/registry/issues/2384
 */
final class RetentionPurgeJob extends AbstractCronJob
{
    /** er_cron_job_runs.job_name / CronJobGuard::ALLOWED_JOB_NAMES value. */
    public const JOB_NAME = 'retention_purge';

    /** healthchecks.io check slug, without the environment suffix. */
    private const HEALTHCHECK_SLUG = 'retention-purge';

    /**
     * Minimum hours between claimed runs.
     *
     * 20, not 24, for the reason {@see BrevoEventReconciliationJob} gives: a
     * hard 24 lets the run time move later by one cron interval each night.
     */
    private const GUARD_INTERVAL_HOURS = 20;

    private readonly DateTimeImmutable $now;

    /**
     * @param DatabaseInterface $db AbstractCronJob's enabled-check and guard connection
     * @param list<RetentionRule> $rules The rules to run, in order
     * @param DateTimeImmutable|null $now The run time that each rule gets; defaults to now
     * @param HealthcheckPinger|null $pinger Pings healthchecks.io after a
     *        claimed run; defaults to a real pinger
     */
    public function __construct(
        DatabaseInterface $db,
        private readonly array $rules,
        ?DateTimeImmutable $now = null,
        ?HealthcheckPinger $pinger = null,
    ) {
        parent::__construct($db, $pinger);
        $this->now = $now ?? new DateTimeImmutable();
    }

    /**
     * {@inheritDoc}
     */
    protected function jobName(): string
    {
        return self::JOB_NAME;
    }

    /**
     * {@inheritDoc}
     */
    protected function guardIntervalHours(): int
    {
        return self::GUARD_INTERVAL_HOURS;
    }

    /**
     * {@inheritDoc}
     */
    protected function healthcheckSlug(): string
    {
        return self::HEALTHCHECK_SLUG;
    }

    /**
     * Run each rule and log one line for each.
     *
     * @throws RetentionPurgeException If one or more rules failed
     */
    protected function execute(): void
    {
        $failedRules = [];

        foreach ($this->rules as $index => $rule) {
            // name() is inside the try, so a rule that throws from name()
            // cannot stop the rules after it.
            $ruleName = "rule #{$index}";

            try {
                $ruleName = $rule->name();
                $result = $rule->purge($this->now);
            } catch (\Throwable $e) {
                $failedRules[] = $ruleName;
                logger(0, LogCategories::LOG_CATEGORY_RETENTION_PURGE, sprintf(
                    "Retention rule '%s' failed: %s%s",
                    $ruleName,
                    ExceptionLogSummary::of($e),
                    self::stepDetail($e)
                ));
                continue;
            }

            if ($result->isSkipped()) {
                logger(0, LogCategories::LOG_CATEGORY_RETENTION_PURGE, sprintf(
                    "Retention rule '%s' skipped: %s",
                    $ruleName,
                    $result->skipReason
                ));
                continue;
            }

            logger(0, LogCategories::LOG_CATEGORY_RETENTION_PURGE, sprintf(
                "Retention rule '%s': %d deleted, %d cleared.",
                $ruleName,
                $result->deleted,
                $result->cleared
            ));
        }

        if ($failedRules !== []) {
            throw new RetentionPurgeException(
                'Retention rules failed: ' . implode(', ', $failedRules)
            );
        }
    }

    /**
     * The failed step and SQLSTATE for the log line.
     *
     * ExceptionLogSummary::of() gives only the class, code and file:line.
     * forStep() builds every step exception, so every step of every rule
     * shows the same file:line in RetentionPurgeException.php. Without this
     * text, an operator cannot tell a failed delete from a failed table check.
     *
     * @param \Throwable $e The exception that the rule threw
     * @return string The text to add to the line, or '' when there is none
     */
    private static function stepDetail(\Throwable $e): string
    {
        if (!$e instanceof RetentionPurgeException || $e->step() === null) {
            return '';
        }

        return sprintf(' (step: %s, SQLSTATE: %s)', $e->step(), $e->sqlState() ?? 'none');
    }
}
