<?php

declare(strict_types=1);

namespace ElanRegistry\Retention;

/**
 * The outcome of one {@see RetentionRule::purge()} call.
 *
 * `deleted` counts the rows that the rule deleted. `cleared` counts the rows
 * that the rule kept but removed personal data from. A skipped result has
 * zero counts and a reason, for example a table that does not exist.
 *
 * @package ElanRegistry\Retention
 * @since v2.30.10
 * @see https://github.com/elan-registry/registry/issues/2384
 */
final readonly class RetentionResult
{
    /**
     * @param string      $ruleName   The {@see RetentionRule::name()} of the rule
     * @param int         $deleted    Rows deleted
     * @param int         $cleared    Rows kept with their personal data cleared
     * @param string|null $skipReason Why the rule did nothing, or null when it ran
     * @throws \InvalidArgumentException If a count is negative
     */
    private function __construct(
        public string $ruleName,
        public int $deleted,
        public int $cleared,
        public ?string $skipReason,
    ) {
        if ($deleted < 0 || $cleared < 0) {
            throw new \InvalidArgumentException(sprintf(
                "RetentionResult for '%s': counts must not be negative (deleted %d, cleared %d)",
                $ruleName,
                $deleted,
                $cleared
            ));
        }
    }

    /**
     * A rule that ran.
     *
     * @param string $ruleName The rule name
     * @param int    $deleted  Rows deleted
     * @param int    $cleared  Rows kept with their personal data cleared
     * @return self The result
     * @throws \InvalidArgumentException If a count is negative
     */
    public static function purged(string $ruleName, int $deleted, int $cleared): self
    {
        return new self($ruleName, $deleted, $cleared, null);
    }

    /**
     * A rule that did nothing, for a reason that is not a failure.
     *
     * @param string $ruleName The rule name
     * @param string $reason   Why the rule did nothing. It must not hold personal data.
     * @return self The result
     */
    public static function skipped(string $ruleName, string $reason): self
    {
        return new self($ruleName, 0, 0, $reason);
    }

    /**
     * Whether the rule did nothing.
     *
     * @return bool True for a skipped result
     */
    public function isSkipped(): bool
    {
        return $this->skipReason !== null;
    }
}
