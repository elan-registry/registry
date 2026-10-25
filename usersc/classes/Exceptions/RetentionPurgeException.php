<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * RetentionPurgeException
 *
 * Thrown when a retention rule cannot purge its rows because of a database
 * error, and by RetentionPurgeJob after a run in which one or more rules
 * failed. A missing table is not an error: the rule returns a skip result.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.30.10
 * @see https://github.com/elan-registry/registry/issues/2384
 */
class RetentionPurgeException extends ElanRegistryException
{
    /** The rule step that failed, for example `delete`. Null for a job-level failure. */
    private ?string $step = null;

    /** The SQLSTATE of the failed query, when the database gave one. */
    private ?string $sqlState = null;

    /**
     * Build the exception for one failed step of a rule.
     *
     * The job log line prints the step and the SQLSTATE, because
     * ExceptionLogSummary::of() leaves out the message. The message can echo
     * a database error text, so it never goes to the log. A step name and a
     * SQLSTATE hold no personal data.
     *
     * @param string $message Technical message (not logged)
     * @param string $step A fixed step name, for example `table check`
     * @param string|null $sqlState The 5-character SQLSTATE, or null
     * @return self
     */
    public static function forStep(string $message, string $step, ?string $sqlState): self
    {
        $e = new self($message);
        $e->step = $step;
        $e->sqlState = $sqlState;

        return $e;
    }

    /**
     * Get the rule step that failed.
     *
     * @return string|null The step that failed, or null for a job-level failure
     */
    public function step(): ?string
    {
        return $this->step;
    }

    /**
     * Get the SQLSTATE of the failed query.
     *
     * @return string|null The SQLSTATE of the failed query, or null
     */
    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "The data retention purge failed. Please contact support.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_RETENTION_PURGE;
    }
}
