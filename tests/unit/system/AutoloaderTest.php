<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

#[Group('system')]
#[Group('autoloader')]
class AutoloaderTest extends TestCase
{
    /** The global 'Car' alias exists only after ElanRegistry\Car\Car autoloads. */
    public function testCoreClassesAutoload(): void
    {
        $this->assertTrue(class_exists(\ElanRegistry\Car\Car::class), 'Real Car class must be autoloadable');
        $this->assertTrue(class_exists('Car'), 'Global Car alias should be registered by Car/Car.php');
        $this->assertTrue(class_exists('ElanRegistry\\Owner'), 'ElanRegistry\\Owner class should auto-load');
        // Owner otherwise falls back to dbi(), which the unit tier does not define.
        new \ElanRegistry\Owner(null, $this->createStub(\ElanRegistry\DatabaseInterface::class));
        $this->assertTrue(class_exists('ElanRegistry\\CarView'), 'ElanRegistry\\CarView class should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Resize'), 'ElanRegistry\\Resize class should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\ChassisValidator'), 'ElanRegistry\\ChassisValidator class should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\EmailTemplate'), 'ElanRegistry\\EmailTemplate class should auto-load');
    }

    /** usersc/classes/admin/ is lowercase, so it needs its own prefix on Linux. */
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

    public function testExceptionClassesAutoload(): void
    {
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarNotFoundException'), 'CarNotFoundException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarCreationException'), 'CarCreationException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarValidationException'), 'CarValidationException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarTransferException'), 'CarTransferException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarMergeException'), 'CarMergeException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\CarDeletionException'), 'CarDeletionException should auto-load');

        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\OwnerCreationException'), 'OwnerCreationException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\OwnerValidationException'), 'OwnerValidationException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\OwnerUpdateException'), 'OwnerUpdateException should auto-load');

        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\ImageProcessingException'), 'ImageProcessingException should auto-load');
        $this->assertTrue(class_exists('ElanRegistry\\Exceptions\\BackupException'), 'BackupException should auto-load');
    }

    /** CarModel is not mocked in bootstrap-unit.php (#1446), so this is a real load. */
    public function testReferenceClassIsAvailable(): void
    {
        $this->assertTrue(
            class_exists('ElanRegistry\\Reference\\CarModel'),
            'ElanRegistry\\Reference\\CarModel must be available'
        );
    }

    /** Catches Car\Car recreated at the old usersc/classes/Car.php path. */
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

    public function testExceptionClassesAreFunctional(): void
    {
        try {
            throw new \ElanRegistry\Exceptions\CarNotFoundException('Test message');
        } catch (\ElanRegistry\Exceptions\CarNotFoundException $e) {
            $this->assertEquals('Test message', $e->getMessage());
        }

        try {
            throw new \ElanRegistry\Exceptions\OwnerCreationException('Test owner message');
        } catch (\ElanRegistry\Exceptions\OwnerCreationException $e) {
            $this->assertEquals('Test owner message', $e->getMessage());
        }

        try {
            throw new \ElanRegistry\Exceptions\BackupException('Test backup message');
        } catch (\ElanRegistry\Exceptions\BackupException $e) {
            $this->assertEquals('Test backup message', $e->getMessage());
        }
    }

    /** PHP registers class_alias() names case-insensitively. */
    public function testCaseInsensitiveLoading(): void
    {
        // Autoloading the real class runs the class_alias() call.
        $this->assertTrue(class_exists(\ElanRegistry\Car\Car::class), 'Real Car class must be autoloadable');

        $this->assertTrue(class_exists('Car'), 'Standard case should work via global Car alias');
        $this->assertTrue(class_exists('car'), 'Lowercase should work (PHP class table is case-insensitive)');
        $this->assertTrue(class_exists('CAR'), 'Uppercase should work (PHP class table is case-insensitive)');
    }
}
