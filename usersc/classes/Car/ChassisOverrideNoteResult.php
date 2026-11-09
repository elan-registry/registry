<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * ChassisOverrideNoteResult - What one {@see ChassisOverrideNote::strip()} call did
 *
 * `comments` is the value to store in `cars.comments`. It is the original
 * comment when `changed` is false.
 *
 * `changed` is true only when the call removed one or more override notes.
 *
 * `manual` is true when the text that stays after the removal still names an
 * override or chassis validation. The call then keeps the original comment,
 * so `changed` is false. An admin must edit that comment.
 *
 * Create a result only with the named constructors, so `changed` and
 * `manual` are never both true.
 *
 * @package ElanRegistry\Car
 * @since v2.31.2
 * @see https://github.com/elan-registry/registry/issues/2613
 */
final readonly class ChassisOverrideNoteResult
{
    /**
     * @param string|null $comments The comment to store, or null when no text stays
     * @param bool        $changed  True when the call removed one or more notes
     * @param bool        $manual   True when the comment needs a manual edit
     */
    private function __construct(
        public ?string $comments,
        public bool $changed,
        public bool $manual,
    ) {}

    /**
     * The call removed no note. The comment stays as it is.
     *
     * @param string|null $comments The original comment
     */
    public static function unchanged(?string $comments): self
    {
        return new self($comments, false, false);
    }

    /**
     * The call removed one or more notes.
     *
     * @param string|null $comments The comment without the notes, or null when no text stays
     */
    public static function stripped(?string $comments): self
    {
        return new self($comments, true, false);
    }

    /**
     * The comment still names an override after the removal. The original
     * comment stays, and an admin must edit it.
     *
     * @param string $comments The original comment
     */
    public static function manual(string $comments): self
    {
        return new self($comments, false, true);
    }
}
