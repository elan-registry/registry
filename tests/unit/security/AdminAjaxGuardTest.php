<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-level pins for the admin AJAX endpoint guards in app/admin/includes/.
 *
 * The endpoint files call ApiResponse::send() (which exits) and require the
 * full users/init.php bootstrap, so they cannot be executed inside PHPUnit.
 * These tests instead pin, against the live source:
 *
 *  1. every process-*.php / load-*.php endpoint is guarded — discovered by
 *     glob, so a new endpoint is covered the moment it lands. The originally
 *     pinned endpoints must call requireAdminAjax(); others may instead use
 *     securePage() plus their own Token::check(); and
 *  2. requireAdminAjax() itself (usersc/includes/custom_functions.php) still
 *     performs the admin-role check and the CSRF Token::check().
 *
 * Matching is token-based: comments are ignored (a commented-out guard does
 * not count) and only real function-call syntax matches, not the name inside
 * a string literal. The pin proves the call is present, not that it runs
 * first or unconditionally.
 *
 * HTTP-level rejection is exercised only for process-user-details.php
 * (tests/playwright/ajax-endpoints.spec.js and
 * tests/playwright/e2e/ajax-endpoints-non-admin.spec.js).
 *
 * @see usersc/includes/custom_functions.php requireAdminAjax()
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
final class AdminAjaxGuardTest extends TestCase
{
    private const ENDPOINT_DIR = 'app/admin/includes';

    /**
     * The endpoints this pin was originally written against. The glob must
     * find at least these, so a broken glob pattern cannot pass vacuously.
     */
    private const KNOWN_ENDPOINTS = [
        'process-owner-search.php',
        'process-owner-update.php',
        'process-owner-sync-location.php',
        'load-owner-info.php',
        'load-owner-profile.php',
        'process-car-details.php',
        'process-transfer-approve.php',
        'process-transfer-deny.php',
        'process-user-details.php',
    ];

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Glob app/admin/includes/{process,load}-*.php. Two globs rather than
     * GLOB_BRACE, which is unavailable on some libc builds.
     *
     * @return array<string, array{string}> endpoint basename => [relative path]
     */
    public static function adminEndpointProvider(): array
    {
        $dir = self::projectRoot() . '/' . self::ENDPOINT_DIR;
        $files = array_merge(
            glob($dir . '/process-*.php') ?: [],
            glob($dir . '/load-*.php') ?: []
        );
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $name = basename($file);
            $cases[$name] = [self::ENDPOINT_DIR . '/' . $name];
        }

