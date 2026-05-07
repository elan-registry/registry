<?php
declare(strict_types=1);

namespace ElanRegistry;

/**
 * Project-owned input helper.
 *
 * Supplements the upstream UserSpice Input class without modifying it.
 * Use Input::raw() (with `use ElanRegistry\Input;`) for all values destined
 * for the database. Use the global \Input::get() only where the returned value
 * is rendered directly into HTML without further escaping (legacy pattern; avoid
 * in new code — it encodes at read time, not at output time).
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
}
