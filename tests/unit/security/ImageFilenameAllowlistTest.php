<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Issue #1307: image filenames are allowlisted before any DB write, glob, or stat.
 * isValidFilename() guards new uploads; isSafeFilename() guards reads of legacy rows.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
final class ImageFilenameAllowlistTest extends TestCase
{
    private const HEX32 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @return array<string, array{string}>
     */
    public static function validNewFilenameProvider(): array
    {
        return [
            'jpg'              => ['img_' . self::HEX32 . '.jpg'],
            'png'              => ['img_' . self::HEX32 . '.png'],
            'gif'              => ['img_' . self::HEX32 . '.gif'],
            'webp'             => ['img_' . self::HEX32 . '.webp'],
            'all hex digits'   => ['img_0123456789abcdef0123456789abcdef.jpg'],
        ];
    }

    #[DataProvider('validNewFilenameProvider')]
    public function testIsValidFilenameAccepts(string $filename): void
    {
        $this->assertTrue(CarImageProcessor::isValidFilename($filename));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNewFilenameProvider(): array
    {
        return [
            'wildcard'                 => ['*'],
            'glob expansion'           => ['img_*.jpg'],
            'traversal'                => ['../../../etc/passwd'],
            'relative traversal'       => ['../img_' . self::HEX32 . '.jpg'],
            // Anchored at ^, so a valid basename behind a path does not match.
            'absolute path traversal'  => ['/some/path/../../../img_' . self::HEX32 . '.jpg'],
            'script tag'               => ['<script>alert(1)</script>'],
            'null byte'                => ['img_' . self::HEX32 . ".jpg\x00extra"],
            'space'                    => ['img_' . self::HEX32 . ' .jpg'],
            'short hex'                => ['img_' . str_repeat('a', 31) . '.jpg'],
            'long hex'                 => ['img_' . str_repeat('a', 33) . '.jpg'],
            'wrong prefix'             => ['upload_' . self::HEX32 . '.jpg'],
            'uppercase hex'            => ['img_' . strtoupper(self::HEX32) . '.jpg'],
            'unsupported extension'    => ['img_' . self::HEX32 . '.bmp'],
            'no extension'             => ['img_' . self::HEX32],
            'empty string'             => [''],
            'plain php filename'       => ['shell.php'],
            // generateSecureFilename() never makes .jpeg; isSafeFilename() still accepts it for legacy rows.
            'jpeg extension'           => ['img_' . self::HEX32 . '.jpeg'],
            'double extension'         => ['img_' . self::HEX32 . '.jpg.php'],
            // PHP's $ matches before a trailing \n; the pattern must use \z.
            'trailing newline'         => ['img_' . self::HEX32 . ".jpg\n"],
        ];
    }

    #[DataProvider('invalidNewFilenameProvider')]
    public function testIsValidFilenameRejects(string $filename): void
    {
        $this->assertFalse(CarImageProcessor::isValidFilename($filename));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeLegacyFilenameProvider(): array
    {
        return [
            'timestamp format'        => ['20151231132429_dscn1711.jpg'],
            'bare hash with .jpeg'    => ['7d88e3abba0d104c1a3d1f3b3646701b.jpeg'],
            'old uniqid format'       => ['img_6216b69958ad87.41015942.jpg'],
            'current secure format'   => ['img_' . self::HEX32 . '.jpg'],
            'jpg'                     => ['photo.jpg'],
            'jpeg'                    => ['photo.jpeg'],
            'png'                     => ['photo.png'],
            'gif'                     => ['photo.gif'],
            'webp'                    => ['photo.webp'],
            'uppercase JPG'           => ['photo.JPG'],
            'uppercase JPEG'          => ['photo.JPEG'],
        ];
    }

    #[DataProvider('safeLegacyFilenameProvider')]
    public function testIsSafeFilenameAccepts(string $filename): void
    {
        $this->assertTrue(CarImageProcessor::isSafeFilename($filename));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeFilenameProvider(): array
    {
        return [
            'space'                 => ['my photo.jpg'],
            'spaces, uppercase ext' => ['On return from CAR SOS.JPG'],
            'traversal'             => ['../../../etc/passwd'],
            'wildcard'              => ['*'],
            'glob pattern'          => ['*.jpg'],
            'script tag'            => ['<script>alert(1)</script>'],
            'null byte'             => ["photo.jpg\x00extra"],
            'unsupported extension' => ['photo.bmp'],
            'php extension'         => ['shell.php'],
            'empty string'          => [''],
        ];
    }

    #[DataProvider('unsafeFilenameProvider')]
    public function testIsSafeFilenameRejects(string $filename): void
    {
        $this->assertFalse(CarImageProcessor::isSafeFilename($filename));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function decodeSkipsUnsafeProvider(): array
    {
        return [
            'traversal'             => ['../../../etc/passwd'],
            'wildcard'              => ['*'],
            'unsupported extension' => ['photo.bmp'],
            'script tag'            => ['<script>alert(1)</script>'],
        ];
    }

    #[DataProvider('decodeSkipsUnsafeProvider')]
    public function testDecodeAndProcessImagesSkipsUnsafeFilename(string $filename): void
    {
        $result = (new CarImageProcessor($this->createStub(CarRepository::class)))->decodeAndProcessImages(
            json_encode([$filename]), '/images/1/', '/', '/var/www/'
        );
        $this->assertEmpty($result, 'Unsafe filename must not appear in decoded image list');
    }

    public function testDecodeAndProcessImagesSkipsMixedValidAndInvalid(): void
    {
        $safeName   = '20151231132429_legacy.jpg';
        $unsafeName = '../../../etc/passwd';

        // The safe entry needs a real file to pass is_file(); otherwise both
        // entries yield nothing and the test passes without the guard.
        $tmpDir = sys_get_temp_dir() . '/elan_allowlist_' . bin2hex(random_bytes(4)) . '/';
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . $safeName, str_repeat('x', 100));

        try {
            $result = (new CarImageProcessor($this->createStub(CarRepository::class)))->decodeAndProcessImages(
                json_encode([$safeName, $unsafeName]),
                '',
                '',
                $tmpDir
            );

            $this->assertCount(1, $result, 'Only the safe entry should appear; traversal must be filtered');
            $this->assertSame($safeName, $result[0]['basename'], 'Safe legacy filename must pass the read-path guard');
        } finally {
            @unlink($tmpDir . $safeName);
            @rmdir($tmpDir);
        }
    }

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
        $name = CarImageProcessor::generateSecureFilename('JPG');
        $this->assertTrue(CarImageProcessor::isValidFilename($name));
        $this->assertStringEndsWith('.jpg', $name);
    }
}
