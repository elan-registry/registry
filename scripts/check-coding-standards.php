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

        // Check for function type declarations
        $this->checkFunctionTypes($filePath, $lines);

        // Check for PHPDoc blocks on public methods
        $this->checkPHPDocBlocks($filePath, $lines);

        // Check for CSRF protection patterns
        $this->checkCSRFProtection($filePath, $content);

        // Check for SQL injection patterns
        $this->checkSQLSecurity($filePath, $content);

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
        // Check for potential SQL injection patterns
        if (preg_match('/\$db\s*->\s*query\s*\(\s*["\'].*\$/', $content)) {
            $this->errors[] = "$filePath: Potential SQL injection - variable concatenation in query";
        }

        if (preg_match('/SELECT.*\$\w+/', $content) && !preg_match('/\$db\s*->\s*query\s*\([^,]+,\s*\[/', $content)) {
            $this->warnings[] = "$filePath: SQL query may not be using prepared statements";
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
        echo "• Add PHPDoc blocks with @param and @return to public methods\n";
        echo "• Use Token::check() for CSRF protection in forms\n";
        echo "• Use prepared statements: \$db->query(\$sql, [\$params])\n\n";
    }
}

// Run the checker
$checker = new CodingStandardsChecker();
exit($checker->run($argv));