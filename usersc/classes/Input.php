<?php
declare(strict_types=1);

namespace ElanRegistry;

/**
 * Project-owned input helper.
 *
 * Supplements the upstream UserSpice \Input class without modifying it.
 * Files that import this class with `use ElanRegistry\Input` can call:
 *   - Input::raw()  — unencoded value for database storage
 *   - Input::get()  — HTML-encoded value (delegates to \Input::get())
 *   - Input::exists() — POST/GET presence check (delegates to \Input::exists())
 *
 * @since v2.23.0
 * @see https://github.com/unibrain1/elanregistry/issues/843
 */
class Input
{
    /**
     * Gets a raw (unencoded) scalar from $_POST or $_GET.
     *
     * POST takes priority over GET. Returns null when the key is absent from
     * both superglobals, when the value is null, or when the value is an array
     * (multi-select inputs are not supported by this method).
     *
     * Use for values that will be stored in the database. Always apply
     * htmlspecialchars() at the HTML output context to prevent XSS.
     *
     * @param string $item The POST/GET key to retrieve.
     * @param bool   $trim Whether to trim leading/trailing whitespace (default true).
     * @return string|null The raw scalar value as a string, or null if not present.
     */
    public static function raw(string $item, bool $trim = true): ?string
    {
        if (isset($_POST[$item])) {
            if (is_array($_POST[$item])) {
                return null;
            }
            $value = (string)$_POST[$item];
        } elseif (isset($_GET[$item])) {
            if (is_array($_GET[$item])) {
                return null;
            }
            $value = (string)$_GET[$item];
        } else {
            return null;
        }
        return $trim ? trim($value) : $value;
    }

    /**
     * Delegates to \Input::get() — returns an HTML-encoded value from $_POST or $_GET.
     *
     * When the key is absent, returns $default_value as-is (not HTML-encoded).
     *
     * @param string $item            The POST/GET key to retrieve.
     * @param mixed  $trim_or_default Boolean to control trimming, or a non-bool default value.
     * @param bool   $fallback        If true, uses htmlentities instead of htmlspecialchars (both use ENT_QUOTES).
     * @param mixed  $default_value   Fallback when the key is absent (used when 2nd param is bool).
     * @return mixed The sanitized value or the default value.
     */
    public static function get(string $item, mixed $trim_or_default = true, bool $fallback = false, mixed $default_value = ''): mixed
    {
        return \Input::get($item, $trim_or_default, $fallback, $default_value);
    }

    /**
     * Delegates to \Input::exists() — checks whether the named superglobal is non-empty.
     *
     * Checks $_POST when $type is 'post', $_GET when $type is 'get'.
     *
     * @param string $type 'post' or 'get' (default 'post').
     * @return bool True when the superglobal is non-empty.
     * @throws \InvalidArgumentException When $type is not 'post' or 'get'.
     */
    public static function exists(string $type = 'post'): bool
    {
        if ($type !== 'post' && $type !== 'get') {
            throw new \InvalidArgumentException(
                "ElanRegistry\\Input::exists() expects 'post' or 'get', got '{$type}'."
            );
        }
        return \Input::exists($type);
    }
}
