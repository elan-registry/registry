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
 *   - Input::existsPost() / Input::existsGet() — POST/GET presence checks
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
     * The second parameter is a trim flag, NOT a default value. To supply a
     * default, use `Input::raw('field') ?? 'fallback'`.
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
     * Checks whether $_POST is non-empty, or whether a specific key is present in $_POST.
     *
     * With no argument: delegates to \Input::exists('post') — true when $_POST is non-empty.
     * With a key: returns isset($_POST[$key]).
     *
     * @param string $key Optional key to check for. When empty, checks the superglobal itself.
     * @return bool True when $_POST is non-empty (no key) or contains $key.
     */
    public static function existsPost(string $key = ''): bool
    {
        return $key !== '' ? isset($_POST[$key]) : \Input::exists('post');
    }

    /**
     * Checks whether $_GET is non-empty, or whether a specific key is present in $_GET.
     *
     * With no argument: delegates to \Input::exists('get') — true when $_GET is non-empty.
     * With a key: returns isset($_GET[$key]).
     *
     * @param string $key Optional key to check for. When empty, checks the superglobal itself.
     * @return bool True when $_GET is non-empty (no key) or contains $key.
     */
    public static function existsGet(string $key = ''): bool
    {
        return $key !== '' ? isset($_GET[$key]) : \Input::exists('get');
    }
}
