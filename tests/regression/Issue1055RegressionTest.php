<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #1055: updateWebsite() skips FILTER_VALIDATE_URL
 *
 * updateWebsite() in app/api/cars/save.php previously only checked the URL
 * scheme, allowing malformed URLs such as "https://" (valid scheme, empty host)
 * to be stored verbatim. A FILTER_VALIDATE_URL guard was added before the
 * scheme check to match the validation level already present in
 * Owner::validateAndSanitizeFields().
 *
 * These tests verify the validation contract: the same URLs that
 * Owner already rejected must now also be rejected by
 * updateWebsite(). Since updateWebsite() is a procedural function inside a
 * script that requires the full framework, we test the shared PHP validation
 * primitive (filter_var FILTER_VALIDATE_URL) and the scheme-whitelist rule
 * directly. CarValidatorTest covers the identical rules through
 * CarValidator::validateAndSanitizeFields().
 *
 * @issue 1055
 * @link https://github.com/unibrain1/elanregistry/issues/1055
 * @category regression
 */
final class Issue1055RegressionTest extends TestCase
{
    // ----------------------------------------------------------------
    // URLs that FILTER_VALIDATE_URL must reject
    // ----------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function malformedUrlProvider(): array
    {
        return [
            'scheme only — no host'      => ['https://'],
            'http scheme only — no host' => ['http:'],
            'not a url'                  => ['not-a-url'],
            'relative path'              => ['/path/to/page'],
            'domain without scheme'      => ['example.com'],
        ];
    }

    #[DataProvider('malformedUrlProvider')]
    public function testMalformedUrlsFailFilterValidateUrl(string $url): void
    {
        $this->assertFalse(
            (bool) filter_var($url, FILTER_VALIDATE_URL),
            "Expected FILTER_VALIDATE_URL to reject '{$url}' — updateWebsite() now uses this guard"
        );
    }

    // ----------------------------------------------------------------
    // Valid URLs must still be accepted (no regression)
    // ----------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function validUrlProvider(): array
    {
        return [
            'https with path'  => ['https://example.com/cars'],
            'http with port'   => ['http://example.com:8080/'],
            'with query string' => ['https://example.com/cars?a=1&b=2'],
            'www prefix'       => ['https://www.elanregistry.org'],
        ];
    }

    #[DataProvider('validUrlProvider')]
    public function testValidUrlsPassFilterValidateUrl(string $url): void
    {
        $this->assertNotFalse(
            filter_var($url, FILTER_VALIDATE_URL),
            "Expected FILTER_VALIDATE_URL to accept '{$url}'"
        );
    }

    // ----------------------------------------------------------------
    // Scheme whitelist — valid URL but non-http(s) scheme must be
    // blocked by the second guard in updateWebsite()
    // ----------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function nonHttpSchemeProvider(): array
    {
        return [
            'javascript protocol' => ['javascript:void(0)'],
            'ftp url'             => ['ftp://files.example.com'],
            'data uri'            => ['data:text/html,<h1>x</h1>'],
        ];
    }

    #[DataProvider('nonHttpSchemeProvider')]
    public function testNonHttpSchemeIsBlockedBySchemeWhitelist(string $url): void
    {
        $isValidUrl = (bool) filter_var($url, FILTER_VALIDATE_URL);
        $scheme     = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $schemeOk   = in_array($scheme, ['http', 'https'], true);

        // At least one of the two guards must reject the URL
        $this->assertFalse(
            $isValidUrl && $schemeOk,
            "URL '{$url}' must be rejected by either FILTER_VALIDATE_URL or the scheme whitelist"
        );
    }
}