        return $cases;
    }

    /**
     * Guard against a glob mistake silently shrinking the data provider.
     */
    public function testProviderDiscoversAllKnownEndpoints(): void
    {
        $discovered = array_keys(self::adminEndpointProvider());

        foreach (self::KNOWN_ENDPOINTS as $endpoint) {
            $this->assertContains(
                $endpoint,
                $discovered,
                "Glob of app/admin/includes/{process,load}-*.php must discover {$endpoint}"
            );
        }
    }

    /**
     * Every admin AJAX endpoint must be guarded.
     *
     * The KNOWN_ENDPOINTS rely on requireAdminAjax() for auth, admin role,
     * CSRF and rate limiting, so they must call it specifically — swapping
     * it for securePage() would silently drop the CSRF check. Any other
     * endpoint may use requireAdminAjax(), or securePage() together with its
     * own Token::check() call, since securePage() does not check CSRF.
     */
    #[DataProvider('adminEndpointProvider')]
    public function testEndpointCallsAdminGuard(string $relativePath): void
    {
        $source = file_get_contents(self::projectRoot() . '/' . $relativePath);
        $this->assertIsString($source, "{$relativePath} must be readable");

        $tokens = self::significantTokens($source);

        if (in_array(basename($relativePath), self::KNOWN_ENDPOINTS, true)) {
            $this->assertTrue(
                self::tokensCallFunction($tokens, 'requireAdminAjax'),
                "{$relativePath} must call requireAdminAjax() (comments are ignored)"
            );
            return;
        }

        $guarded = self::tokensCallFunction($tokens, 'requireAdminAjax')
            || (self::tokensCallFunction($tokens, 'securePage')
                && self::tokensCallStaticMethod($tokens, 'Token', 'check'));

        $this->assertTrue(
            $guarded,
            "{$relativePath} must call requireAdminAjax(), or securePage() plus Token::check() (comments are ignored)"
        );
    }

    /**
     * requireAdminAjax() must contain both the admin-role check and the CSRF
     * check. Matching is scoped to the function body, so the same calls
     * elsewhere in custom_functions.php cannot satisfy it.
     */
    public function testRequireAdminAjaxContainsRoleAndCsrfChecks(): void
    {
        $source = file_get_contents(self::projectRoot() . '/usersc/includes/custom_functions.php');
        $this->assertIsString($source, 'usersc/includes/custom_functions.php must be readable');

        $body = self::extractFunctionBodyTokens($source, 'requireAdminAjax');
        $this->assertNotNull($body, 'requireAdminAjax() must be defined in usersc/includes/custom_functions.php');

        $this->assertTrue(
            self::tokensCallFunction($body, 'isRegistryAdmin'),
            'requireAdminAjax() must call isRegistryAdmin() for the admin-role check'
        );
        $this->assertTrue(
            self::tokensCallStaticMethod($body, 'Token', 'check'),
            'requireAdminAjax() must call Token::check() for CSRF validation'
        );
    }

    /**
     * Return the significant tokens of the body of function $name (between
     * its outer braces), or null if the function is not declared in $source.
     *
     * @return list<array{int, string, int}|string>|null
     */
    public static function extractFunctionBodyTokens(string $source, string $name): ?array
    {
        $tokens = self::significantTokens($source);
        $count = count($tokens);

        for ($i = 0; $i < $count - 1; $i++) {
            if (!self::isToken($tokens[$i], T_FUNCTION)
                || !self::isToken($tokens[$i + 1], T_STRING)
                || strcasecmp($tokens[$i + 1][1], $name) !== 0
            ) {
                continue;
            }

            $open = $i + 2;
            while ($open < $count && $tokens[$open] !== '{') {
                $open++;
            }

            $depth = 0;
            for ($j = $open; $j < $count; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                // T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES text ends in '{' and closes with '}'.
                if ($text === '{' || str_ends_with($text, '{')) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return array_slice($tokens, $open + 1, $j - $open - 1);
                    }
                }
            }
            return null;
        }

        return null;
    }

    /**
     * Whether $tokens contain a real call to function $name (case-insensitive),
     * ignoring string literals, declarations and method calls. Comments are
     * already absent from significant tokens.
     *
     * @param list<array{int, string, int}|string> $tokens Significant tokens
     */
    public static function tokensCallFunction(array $tokens, string $name): bool
    {
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            $token = $tokens[$i];
            if (!(self::isToken($token, T_STRING) || self::isToken($token, T_NAME_FULLY_QUALIFIED))
                || strcasecmp(ltrim($token[1], '\\'), $name) !== 0
                || $tokens[$i + 1] !== '('
            ) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            if ($previous !== null && (
                self::isToken($previous, T_FUNCTION)
                || self::isToken($previous, T_OBJECT_OPERATOR)
                || self::isToken($previous, T_NULLSAFE_OBJECT_OPERATOR)
                || self::isToken($previous, T_DOUBLE_COLON)
            )) {
                continue;
            }

            return true;
        }
        return false;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens Significant tokens
     */
    public static function tokensCallStaticMethod(array $tokens, string $class, string $method): bool
    {
        $count = count($tokens);
        for ($i = 0; $i < $count - 3; $i++) {
            $classToken = $tokens[$i];
            if ((self::isToken($classToken, T_STRING) || self::isToken($classToken, T_NAME_FULLY_QUALIFIED))
                && strcasecmp(ltrim($classToken[1], '\\'), $class) === 0
                && self::isToken($tokens[$i + 1], T_DOUBLE_COLON)
                && self::isToken($tokens[$i + 2], T_STRING)
                && strcasecmp($tokens[$i + 2][1], $method) === 0
                && $tokens[$i + 3] === '('
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tokenize $source, dropping whitespace, comments and docblocks.
     *
     * @return list<array{int, string, int}|string>
     */
    private static function significantTokens(string $source): array
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }
        return $significant;
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private static function isToken(array|string $token, int $type): bool
    {
        return is_array($token) && $token[0] === $type;
    }
}
