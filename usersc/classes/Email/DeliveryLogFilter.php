<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

/**
 * DeliveryLogFilter - the validated filters of the admin email delivery tab (#2102)
 *
 * Only {@see self::fromInput()} builds a filter, so each value is valid.
 * An invalid or wrong-typed input value gives the default, never an error.
 *
 * @package ElanRegistry\Email
 * @since v2.31.1
 * @see https://github.com/elan-registry/registry/issues/2102
 */
final readonly class DeliveryLogFilter
{
    /** The outcome filter value that shows every mail. */
    public const OUTCOME_ALL = 'all';

    /** The outcome filter value for "No outcome after N hours". */
    public const OUTCOME_STALE = 'stale';

    /** The default and the bounds of the "No outcome after N hours" value. */
    public const HOURS_DEFAULT = 2;
    public const HOURS_MIN = 1;
    public const HOURS_MAX = 720;

    /**
     * @param string|null $recipient An exact recipient address, or null for any
     * @param string|null $tag       One tag, commas folded to spaces, or null for any
     * @param string      $outcome   {@see self::OUTCOME_ALL}, {@see self::OUTCOME_STALE},
     *                               or a {@see DeliveryOutcome} value
     * @param int         $hours     The age in hours for {@see self::OUTCOME_STALE}, 1 to 720
     * @param int         $page      The requested page, 1 or more
     */
    private function __construct(
        public ?string $recipient,
        public ?string $tag,
        public string $outcome,
        public int $hours,
        public int $page,
    ) {
    }

    /**
     * Build a filter from raw request values.
     *
     * - `email`: kept only when it is a valid address.
     * - `tag`: commas folded to spaces, then trimmed. The plugin trims each
     *   stored tag and folds its commas (`sendinblueStoredTags()`). The value
     *   is not entity-decoded: the caller passes `ElanInput::raw()`, which does
     *   not encode, and the pager links send the tag back as it is. Empty
     *   gives null.
     * - `outcome`: kept only when it is on {@see self::allowedOutcomes()}.
     * - `hours`: an integer 1 to 720, else 2.
     * - `page`: an integer 1 or more, else 1.
     *
     * @param mixed $email   The raw `email` value
     * @param mixed $tag     The raw `tag` value
     * @param mixed $outcome The raw `outcome` value
     * @param mixed $hours   The raw `hours` value
     * @param mixed $page    The raw `page` value
     * @return self The validated filter
     */
    public static function fromInput(mixed $email, mixed $tag, mixed $outcome, mixed $hours, mixed $page): self
    {
        $recipient = is_string($email) ? trim($email) : '';
        $recipient = filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false ? $recipient : null;

        $tagValue = is_string($tag)
            ? trim(str_replace(',', ' ', $tag))
            : '';

        $outcomeValue = is_string($outcome) && in_array($outcome, self::allowedOutcomes(), true)
            ? $outcome
            : self::OUTCOME_ALL;

        return new self(
            $recipient,
            $tagValue !== '' ? $tagValue : null,
            $outcomeValue,
            self::intInRange($hours, self::HOURS_MIN, self::HOURS_MAX, self::HOURS_DEFAULT),
            self::intInRange($page, 1, PHP_INT_MAX, 1),
        );
    }

    /**
     * Get the allowed outcome filter values.
     *
     * @return list<string> `all`, each {@see DeliveryOutcome} value, and `stale`
     */
    public static function allowedOutcomes(): array
    {
        return [
            self::OUTCOME_ALL,
            ...array_map(static fn(DeliveryOutcome $o): string => $o->value, DeliveryOutcome::cases()),
            self::OUTCOME_STALE,
        ];
    }

    /**
     * Read an integer in a range from a raw value.
     *
     * Only an int or a string is read. An array or an object gives the default.
     *
     * @param mixed $value   The raw value
     * @param int   $min     The lowest allowed value
     * @param int   $max     The highest allowed value
     * @param int   $default The value for invalid input
     * @return int The value, or the default
     */
    private static function intInRange(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_int($value) && !is_string($value)) {
            return $default;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        return $int === false ? $default : $int;
    }
}
