<?php
/**
 * PSR-4 Autoloader for Custom Application Classes
 *
 * Loads namespaced (PSR-4) classes via a configurable prefix→directory map.
 *
 * PSR-4 mappings match the composer.json autoload.psr-4 section.
 * Entries must be ordered longest-prefix-first — this constraint is not
 * expressible in composer.json but is enforced here to ensure more-specific
 * prefixes (e.g., ElanRegistry\Admin\) are checked before the catch-all.
 *
 * @package ElanRegistry
 * @since v2.11.0
 * @see Issue #608 - Rewrite class autoloader for PSR-4 namespace support
 * @see Issue #779 - Remove recursive fallback; all classes now at PSR-4 paths
 */

declare(strict_types=1);

class ElanRegistryAutoloader
{
    /**
     * PSR-4 prefix-to-base-directory mappings.
     *
     * Entries MUST be ordered longest-prefix-first so that more-specific
     * prefixes take precedence over the root ElanRegistry\ prefix.
     * Entries match the autoload.psr-4 paths in composer.json, but must
     * maintain longest-prefix-first order — a constraint composer.json cannot enforce.
     *
     * @var array<string, string>
     */
    protected static array $namespaceMappings = [
        'ElanRegistry\\Exceptions\\'  => __DIR__ . '/Exceptions/',
        'ElanRegistry\\Reference\\'   => __DIR__ . '/Reference/',
        'ElanRegistry\\Admin\\'       => __DIR__ . '/admin/',
        'ElanRegistry\\'              => __DIR__ . '/',
    ];

    /**
     * File extension for PHP class files
     */
    protected static string $fileExt = '.php';

    /**
     * PSR-4 autoloader entry point.
     *
     * Runs AFTER UserSpice's autoloader (registered with prepend=false) so
     * UserSpice handles its own classes first.
     *
     * @param string $className Fully qualified class name
     * @return void
     */
    public static function loader(string $className): void
    {
        static::loadNamespaced($className);
    }

    /**
     * PSR-4 autoloader for namespaced ElanRegistry classes.
     *
     * Iterates prefix mappings in longest-first order, strips the matching
     * prefix, and maps the remainder to a file path under the base directory.
     *
     * Example resolutions:
     *   ElanRegistry\Reference\CarModel        → usersc/classes/Reference/CarModel.php
     *   ElanRegistry\Car\CarRepository         → usersc/classes/Car/CarRepository.php
     *   ElanRegistry\Exceptions\CarNotFoundException → usersc/classes/Exceptions/CarNotFoundException.php
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

}

// Register PSR-4 autoloader with SPL.
// prepend=false: append to queue so UserSpice handles its own classes first.
spl_autoload_register(
    'ElanRegistryAutoloader::loader',
    true,   // throw exceptions on registration error
    false   // append — run after UserSpice autoloader
);
