<?php

declare(strict_types=1);

use ElanRegistry\InputSanitizer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for InputSanitizer::normalize()
 *
 * Verifies the encode-at-output pattern: special characters and HTML tags
 * must be stored raw (unencoded) — htmlspecialchars() is applied only at
 * the render layer.
 *
 * @see https://github.com/unibrain1/elanregistry/issues/941
 */
#[Group('fast')]
#[Group('unit')]
final class InputSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function unchangedInputProvider(): array
    {
        return [
            'html tags preserved'             => ['<b>Bold</b> name'],
            'less than and greater than kept' => ['A < B > C'],
            'ampersand kept'                  => ['Smith & Jones'],
            'double quotes kept'              => ['"quoted"'],
            'empty string unchanged'          => [''],
            'script tags not stripped'        => ['<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('unchangedInputProvider')]
    public function testNormalizeReturnsInputUnchanged(string $input): void
    {
        $this->assertSame($input, InputSanitizer::normalize($input));
    }

    public function testTrimsLeadingAndTrailingWhitespace(): void
    {
        $this->assertSame('Alice', InputSanitizer::normalize('  Alice  '));
    }

    public function testEnforcesMaxLength(): void
    {
        $this->assertSame('Al', InputSanitizer::normalize('Alice', 2));
    }

    public function testTruncatesOnCharactersNotBytes(): void
    {
        // "Müller" is 6 characters but 7 bytes; maxLength=3 should give "Mül" not a severed byte sequence
        $this->assertSame('Mül', InputSanitizer::normalize('Müller', 3));
    }
}
