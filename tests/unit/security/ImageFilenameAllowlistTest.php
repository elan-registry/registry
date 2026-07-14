<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression tests for the image filename allowlist guard introduced in issue #1307.
 *
 * CarImageProcessor::ALLOWED_EXTENSIONS is the single source of truth for
 * which extensions are permitted. generateSecureFilename() produces names
 * that match the allowlist; isValidFilename() rejects everything else.
 *
 * The guard is applied in four layers:
 *   1. buildImageDetails() in save.php  — filters invalid entries before DB write
 *   2. uploadImages() in save.php       — filters $requestedOrder before writing cars.image
 *   3. mvTmpImages() in save.php        — skips invalid entries before glob
 *   4. decodeAndProcessImages()         — skips invalid entries before stat
 *
 * This file tests all three entry points via CarImageProcessor public API.
 *
 * @see usersc/classes/Car/CarImageProcessor.php
 * @see app/api/cars/save.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
final class ImageFilenameAllowlistTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Build a 32-char hex string — the random component of a secure filename. */
    private function hex32(): string
    {
        return str_repeat('a', 32);
    }

    // -------------------------------------------------------------------------
    // isValidFilename — valid inputs must pass
    // -------------------------------------------------------------------------

    public function testIsValidFilenameAcceptsJpgExtension(): void
    {
        $this->assertTrue(CarImageProcessor::isValidFilename('img_' . $this->hex32() . '.jpg'));
    }

    public function testIsValidFilenameAcceptsPngExtension(): void
    {
        $this->assertTrue(CarImageProcessor::isValidFilename('img_' . $this->hex32() . '.png'));
    }

    public function testIsValidFilenameAcceptsGifExtension(): void
    {
        $this->assertTrue(CarImageProcessor::isValidFilename('img_' . $this->hex32() . '.gif'));
    }

    public function testIsValidFilenameAcceptsWebpExtension(): void
    {
        $this->assertTrue(CarImageProcessor::isValidFilename('img_' . $this->hex32() . '.webp'));
    }

    public function testIsValidFilenameAcceptsMixedHexDigits(): void
    {
        // Real output from generateSecureFilename() uses all hex chars 0-9 a-f
        $this->assertTrue(CarImageProcessor::isValidFilename('img_0123456789abcdef0123456789abcdef.jpg'));
    }

    // -------------------------------------------------------------------------
    // isValidFilename — attack payloads must be rejected
    // -------------------------------------------------------------------------

    public function testIsValidFilenameRejectsWildcard(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('*'));
    }

    public function testIsValidFilenameRejectsGlobExpansion(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('img_*.jpg'));
    }

    public function testIsValidFilenameRejectsTraversal(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('../../../etc/passwd'));
    }

    public function testIsValidFilenameRejectsRelativeTraversalPrefix(): void
    {
        // '../img_[hex32].jpg' starts with '../', not 'img_' — regex anchor rejects it.
        $this->assertFalse(CarImageProcessor::isValidFilename('../img_' . $this->hex32() . '.jpg'));
    }

    public function testIsValidFilenameRejectsScriptTag(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('<script>alert(1)</script>'));
    }

    public function testIsValidFilenameRejectsNullByte(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename("img_" . $this->hex32() . ".jpg\x00extra"));
    }

    public function testIsValidFilenameRejectsSpace(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . $this->hex32() . ' .jpg'));
    }

    public function testIsValidFilenameRejectsShortHex(): void
    {
        // 31 hex chars instead of 32 — too short
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . str_repeat('a', 31) . '.jpg'));
    }

    public function testIsValidFilenameRejectsLongHex(): void
    {
        // 33 hex chars — too long
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . str_repeat('a', 33) . '.jpg'));
    }

    public function testIsValidFilenameRejectsWrongPrefix(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('upload_' . $this->hex32() . '.jpg'));
    }

    public function testIsValidFilenameRejectsUppercaseHex(): void
    {
        // generateSecureFilename() produces lowercase hex only; uppercase must not match
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . strtoupper($this->hex32()) . '.jpg'));
    }

    public function testIsValidFilenameRejectsUnsupportedExtension(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . $this->hex32() . '.bmp'));
    }

    public function testIsValidFilenameRejectsNoExtension(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . $this->hex32()));
    }

    public function testIsValidFilenameRejectsEmptyString(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename(''));
    }

    public function testIsValidFilenameRejectsPlainPhpFilename(): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename('shell.php'));
    }

    // -------------------------------------------------------------------------
    // Full-string anchor — the regex ^img_…$ rejects any path prefix without
    // needing basename() normalisation.
    // -------------------------------------------------------------------------

    public function testIsValidFilenameRejectsAbsolutePathTraversal(): void
    {
        // The regex is anchored at ^ so '/some/path/../../../img_[hex32].jpg' cannot
        // match even though its basename would be a valid secure filename.
        $this->assertFalse(CarImageProcessor::isValidFilename('/some/path/../../../img_' . $this->hex32() . '.jpg'));
    }

    // -------------------------------------------------------------------------
    // decodeAndProcessImages — invalid filenames produce empty result
    //
    // These tests verify the observable security contract: filenames that
    // fail the allowlist are never included in the output array (they are
    // skipped before any filesystem call).
    // -------------------------------------------------------------------------

    public function testDecodeAndProcessImagesSkipsTraversalFilename(): void
    {
        $result = (new CarImageProcessor())->decodeAndProcessImages(
            json_encode(['../../../etc/passwd']), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'Traversal filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsWildcardFilename(): void
    {
        $result = (new CarImageProcessor())->decodeAndProcessImages(
            json_encode(['*']), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'Wildcard filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsNonSecureFilename(): void
    {
        $result = (new CarImageProcessor())->decodeAndProcessImages(
            json_encode(['photo.jpg']), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'Non-secure filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsScriptTagFilename(): void
    {
        $result = (new CarImageProcessor())->decodeAndProcessImages(
            json_encode(['<script>alert(1)</script>']), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'XSS payload filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsMixedValidAndInvalid(): void
    {
        $validName   = 'img_' . $this->hex32() . '.jpg';
        $invalidName = '../../../etc/passwd';

        // Pre-flight: confirm the allowlist classifies inputs as expected.
        $this->assertTrue(CarImageProcessor::isValidFilename($validName));
        $this->assertFalse(CarImageProcessor::isValidFilename($invalidName));

        $result = (new CarImageProcessor())->decodeAndProcessImages(
            json_encode([$validName, $invalidName]), '/images/1/', '/', '/var/www/'
        );

        // The invalid entry is rejected by the allowlist; the valid entry passes
        // the allowlist but is_file() returns false in the test environment.
        $this->assertEmpty($result);
    }

    // -------------------------------------------------------------------------
    // Trailing-newline bypass — \z anchor prevents $ from matching before \n
    // -------------------------------------------------------------------------

    public function testIsValidFilenameRejectsTrailingNewline(): void
    {
        // PHP's $ anchor matches before a trailing \n; \z is unambiguous.
        // A filename like "img_[hex].jpg\n" must not pass.
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . $this->hex32() . ".jpg\n"));
    }

    // -------------------------------------------------------------------------
    // generateSecureFilename — canonical source of filenames that isValidFilename accepts
    // -------------------------------------------------------------------------

    public function testGenerateSecureFilenameProducesValidFilename(): void
    {
        foreach (CarImageProcessor::ALLOWED_EXTENSIONS as $ext) {
            $name = CarImageProcessor::generateSecureFilename($ext);
            $this->assertTrue(
                CarImageProcessor::isValidFilename($name),
                "generateSecureFilename('{$ext}') produced '{$name}' which isValidFilename() rejects"
            );
        }
    }

    public function testGenerateSecureFilenameHasExpectedFormat(): void
    {
        $name = CarImageProcessor::generateSecureFilename('jpg');

        $this->assertStringStartsWith('img_', $name);
        $this->assertMatchesRegularExpression('/^img_[0-9a-f]{32}\.jpg$/', $name);
    }

    public function testGenerateSecureFilenameProducesUniqueNames(): void
    {
        $names = array_map(fn() => CarImageProcessor::generateSecureFilename('jpg'), range(1, 5));
        $this->assertSame(count($names), count(array_unique($names)), 'generateSecureFilename() must not produce duplicates');
    }

    public function testGenerateSecureFilenameThrowsOnUnsupportedExtension(): void
    {
        $this->expectException(\ElanRegistry\Exceptions\ImageProcessingException::class);
        CarImageProcessor::generateSecureFilename('exe');
    }

    public function testGenerateSecureFilenameNormalisesExtensionToLowercase(): void
    {
        // Extensions from MIME detection are always lowercase, but the method
        // normalises to lowercase defensively — the result must still be valid.
        $name = CarImageProcessor::generateSecureFilename('JPG');
        $this->assertTrue(CarImageProcessor::isValidFilename($name));
        $this->assertStringEndsWith('.jpg', $name);
    }
}
