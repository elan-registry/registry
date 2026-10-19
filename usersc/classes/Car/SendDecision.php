<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * SendDecision - The result of a {@see SendPolicy} check for one send
 *
 * `$flags` holds only the flags that block the checked mail kind, so each
 * name is a reason for refusal. The constructor puts them in the fixed
 * order of {@see self::FLAG_ORDER} and removes duplicates, so log lines and
 * messages built from them are stable. `$allowed` is true only when no flag
 * blocks the send.
 *
 * @package ElanRegistry\Car
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2360
 */
final readonly class SendDecision
{
    public const FLAG_NO_MATCH   = 'no_match';
    public const FLAG_NO_PROFILE = 'no_profile';
    // The profile flag names come from DeliverabilityFlagLog, so the two
    // classes cannot hold different names for one flag.
    public const FLAG_BOUNCED    = DeliverabilityFlagLog::FLAG_BOUNCED;
    public const FLAG_OPTED_OUT  = DeliverabilityFlagLog::FLAG_OPTED_OUT;
    public const FLAG_BLOCKED    = DeliverabilityFlagLog::FLAG_BLOCKED;

    /** The order of the flag names in {@see self::$flags} */
    public const FLAG_ORDER = [
        self::FLAG_NO_MATCH,
        self::FLAG_NO_PROFILE,
        self::FLAG_BOUNCED,
        self::FLAG_OPTED_OUT,
        self::FLAG_BLOCKED,
    ];

    public bool $allowed;

    /** @var list<string> */
    public array $flags;

    public ?string $bouncedAt;

    /** The count of users the decision covers: 1 for one owner, the match count for an address */
    public int $matchCount;

    /**
     * @param list<string> $flags      Flag names that block the send, from {@see self::FLAG_ORDER}
     * @param string|null  $bouncedAt  Latest `email_bounced_at`. It is kept only when
     *                                 `email_bounced` is in `$flags`.
     * @param int          $matchCount The count of users checked. Log lines name it.
     * @throws \InvalidArgumentException If a flag name is not in {@see self::FLAG_ORDER},
     *                                   if `$matchCount` is negative, or if
     *                                   `$matchCount` is 0 and `no_match` is
     *                                   not in `$flags` (or the reverse)
     */
    public function __construct(array $flags, ?string $bouncedAt = null, int $matchCount = 1)
    {
        // PHP does not enforce the list<string> type at runtime. Check each
        // element, so that a wrong-typed element gets the documented exception.
        foreach ($flags as $flag) {
            if (!is_string($flag) || !in_array($flag, self::FLAG_ORDER, true)) {
                throw new \InvalidArgumentException(
                    'SendDecision: unknown flag name: ' . (is_string($flag) ? $flag : get_debug_type($flag))
                );
            }
        }

        $flags = array_values(array_intersect(self::FLAG_ORDER, $flags));

        // No matching user and the no_match flag mean the same thing. A
        // decision that holds only one of them is a caller bug.
        if ($matchCount < 0 || ($matchCount === 0) !== in_array(self::FLAG_NO_MATCH, $flags, true)) {
            throw new \InvalidArgumentException(
                'SendDecision: match count ' . $matchCount . ' does not agree with the no_match flag'
            );
        }

        $this->flags     = $flags;
        $this->allowed   = $this->flags === [];
        $this->bouncedAt = in_array(self::FLAG_BOUNCED, $this->flags, true) ? $bouncedAt : null;
        $this->matchCount = $matchCount;
    }

    /**
     * True when the named flag blocks the send
     *
     * @param string $flag A name from {@see self::FLAG_ORDER}
     * @return bool
     */
    public function has(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }
}
