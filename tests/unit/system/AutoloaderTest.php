<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test custom autoloader functionality
 *
 * Verifies that the hybrid namespace-aware autoloader correctly loads
 * all custom classes via PSR-4 prefix mappings.
 */
#[Group('system')]
#[Group('autoloader')]
class AutoloaderTest extends TestCase
{
    /**
     * Test that core application classes are available via PSR-4 autoloading.
     *
     * - Owner, CarView, Resize, ChassisValidator, and EmailTemplate are
     *   namespaced under ElanRegistry\ and load via the PSR-4 root prefix
     *   mapping to usersc/classes/.
     * - The class_exists('Car') assertion is a regression guard confirming the global
     *   Car alias remains available. In the unit test environment it resolves to the
     *   bootstrap mock rather than the real ElanRegistry\Car\Car; in production it
     *   resolves via class_alias() at the bottom of Car/Car.php.
     */
    public function testCoreClassesAutoload(): void
    {
        $this->assertTrue(class_exists('Car'), 'Car class should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Owner'), 'ElanRegistry\\Owner class should auto-load');
        $this->assertInstanceOf(\ElanRegistry\Owner::class, new \ElanRegistry\Owner(), 'Owner must instantiate as the ElanRegistry\\Owner class (not an alias or wrong class declaration)');
        $this->assertTrue(class_exists('ElanRegistry\\CarView'), 'ElanRegistry\\CarView class should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Resize'), 'ElanRegistry\\Resize class should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\ChassisValidator'), 'ElanRegistry\\ChassisValidator class should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\EmailTemplate'), 'ElanRegistry\\EmailTemplate class should auto-load');
    }

    /**
     * Test that admin classes are auto-loaded correctly via PSR-4.
     *
     * These classes are namespaced under ElanRegistry\Admin and live in
     * usersc/classes/admin/ (lowercase). They load via the explicit ElanRegistry\Admin\
     * prefix mapping, NOT the catch-all ElanRegistry\ prefix — the explicit entry is
     * required to ensure the lowercase path is used on case-sensitive Linux filesystems.
     */
    public function testAdminClassesAutoload(): void
    {
        $this->assertTrue(
            class_exists('ElanRegistry\\Admin\\BackupManager'),
            'ElanRegistry\\Admin\\BackupManager class should auto-load from admin/'
        );
        $this->assertTrue(
            class_exists('ElanRegistry\\Admin\\PagePermissionClassifier'),
            'ElanRegistry\\Admin\\PagePermissionClassifier class should auto-load from admin/'
        );

        $rc = new ReflectionClass('ElanRegistry\\Admin\\BackupManager');
        $this->assertStringEndsWith(
            '/usersc/classes/admin/BackupManager.php',
            (string) $rc->getFileName(),
            'BackupManager must load from usersc/classes/admin/ (lowercase) via the ElanRegistry\\Admin\\ prefix mapping'
        );

        $rc2 = new ReflectionClass('ElanRegistry\\Admin\\PagePermissionClassifier');
        $this->assertStringEndsWith(
            '/usersc/classes/admin/PagePermissionClassifier.php',
            (string) $rc2->getFileName(),
            'PagePermissionClassifier must load from usersc/classes/admin/ (lowercase) via the ElanRegistry\\Admin\\ prefix mapping'
        );
    }

    /**
     * Test that DocumentPortalTemplate auto-loads from its PSR-4 location.
     *
     * DocumentPortalTemplate declares namespace ElanRegistry\Documentation and
     * lives at usersc/classes/Documentation/DocumentPortalTemplate.php — a
     * PSR-4-compliant path under the root ElanRegistry\ → usersc/classes/ mapping.
     */
    public function testNamespacedClassesAutoload(): void
    {
        $this->assertTrue(
            class_exists('ElanRegistry\\Documentation\\DocumentPortalTemplate'),
            'Namespaced DocumentPortalTemplate class should auto-load'
        );

        $rc = new ReflectionClass('ElanRegistry\\Documentation\\DocumentPortalTemplate');
        $this->assertStringEndsWith(
            '/usersc/classes/Documentation/DocumentPortalTemplate.php',
            (string) $rc->getFileName(),
            'DocumentPortalTemplate must load from usersc/classes/Documentation/DocumentPortalTemplate.php via PSR-4'
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
     * PSR-4 path resolution.
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
     * ElanRegistry\Car\CarRepository and ElanRegistry\Car\Car both live under
     * usersc/classes/Car/ — PSR-4-compliant paths under the root ElanRegistry\ →
     * usersc/classes/ mapping. Neither is mocked in the bootstrap, so
     * ReflectionClass::getFileName() verifies the autoloader resolved the real files.
     *
     * Car\Car specifically uses the double-directory pattern (Car/Car.php). A
     * regression where the file is recreated at the old usersc/classes/Car.php path
     * would be caught by this assertion.
     */
    public function testPsr4RootPrefixResolution(): void
    {
        $rc = new ReflectionClass('ElanRegistry\\Car\\CarRepository');
        $this->assertStringEndsWith(
            '/usersc/classes/Car/CarRepository.php',
            (string) $rc->getFileName(),
            'ElanRegistry\\Car\\CarRepository must load from usersc/classes/Car/CarRepository.php via root PSR-4 prefix'
        );

        $rc2 = new ReflectionClass('ElanRegistry\\Car\\Car');
        $this->assertStringEndsWith(
            '/usersc/classes/Car/Car.php',
            (string) $rc2->getFileName(),
            'ElanRegistry\\Car\\Car must load from usersc/classes/Car/Car.php (double-directory PSR-4 pattern)'
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
     * This test verifies that the global Car alias allows case-insensitive class_exists checks.
     *
     * In production, these resolve via class_alias(\ElanRegistry\Car\Car::class, 'Car')
     * at the bottom of Car/Car.php — PHP registers the alias case-insensitively, so
     * 'car' and 'CAR' resolve to the same entry. In the unit test environment, 'Car'
     * resolves via the bootstrap mock, which PHP also registers case-insensitively.
     */
    public function testCaseInsensitiveLoading(): void
    {
        // All three resolve to the same class — in production via class_alias() in Car/Car.php, in unit tests via the bootstrap mock
        $this->assertTrue(class_exists('Car'), 'Standard case should work via global Car alias');
        $this->assertTrue(class_exists('car'), 'Lowercase should work (PHP class table is case-insensitive)');
        $this->assertTrue(class_exists('CAR'), 'Uppercase should work (PHP class table is case-insensitive)');
    }
}
