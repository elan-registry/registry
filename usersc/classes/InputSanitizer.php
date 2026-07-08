<?php
declare(strict_types=1);

namespace ElanRegistry;

/**
 * String input normalization utility shared across validation logic.
 *
 * Trims whitespace and enforces a maximum length without modifying or
 * escaping content — HTML entities and special characters are preserved.
 * Callers MUST apply htmlspecialchars() at the render layer (encode-at-output).
 *
 * @see https://github.com/unibrain1/elanregistry/issues/941 for encode-at-output rationale
 */
class InputSanitizer
{
    private function __construct() {}

    /**
     * @param string $input     Raw input string
     * @param int    $maxLength Maximum allowed length (default 255)
     * @return string Trimmed, length-capped string — raw, not HTML-encoded
     */
    public static function normalize(string $input, int $maxLength = 255): string
    {
        $normalized = trim($input);
        return mb_strlen($normalized) > $maxLength ? mb_substr($normalized, 0, $maxLength) : $normalized;
    }
}
