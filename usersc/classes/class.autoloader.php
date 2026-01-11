<?php
/**
 * Hybrid Autoloader for Custom Application Classes
 *
 * Supports both namespaced (PSR-4) and non-namespaced (recursive scan) classes.
 * This provides a clean migration path when adding namespaces to existing classes.
 *
 * - PSR-4 for namespaced classes (fast, direct path calculation)
 * - Recursive iterator for non-namespaced classes (backward compatibility)
 *
 * @package ElanRegistry
 * @since v2.11.0
 * @see Issue #407 - Architecture: Introduce namespaces for custom classes
 */

declare(strict_types=1);

class UserspiceCustomAutoloader
{
    /**
     * Project namespace prefix for PSR-4 autoloading
     */
    protected static string $namespacePrefix = 'ElanRegistry\\';

    /**
     * Base directory for namespace (usersc/classes/)
     */
    protected static string $baseDir = __DIR__ . '/';

    /**
     * File extension for PHP class files
     */
    protected static string $fileExt = '.php';

    /**
     * Cached file iterator for recursive scanning (initialized once per request)
     */
    protected static ?RecursiveIteratorIterator $fileIterator = null;

    /**
     * Hybrid autoloader - tries PSR-4 first, then recursive scan
     *
     * This dual approach provides:
     * - Fast loading for namespaced classes (MarkdownParser, DocumentConfig)
     * - Backward compatibility for non-namespaced classes (Car, ElanRegistryOwner)
     * - Zero-change migration path when adding namespaces to existing classes
     *
     * @param string $className The name of the class to load
     * @return void
     */
    public static function loader(string $className): void
    {
        // This autoloader runs AFTER UserSpice autoloader (registered with append, not prepend)
        // So UserSpice handles its own classes first, and we only handle custom classes

        // Try PSR-4 for namespaced classes first (fast path)
        if (static::loadNamespaced($className)) {
            return;
        }

        // Fall back to recursive scan for non-namespaced custom classes
        static::loadRecursive($className);
    }

    /**
     * PSR-4 autoloader for namespaced classes
     *
     * Handles classes in ElanRegistry namespace:
     * - ElanRegistry\Car
     * - ElanRegistry\Exceptions\CarNotFoundException
     * - ElanRegistry\Documentation\MarkdownParser
     *
     * Direct path calculation provides optimal performance.
     *
     * @param string $className Fully qualified class name with namespace
     * @return bool True if class was loaded successfully
     */
    protected static function loadNamespaced(string $className): bool
    {
        $prefix = static::$namespacePrefix;
        $len = strlen($prefix);

        // Does the class use our namespace prefix?
        if (strncmp($prefix, $className, $len) !== 0) {
            return false;
        }

        // Get relative class name (strip namespace prefix)
        $relativeClass = substr($className, $len);

        // Convert namespace separators to directory separators
        // Example: Exceptions\CarNotFoundException -> Exceptions/CarNotFoundException.php
        $file = static::$baseDir .
                str_replace('\\', '/', $relativeClass) .
                static::$fileExt;

        // If file exists, load it
        if (file_exists($file) && is_readable($file)) {
            require_once $file;
            return true;
        }

        return false;
    }

    /**
     * Recursive directory scanner for non-namespaced classes
     *
     * Searches entire usersc/classes/ directory tree for matching filename.
     * Used for backward compatibility with existing non-namespaced classes.
     *
     * Iterator is cached per request for performance (directory tree
     * scanned only once).
     *
     * @param string $className Simple class name without namespace
     * @return void
     */
    protected static function loadRecursive(string $className): void
    {
        // Initialize iterator on first use (cached for subsequent loads)
        if (is_null(static::$fileIterator)) {
            static::$fileIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    static::$baseDir,
                    RecursiveDirectoryIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        }

        // Extract just the class name from fully qualified name
        // e.g., "ElanRegistry\Documentation\MarkdownParser" -> "MarkdownParser"
        $classNameParts = explode('\\', $className);
        $simpleClassName = end($classNameParts);
        $filename = $simpleClassName . static::$fileExt;

        // Search for class file (case-insensitive for compatibility)
        foreach (static::$fileIterator as $file) {
            if (strtolower($file->getFilename()) === strtolower($filename)) {
                if ($file->isReadable()) {
                    require_once $file->getPathname();
                }
                break;
            }
        }
    }
}

// Register hybrid autoloader with SPL
// - throw=true: Throw exceptions on registration error
// - prepend=false: Add to end of autoloader queue (let UserSpice handle its classes first)
spl_autoload_register(
    'UserspiceCustomAutoloader::loader',
    true,   // throw exceptions on error
    false   // append to autoloader queue (not prepend)
);
