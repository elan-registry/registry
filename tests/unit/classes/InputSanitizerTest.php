<?php

declare(strict_types=1);

use ElanRegistry\InputSanitizer;
use PHPUnit\Framework\TestCase;
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
    public function testPreservesHtmlTags(): void
    {
        $this->assertSame('<b>Bold</b> name', InputSanitizer::normalize('<b>Bold</b> name'));
    }

    public function testPreservesLessThanAndGreaterThan(): void
    {
        $this->assertSame('A < B > C', InputSanitizer::normalize('A < B > C'));
    }

    public function testPreservesAmpersand(): void
    {
        $this->assertSame('Smith & Jones', InputSanitizer::normalize('Smith & Jones'));
    }

    public function testPreservesDoubleQuotes(): void
    {
        $this->assertSame('"quoted"', InputSanitizer::normalize('"quoted"'));
    }

    public function testTrimsLeadingAndTrailingWhitespace(): void
    {
        $this->assertSame('Alice', InputSanitizer::normalize('  Alice  '));
    }

    public function testEnforcesMaxLength(): void
    {
        $this->assertSame('Al', InputSanitizer::normalize('Alice', 2));
    }

    public function testReturnsEmptyStringUnchanged(): void
    {
        $this->assertSame('', InputSanitizer::normalize(''));
    }

    public function testDoesNotStripHtmlTags(): void
    {
        $input = '<script>alert(1)</script>';
        $this->assertSame($input, InputSanitizer::normalize($input));
    }

    public function testTruncatesOnCharactersNotBytes(): void
    {
        // "Müller" is 6 characters but 7 bytes; maxLength=3 should give "Mül" not a severed byte sequence
        $this->assertSame('Mül', InputSanitizer::normalize('Müller', 3));
    }
}
