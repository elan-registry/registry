<?php

declare(strict_types=1);

namespace ElanRegistry\Retention;

use DateTimeImmutable;
use ElanRegistry\Exceptions\RetentionPurgeException;

/**
 * One data retention rule for one table.
 *
 * A purge rule deletes or clears the rows that are older than its window. An
 * alarm rule ({@see DeletedUserAddressesBacklogRule}) deletes nothing. It
 * throws when it finds rows that show that a privacy step did not finish, so
 * that the run fails and healthchecks.io alerts.
 *
 * {@see RetentionPolicy::rules()} holds the list of rules, and
 * {@see \ElanRegistry\Cron\RetentionPurgeJob} runs each of them once a day.
 * Each rule writes its table name as a literal in its own SQL. A table name
 * never comes from configuration.
 *
 * @package ElanRegistry\Retention
 * @since v2.30.10
 * @see https://github.com/elan-registry/registry/issues/2384
 */
interface RetentionRule
{
    /**
     * A stable name for log lines, for example `sendinblue_messages`.
     *
     * @return string The rule name
     */
    public function name(): string;

    /**
     * Apply the rule to its table.
     *
     * A purge rule deletes or clears the rows that are older than its window.
     * It computes its cutoff from `$now`. A second call with the same `$now`
     * changes no row. An alarm rule changes no row. It can use the database
     * clock in place of `$now` when the database wrote the column it reads,
     * and it then says why in its class comment.
     *
     * @param DateTimeImmutable $now The run time
     * @return RetentionResult The counts, or a skip when the table does not exist
     * @throws RetentionPurgeException If a database query fails, or if an alarm
     *                                 rule finds its condition
     */
    public function purge(DateTimeImmutable $now): RetentionResult;
}
