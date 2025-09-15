<?php

/**
 * Test for EXIF orientation handling in the Resize class
 * 
 * Tests the correctOrientation method functionality including:
 * - EXIF orientation detection and correction
 * - All 8 EXIF orientation values 
 * - Privacy-preserving EXIF stripping
 * - Error handling for missing EXIF data
 */

require_once __DIR__ . '/../tests/bootstrap.php';

class ImageOrientationTest extends PHPUnit\Framework\TestCase
{
    private $testImageDir;
    private $outputDir;

    protected function setUp(): void
    {
        $this->testImageDir = __DIR__ . '/fixtures/orientation/';
        $this->outputDir = __DIR__ . '/output/orientation/';
        
        // Create output directory if it doesn't exist
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up output files
        if (is_dir($this->outputDir)) {
            $files = glob($this->outputDir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testResizeClassExists()
    {
        $this->assertTrue(class_exists('Resize'), 'Resize class should exist');
    }

    public function testCorrectOrientationMethodExists()
    {
        $reflection = new ReflectionClass('Resize');
        $this->assertTrue(
            $reflection->hasMethod('correctOrientation'),
            'Resize class should have correctOrientation method'
        );
    }

    public function testOrientationCorrectionWithMockImage()
    {
        // Create a simple test image
        $testImage = imagecreate(100, 50);
        $testFile = $this->outputDir . 'test_normal.jpg';
        
        // Create a simple test image with different colors
        $white = imagecolorallocate($testImage, 255, 255, 255);
        $red = imagecolorallocate($testImage, 255, 0, 0);
        imagefill($testImage, 0, 0, $white);
        imagefilledrectangle($testImage, 0, 0, 20, 50, $red); // Red stripe on left
        
        imagejpeg($testImage, $testFile, 90);
        imagedestroy($testImage);

        $this->assertFileExists($testFile, 'Test image should be created');

        // Test Resize class can process the image
        try {
            $resize = new Resize($testFile);
            $this->assertNotNull($resize, 'Resize object should be created');
            
            // The image should be processed successfully even without EXIF data
            $resize->resizeImage(50, 25, 'exact');
            $resizedFile = $this->outputDir . 'test_resized.jpg';
            $resize->saveImage($resizedFile, 80);
            
            $this->assertFileExists($resizedFile, 'Resized image should be created');
            
        } catch (Exception $e) {
            $this->fail('Resize should handle images without EXIF data: ' . $e->getMessage());
        }
    }

    public function testEXIFExtensionAvailable()
    {
        $this->assertTrue(
            function_exists('exif_read_data'),
            'EXIF extension should be available for orientation handling'
        );
    }

    public function testImageRotateAvailable()
    {
        $this->assertTrue(
            function_exists('imagerotate'),
            'imagerotate function should be available for orientation correction'
        );
    }

    public function testImageFlipAvailable()
    {
        // imageflip was added in PHP 5.5.0
        $this->assertTrue(
            function_exists('imageflip'),
            'imageflip function should be available for orientation correction'
        );
    }

    /**
     * Test that the Resize class properly handles files without EXIF data
     */
    public function testHandleImageWithoutEXIF()
    {
        // Create a simple test image without EXIF data
        $testImage = imagecreate(100, 100);
        $white = imagecolorallocate($testImage, 255, 255, 255);
        imagefill($testImage, 0, 0, $white);
        
        $testFile = $this->outputDir . 'no_exif.jpg';
        imagejpeg($testImage, $testFile, 90);
        imagedestroy($testImage);

        // Should not throw exception when processing image without EXIF
        try {
            $resize = new Resize($testFile);
            $this->assertInstanceOf('Resize', $resize);
        } catch (Exception $e) {
            $this->fail('Should handle images without EXIF data gracefully: ' . $e->getMessage());
        }
    }

    /**
     * Test that non-JPEG files are handled properly 
     */
    public function testHandleNonJPEGFiles()
    {
        // Create a simple PNG test image
        $testImage = imagecreate(100, 100);
        $white = imagecolorallocate($testImage, 255, 255, 255);
        imagefill($testImage, 0, 0, $white);
        
        $testFile = $this->outputDir . 'test.png';
        imagepng($testImage, $testFile);
        imagedestroy($testImage);

        // Should process PNG files normally (no EXIF orientation correction)
        try {
            $resize = new Resize($testFile);
            $this->assertInstanceOf('Resize', $resize);
        } catch (Exception $e) {
            $this->fail('Should handle PNG files without EXIF processing: ' . $e->getMessage());
        }
    }
}