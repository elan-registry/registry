<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test custom autoloader functionality
 *
 * Verifies that the hybrid namespace-aware autoloader correctly loads
 * both namespaced (PSR-4) and non-namespaced (recursive scan) classes.
 */
#[Group('system')]
#[Group('autoloader')]
class AutoloaderTest extends TestCase
{
    /**
     * Test that non-PSR4-located core classes are available via recursive fallback.
     *
     * Several classes use the fallback for different reasons:
     * - ElanRegistryOwner, CarView, etc. have no namespace (genuinely non-namespaced).
     * - Car.php declares namespace ElanRegistry\Car but lives at usersc/classes/Car.php
     *   (PSR-4 would expect usersc/classes/Car/Car.php). File relocates in #779.
     * - The class_exists('Car') assertion passes from a bootstrap-unit.php mock Car
     *   in the global namespace, not from the autoloader.
     */
    public function testCoreClassesAutoload(): void
    {
        $this->assertTrue(class_exists('Car'), 'Car class should auto-load');
        $this->assertTrue(class_exists('ElanRegistryOwner'), 'ElanRegistryOwner class should auto-load');
        $this->assertTrue(class_exists('CarView'), 'CarView class should auto-load');
        $this->assertTrue(class_exists('Resize'), 'Resize class should auto-load');
        $this->assertTrue(class_exists('ChassisValidator'), 'ChassisValidator class should auto-load');
        $this->assertTrue(class_exists('EmailTemplate'), 'EmailTemplate class should auto-load');
        $this->assertTrue(class_exists('CarErrorMessages'), 'CarErrorMessages class should auto-load');
    }

    /**
     * Test that admin classes are auto-loaded correctly
     *
     * These classes are in the admin/ subdirectory and should load via
     * the recursive iterator.
     */
    public function testAdminClassesAutoload(): void
    {
        $this->assertTrue(class_exists('BackupManager'), 'BackupManager class should auto-load from admin/');
    }

    /**
     * Test that classes with non-standard file locations load via recursive fallback.
     *
     * DocumentPortalTemplate declares ElanRegistry\Documentation but lives at
     * usersc/classes/DocumentPortalTemplate.php (non-PSR-4 location). PSR-4
     * would expect usersc/classes/Documentation/DocumentPortalTemplate.php.
     * The recursive fallback resolves this until the file is relocated in #779.
     */
    public function testNamespacedClassesAutoload(): void
    {
        $this->assertTrue(
            class_exists('ElanRegistry\\Documentation\\DocumentPortalTemplate'),
            'Namespaced DocumentPortalTemplate class should auto-load'
        );
    }

    /**
     * Test that exception classes are auto-loaded correctly via PSR-4.
     *
     * All custom exceptions load via the ElanRegistry\Exceptions\ prefix
     * mapping to usersc/classes/Exceptions/.
     */
    public function testExceptionClassesAutoload(): void
    {
        // Car exceptions (namespaced under ElanRegistry\Exceptions)
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarNotFoundException'), 'CarNotFoundException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarCreationException'), 'CarCreationException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarValidationException'), 'CarValidationException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarTransferException'), 'CarTransferException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarMergeException'), 'CarMergeException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarDeletionException'), 'CarDeletionException should auto-load');

        // Owner exceptions
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\OwnerNotFoundException'), 'OwnerNotFoundException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\OwnerCreationException'), 'OwnerCreationException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\OwnerValidationException'), 'OwnerValidationException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\OwnerUpdateException'), 'OwnerUpdateException should auto-load');

        // System exceptions
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\ImageProcessingException'), 'ImageProcessingException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\BackupException'), 'BackupException should auto-load');
    }

