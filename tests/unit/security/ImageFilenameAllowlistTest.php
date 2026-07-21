<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Car\CarRepository;
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
 * This file tests two of the four layers directly via CarImageProcessor public API
 * (isValidFilename() and isSafeFilename()) and verifies observable rejection behavior
 * in decodeAndProcessImages().
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

    public function testIsValidFilenameRejectsJpegExtension(): void
    {
        // 'jpeg' is absent from ALLOWED_EXTENSIONS; generateSecureFilename() always
        // produces .jpg via the MIME map, never .jpeg. isSafeFilename() accepts
        // .jpeg for legacy DB rows — this test documents that divergence.
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . $this->hex32() . '.jpeg'));
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

    public function testIsValidFilenameRejectsDoubleExtension(): void
    {
        // The regex is fully anchored — img_[hex].jpg.php ends with '.php', not
        // a valid extension, so it cannot match even though it contains '.jpg'.
        $this->assertFalse(CarImageProcessor::isValidFilename('img_' . $this->hex32() . '.jpg.php'));
    }

    // -------------------------------------------------------------------------
    // isSafeFilename — read-path guard (accepts legacy filenames, rejects attacks)
    //
    // isSafeFilename() is used by decodeAndProcessImages() and must accept the
    // full range of historical naming conventions stored in the database while
    // still blocking traversal, glob expansion, and HTML injection.
    // -------------------------------------------------------------------------

    public function testIsSafeFilenameAcceptsTimestampFormat(): void
    {
        $this->assertTrue(CarImageProcessor::isSafeFilename('20151231132429_dscn1711.jpg'));
    }

    public function testIsSafeFilenameAcceptsBareHashWithJpegExtension(): void
    {
        $this->assertTrue(CarImageProcessor::isSafeFilename('7d88e3abba0d104c1a3d1f3b3646701b.jpeg'));
    }

    public function testIsSafeFilenameAcceptsOldUniqidFormat(): void
    {
        $this->assertTrue(CarImageProcessor::isSafeFilename('img_6216b69958ad87.41015942.jpg'));
    }

    public function testIsSafeFilenameAcceptsCurrentSecureFormat(): void
    {
        $name = 'img_' . $this->hex32() . '.jpg';
        $this->assertTrue(CarImageProcessor::isSafeFilename($name));
    }

    public function testIsSafeFilenameAcceptsAllAllowedExtensions(): void
    {
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
            $this->assertTrue(
                CarImageProcessor::isSafeFilename('photo.' . $ext),
                "isSafeFilename() must accept .{$ext} extension"
            );
        }
    }

    public function testIsSafeFilenameRejectsFilenameWithSpace(): void
    {
        // All server-controlled filenames are space-free; space is not in [\w\-.].
        $this->assertFalse(CarImageProcessor::isSafeFilename('my photo.jpg'));
        $this->assertFalse(CarImageProcessor::isSafeFilename('On return from CAR SOS.JPG'));
    }

    public function testIsSafeFilenameRejectsTraversal(): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename('../../../etc/passwd'));
    }

    public function testIsSafeFilenameRejectsWildcard(): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename('*'));
    }

    public function testIsSafeFilenameRejectsGlobPattern(): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename('*.jpg'));
    }

    public function testIsSafeFilenameRejectsScriptTagXss(): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename('<script>alert(1)</script>'));
    }

    public function testIsSafeFilenameRejectsNullByte(): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename("photo.jpg\x00extra"));
    }

    public function testIsSafeFilenameRejectsUnsupportedExtension(): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename('photo.bmp'));
    }

    public function testIsSafeFilenameRejectsPhpExtension(): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename('shell.php'));
    }

    public function testIsSafeFilenameRejectsEmptyString(): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename(''));
    }

    public function testIsSafeFilenameAcceptsMixedCaseExtension(): void
    {
        // The /i flag makes extension matching case-insensitive; legacy DB rows
        // may have uppercase extensions.
        $this->assertTrue(CarImageProcessor::isSafeFilename('photo.JPG'));
        $this->assertTrue(CarImageProcessor::isSafeFilename('photo.JPEG'));
    }

    // -------------------------------------------------------------------------
    // decodeAndProcessImages — unsafe filenames produce empty result
    //
    // These tests verify the observable security contract: filenames that
    // fail isSafeFilename() are never included in the output array (they are
    // skipped before any filesystem call).
    // -------------------------------------------------------------------------

    public function testDecodeAndProcessImagesSkipsTraversalFilename(): void
    {
        $result = (new CarImageProcessor($this->createMock(CarRepository::class)))->decodeAndProcessImages(
            json_encode(['../../../etc/passwd']), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'Traversal filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsWildcardFilename(): void
    {
        $result = (new CarImageProcessor($this->createMock(CarRepository::class)))->decodeAndProcessImages(
            json_encode(['*']), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'Wildcard filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsUnsupportedExtension(): void
    {
        // 'photo.bmp' is rejected by isSafeFilename() — unsupported extension.
        $result = (new CarImageProcessor($this->createMock(CarRepository::class)))->decodeAndProcessImages(
            json_encode(['photo.bmp']), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'Unsupported-extension filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsScriptTagFilename(): void
    {
        $result = (new CarImageProcessor($this->createMock(CarRepository::class)))->decodeAndProcessImages(
            json_encode(['<script>alert(1)</script>']), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'XSS payload filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsMixedValidAndInvalid(): void
    {
        $safeName   = '20151231132429_legacy.jpg'; // legacy format — passes isSafeFilename()
        $unsafeName = '../../../etc/passwd';        // traversal — rejected by isSafeFilename()

        // Create a real temp file so the safe entry passes is_file() — this makes
        // the assertion meaningful: if the isSafeFilename() guard were removed the
        // unsafe entry would also reach is_file() and the count assertion would fail
        // (both would produce 0 entries from is_file, making the test a no-op again).
        $tmpDir = sys_get_temp_dir() . '/elan_allowlist_' . bin2hex(random_bytes(4)) . '/';
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . $safeName, str_repeat('x', 100));

        try {
            $result = (new CarImageProcessor($this->createMock(CarRepository::class)))->decodeAndProcessImages(
                json_encode([$safeName, $unsafeName]),
                '',
                '',
                $tmpDir
            );

            // The safe legacy filename passes isSafeFilename() and is_file() → in result.
            $this->assertCount(1, $result, 'Only the safe entry should appear; traversal must be filtered');
            $this->assertSame($safeName, $result[0]['basename'], 'Safe legacy filename must pass the read-path guard');
        } finally {
            @unlink($tmpDir . $safeName);
            @rmdir($tmpDir);
        }
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
