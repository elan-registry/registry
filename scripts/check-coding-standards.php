<?php

declare(strict_types=1);

/**
 * Coding Standards Checker
 *
 * Local script to check for common coding standard violations before creating PRs.
 * Helps prevent blocking issues in Claude Code Review and other automated checks.
 *
 * Usage: php scripts/check-coding-standards.php [directory]
 *
 * @author Elan Registry Development Team
 */

class CodingStandardsChecker
{
    private array $errors = [];
    private array $warnings = [];
    private int $filesChecked = 0;

    /**
     * Main execution method
     *
     * @param array $args Command line arguments
     * @return int Exit code (0 = success, 1 = issues found)
     */
    public function run(array $args): int
    {
        $directory = $args[1] ?? '.';
        $isStaged = in_array('--staged', $args);

        if ($isStaged) {
            echo "🔍 Checking coding standards for staged files in: $directory\n";
        } else {
            echo "🔍 Checking coding standards in: $directory\n";
        }
        echo "=" . str_repeat("=", 50) . "\n\n";

        $this->checkDirectory($directory);

        $this->printResults($isStaged);

        return count($this->errors) > 0 ? 1 : 0;
    }

    /**
     * Check all PHP files in directory recursively
     *
     * @param string $directory Directory to check
     * @return void
     */
    private function checkDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Skip vendor and node_modules
                $path = $file->getPathname();
                if (strpos($path, '/vendor/') !== false ||
                    strpos($path, '/node_modules/') !== false ||
                    strpos($path, '/.git/') !== false) {
                    continue;
                }

