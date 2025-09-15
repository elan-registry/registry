<?php

declare(strict_types=1);

/**
 * Simple syntax test for Car class type declarations (Issue #239)
 * 
 * Tests PHP syntax and basic reflection without loading UserSpice framework
 */

// Test that the Car class file has valid PHP syntax
function testCarClassSyntax(): bool {
    $carFile = dirname(__DIR__, 2) . '/usersc/classes/Car.php';
    if (!file_exists($carFile)) {
        echo "❌ Car.php file not found\n";
        return false;
    }
    
    $output = [];
    $returnVar = 0;
    exec("php -l " . escapeshellarg($carFile), $output, $returnVar);
    
    if ($returnVar === 0) {
        echo "✅ Car.php syntax is valid\n";
        return true;
    } else {
        echo "❌ Car.php has syntax errors:\n";
        echo implode("\n", $output) . "\n";
        return false;
    }
}

// Test that we can check method signatures via reflection
function testMethodSignatures(): bool {
    // Create a temporary test version to check method signatures
    $carFile = dirname(__DIR__, 2) . '/usersc/classes/Car.php';
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
            echo "✅ Found signature: $signature\n";
        } else {
            echo "❌ Missing signature: $signature\n";
            $allFound = false;
        }
    }
    
    return $allFound;
}

// Test that union types are supported (PHP 8+)
function testUnionTypeSupport(): bool {
    if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
        echo "✅ PHP " . PHP_VERSION . " supports union types\n";
        return true;
    } else {
        echo "⚠️  PHP " . PHP_VERSION . " - Union types require PHP 8.0+\n";
        return false;
    }
}

// Run all tests
echo "🧪 Testing Car class type declarations (Issue #239)\n";
echo "================================================\n";

$syntaxOk = testCarClassSyntax();
$signaturesOk = testMethodSignatures();
$unionTypesOk = testUnionTypeSupport();

echo "\n📊 Results:\n";
echo "- Syntax valid: " . ($syntaxOk ? "✅" : "❌") . "\n";
echo "- Signatures correct: " . ($signaturesOk ? "✅" : "❌") . "\n";
echo "- Union types supported: " . ($unionTypesOk ? "✅" : "⚠️") . "\n";

if ($syntaxOk && $signaturesOk && $unionTypesOk) {
    echo "\n🎉 All type declaration tests PASSED!\n";
    exit(0);
} else {
    echo "\n❌ Some tests FAILED!\n";
    exit(1);
}