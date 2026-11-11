<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * ChassisOverrideNote - Removes chassis override notes from a car comment
 *
 * When an admin overrides chassis validation, the save code appends a note to
 * `cars.comments`. These notes are not owner text. This class removes them.
 * It does not use the database.
 *
 * Each of these forms is a note:
 *
 * - `CHASSIS VALIDATION OVERRIDDEN: 2024-05-01 12:34:56 - Admin override used for chassis validation.`
 * - `CHASSIS VALIDATION OVERRIDDEN: 2024-05-01 12:34:56`
 * - `CHASSIS VALIDATION OVERRIDDEN:2024-05-01 12:34:56`
 *
 * Today `app/api/cars/save.php` writes only the short form,
 * `CHASSIS VALIDATION OVERRIDDEN: <Y-m-d H:i:s>`. The long form and the
 * no-space form occur only in older data.
 *
 * The class removes the line breaks before a note, not after it. Thus a note
 * at the start of a comment leaves the line break that follows it, because
 * owner text is never trimmed.
 *
 * {@see self::MANUAL_PATTERN} also matches ordinary owner words, for example
 * "override valve". Such a car goes to the manual edit list. That is the safe
 * direction: the fix script does not change a comment that it cannot read.
 *
 * @package ElanRegistry\Car
 * @since v2.31.2
 * @see https://github.com/elan-registry/registry/issues/2613
 */
final class ChassisOverrideNote
{
    /**
     * One note, with the line breaks before it and the spaces or tabs at the
     * end of the owner line before it. `(?!\d)` stops a match when more
     * digits follow the seconds. When spaces or tabs sit between a line break
     * and the note, the match removes them but keeps that line break.
     * `save.php` never writes them.
     */
    private const NOTE_PATTERN = '/[ \t]*+(?:\r\n|\r|\n)*+CHASSIS VALIDATION OVERRIDDEN: ?\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(?!\d)(?: - Admin override used for chassis validation\.)?/';

    /**
     * Text that names an override but is not a note in a known form.
     */
    private const MANUAL_PATTERN = '/overrid|chassis validation/i';

    /**
     * Remove every chassis override note from a car comment.
     *
     * The text that stays is not trimmed, so other owner text does not change.
     * When only whitespace stays, the result comment is null.
     *
     * When the text that stays still matches {@see self::MANUAL_PATTERN}, the
     * result keeps the original comment, with `changed` false and `manual`
     * true.
     *
     * @param string|null $comments The value of `cars.comments`
     * @return ChassisOverrideNoteResult The comment to store, and what the call did
     * @throws \RuntimeException When the PCRE engine fails (for example, a backtrack limit)
     */
    public static function strip(?string $comments): ChassisOverrideNoteResult
    {
        if ($comments === null) {
            return ChassisOverrideNoteResult::unchanged(null);
        }

        $count = 0;
        $remaining = preg_replace(self::NOTE_PATTERN, '', $comments, -1, $count);
        if ($remaining === null) {
            throw new \RuntimeException('Chassis override note pattern failed: ' . preg_last_error_msg());
        }

        // Override text with no known note also needs a manual edit: the fix
        // script must not clear the flag and leave a note it cannot remove.
        $manual = preg_match(self::MANUAL_PATTERN, $remaining);
        if ($manual === false) {
            throw new \RuntimeException('Chassis override manual pattern failed: ' . preg_last_error_msg());
        }
        if ($manual === 1) {
            return ChassisOverrideNoteResult::manual($comments);
        }

        if ($count === 0) {
            return ChassisOverrideNoteResult::unchanged($comments);
        }

        return ChassisOverrideNoteResult::stripped(trim($remaining) === '' ? null : $remaining);
    }
}