                $this->checkFile($path);
            }
        }
    }

    /**
     * Check individual PHP file for coding standards
     *
     * @param string $filePath Path to PHP file
     * @return void
     */
    private function checkFile(string $filePath): void
    {
        $this->filesChecked++;
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        // Check for strict types declaration
        $this->checkStrictTypes($filePath, $content);

        // Check for database type casting in strict mode
        $this->checkDatabaseTypeCasting($filePath, $content, $lines);

        // Check for function type declarations
        $this->checkFunctionTypes($filePath, $lines);

        // Check for PHPDoc blocks on public methods
        $this->checkPHPDocBlocks($filePath, $lines);

        // Enhanced security checks
        $this->checkCSRFProtection($filePath, $content);
        $this->checkSQLSecurity($filePath, $content);
        $this->checkInputValidation($filePath, $content);

        // Architecture checks
        $this->checkExceptionHandling($filePath, $content);
        $this->checkErrorHandling($filePath, $lines);

        // Performance checks
        $this->checkNPlusOneQueries($filePath, $content);
        $this->checkCachingOpportunities($filePath, $content);

        // Enhanced documentation checks
        $this->checkPHPDocCompleteness($filePath, $lines);

        // Regression test validation
        $this->checkRegressionTestStructure($filePath, $content, $lines);

        echo ".";
        if ($this->filesChecked % 50 === 0) {
            echo " $this->filesChecked\n";
        }
    }

    /**
     * Check for strict types declaration
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @return void
     */
    private function checkStrictTypes(string $filePath, string $content): void
    {
        // Skip if it's not a new file or if it's a template/include file
        if (strpos($content, 'declare(strict_types=1)') === false) {
            // Only flag as error if it contains classes or functions (not just includes)
            if (preg_match('/\b(class|function)\s+\w+/', $content)) {
                $this->errors[] = "$filePath: Missing declare(strict_types=1) declaration";
            }
        }
    }

    /**
     * Check for proper type casting of database values in strict mode
     *
     * Detects database values (especially integer IDs) being passed to strict-typed
     * function parameters without explicit type casting, which can cause TypeError
     * when database returns strings instead of integers.
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @param array $lines File lines
     * @return void
     */
    private function checkDatabaseTypeCasting(string $filePath, string $content, array $lines): void
    {
        // Only check files with strict_types=1
        if (strpos($content, 'declare(strict_types=1)') === false) {
            return;
        }

        foreach ($lines as $lineNum => $line) {
            $lineNumber = $lineNum + 1;

            // Pattern 1: Direct database object property access to strict function
            // Example: new ClassName(..., $user->data()->id, ...)
            // Example: someFunction(..., $row->id, ...)
            if (preg_match('/new\s+\w+\([^)]*\$\w+->(data\(\)->)?id[,\)]/', $line)) {
                if (!preg_match('/\(int\)\s*\$\w+->(data\(\)->)?id/', $line)) {
                    $this->warnings[] = "$filePath:$lineNumber: Database ID value may need explicit (int) cast in strict mode";
                }
            }

            // Pattern 2: Function calls with database object properties
            // Example: functionName($obj->user_id)
            if (preg_match('/\w+\([^)]*\$\w+->\w+_id[,\)]/', $line)) {
                if (!preg_match('/\(int\)\s*\$\w+->\w+_id/', $line)) {
                    $this->warnings[] = "$filePath:$lineNumber: Database ID field may need explicit (int) cast in strict mode";
                }
            }

            // Pattern 3: Database result->first() properties
            // Example: $result->first()->id
            if (preg_match('/\$\w+->first\(\)->\w+/', $line)) {
                if (!preg_match('/\(int\)/', $line) && !preg_match('/\(float\)/', $line) && !preg_match('/\(bool\)/', $line)) {
                    $this->warnings[] = "$filePath:$lineNumber: Database query result may need explicit type cast in strict mode (int/float/bool)";
                }
            }
        }
    }

    /**
     * Check function and method type declarations
     *
     * @param string $filePath File path for error reporting
     * @param array $lines File lines
     * @return void
     */
    private function checkFunctionTypes(string $filePath, array $lines): void
    {
        foreach ($lines as $lineNum => $line) {
            $lineNumber = $lineNum + 1;

            // Check for functions without return types
            if (preg_match('/\b(public|private|protected|function)\s+function\s+(\w+)\s*\([^)]*\)\s*\{/', $line)) {
                if (!preg_match('/:\s*(void|int|string|bool|array|object|float|\w+(\|\w+)*)\s*\{/', $line)) {
                    $this->errors[] = "$filePath:$lineNumber: Function missing return type declaration";
                }
            }

            // Check for function parameters without types
            if (preg_match('/function\s+\w+\s*\([^)]*\$\w+(?!\s*:)/', $line)) {
                if (!preg_match('/\$\w+\s*=\s*/', $line)) { // Skip default parameters
                    $this->warnings[] = "$filePath:$lineNumber: Function parameter may be missing type declaration";
                }
            }
        }
    }

    /**
     * Check for PHPDoc blocks on public methods
     *
     * @param string $filePath File path for error reporting
     * @param array $lines File lines
     * @return void
     */
    private function checkPHPDocBlocks(string $filePath, array $lines): void
    {
        $inClass = false;
        $lastDocBlockEnd = -10; // Track where last docblock ended

        foreach ($lines as $lineNum => $line) {
            $lineNumber = $lineNum + 1;

            if (preg_match('/^class\s+\w+/', trim($line))) {
                $inClass = true;
                continue;
            }

            // Track DocBlock end
            if (preg_match('/^\s*\*\//', $line)) {
                $lastDocBlockEnd = $lineNum;
                continue;
            }

            if ($inClass && preg_match('/^\s*public\s+function\s+(\w+)/', $line, $matches)) {
                $functionName = $matches[1];

                // Check if there's a recent DocBlock (within 5 lines, accounting for blank lines)
                $hasRecentDocBlock = ($lineNum - $lastDocBlockEnd) <= 5;

                if (!$hasRecentDocBlock && $functionName !== '__construct') {
                    $this->errors[] = "$filePath:$lineNumber: Public method '$functionName' missing PHPDoc block";
                }
            }
        }
    }

    /**
     * Check for CSRF protection in forms
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @return void
     */
    private function checkCSRFProtection(string $filePath, string $content): void
    {
        // Check if file contains form processing but no CSRF validation
        if (preg_match('/\$_POST\[/', $content) &&
            !preg_match('/Token::check\(/', $content) &&
            strpos($content, 'CSRF') === false) {
            $this->warnings[] = "$filePath: Form processing detected but no CSRF validation found";
        }
    }

    /**
     * Check for potential SQL injection vulnerabilities
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @return void
     */
    private function checkSQLSecurity(string $filePath, string $content): void
    {
        // Check for potential SQL injection patterns - variable concatenation inside query string
        // This detects variables embedded directly in query strings (unsafe)
        // versus using prepared statements with placeholders (safe)
        if (preg_match('/\$db\s*->\s*query\s*\(\s*["\'][^"\']*\$[^"\']*["\']/', $content)) {
            $this->errors[] = "$filePath: Potential SQL injection - variable concatenation in query";
        }

        if (preg_match('/SELECT.*\$\w+/', $content) && !preg_match('/\$db\s*->\s*query\s*\([^,]+,\s*\[/', $content)) {
            $this->warnings[] = "$filePath: SQL query may not be using prepared statements";
        }
    }

    /**
     * Check for input validation and sanitization
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @return void
     */
    private function checkInputValidation(string $filePath, string $content): void
    {
        // Check for direct use of superglobals without validation
        $superglobals = ['$_POST', '$_GET', '$_REQUEST', '$_COOKIE', '$_SESSION', '$_FILES'];

        foreach ($superglobals as $global) {
            if (preg_match('/' . preg_quote($global, '/') . '\[/', $content)) {
                // Check if there's any validation nearby
                if (!preg_match('/filter_var|htmlspecialchars|strip_tags|is_numeric|is_string|empty\(|isset\(/', $content)) {
                    $this->warnings[] = "$filePath: Direct use of $global detected - ensure proper validation and sanitization";
                }
            }
        }

        // Check for direct output of user input (XSS vulnerability)
        if (preg_match('/echo\s+.*\$_(GET|POST|REQUEST)\[/', $content)) {
            $this->errors[] = "$filePath: Direct output of user input - XSS vulnerability, use htmlspecialchars()";
        }

        // Check for file upload without validation
        if (preg_match('/move_uploaded_file\(.*\$_FILES/', $content) &&
            !preg_match('/\.(jpg|jpeg|png|gif)\$.*i/', $content)) {
            $this->warnings[] = "$filePath: File upload detected - ensure proper file type and size validation";
        }

        // Check for email operations without validation
        if (preg_match('/mail\(.*\$_(GET|POST)/', $content)) {
            $this->errors[] = "$filePath: Email function with user input - validate email addresses and sanitize content";
        }
    }

    /**
     * Check for proper exception handling
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @return void
     */
    private function checkExceptionHandling(string $filePath, string $content): void
    {
        // Check for generic Exception throws
        if (preg_match('/throw\s+new\s+Exception\(/', $content)) {
            $this->errors[] = "$filePath: Using generic Exception - create specific exception classes instead";
        }

        if (preg_match('/throw\s+new\s+RuntimeException\(/', $content)) {
            $this->warnings[] = "$filePath: Using RuntimeException - consider more specific exception types";
        }

        // Check for Exception in catch blocks (too generic)
        if (preg_match('/catch\s*\(\s*Exception\s+/', $content)) {
            $this->warnings[] = "$filePath: Catching generic Exception - catch specific exception types when possible";
        }
    }

    /**
     * Check for proper error handling in risky operations
     *
     * @param string $filePath File path for error reporting
     * @param array $lines File lines
     * @return void
     */
    private function checkErrorHandling(string $filePath, array $lines): void
    {
        foreach ($lines as $lineNum => $line) {
            $lineNumber = $lineNum + 1;

            // Check for database operations without try-catch
            if (preg_match('/\$\w+\s*->\s*(prepare|execute|query)\(/', $line)) {
                // Look for try-catch in surrounding lines (within 10 lines)
                $hasTryCatch = false;
                for ($i = max(0, $lineNum - 10); $i < min(count($lines), $lineNum + 10); $i++) {
                    if (preg_match('/try\s*\{|catch\s*\(/', $lines[$i])) {
                        $hasTryCatch = true;
                        break;
                    }
                }
                if (!$hasTryCatch) {
                    $this->warnings[] = "$filePath:$lineNumber: Database operation should be wrapped in try-catch block";
                }
            }

            // Check for file operations without error handling
            if (preg_match('/(file_get_contents|file_put_contents|fopen|fwrite|move_uploaded_file)\(/', $line)) {
                $hasTryCatch = false;
                for ($i = max(0, $lineNum - 5); $i < min(count($lines), $lineNum + 5); $i++) {
                    if (preg_match('/try\s*\{|catch\s*\(/', $lines[$i])) {
                        $hasTryCatch = true;
                        break;
                    }
                }
                if (!$hasTryCatch) {
                    $this->warnings[] = "$filePath:$lineNumber: File operation should include error handling";
                }
            }

            // Check for JSON operations without error handling
            if (preg_match('/json_decode\(/', $line)) {
                $hasErrorCheck = false;
                for ($i = $lineNum; $i < min(count($lines), $lineNum + 3); $i++) {
                    if (preg_match('/json_last_error|JSON_ERROR/', $lines[$i])) {
                        $hasErrorCheck = true;
                        break;
                    }
                }
                if (!$hasErrorCheck) {
                    $this->warnings[] = "$filePath:$lineNumber: JSON decode should check for errors with json_last_error()";
                }
            }
        }
    }

    /**
     * Check for N+1 query patterns
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @return void
     */
    private function checkNPlusOneQueries(string $filePath, string $content): void
    {
        // Check for database queries inside foreach loops
        if (preg_match('/foreach\s*\([^}]*\{[^}]*\$\w+\s*->\s*query\(/s', $content)) {
            $this->warnings[] = "$filePath: Potential N+1 query pattern - database query inside foreach loop";
        }

        // Check for multiple individual queries that could be combined
        $queryCount = preg_match_all('/\$\w+\s*->\s*query\(/', $content);
        if ($queryCount > 5) {
            $this->warnings[] = "$filePath: High number of database queries ($queryCount) - consider optimizing with JOINs or batch operations";
        }

        // Check for individual existence checks in loops
        if (preg_match('/foreach.*SELECT.*WHERE.*=.*\$/s', $content)) {
            $this->warnings[] = "$filePath: Individual record lookups in loop - consider using IN clause or JOINs";
        }
    }

    /**
     * Check for missing caching opportunities
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @return void
     */
    private function checkCachingOpportunities(string $filePath, string $content): void
    {
        // Check for expensive operations that should be cached
        $expensiveOperations = [
            '/file_get_contents\(.*http/' => 'API calls should be cached',
            '/scandir\(|glob\(/' => 'Directory scans should be cached',
            '/getimagesize\(|filesize\(/' => 'File system operations should be cached for frequently accessed files',
            '/COUNT\(\*\)|SUM\(|AVG\(|GROUP BY/i' => 'Database aggregations should be cached',
            '/json_decode\(.*file_get_contents/' => 'External data fetching should be cached'
        ];

        foreach ($expensiveOperations as $pattern => $message) {
            if (preg_match($pattern, $content)) {
                $this->warnings[] = "$filePath: $message";
            }
        }

        // Check for repeated complex calculations
        if (preg_match_all('/\$\w+\s*=\s*[^;]*[\+\-\*\/][^;]*[\+\-\*\/]/', $content) > 3) {
            $this->warnings[] = "$filePath: Multiple complex calculations detected - consider caching results";
        }
    }

    /**
     * Check for complete PHPDoc documentation
     *
     * @param string $filePath File path for error reporting
     * @param array $lines File lines
     * @return void
     */
    private function checkPHPDocCompleteness(string $filePath, array $lines): void
    {
        $inDocBlock = false;
        $docBlockContent = '';
        $nextFunction = '';

        foreach ($lines as $lineNum => $line) {
            $lineNumber = $lineNum + 1;

            // Track DocBlock content
            if (preg_match('/\/\*\*/', $line)) {
                $inDocBlock = true;
                $docBlockContent = '';
                continue;
            }

            if ($inDocBlock) {
                $docBlockContent .= $line . "\n";

                if (preg_match('/\*\//', $line)) {
                    $inDocBlock = false;

                    // Check the next non-empty line for function
                    for ($i = $lineNum + 1; $i < count($lines); $i++) {
                        if (trim($lines[$i]) !== '') {
                            if (preg_match('/public\s+function\s+(\w+)\s*\(([^)]*)\).*:\s*(\S+)/', $lines[$i], $matches)) {
                                $functionName = $matches[1];
                                $params = $matches[2];
                                $returnType = $matches[3];

                                // Check for missing @param tags
                                $paramCount = preg_match_all('/\$\w+/', $params);
                                $docParamCount = preg_match_all('/@param/', $docBlockContent);

                                if ($paramCount > 0 && $docParamCount < $paramCount) {
                                    $this->errors[] = "$filePath:$lineNumber: PHPDoc missing @param tags for function '$functionName'";
                                }

                                // Check for missing @return tag
                                if ($returnType !== 'void' && !preg_match('/@return/', $docBlockContent)) {
                                    $this->errors[] = "$filePath:$lineNumber: PHPDoc missing @return tag for function '$functionName'";
                                }

                                // Check for @throws when exceptions are thrown
                                if (preg_match('/throw\s+new/', $lines[$i + 1] ?? '')) {
                                    if (!preg_match('/@throws/', $docBlockContent)) {
                                        $this->warnings[] = "$filePath:$lineNumber: PHPDoc missing @throws tag for function '$functionName' that throws exceptions";
                                    }
                                }
                            }
                            break;
                        }
                    }

                    $docBlockContent = '';
                }
            }
        }
    }

    /**
     * Check regression test structure and issue linking
     *
     * @param string $filePath File path for error reporting
     * @param string $content File content
     * @param array $lines File lines
     * @return void
     */
    private function checkRegressionTestStructure(string $filePath, string $content, array $lines): void
    {
        // Only check files in tests/regression/ directory
        if (strpos($filePath, 'tests/regression/') === false) {
            return;
        }

        // Skip template files
        if (strpos($filePath, 'Template.php') !== false || strpos($filePath, 'README.md') !== false) {
            return;
        }

        $filename = basename($filePath, '.php');

        // Check filename pattern: Issue{Number}RegressionTest
        if (!preg_match('/^Issue(\d+)RegressionTest$/', $filename, $matches)) {
            $this->errors[] = "$filePath: Regression test filename must follow pattern Issue{Number}RegressionTest.php";
            return;
        }

        $issueNumber = $matches[1];

        // Check for required annotations
        if (!preg_match('/@issue\s+' . preg_quote($issueNumber, '/') . '\b/', $content)) {
            $this->errors[] = "$filePath: Missing @issue annotation. Add: @issue $issueNumber";
        }

        if (!preg_match('/@link\s+.*github\.com.*issues\/' . preg_quote($issueNumber, '/') . '\b/', $content)) {
            $this->errors[] = "$filePath: Missing @link annotation. Add: @link https://github.com/unibrain1/elanregistry/issues/$issueNumber";
        }

        // Check for class name consistency
        if (!preg_match('/class\s+Issue' . preg_quote($issueNumber, '/') . 'RegressionTest/', $content)) {
            $this->errors[] = "$filePath: Class name must match filename: Issue{$issueNumber}RegressionTest";
        }

        // Check for test method naming pattern (warning only)
        if (!preg_match('/testIssue' . preg_quote($issueNumber, '/') . '_/', $content)) {
            $this->warnings[] = "$filePath: Consider using test method pattern: testIssue{$issueNumber}_SpecificBehavior()";
        }

        // Check for issue description in PHPDoc
        if (!preg_match('/\*\s*(Description|GitHub Issue):\s*.*\b' . preg_quote($issueNumber, '/') . '\b/', $content)) {
            $this->warnings[] = "$filePath: Consider adding issue description in PHPDoc comment";
        }

        // Check that test extends TestCase
        if (!preg_match('/extends\s+TestCase/', $content)) {
            $this->errors[] = "$filePath: Regression test must extend PHPUnit\\Framework\\TestCase";
        }

        // Check for proper namespace/use statements
        if (!preg_match('/use\s+PHPUnit\\\Framework\\\TestCase/', $content)) {
            $this->warnings[] = "$filePath: Consider using explicit PHPUnit\\Framework\\TestCase import";
        }
    }

    /**
     * Print results summary
     *
     * @param bool $isStaged Whether this is for staged files
     * @return void
     */
    private function printResults(bool $isStaged = false): void
    {
        echo "\n\n";
        echo "=" . str_repeat("=", 60) . "\n";
        echo "📊 CODING STANDARDS CHECK RESULTS\n";
        echo "=" . str_repeat("=", 60) . "\n";
        echo "Files checked: $this->filesChecked\n";
        echo "Errors: " . count($this->errors) . "\n";
        echo "Warnings: " . count($this->warnings) . "\n\n";

        if (count($this->errors) > 0) {
            echo "❌ BLOCKING ISSUES (must fix before PR):\n";
            echo "-" . str_repeat("-", 58) . "\n";
            foreach ($this->errors as $error) {
                echo "• $error\n";
            }
            echo "\n";
        }

        if (count($this->warnings) > 0) {
            echo "⚠️  WARNINGS (should consider fixing):\n";
            echo "-" . str_repeat("-", 58) . "\n";
            foreach ($this->warnings as $warning) {
                echo "• $warning\n";
            }
            echo "\n";
        }

        if (count($this->errors) === 0 && count($this->warnings) === 0) {
            echo "✅ All coding standards checks passed!\n";
            echo "🚀 Ready for PR creation!\n\n";
        } else {
            echo "💡 Fix the blocking issues above before creating your PR.\n";
            echo "📖 See docs/development/CODING_STANDARDS.md for details.\n\n";
        }

        echo "🔧 Quick fixes:\n";
        echo "• Add declare(strict_types=1); after <?php in new files\n";
        echo "• Add return types to all functions: function name(): returnType\n";
        echo "• Add parameter types: function name(Type \$param): returnType\n";
        echo "• Add PHPDoc blocks with @param, @return, @throws to public methods\n";
        echo "• Use Token::check() for CSRF protection in forms\n";
        echo "• Use prepared statements: \$db->query(\$sql, [\$params])\n";
        echo "• Validate user input with filter_var(), htmlspecialchars(), etc.\n";
        echo "• Use specific exception types instead of generic Exception\n";
        echo "• Wrap risky operations (DB, file, JSON) in try-catch blocks\n";
        echo "• Optimize N+1 queries with JOINs or batch operations\n";
        echo "• Cache expensive operations (API calls, aggregations, file scans)\n";
        echo "• For regression tests: Add @issue and @link annotations\n";
        echo "• Use regression test template: tests/regression/RegressionTestTemplate.php\n\n";
    }
}

// Run the checker
$checker = new CodingStandardsChecker();
exit($checker->run($argv));