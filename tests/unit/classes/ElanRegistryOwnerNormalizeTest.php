<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for ElanRegistryOwner::normalizeString()
 *
 * Verifies the encode-at-output pattern: special characters and HTML tags
 * must be stored raw (unencoded) — htmlspecialchars() is applied only at
 * the render layer.
 *
 * @see https://github.com/unibrain1/elanregistry/issues/941
 */
#[Group('fast')]
#[Group('unit')]
final class ElanRegistryOwnerNormalizeTest extends TestCase
{
    private \ReflectionMethod $method;
    private \ElanRegistryOwner $owner;

    protected function setUp(): void
    {
        $this->owner  = new \ElanRegistryOwner();
        $ref          = new \ReflectionClass(\ElanRegistryOwner::class);
        $this->method = $ref->getMethod('normalizeString');
    }

    private function normalize(string $input, int $maxLength = 255): string
    {
        return $this->method->invoke($this->owner, $input, $maxLength);
    }

    public function testPreservesHtmlTags(): void
    {
        $this->assertSame('<b>Bold</b> name', $this->normalize('<b>Bold</b> name'));
    }

    public function testPreservesLessThanAndGreaterThan(): void
    {
        $this->assertSame('A < B > C', $this->normalize('A < B > C'));
    }

    public function testPreservesAmpersand(): void
    {
        $this->assertSame('Smith & Jones', $this->normalize('Smith & Jones'));
    }

    public function testPreservesDoubleQuotes(): void
    {
        $this->assertSame('"quoted"', $this->normalize('"quoted"'));
    }

    public function testTrimsLeadingAndTrailingWhitespace(): void
    {
        $this->assertSame('Alice', $this->normalize('  Alice  '));
    }

    public function testEnforcesMaxLength(): void
    {
        $this->assertSame('Al', $this->normalize('Alice', 2));
    }

    public function testReturnsEmptyStringUnchanged(): void
    {
        $this->assertSame('', $this->normalize(''));
    }

    public function testDoesNotStripTagsUnlikeOldSanitizeString(): void
    {
        $input = '<script>alert(1)</script>';
        $this->assertSame($input, $this->normalize($input));
    }
}
