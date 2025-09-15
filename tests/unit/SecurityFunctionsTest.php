<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Direct tests of security functions without full framework bootstrap
 * SECURITY: Removed eval() usage and implemented safe function definitions
 */
class SecurityFunctionsTest extends TestCase
{
    private $tempDir;
    
    protected function setUp(): void
    {
        // Create temporary directory for testing
        $this->tempDir = sys_get_temp_dir() . '/elan_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        // Include the security functions directly
        if (!function_exists('generateSecureFilename')) {
            $this->loadSecurityFunctions();
        }
    }
    
    protected function tearDown(): void
    {
        // Clean up temporary files
        $this->cleanupTempDir();
    }
    
    private function loadSecurityFunctions(): void
    {
        // Define security functions directly without eval() - SECURITY FIX
        if (!function_exists('generateSecureFilename')) {
            function generateSecureFilename($extension)
            {
                $randomBytes = random_bytes(16);
                $secureFilename = "img_" . bin2hex($randomBytes) . "." . $extension;
                return $secureFilename;
            }
        }
        
        if (!function_exists('validateFileUpload')) {
            function validateFileUpload($file, $maxSize = 5242880)
            {
                if ($file["error"] !== UPLOAD_ERR_OK) {
                    throw new Exception("File upload error: " . $file["error"]);
                }
                
                if ($file["size"] > $maxSize) {
                    throw new Exception("File too large. Maximum size: " . ($maxSize / 1024 / 1024) . "MB");
                }
                
                if (!file_exists($file["tmp_name"])) {
                    throw new Exception("Invalid file upload");
                }
                
                if ($file["size"] < 100) {
                    throw new Exception("File too small - minimum 100 bytes required");
                }
                
                return true;
            }
        }
        
        if (!function_exists('getMimeType')) {
            function getMimeType($file)
            {
                if (!file_exists($file)) {
                    throw new Exception("File does not exist");
                }
                
                $mtype = false;
                
                if (function_exists("finfo_open")) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mtype = finfo_file($finfo, $file);
                    finfo_close($finfo);
                } elseif (function_exists("mime_content_type")) {
                    $mtype = mime_content_type($file);
                } else {
                    throw new Exception("Unable to determine file MIME type");
                }
                
                $allowedTypes = ["image/jpeg", "image/jpg", "image/png", "image/gif", "image/webp"];
                if (!in_array($mtype, $allowedTypes, true)) {
                    throw new Exception("Invalid file type detected: " . $mtype);
                }
                
                return $mtype;
            }
        }
        
        if (!function_exists('getExtension')) {
            function getExtension($mimeType)
            {
                $allowedExtensions = array(
                    "image/jpeg" => "jpg",
                    "image/jpg" => "jpg", 
                    "image/png" => "png",
                    "image/gif" => "gif",
                    "image/webp" => "webp"
                );
                
                if (!array_key_exists($mimeType, $allowedExtensions)) {
                    throw new Exception("Unsupported file type: " . $mimeType);
                }
                
                return $allowedExtensions[$mimeType];
            }
        }
    }
    
    public function testSecureFilenameGeneration(): void
    {
        $filename1 = generateSecureFilename('jpg');
        $filename2 = generateSecureFilename('jpg');
        
        // Filenames should be different (cryptographically random)
        $this->assertNotEquals($filename1, $filename2);
        
        // Should follow expected pattern
        $this->assertMatchesRegularExpression('/^img_[a-f0-9]{32}\.jpg$/', $filename1);
        $this->assertMatchesRegularExpression('/^img_[a-f0-9]{32}\.jpg$/', $filename2);
        
        // Should be proper length (img_ + 32 hex chars + .ext)
        $this->assertEquals(40, strlen($filename1)); // img_ (4) + 32 hex + .jpg (4)
    }
    
    public function testMimeTypeValidationValid(): void
    {
        // Create test files with valid MIME types
        $jpegFile = $this->createTestFile('test.jpg', $this->createJpegData());
        $pngFile = $this->createTestFile('test.png', $this->createPngData());
        
        // Should return correct MIME types for valid image types
        $this->assertEquals('image/jpeg', getMimeType($jpegFile));
        $this->assertEquals('image/png', getMimeType($pngFile));
    }
    
    public function testMimeTypeValidationInvalid(): void
    {
        // Create test file with invalid MIME type  
        $phpFile = $this->createTestFile('malicious.php', '<?php echo "hack"; ?>');
        
        // Should throw exception for invalid types
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid file type detected');
        getMimeType($phpFile);
    }
    
    public function testExtensionMappingSecurity(): void
    {
        // Valid MIME types should return correct extensions
        $this->assertEquals('jpg', getExtension('image/jpeg'));
        $this->assertEquals('png', getExtension('image/png'));
        $this->assertEquals('gif', getExtension('image/gif'));
        $this->assertEquals('webp', getExtension('image/webp'));
        
        // Invalid MIME types should throw exceptions
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported file type');
        getExtension('application/x-php');
    }
    
    public function testFileUploadSizeValidation(): void
    {
        // Test file within size limit
        $validFile = [
            'error' => UPLOAD_ERR_OK,
            'size' => 1024 * 1024, // 1MB
            'tmp_name' => $this->createTestFile('valid.jpg', str_repeat('x', 1024 * 1024))
        ];
        
        $this->assertTrue(validateFileUpload($validFile));
        
        // Test file exceeding size limit
        $largeFile = [
            'error' => UPLOAD_ERR_OK,
            'size' => 10 * 1024 * 1024, // 10MB - exceeds 5MB limit
            'tmp_name' => $this->createTestFile('large.jpg', 'dummy')
        ];
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File too large');
        validateFileUpload($largeFile);
    }
    
    public function testFileUploadMinimumSize(): void
    {
        // Test file below minimum size
        $tinyFile = [
            'error' => UPLOAD_ERR_OK,
            'size' => 50, // Below 100 byte minimum
            'tmp_name' => $this->createTestFile('tiny.jpg', str_repeat('x', 50))
        ];
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File too small');
        validateFileUpload($tinyFile);
    }
    
    public function testFileUploadErrorHandling(): void
    {
        // Test various upload errors
        $errorCases = [
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE,
            UPLOAD_ERR_PARTIAL,
            UPLOAD_ERR_NO_FILE,
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE,
            UPLOAD_ERR_EXTENSION
        ];
        
        foreach ($errorCases as $errorCode) {
            $fileWithError = [
                'error' => $errorCode,
                'size' => 1024,
                'tmp_name' => $this->createTestFile('error.jpg', 'dummy')
            ];
            
            try {
                validateFileUpload($fileWithError);
                $this->fail("Expected exception for error code: $errorCode");
            } catch (Exception $e) {
                $this->assertStringContainsString('File upload error', $e->getMessage());
            }
        }
    }
    
    public function testFilenameCollisionHandling(): void
    {
        // Generate multiple filenames - should all be unique
        $filenames = [];
        for ($i = 0; $i < 1000; $i++) {
            $filename = generateSecureFilename('jpg');
            $this->assertNotContains($filename, $filenames, 'Filename collision detected');
            $filenames[] = $filename;
        }
    }
    
    public function testFilenameEntropy(): void
    {
        $filenames = [];
        $patterns = [];
        
        // Generate many filenames and analyze for patterns
        for ($i = 0; $i < 100; $i++) {
            $filename = generateSecureFilename('jpg');
            $filenames[] = $filename;
            
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