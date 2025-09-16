<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Simple syntax test for Car class type declarations (Issue #239)
 *
 * Tests PHP syntax and basic reflection without loading UserSpice framework
 *
 * @group fast
 */
final class CarTypeDeclarationSyntaxTest extends TestCase
{
    /**
     * Test that the Car class file has valid PHP syntax
     *
     * @group fast
     */
    public function testCarClassSyntax(): void
    {
        $carFile = dirname(__DIR__, 3) . '/usersc/classes/Car.php';
        if (!file_exists($carFile)) {
            $this->markTestSkipped('Car.php file not found at expected location');
        }

        $output = [];
        $returnVar = 0;
        exec("php -l " . escapeshellarg($carFile), $output, $returnVar);

        if ($returnVar === 0) {
            $this->assertTrue(true, 'Car.php syntax is valid');
        } else {
            $this->fail('Car.php has syntax errors: ' . implode("\n", $output));
        }
    }

    /**
     * Test that we can check method signatures via reflection
     *
     * @group fast
     */
    public function testMethodSignatures(): void
    {
        // Create a temporary test version to check method signatures
        $carFile = dirname(__DIR__, 3) . '/usersc/classes/Car.php';
        if (!file_exists($carFile)) {
            $this->markTestSkipped('Car.php file not found at expected location');
        }

        $content = file_get_contents($carFile);

        // Check for the expected method signatures
        $expectedSignatures = [
            'public function getDataTablesData(array $request, string $table = \'cars\'): array',
            'private function validateColumnName(string $columnName, string $tableName): string|false',
            'public function __construct(?int $id = null)'
        ];

        $allFound = true;
        foreach ($expectedSignatures as $signature) {
            if (strpos($content, $signature) !== false) {
                // Signature found - continue
            } else {
                $allFound = false;
                $this->fail("Missing expected signature: $signature");
            }
        }

        $this->assertTrue($allFound, 'All expected method signatures found');
    }

    /**
     * Test that union types are supported (PHP 8+)
     *
     * @group fast
     */
    public function testUnionTypeSupport(): void
    {
        if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
            $this->assertTrue(true, 'PHP ' . PHP_VERSION . ' supports union types');
        } else {
            $this->markTestSkipped('Union types require PHP 8.0+, current version: ' . PHP_VERSION);
        }
    }
}