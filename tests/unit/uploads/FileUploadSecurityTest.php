<?php

declare(strict_types=1);

require_once __DIR__ . '/_is_uploaded_file_namespace_overrides.php';

use PHPUnit\Framework\TestCase;

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Exceptions\ImageProcessingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Security-focused test cases for file upload functionality
 *
 * Tests usersc/classes/Car/CarImageProcessor.php's upload-validation methods
 * (getMimeType, getExtension, validateFileUpload) — the same methods
 * app/api/cars/save.php calls during a real upload.
 * Validates protection against common file upload attack vectors.
 *
 * Requires _is_uploaded_file_namespace_overrides.php (see its docblock):
 * validateFileUpload()'s is_uploaded_file() check can never pass for a file
 * this test builds with file_put_contents(), so that override relaxes it to
 * also accept an existing file on disk.
 */
#[Group('fast')]
class FileUploadSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for testing
        $this->tempDir = sys_get_temp_dir() . '/elan_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        $this->cleanupTempDir();
    }

    /**
     * Test MIME type validation with valid types
     */
    public function testMimeTypeValidationValid(): void
    {
        // Create test files with valid MIME types
        $jpegFile = $this->createTestFile('test.jpg', $this->createJpegData());
        $pngFile = $this->createTestFile('test.png', $this->createPngData());

        // Should not throw exceptions for valid image types
        $this->assertEquals('image/jpeg', CarImageProcessor::getMimeType($jpegFile));
        $this->assertEquals('image/png', CarImageProcessor::getMimeType($pngFile));
    }

    /**
     * Test MIME type validation with invalid types
     */
    #[DataProvider('invalidMimeFileProvider')]
    public function testMimeTypeValidationInvalid(string $filename, string $content): void
    {
        $file = $this->createTestFile($filename, $content);

        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Invalid file type detected');
        CarImageProcessor::getMimeType($file);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidMimeFileProvider(): array
    {
        return [
            'php file' => ['malicious.php', '<?php echo "hack"; ?>'],
            'text file' => ['test.txt', 'plain text content'],
        ];
    }

    /**
     * Test MIME type detection failure when the file can't be read
     */
    public function testGetMimeTypeThrowsOnUnreadableFile(): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Unable to read file for MIME type detection');
        CarImageProcessor::getMimeType($this->tempDir . '/does-not-exist.jpg');
    }

    /**
     * Test file extension mapping for valid MIME types
     */
    public function testExtensionMappingValidTypes(): void
    {
        $this->assertEquals('jpg', CarImageProcessor::getExtension('image/jpeg'));
        $this->assertEquals('png', CarImageProcessor::getExtension('image/png'));
        $this->assertEquals('gif', CarImageProcessor::getExtension('image/gif'));
    }

    /**
     * Test file extension mapping rejects unsupported MIME types
     */
    #[DataProvider('invalidMimeTypeProvider')]
    public function testExtensionMappingInvalidTypes(string $mimeType): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Unsupported file type');
        CarImageProcessor::getExtension($mimeType);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidMimeTypeProvider(): array
    {
        return [
            'php mime' => ['application/x-php'],
            'text mime' => ['text/plain'],
            'javascript mime' => ['application/javascript'],
            // Resize.php cannot decode WebP, so the upload allowlist excludes it (#2627).
            'webp mime' => ['image/webp'],
        ];
    }

    /**
     * Test file upload validation - size limits
     */
    public function testFileUploadSizeValidation(): void
    {
        // Test file within size limit
        $validFile = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024 * 1024, // 1MB
            'tmp_name' => $this->createTestFile('valid.jpg', str_repeat('x', 1024 * 1024))
        ];

        CarImageProcessor::validateFileUpload($validFile); // void — success means no exception thrown

        // Test file exceeding size limit
        $largeFile = [
            'error' => UPLOAD_ERR_OK,
            'size' => CarImageProcessor::serverMaxBytes() * 2,
            'tmp_name' => $this->createTestFile('large.jpg', 'dummy')
        ];

        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('File too large');
        CarImageProcessor::validateFileUpload($largeFile);
    }

    /**
     * A photo above the size limit gives the owner the limit, not the generic
     * exception text (#2627). The technical message stays for the log.
     */
    public function testValidateFileUploadGivesOwnerMessageWithLimitForOversizeFile(): void
    {
        try {
            CarImageProcessor::validateFileUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => CarImageProcessor::serverMaxBytes() + 1,
                'tmp_name' => $this->createTestFile('over.jpg', 'dummy'),
            ]);
            $this->fail('validateFileUpload() must throw for a file above the maximum size');
        } catch (ImageProcessingException $e) {
            $this->assertSame(self::tooLargeUserMessage(), $e->getUserMessage());
            $this->assertStringStartsWith('File too large', $e->getMessage());
        }
    }

    /**
     * Test that a size exactly at the max boundary passes — the check uses
     * strict >, so a file exactly at the edge must succeed.
     */
    public function testFileUploadSizeBoundaries(): void
    {
        // The call is void — an uncaught exception would fail this test on its own;
        // addToAssertionCount() just satisfies PHPUnit's "no assertions performed" check
        // without a fake assertTrue(true) (PHPStan flags that as always-true).
        $atMaxSize = [
            'error' => UPLOAD_ERR_OK,
            'size' => CarImageProcessor::serverMaxBytes(),
            'tmp_name' => $this->createTestFile('at-max.jpg', 'dummy'),
        ];
        CarImageProcessor::validateFileUpload($atMaxSize);
        $this->addToAssertionCount(1);
    }

    /**
     * Test file upload validation - upload errors
     */
    #[DataProvider('uploadErrorCodeProvider')]
    public function testFileUploadErrorHandling(int $errorCode): void
    {
        $fileWithError = [
            'error' => $errorCode,
            'size' => 1024,
            'tmp_name' => $this->createTestFile('error.jpg', 'dummy')
        ];

        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('File upload error');
        CarImageProcessor::validateFileUpload($fileWithError);
    }

    /**
     * The owner must learn which photo failed and what to do (#2626). The
     * technical message stays for the log.
     */
    #[DataProvider('uploadErrorUserMessageProvider')]
    public function testValidateFileUploadGivesOwnerMessageForUploadError(int $errorCode, string $expectedUserMessage): void
    {
        try {
            CarImageProcessor::validateFileUpload(['error' => $errorCode, 'size' => 0, 'tmp_name' => '']);
            $this->fail('validateFileUpload() must throw for an upload error code');
        } catch (ImageProcessingException $e) {
            $this->assertSame($expectedUserMessage, $e->getUserMessage());
            $this->assertSame('File upload error: ' . $errorCode, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function uploadErrorUserMessageProvider(): array
    {
        $tooLarge = self::tooLargeUserMessage();
        $other    = 'The server could not receive the photo. Upload the photo again.';

        return [
            'INI_SIZE'    => [UPLOAD_ERR_INI_SIZE, $tooLarge],
            'FORM_SIZE'   => [UPLOAD_ERR_FORM_SIZE, $tooLarge],
            'PARTIAL'     => [UPLOAD_ERR_PARTIAL, 'Photo upload did not complete. Upload the photo again.'],
            'NO_TMP_DIR'  => [UPLOAD_ERR_NO_TMP_DIR, $other],
            'CANT_WRITE'  => [UPLOAD_ERR_CANT_WRITE, $other],
            'unknown 99'  => [99, $other],
        ];
    }

    /**
     * Built from the configured limit, so a change to ELAN_IMAGE_SERVER_MAX_SIZE
     * does not break the tests.
     */
    private static function tooLargeUserMessage(): string
    {
        return 'Photo is too large. The maximum size is ' . round(ELAN_IMAGE_SERVER_MAX_SIZE, 1)
            . ' MB. Make the photo smaller, then upload it again.';
    }

    /**
     * @return array<string, array{int}>
     */
    public static function uploadErrorCodeProvider(): array
    {
        return [
            'INI_SIZE' => [UPLOAD_ERR_INI_SIZE],
            'FORM_SIZE' => [UPLOAD_ERR_FORM_SIZE],
            'PARTIAL' => [UPLOAD_ERR_PARTIAL],
            'NO_FILE' => [UPLOAD_ERR_NO_FILE],
            'NO_TMP_DIR' => [UPLOAD_ERR_NO_TMP_DIR],
            'CANT_WRITE' => [UPLOAD_ERR_CANT_WRITE],
            'EXTENSION' => [UPLOAD_ERR_EXTENSION],
        ];
    }

    /**
     * A body above post_max_size makes PHP discard $_POST and $_FILES, so
     * save.php must tell that case apart from a request with no data (#2647).
     */
    #[DataProvider('postMaxSizeProvider')]
    public function testExceedsPostMaxSizeComparesContentLengthWithLimit(int $contentLength, string $postMaxSize, bool $expected): void
    {
        $this->assertSame($expected, CarImageProcessor::exceedsPostMaxSize($contentLength, $postMaxSize));
    }

    /**
     * @return array<string, array{int, string, bool}>
     */
    public static function postMaxSizeProvider(): array
    {
        return [
            'above byte limit'       => [1025, '1024', true],
            'equal to byte limit'    => [1024, '1024', false],
            'below byte limit'       => [1023, '1024', false],
            // PHP treats post_max_size 0 as no limit.
            'zero means no limit'    => [PHP_INT_MAX, '0', false],
            'negative means no limit' => [PHP_INT_MAX, '-1', false],
            'above 64M shorthand'    => [64 * 1024 * 1024 + 1, '64M', true],
            'equal to 64M shorthand' => [64 * 1024 * 1024, '64M', false],
            'above 1G shorthand'     => [1024 * 1024 * 1024 + 1, '1G', true],
            'below 1G shorthand'     => [1024 * 1024 * 1024 - 1, '1G', false],
        ];
    }

    /**
     * Test that a tmp_name PHP never actually received an upload is rejected.
     *
     * is_uploaded_file() is the only check standing between validateFileUpload()
     * and accepting an arbitrary server-side path as an "upload" — this is the
     * one branch _is_uploaded_file_namespace_overrides.php's test-only override
     * still lets fail closed, since it falls back to file_exists() rather than
     * unconditionally returning true. A tmp_name pointing at nothing satisfies
     * neither is_uploaded_file() nor file_exists(), so the guard still throws.
     */
    public function testRejectsNonUploadedFile(): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Invalid file upload');
        CarImageProcessor::validateFileUpload([
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
            'tmp_name' => $this->tempDir . '/does-not-exist.jpg',
        ]);
    }

    /**
     * The owner sees getUserMessage(), never the technical message. One test
     * for each owner message (#1844).
     */
    public function testTooSmallFileGivesEmptyOrDamagedOwnerMessage(): void
    {
        try {
            CarImageProcessor::validateFileUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => 50,
                'tmp_name' => $this->createTestFile('tiny.jpg', str_repeat('x', 50)),
            ]);
            $this->fail('A 50-byte file must be rejected');
        } catch (ImageProcessingException $e) {
            $this->assertSame('The photo file is empty or damaged. Choose a different photo.', $e->getUserMessage());
            $this->assertStringContainsString('File too small', $e->getMessage());
        }
    }

    /**
     * Test that a path that is not an upload gives the not-received owner message
     */
    public function testNotAnUploadedFileGivesNotReceivedOwnerMessage(): void
    {
        try {
            CarImageProcessor::validateFileUpload([
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
                'tmp_name' => $this->tempDir . '/does-not-exist.jpg',
            ]);
            $this->fail('A path that is not an upload must be rejected');
        } catch (ImageProcessingException $e) {
            $this->assertSame('The server could not receive the photo. Upload the photo again.', $e->getUserMessage());
            $this->assertStringContainsString('Invalid file upload', $e->getMessage());
        }
    }

    /**
     * Test that a disallowed file type gives the type owner message without the MIME string
     */
    public function testDisallowedFileTypeGivesTypeOwnerMessageWithoutMimeString(): void
    {
        $file = $this->createTestFile('notes.jpg', 'plain text content');

        try {
            CarImageProcessor::getMimeType($file);
            $this->fail('A text file must be rejected');
        } catch (ImageProcessingException $e) {
            $this->assertSame('The file is not a JPEG, PNG or GIF photo. Choose a different photo.', $e->getUserMessage());
            // The technical MIME string is for the log only.
            $this->assertStringContainsString('text/plain', $e->getMessage());
            $this->assertStringNotContainsString('text/plain', $e->getUserMessage());
        }
    }

    /**
     * Test that getExtension() gives the type owner message for an unsupported MIME type
     */
    public function testGetExtensionForUnsupportedTypeGivesTypeOwnerMessage(): void
    {
        try {
            CarImageProcessor::getExtension('text/plain');
            $this->fail('An unsupported MIME type must be rejected');
        } catch (ImageProcessingException $e) {
            $this->assertSame('The file is not a JPEG, PNG or GIF photo. Choose a different photo.', $e->getUserMessage());
        }
    }

    /**
     * Test that a file the server cannot read gives the unreadable owner message
     */
    public function testUnreadableFileGivesUnreadableOwnerMessage(): void
    {
        try {
            CarImageProcessor::getMimeType($this->tempDir . '/does-not-exist.jpg');
            $this->fail('A missing file must be rejected');
        } catch (ImageProcessingException $e) {
            $this->assertSame('The server could not read the photo. Upload the photo again.', $e->getUserMessage());
            $this->assertStringContainsString('Unable to read file for MIME type detection', $e->getMessage());
        }
    }

    /**
     * 99 bytes are rejected and 100 pass. Literal values, not the constant,
     * so a change to MIN_FILE_BYTES fails the test (the client check in
     * car-edit.js reads the same value).
     */
    public function testMinFileBytesBoundary(): void
    {
        $atMin = [
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
            'tmp_name' => $this->createTestFile('at-min.jpg', str_repeat('x', 100)),
        ];
        CarImageProcessor::validateFileUpload($atMin);
        $this->addToAssertionCount(1);

        $belowMin = [
            'error' => UPLOAD_ERR_OK,
            'size' => 99,
            'tmp_name' => $this->createTestFile('below-min.jpg', str_repeat('x', 99)),
        ];
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('File too small');
        CarImageProcessor::validateFileUpload($belowMin);
    }

    // Helper methods

    private function createTestFile(string $filename, string $content): string
    {
        $filepath = $this->tempDir . '/' . $filename;
        file_put_contents($filepath, $content);
        return $filepath;
    }

    private function createJpegData(): string
    {
        // Minimal valid JPEG header
        return base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/wAAAAAAA');
    }

    private function createPngData(): string
    {
        // Minimal valid PNG header
        return "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\tpHYs\x00\x00\x0b\x13\x00\x00\x0b\x13\x01\x00\x9a\x9c\x18\x00\x00\x00\fIDATx\x9cc\xf8\x00\x00\x00\x01\x00\x01\x00\x00\x00\x00IEND\xaeB`\x82";
    }

    private function cleanupTempDir(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }
}
