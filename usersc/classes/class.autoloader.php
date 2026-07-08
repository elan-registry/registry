<?php
/**
 * PSR-4 Autoloader for Custom Application Classes
 *
 * Supports namespaced (PSR-4) classes via a configurable prefix→directory map,
 * with a recursive-scan fallback for classes not yet at their PSR-4 paths.
 *
 * PSR-4 mappings mirror the composer.json autoload section exactly so
 * both autoloaders resolve identical paths.
 *
 * @package ElanRegistry
 * @since v2.11.0
 * @see Issue #608 - Rewrite class autoloader for PSR-4 namespace support
 */

declare(strict_types=1);

class ElanRegistryAutoloader
{
    /**
     * PSR-4 prefix-to-base-directory mappings.
     *
     * Entries MUST be ordered longest-prefix-first so that more-specific
     * prefixes take precedence over the root ElanRegistry\ prefix.
     * Mirrors the "autoload.psr-4" section of composer.json exactly.
     *
     * @var array<string, string>
     */
    protected static array $namespaceMappings = [
        'ElanRegistry\\Exceptions\\'  => __DIR__ . '/Exceptions/',
        'ElanRegistry\\Reference\\'   => __DIR__ . '/ElanRegistry/Reference/',
        'ElanRegistry\\'              => __DIR__ . '/',
    ];

    /**
     * File extension for PHP class files
     */
    protected static string $fileExt = '.php';

    /**
     * Cached file iterator for recursive scanning (initialized once per request)
     */
    protected static ?RecursiveIteratorIterator $fileIterator = null;

    /**
     * Hybrid autoloader — tries PSR-4 first, then recursive scan.
     *
     * This autoloader runs AFTER UserSpice's autoloader (registered with
     * prepend=false, appending to the SPL queue) so UserSpice handles its own classes first.
     *
     * @param string $className Fully qualified class name
     * @return void
     */
    public static function loader(string $className): void
    {
        if (static::loadNamespaced($className)) {
            return;
        }

        static::loadRecursive($className);
    }

    /**
     * PSR-4 autoloader for namespaced ElanRegistry classes.
     *
     * Iterates prefix mappings in longest-first order, strips the matching
     * prefix, and maps the remainder to a file path under the base directory.
     *
     * Example resolutions:
     *   ElanRegistry\Reference\CarModel     → usersc/classes/ElanRegistry/Reference/CarModel.php
     *   ElanRegistry\Car\CarRepository      → usersc/classes/Car/CarRepository.php
     *   ElanRegistry\Exceptions\CarNotFound → usersc/classes/Exceptions/CarNotFound.php
     *
     * @param string $className Fully qualified class name with namespace
     * @return bool True if the class file was found and loaded
     */
    protected static function loadNamespaced(string $className): bool
    {
        foreach (static::$namespaceMappings as $prefix => $baseDir) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }

            $file = $baseDir .
                    str_replace('\\', '/', substr($className, strlen($prefix))) .
                    static::$fileExt;

            if (file_exists($file) && is_readable($file)) {
                require_once $file;
                return true;
            }
        }

        return false;
    }

    /**
     * Recursive directory scanner for classes not resolved by PSR-4 prefix matching.
     *
     * Searches the entire usersc/classes/ directory tree for a file matching
     * the simple class name. The iterator object is cached to avoid re-construction;
     * foreach still re-walks the directory tree on each call.
     *
     * This path handles classes whose files are not yet at their expected PSR-4
     * locations — both non-namespaced classes (e.g., AppConstants, LogCategories)
     * and namespaced classes at non-standard paths (e.g., DocumentPortalTemplate).
     *
     * @param string $className Simple or fully qualified class name
     * @return void
     */
    protected static function loadRecursive(string $className): void
    {
        if (static::$fileIterator === null) {
            static::$fileIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    __DIR__ . '/',
                    RecursiveDirectoryIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
        }

        $classNameParts = explode('\\', $className);
        $filename       = end($classNameParts) . static::$fileExt;

        foreach (static::$fileIterator as $file) {
            if (strtolower($file->getFilename()) === strtolower($filename)) {
                if (!$file->isReadable()) {
                    continue;
                }
                require_once $file->getPathname();
                break;
            }
        }
    }
}

// Register PSR-4 autoloader with SPL.
// prepend=false: append to queue so UserSpice handles its own classes first.
spl_autoload_register(
    'ElanRegistryAutoloader::loader',
    true,   // throw exceptions on registration error
    false   // append — run after UserSpice autoloader
);
