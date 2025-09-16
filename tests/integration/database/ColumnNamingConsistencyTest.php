<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Column Naming Consistency Test
 * 
 * Static analysis of PHP files to verify column usage patterns and
 * identify all code that will need updating during migration.
 * 
 * Purpose:
 * - Scan all files using 'carid' and 'car_id'
 * - Generate consistency report
 * - Flag mixed usage patterns
 * - Validate query syntax
 * - Prepare file update list for migration
 */
final class ColumnNamingConsistencyTest extends TestCase
{
    private $projectRoot;
    private $analysisReport = [];
    private $affectedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__);
        $this->analysisReport = [];
        $this->affectedFiles = [];
    }

    /**
     * Test scanning for files that use 'carid' column
     */
    public function testScanFilesUsingCarId(): void
    {
        $caridFiles = $this->scanForPattern('carid');
        
        $this->assertNotEmpty($caridFiles, "Should find files using 'carid' pattern");
        $this->assertGreaterThanOrEqual(10, count($caridFiles), "Should find at least 10 files using 'carid'");
        
        $this->analysisReport['carid_usage'] = [
            'file_count' => count($caridFiles),
            'files' => $caridFiles
        ];
        
        // Verify expected files are in the list
        $expectedFiles = [
            'usersc/classes/Car.php',
            'app/cars/actions/edit.php',
            'app/cars/edit.php',
            'tests/bootstrap.php',
            'usersc/scripts/after_user_deletion.php'
        ];
        
        foreach ($expectedFiles as $expectedFile) {
            $fullPath = $this->projectRoot . '/' . $expectedFile;
            $found = false;
            foreach ($caridFiles as $file) {
                if (str_contains($file['file'], $expectedFile)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Expected file should use 'carid': {$expectedFile}");
        }
    }

    /**
     * Test scanning for files that use 'car_id' column
     */
    public function testScanFilesUsingCarIdUnderscore(): void
    {
        $carIdFiles = $this->scanForPattern('car_id');
        
        $this->assertNotEmpty($carIdFiles, "Should find files using 'car_id' pattern");
        $this->assertGreaterThanOrEqual(15, count($carIdFiles), "Should find at least 15 files using 'car_id'");
        
        $this->analysisReport['car_id_usage'] = [
            'file_count' => count($carIdFiles),
            'files' => $carIdFiles
        ];
        
        // Verify expected files are in the list
        $expectedFiles = [
            'app/cars/details.php',
            'app/cars/index.php',
            'app/verify/verify_car.php',
            'usersc/classes/Car.php'  // Uses both patterns
        ];
        
        foreach ($expectedFiles as $expectedFile) {
            $found = false;
            foreach ($carIdFiles as $file) {
                if (str_contains($file['file'], $expectedFile)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Expected file should use 'car_id': {$expectedFile}");
        }
    }

    /**
     * Test for files that use both patterns (mixed usage)
     */
    public function testIdentifyMixedUsageFiles(): void
    {
        $caridFiles = array_column($this->scanForPattern('carid'), 'file');
        $carIdFiles = array_column($this->scanForPattern('car_id'), 'file');
        
        $mixedUsageFiles = array_intersect($caridFiles, $carIdFiles);
        
        $this->analysisReport['mixed_usage'] = [
            'file_count' => count($mixedUsageFiles),
            'files' => array_values($mixedUsageFiles)
        ];
        
        // Car.php is expected to have mixed usage
        $carPhpFound = false;
        foreach ($mixedUsageFiles as $file) {
            if (str_contains($file, 'usersc/classes/Car.php')) {
                $carPhpFound = true;
                break;
            }
        }
        $this->assertTrue($carPhpFound, "Car.php should have mixed usage of both patterns");
        
        echo "\nFiles with mixed carid/car_id usage: " . count($mixedUsageFiles) . "\n";
        foreach ($mixedUsageFiles as $file) {
            echo "  - " . str_replace($this->projectRoot, '', $file) . "\n";
        }
    }

    /**
     * Test SQL query syntax in files using carid
     */
    public function testSqlQuerySyntaxValidation(): void
    {
        $files = $this->scanForPattern('carid');
        $syntaxIssues = [];
        
        foreach ($files as $fileData) {
            $content = file_get_contents($fileData['file']);
            
            // Check for common SQL patterns with carid
            $patterns = [
                '/SELECT.*carid.*FROM\s+car_user/i',
                '/INSERT.*INTO\s+car_user.*carid/i',
                '/UPDATE.*car_user.*SET.*carid/i',
                '/DELETE.*FROM\s+car_user.*WHERE.*carid/i',
                '/JOIN.*ON.*carid\s*=/i'
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    foreach ($matches[0] as $match) {
                        // Check for potential syntax issues
                        if (!$this->validateSqlFragment($match)) {
                            $syntaxIssues[] = [
                                'file' => $fileData['file'],
                                'query' => trim($match),
                                'issue' => 'Potential syntax issue'
                            ];
                        }
                    }
                }
            }
        }
        
        $this->analysisReport['syntax_issues'] = $syntaxIssues;
        $this->assertLessThanOrEqual(5, count($syntaxIssues), 
            "Should have minimal SQL syntax issues (found " . count($syntaxIssues) . ")");
        
        if (!empty($syntaxIssues)) {
            echo "\nSQL syntax issues found:\n";
            foreach ($syntaxIssues as $issue) {
                echo "  - {$issue['file']}: {$issue['query']}\n";
            }
        }
    }

    /**
     * Test preparation of file update list for migration
     */
    public function testPrepareMigrationFileList(): void
    {
        $caridFiles = $this->scanForPattern('carid');
        
        $migrationList = [];
        foreach ($caridFiles as $fileData) {
            $relativePath = str_replace($this->projectRoot . '/', '', $fileData['file']);
            
            // Skip test files and FIX scripts from automatic updates
            if (str_starts_with($relativePath, 'tests/') || str_starts_with($relativePath, 'FIX/')) {
                continue;
            }
            
            $migrationList[] = [
                'file' => $relativePath,
                'matches' => $fileData['matches'],
                'estimated_changes' => count($fileData['match_details']),
                'priority' => $this->calculateUpdatePriority($relativePath)
            ];
        }
        
        // Sort by priority
        usort($migrationList, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        $this->analysisReport['migration_file_list'] = $migrationList;
        
        $this->assertGreaterThan(0, count($migrationList), "Should have files to migrate");
        
        // High priority files should include Car.php and main app files
        $highPriorityFiles = array_filter($migrationList, function($item) {
            return $item['priority'] >= 3;
        });
        
        $this->assertGreaterThan(0, count($highPriorityFiles), "Should have high priority files");
        
        echo "\nMigration file list prepared: " . count($migrationList) . " files\n";
        echo "High priority files: " . count($highPriorityFiles) . "\n";
    }

    /**
     * Generate comprehensive analysis report
     */
    public function testGenerateAnalysisReport(): void
    {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'analysis_summary' => [
                'carid_files' => $this->analysisReport['carid_usage']['file_count'] ?? 0,
                'car_id_files' => $this->analysisReport['car_id_usage']['file_count'] ?? 0,
                'mixed_usage_files' => $this->analysisReport['mixed_usage']['file_count'] ?? 0,
                'syntax_issues' => count($this->analysisReport['syntax_issues'] ?? []),
                'files_to_migrate' => count($this->analysisReport['migration_file_list'] ?? [])
            ],
            'detailed_analysis' => $this->analysisReport
        ];
        
        // Write report to file
        $reportPath = __DIR__ . '/reports/column_naming_analysis_' . date('Y-m-d_H-i-s') . '.json';
        
        // Create reports directory if it doesn't exist
        $reportsDir = dirname($reportPath);
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }
        
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        $this->assertTrue(file_exists($reportPath), "Analysis report should be created");
        $this->assertGreaterThan(0, filesize($reportPath), "Report should contain data");
        
        echo "\nColumn naming analysis report created: {$reportPath}\n";
        echo "Summary: {$report['analysis_summary']['carid_files']} carid files, ";
        echo "{$report['analysis_summary']['car_id_files']} car_id files, ";
        echo "{$report['analysis_summary']['mixed_usage_files']} mixed usage\n";
    }

    /**
     * Scan for specific pattern in PHP files
     */
    private function scanForPattern(string $pattern): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->projectRoot));
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filepath = $file->getPathname();
                
                // Skip certain directories
                if (str_contains($filepath, '/vendor/') || 
                    str_contains($filepath, '/.git/') || 
                    str_contains($filepath, '/node_modules/')) {
                    continue;
                }
                
                $content = file_get_contents($filepath);
                
                // Look for the pattern
                if (preg_match_all('/\b' . preg_quote($pattern, '/') . '\b/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $files[] = [
                        'file' => $filepath,
                        'matches' => count($matches[0]),
                        'match_details' => $matches[0]
                    ];
                }
            }
        }
        
        return $files;
    }

    /**
     * Basic SQL fragment validation
     */
    private function validateSqlFragment(string $fragment): bool
    {
        // Basic checks for common SQL syntax issues
        $fragment = trim($fragment);
        
        // Check for unmatched quotes
        $singleQuotes = substr_count($fragment, "'") - substr_count($fragment, "\\'");
        if ($singleQuotes % 2 !== 0) {
            return false;
        }
        
        // Check for basic SQL structure
        if (preg_match('/SELECT.*FROM|INSERT.*INTO|UPDATE.*SET|DELETE.*FROM/i', $fragment)) {
            return true;
        }
        
        return true; // Default to valid for fragments
    }

    /**
     * Calculate update priority for files
     */
    private function calculateUpdatePriority(string $filepath): int
    {
        // High priority: Core classes and main app files
        if (str_contains($filepath, 'usersc/classes/Car.php')) {
            return 5;
        }
        
        if (str_starts_with($filepath, 'app/cars/')) {
            return 4;
        }
        
        if (str_contains($filepath, 'actions/edit.php')) {
            return 4;
        }
        
        // Medium priority: Other app files
        if (str_starts_with($filepath, 'app/') || str_starts_with($filepath, 'usersc/')) {
            return 3;
        }
        
        // Lower priority: Other files
        return 2;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Output summary for debugging
        if (!empty($this->analysisReport)) {
            echo "\n--- Column Naming Analysis Summary ---\n";
            if (isset($this->analysisReport['carid_usage'])) {
                echo "Files using 'carid': " . $this->analysisReport['carid_usage']['file_count'] . "\n";
            }
            if (isset($this->analysisReport['car_id_usage'])) {
                echo "Files using 'car_id': " . $this->analysisReport['car_id_usage']['file_count'] . "\n";
            }
            if (isset($this->analysisReport['mixed_usage'])) {
                echo "Files with mixed usage: " . $this->analysisReport['mixed_usage']['file_count'] . "\n";
            }
        }
    }
}