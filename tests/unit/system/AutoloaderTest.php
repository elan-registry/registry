<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test custom autoloader functionality
 *
 * Verifies that the hybrid namespace-aware autoloader correctly loads
 * both namespaced (PSR-4) and non-namespaced (recursive scan) classes.
 *
 * @group system
 * @group autoloader
 */
class AutoloaderTest extends TestCase
{
    /**
     * Test that core classes are auto-loaded correctly
     *
     * These classes should load via the recursive iterator fallback
     * since they don't have namespaces yet.
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
        $this->assertTrue(class_exists('EnhancedSchemaManager'), 'EnhancedSchemaManager class should auto-load from admin/');
    }

    /**
     * Test that namespaced documentation classes auto-load
     *
     * These classes use the ElanRegistry\Documentation namespace but are not
     * in PSR-4 compliant directory structure yet. They load via recursive
     * fallback. When namespace migration (Issue #407) is complete, they will
     * be moved to proper PSR-4 structure.
     */
    public function testNamespacedClassesAutoload(): void
    {
        $this->assertTrue(
            class_exists('ElanRegistry\\Documentation\\MarkdownParser'),
            'Namespaced MarkdownParser class should auto-load'
        );
        $this->assertTrue(
            class_exists('ElanRegistry\\Documentation\\DocumentConfig'),
            'Namespaced DocumentConfig class should auto-load'
        );
    }

    /**
     * Test that exception classes are auto-loaded correctly
     *
     * All custom exceptions should auto-load from usersc/classes/exceptions/
     * directory via recursive iterator.
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
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\SchemaException'), 'SchemaException should auto-load');
    }

    /**
     * Test that autoloader doesn't fail on nonexistent classes
     *
     * The autoloader should gracefully handle requests for classes that
     * don't exist, returning false rather than throwing exceptions.
     */
    public function testAutoloaderDoesNotFailOnNonexistentClass(): void
    {
        // Should return false, not throw exception
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