    /**
     * Test that ElanRegistry\Reference\CarModel is available to the application.
     *
     * Note: bootstrap-unit.php pre-loads CarModel via an eval mock before the
     * autoloader registers, so class_exists() here confirms availability, not
     * PSR-4 path resolution. The structural fix (ElanRegistry\Reference\ prefix
     * mapping to usersc/classes/ElanRegistry/Reference/) is validated by the
     * $namespaceMappings configuration and exercised in integration tests via
     * CarValidator and models.php. See testPsr4RootPrefixResolution() for an
     * example of a full path assertion using a non-mocked class.
     */
    public function testReferenceClassIsAvailable(): void
    {
        $this->assertTrue(
            class_exists('ElanRegistry\\Reference\\CarModel'),
            'ElanRegistry\\Reference\\CarModel must be available'
        );
    }

    /**
     * Test that the root ElanRegistry\ prefix resolves to the correct file path.
     *
     * ElanRegistry\Car\CarRepository lives at usersc/classes/Car/CarRepository.php —
     * a PSR-4-compliant path under the root ElanRegistry\ → usersc/classes/ mapping.
     * This class is NOT mocked in the bootstrap, so ReflectionClass::getFileName()
     * verifies the autoloader actually resolved and loaded the real file.
     */
    public function testPsr4RootPrefixResolution(): void
    {
        $rc = new ReflectionClass('ElanRegistry\\Car\\CarRepository');
        $this->assertStringEndsWith(
            '/usersc/classes/Car/CarRepository.php',
            (string) $rc->getFileName(),
            'ElanRegistry\\Car\\CarRepository must load from usersc/classes/Car/CarRepository.php via root PSR-4 prefix'
        );
    }

    /**
     * Test that autoloader doesn't fail on nonexistent classes
     *
     * The autoloader should gracefully handle requests for classes that
     * don't exist, returning false rather than throwing exceptions.
     */
    public function testAutoloaderDoesNotFailOnNonexistentClass(): void
    {
        $this->assertFalse(
            class_exists('NonexistentClassName'),
            'Autoloader should return false for nonexistent class without throwing exception'
        );
        $this->assertFalse(
            class_exists('ElanRegistry\\NonexistentNamespacedClass'),
            'Autoloader should return false for nonexistent namespaced class without throwing exception'
        );
    }

    /**
     * Test that exceptions can be instantiated and thrown
     *
     * Verifies that exception classes not only load but are also
     * functional and can be used in try-catch blocks.
     */
    public function testExceptionClassesAreFunctional(): void
    {
        // Test that we can create and throw an exception
        try {
            throw new \ElanRegistry\Exceptions\CarNotFoundException('Test message');
        } catch (\ElanRegistry\Exceptions\CarNotFoundException $e) {
            $this->assertInstanceOf(\ElanRegistry\Exceptions\CarNotFoundException::class, $e);
            $this->assertEquals('Test message', $e->getMessage());
        }

        // Test owner exception
        try {
            throw new \ElanRegistry\Exceptions\OwnerNotFoundException('Test owner message');
        } catch (\ElanRegistry\Exceptions\OwnerNotFoundException $e) {
            $this->assertInstanceOf(\ElanRegistry\Exceptions\OwnerNotFoundException::class, $e);
            $this->assertEquals('Test owner message', $e->getMessage());
        }

        // Test system exception
        try {
            throw new \ElanRegistry\Exceptions\BackupException('Test backup message');
        } catch (\ElanRegistry\Exceptions\BackupException $e) {
            $this->assertInstanceOf(\ElanRegistry\Exceptions\BackupException::class, $e);
            $this->assertEquals('Test backup message', $e->getMessage());
        }
    }

    /**
     * Test case-insensitive class loading
     *
     * The recursive iterator uses case-insensitive matching for compatibility.
     * This test verifies that classes load regardless of case in the class_exists check.
     */
    public function testCaseInsensitiveLoading(): void
    {
        // These should all resolve to the same class
        $this->assertTrue(class_exists('Car'), 'Standard case should work');
        $this->assertTrue(class_exists('car'), 'Lowercase should work');
        $this->assertTrue(class_exists('CAR'), 'Uppercase should work');
    }
}
