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
 * (getMimeType, getExtension, validateFileUpload, generateSecureFilename) —
 * the same methods app/api/cars/save.php calls during a real upload.
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
     * Test secure filename generation
     */
    public function testSecureFilenameGeneration(): void
    {
        $filename1 = CarImageProcessor::generateSecureFilename('jpg');
        $filename2 = CarImageProcessor::generateSecureFilename('jpg');

        // Filenames should be different (cryptographically random)
        $this->assertNotEquals($filename1, $filename2);

        // Should follow expected pattern
        $this->assertMatchesRegularExpression('/^img_[a-f0-9]{32}\.jpg$/', $filename1);
        $this->assertMatchesRegularExpression('/^img_[a-f0-9]{32}\.jpg$/', $filename2);

        // Should be proper length (img_ + 32 hex chars + .ext)
        $this->assertEquals(40, strlen($filename1)); // img_ (4) + 32 hex + .jpg (4)
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
        $this->assertEquals('webp', CarImageProcessor::getExtension('image/webp'));
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
            'size' => 10 * 1024 * 1024, // 10MB - exceeds 5MB limit
            'tmp_name' => $this->createTestFile('large.jpg', 'dummy')
        ];

        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('File too large');
        CarImageProcessor::validateFileUpload($largeFile);
    }

    /**
     * Test file upload validation - minimum size
     */
    public function testFileUploadMinimumSize(): void
    {
        // Test file below minimum size
        $tinyFile = [
            'error' => UPLOAD_ERR_OK,
            'size' => 50, // Below 100 byte minimum
            'tmp_name' => $this->createTestFile('tiny.jpg', str_repeat('x', 50))
        ];

        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('File too small');
        CarImageProcessor::validateFileUpload($tinyFile);
    }

    /**
     * Test that sizes exactly at the min/max boundaries pass — the checks use
     * strict > and <, so a file exactly at either edge must succeed.
     */
    public function testFileUploadSizeBoundaries(): void
    {
        // Both calls are void — an uncaught exception would fail this test on its own;
        // addToAssertionCount() just satisfies PHPUnit's "no assertions performed" check
        // without a fake assertTrue(true) (PHPStan flags that as always-true).
        $atMinSize = [
            'error' => UPLOAD_ERR_OK,
            'size' => 100, // exactly the minimum
            'tmp_name' => $this->createTestFile('at-min.jpg', str_repeat('x', 100)),
        ];
        CarImageProcessor::validateFileUpload($atMinSize);
        $this->addToAssertionCount(1);

        $atMaxSize = [
            'error' => UPLOAD_ERR_OK,
            'size' => 5242880, // exactly the default 5MB maximum
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
        $tooLarge = 'Photo is too large for the server. Make the photo smaller, then upload it again.';
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
     * Test protection against zip bombs and polyglot files
     */
    public function testMaliciousFileProtection(): void
    {
        // Test polyglot file (appears as image but contains script)
        $polyglotContent = $this->createPolyglotFile();
        $polyglotFile = $this->createTestFile('polyglot.jpg', $polyglotContent);

        // Note: Simple MIME type detection with finfo_file() will detect this as a valid JPEG
        // since it has proper JPEG headers. More sophisticated detection would be needed
        // to detect embedded script content.
        $mimeType = CarImageProcessor::getMimeType($polyglotFile);

        // The current implementation will accept this as image/jpeg, which demonstrates
        // the limitation of basic MIME type checking for security
        $this->assertEquals('image/jpeg', $mimeType,
            'Simple MIME detection accepts polyglot files with valid image headers');

        // In a production environment, additional content scanning would be needed
        // to detect embedded script content in image files
    }

    /**
     * Test filename collision handling
     */
    public function testFilenameCollisionHandling(): void
    {
        // Generate multiple filenames - should all be unique
        $filenames = [];
        for ($i = 0; $i < 1000; $i++) {
            $filename = CarImageProcessor::generateSecureFilename('jpg');
            $this->assertNotContains($filename, $filenames, 'Filename collision detected');
            $filenames[] = $filename;
        }
    }

    /**
     * Test entropy of secure filename generation
     */
    public function testFilenameEntropy(): void
    {
        $patterns = [];

        // Generate many filenames and analyze for patterns
        for ($i = 0; $i < 100; $i++) {
            $filename = CarImageProcessor::generateSecureFilename('jpg');

            // Extract the random part (remove img_ prefix and .jpg suffix)
            $randomPart = substr($filename, 4, 32);
            $patterns[] = $randomPart;
        }

        // Check that we have good distribution of characters
        $charCounts = array_count_values(str_split(implode('', $patterns)));

        // Should have reasonable distribution across hex chars (0-9, a-f)
        $expectedChars = array_merge(range('0', '9'), range('a', 'f'));
        foreach ($expectedChars as $char) {
            $this->assertArrayHasKey($char, $charCounts, "Missing character '$char' in random generation");
        }

        // No character should be overly dominant (rough entropy check)
        $totalChars = array_sum($charCounts);
        foreach ($charCounts as $count) {
            $frequency = $count / $totalChars;
            $this->assertLessThan(0.15, $frequency, 'Character frequency too high - poor entropy');
        }
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

    private function createPolyglotFile(): string
    {
        // Create a file that has JPEG header but contains script content
        return $this->createJpegData() . "\n<?php echo 'malicious code'; ?>";
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
