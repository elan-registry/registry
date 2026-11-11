<?php

declare(strict_types=1);

use ElanRegistry\Car\ChassisOverrideNote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChassisOverrideNote::strip() (issue #2613).
 *
 * Expected values are written as literals, not computed from the note text.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('cars')]
final class ChassisOverrideNoteTest extends TestCase
{
    private const LONG = 'CHASSIS VALIDATION OVERRIDDEN: 2024-05-01 12:34:56 - Admin override used for chassis validation.';
    private const SHORT = 'CHASSIS VALIDATION OVERRIDDEN: 2024-05-01 12:34:56';
    private const NO_SPACE = 'CHASSIS VALIDATION OVERRIDDEN:2024-05-01 12:34:56';

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: bool, 3: bool}>
     */
    public static function stripProvider(): array
    {
        $long = self::LONG;
        $short = self::SHORT;

        return [
            'long variant only' => [$long, null, true, false],
            'short variant only' => [$short, null, true, false],
            'short variant, no space after colon' => [self::NO_SPACE, null, true, false],
            'owner text then long note' => ["Nice car.\n" . $long, 'Nice car.', true, false],
            'owner text then short note' => ["Nice car.\n" . $short, 'Nice car.', true, false],
            'owner text then no-space note' => ["Nice car.\n" . self::NO_SPACE, 'Nice car.', true, false],
            'break CRLF' => ["Nice car.\r\n" . $short, 'Nice car.', true, false],
            'break CRLF CRLF' => ["Nice car.\r\n\r\n" . $short, 'Nice car.', true, false],
            'break LF LF' => ["Nice car.\n\n" . $short, 'Nice car.', true, false],
            'break CRLF LF' => ["Nice car.\r\n\n" . $short, 'Nice car.', true, false],
            'break LF CRLF LF' => ["Nice car.\n\r\n\n" . $short, 'Nice car.', true, false],
            'trailing spaces before note' => ["Nice car.   \n" . $short, 'Nice car.', true, false],
            'trailing tab and spaces, no break' => ["Nice car. \t " . $short, 'Nice car.', true, false],
            'two notes' => ["Nice car.\n" . $short . "\n" . $long, 'Nice car.', true, false],
            'four notes' => [
                "Nice car.\n" . $short . "\r\n" . $long . "\n\n" . self::NO_SPACE . "\n" . $short,
                'Nice car.',
                true,
                false,
            ],
            'notes only, two' => [$short . "\n" . $long, null, true, false],
            'whitespace left over' => ["  \n" . $short, null, true, false],
            'owner text with inner break, note at end' => [
                "Line one.\nLine two.\n" . $short,
                "Line one.\nLine two.",
                true,
                false,
            ],
            'owner text after note stays' => [
                "Before.\n" . $short . "\nAfter.",
                "Before.\nAfter.",
                true,
                false,
            ],
            'note at start, owner text after keeps the break' => [$short . "\nAfter.", "\nAfter.", true, false],
            'trailing break after the last note stays' => ["Nice.\n" . $short . "\n", "Nice.\n", true, false],
            'null' => [null, null, false, false],
            'no note' => ["Nice car.\nSecond line.", "Nice car.\nSecond line.", false, false],
            'empty string' => ['', '', false, false],
        ];
    }

    #[DataProvider('stripProvider')]
    public function testStripReturnsExpectedResult(?string $input, ?string $comments, bool $changed, bool $manual): void
    {
        $result = ChassisOverrideNote::strip($input);

        $this->assertSame($comments, $result->comments);
        $this->assertSame($changed, $result->changed);
        $this->assertSame($manual, $result->manual);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function manualProvider(): array
    {
        return [
            'note plus hand-written override text' => ["Nice car.\n" . self::SHORT . "\nOverride me"],
            'note plus hand-written chassis validation text' => [self::LONG . "\nchassis validation was waived"],
            'known note plus unknown-form note' => [self::SHORT . "\nCHASSIS VALIDATION OVERRIDDEN: yesterday"],
            'unknown-form note only' => ["Nice car.\nCHASSIS VALIDATION OVERRIDDEN: yesterday"],
            'override text with no note' => ['Overridden by admin'],
            'lowercase note' => ["Nice car.\nchassis validation overridden: 2024-05-01 12:34:56"],
            'note with extra digits after the seconds' => ["Nice car.\nCHASSIS VALIDATION OVERRIDDEN: 2024-05-01 12:34:56789"],
        ];
    }

    #[DataProvider('manualProvider')]
    public function testStripFlagsManualEditAndKeepsOriginalWhenOverrideTextRemains(string $input): void
    {
        $result = ChassisOverrideNote::strip($input);

        $this->assertSame($input, $result->comments);
        $this->assertFalse($result->changed);
        $this->assertTrue($result->manual);
    }
}
