<?php

declare(strict_types=1);

namespace ElanRegistry;

/**
 * Builds the canonical URL that head_tags.php emits (#1960).
 *
 * The canonical is default-deny: the request query string never reaches it.
 * Only the parameters that a page passes in $query, from values it has
 * validated, go into the URL. See docs/development/CLASSES.md.
 */
final class CanonicalUrl
{
    /**
     * Build a canonical URL from an origin, a script path, and the query
     * parameters that identify the resource.
     *
     * A final `index.php` segment becomes its directory, so `/index.php` and
     * `/` give one canonical. Query values are encoded as RFC 3986, so a
     * space becomes `%20`, the same as the `rawurlencode()` internal links.
     *
     * The method does not validate its arguments. The caller must pass a
     * trusted origin with no trailing slash, for example `$current_origin`,
     * and a script path that starts with `/`, for example `$php_self`.
     *
     * @param string $origin Trusted scheme, host, and optional port, with no trailing slash
     * @param string $scriptPath Script path from the web root that starts with `/`
     * @param array<string, int|string|null> $query Validated parameters to keep. Null values are left out.
     * @return string The canonical URL
     */
    public static function build(string $origin, string $scriptPath, array $query = []): string
    {
        $path = $scriptPath === '' ? '/' : $scriptPath;

        if (str_ends_with($path, '/index.php')) {
            $path = substr($path, 0, -strlen('index.php'));
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $origin . $path . ($queryString === '' ? '' : '?' . $queryString);
    }
}
