<?php

declare(strict_types=1);

namespace ElanRegistry\Retention;

use DateTimeImmutable;
use ElanRegistry\Exceptions\RetentionPurgeException;

/**
 * One data retention rule: one table, one window, one purge.
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
     * Delete or clear the rows that are older than the rule's window.
     *
     * The rule computes its own cutoff from `$now` and its window. A second
     * call with the same `$now` changes no row.
     *
     * @param DateTimeImmutable $now The run time
     * @return RetentionResult The counts, or a skip when the table does not exist
     * @throws RetentionPurgeException If a database query fails
     */
    public function purge(DateTimeImmutable $now): RetentionResult;
}
