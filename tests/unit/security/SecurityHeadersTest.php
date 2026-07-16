<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test security_headers.php HTTPS detection migration
 *
 * Verifies that security_headers.php uses validated $is_https
 * global instead of raw $_SERVER proxy headers.
 */
#[Group('security')]
#[Group('security-headers')]
#[Group('server-globals')]
class SecurityHeadersTest extends TestCase
{
    private string $securityHeadersFile = '';
    private string $fileContent = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityHeadersFile = dirname(__DIR__, 3) . '/usersc/includes/security_headers.php';

        // Load file once with error handling
        if (!is_file($this->securityHeadersFile)) {
            $this->markTestSkipped("security_headers.php not found at {$this->securityHeadersFile}");
        }

        $content = file_get_contents($this->securityHeadersFile);
        if ($content === false) {
            $this->markTestSkipped("Unable to read security_headers.php");
        }

        $this->fileContent = (string) $content;
    }

    /**
     * Test that security_headers.php doesn't redefine $is_https
     */
    public function testDoesNotRedefineIsHttps(): void
    {
        // Should not have assignment to $is_https
        $this->assertStringNotContainsString(
            '$is_https =',
            $this->fileContent,
            'security_headers.php should not redefine $is_https (use server global instead)'
        );
    }

    /**
     * Test that security_headers.php doesn't access $_SERVER for HTTPS detection
     */
    public function testNoServerAccessForHttpsDetection(): void
    {
        // Should not check $_SERVER['HTTPS']
        $this->assertStringNotContainsString(
            "['HTTPS']",
            $this->fileContent,
            'security_headers.php should not check $_SERVER[\'HTTPS\'] directly'
        );

        // Should not check $_SERVER['SERVER_PORT']
        $this->assertStringNotContainsString(
            "['SERVER_PORT']",
            $this->fileContent,
            'security_headers.php should not check $_SERVER[\'SERVER_PORT\'] directly'
        );

        // Should not check X-Forwarded-Proto
        $this->assertStringNotContainsString(
            'X_FORWARDED_PROTO',
            $this->fileContent,
            'security_headers.php should not check X-Forwarded-Proto header directly'
        );
    }

    /**
     * Test that HSTS header uses $is_https global
     */
    public function testHstsHeaderUsesIsHttpsGlobal(): void
    {
        // Should check $is_https for HSTS
        $this->assertStringContainsString(
            'if ($is_https)',
            $this->fileContent,
            'security_headers.php should use $is_https global for HSTS logic'
        );

        // Should set HSTS header
        $this->assertStringContainsString(
            'Strict-Transport-Security',
            $this->fileContent,
            'security_headers.php should set HSTS header'
        );
    }

    /**
     * Test that file sets all expected security headers
     */
    public function testSetsAllSecurityHeaders(): void
    {
        $expectedHeaders = [
            'Content-Security-Policy',
            'X-Frame-Options',
            'X-Content-Type-Options',
            'Referrer-Policy',
            'Permissions-Policy',
        ];

        foreach ($expectedHeaders as $header) {
            $this->assertStringContainsString(
                $header,
                $this->fileContent,
                "security_headers.php should set {$header} header"
            );
        }

        // X-XSS-Protection was removed (#976): deprecated by all modern browsers,
        // implies protection that isn't actually provided. CSP is the correct mechanism.
        $this->assertStringNotContainsString(
            'X-XSS-Protection',
            $this->fileContent,
            'security_headers.php must not set X-XSS-Protection (deprecated, removed in #976)'
        );
    }

    /**
     * Test that no direct $_SERVER array access for HTTPS detection exists
     */
    public function testNoHttpsDetectionLogic(): void
    {
        // Should not have complex boolean logic with $_SERVER
        $this->assertStringNotContainsString(
            '!empty($_SERVER[\'HTTPS\'])',
            $this->fileContent,
            'security_headers.php should not contain HTTPS detection logic'
        );
    }

    /**
     * Test that file doesn't have unvalidated proxy header checks
     */
    public function testNoUnvalidatedProxyHeaders(): void
    {
        // Should not check HTTP_X_FORWARDED_PROTO
        $this->assertStringNotContainsString(
            'HTTP_X_FORWARDED_PROTO',
            $this->fileContent,
            'security_headers.php should not check unvalidated X-Forwarded-Proto header'
        );
    }

    /**
     * Test that CSP includes frame-ancestors directive for anti-clickjacking
     *
     * Modern anti-clickjacking protection via CSP frame-ancestors directive
     * (CSP3 standard, preferred over frame-src for this purpose)
     */
    public function testCspContainsFrameAncestors(): void
    {
        // Should include frame-ancestors directive in CSP
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            $this->fileContent,
            'CSP should include frame-ancestors directive for anti-clickjacking protection'
        );

        // Verify it appears in the context of the Content-Security-Policy header
        // (account for multiline string concatenation with dot operators)
        $this->assertMatchesRegularExpression(
            '/Content-Security-Policy:.*frame-ancestors\s+\'self\'/s',
            $this->fileContent,
            'CSP header should contain frame-ancestors directive'
        );
    }

    /**
     * Test that CSP script-src does not contain unsafe-inline (removed in #1328)
     *
     * unsafe-inline was removed and replaced with per-request nonces in #1328.
     * Adding it back would silently break the entire nonce strategy.
     */
    public function testCspScriptSrcDoesNotContainUnsafeInline(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/script-src\s[^;]*\'unsafe-inline\'/',
            $this->fileContent,
            "CSP script-src must not contain 'unsafe-inline' — removed in #1328, replaced by nonce"
        );
    }

    /**
     * Test that CSP script-src includes a nonce token (replacement for unsafe-inline)
     */
    public function testCspScriptSrcContainsNonce(): void
    {
        $this->assertMatchesRegularExpression(
            '/script-src[^;]*\'nonce-/',
            $this->fileContent,
            "CSP script-src must include a nonce token — the nonce replaces 'unsafe-inline'"
        );
    }

    /**
     * Test that CSP includes form-action 'self' directive
     *
     * form-action does not fall back to default-src, so it must be listed
     * explicitly to prevent form hijacking to attacker-controlled origins.
     */
    public function testCspContainsFormAction(): void
    {
        $this->assertMatchesRegularExpression(
            '/Content-Security-Policy:.*form-action\s+\'self\'/s',
            $this->fileContent,
            'CSP header should contain form-action \'self\' (form-action does not fall back to default-src)'
        );
    }

    /**
     * Test that CSP script-src does not include unsafe-eval
     *
     * No custom JavaScript uses eval() or new Function(), so unsafe-eval
     * can and must be omitted from script-src.
     */
    public function testCspDoesNotContainUnsafeEval(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/script-src\s[^;]*\'unsafe-eval\'/',
            $this->fileContent,
            'CSP script-src must not contain \'unsafe-eval\' (no eval() or new Function() usage in custom JS)'
        );
    }

    /**
     * Test that /usersc/join.php doesn't set duplicate X-Frame-Options header
     *
     * Security headers should be set globally via security_headers.php
     * Individual pages should not override them
     */
    public function testUserscJoinNoFrameOptions(): void
    {
        $joinFile = dirname(__DIR__, 3) . '/usersc/join.php';

        if (!is_file($joinFile)) {
            $this->markTestSkipped("usersc/join.php not found at {$joinFile}");
        }

        $content = file_get_contents($joinFile);
        if ($content === false) {
            $this->markTestSkipped("Unable to read usersc/join.php");
        }

        $joinContent = (string) $content;

        // Should NOT have X-Frame-Options header call
        $this->assertStringNotContainsString(
            "header('X-Frame-Options:",
            $joinContent,
            'usersc/join.php should not set X-Frame-Options (relies on global header)'
        );

        // Should have comment explaining why
        $this->assertStringContainsString(
            'Security headers',
            $joinContent,
            'usersc/join.php should have comment about global security headers'
        );
    }

    /**
     * Verify that the five SHA-256 hashes in script-src match the actual upstream
     * script blocks on disk. If a UserSpice update changes one of these files, this
     * test fails and identifies which file needs a new hash in security_headers.php.
     *
     * Hash = base64(sha256(exact bytes between opening > and closing </script>)).
     *
     * Extraction notes:
     *  - header.php and customize.php select2 block: opening tag contains a PHP nonce
     *    attribute that ends with the PHP closing sequence followed by '">'. We locate
     *    the body by finding that known suffix after the PHP close sequence.
     *  - customize.php accordion and modal blocks: plain <script> tag with no attrs.
     *  - autoassignun: the whole file is a single <script> block.
     *
     * IMPORTANT: this method must not contain the literal two-char PHP closing sequence
     * in any comment or string, or PHP will exit its own parsing mode mid-file.
     * We construct it dynamically: chr(63).chr(62) = question-mark + greater-than.
     */
    public function testUpstreamScriptHashesMatchActualFiles(): void
    {
        $root = dirname(__DIR__, 3);

        // Construct the PHP closing sequence dynamically to avoid it appearing
        // literally in this source file (it would terminate PHP's parse mode).
        $phpClose = chr(63) . chr(62);

        $bodies = $this->extractUpstreamScriptBodies($root, $phpClose);

        foreach ($bodies as $label => $body) {
            $hash = base64_encode(hash('sha256', $body, true));
            $this->assertStringContainsString(
                "'sha256-{$hash}'",
                $this->fileContent,
                "Hash mismatch for '{$label}' — the upstream file may have changed. " .
                "Recompute its SHA-256 and update security_headers.php, then run " .
                "'composer phpstan:baseline' if needed."
            );
        }
    }

    /**
     * @return array<string, string>  label => raw script body (exact bytes to hash)
     */
    private function extractUpstreamScriptBodies(string $root, string $phpClose): array
    {
        $bodies = [];

        // 1. header.php dark-mode restore
        //    Opening tag: <script nonce="<?= ... {phpClose}">
        //    Body starts immediately after {phpClose}"> and runs to </script>.
        $hdrFile = $root . '/usersc/templates/customizer/header.php';
        if (is_file($hdrFile)) {
            $src   = (string) file_get_contents($hdrFile);
            $after = $phpClose . '">';
            $pos   = strpos($src, $after);
            if ($pos !== false) {
                $start = $pos + strlen($after);
                $end   = strpos($src, '</script>', $start);
                if ($end !== false) {
                    $bodies['header.php: dark-mode restore'] = substr($src, $start, $end - $start);
                }
            }
        }

        // 2–4. customize.php: accordion, modal, and Select2 blocks
        $custFile = $root . '/usersc/templates/customizer/customize.php';
        if (is_file($custFile)) {
            $src = (string) file_get_contents($custFile);

            // Blocks 2 & 3: plain <script> with no attributes (no nonce).
            // Body is: newline + content + newline (between <script>\n and \n</script>).
            $plainTag = "<script>\n";
            $pos2 = strpos($src, $plainTag);
            if ($pos2 !== false) {
                $start = $pos2 + strlen($plainTag);
                $end   = strpos($src, "\n</script>", $start);
                if ($end !== false) {
                    $bodies['customize.php: accordion + form-change tracking'] =
                        "\n" . substr($src, $start, $end - $start) . "\n";
                }

                $pos3 = strpos($src, $plainTag, $pos2 + 1);
                if ($pos3 !== false) {
                    $start = $pos3 + strlen($plainTag);
                    $end   = strpos($src, "\n</script>", $start);
                    if ($end !== false) {
                        $bodies['customize.php: modal width + button highlight'] =
                            "\n" . substr($src, $start, $end - $start) . "\n";
                    }
                }
            }

            // Block 4: Select2 init — nonce attr ends with {phpClose}" type="text/javascript">
            $select2Tag = $phpClose . '" type="text/javascript">';
            $pos4 = strpos($src, $select2Tag);
            if ($pos4 !== false) {
                $start = $pos4 + strlen($select2Tag);
                $end   = strpos($src, '</script>', $start);
                if ($end !== false) {
                    $bodies['customize.php: jQuery Select2 init'] = substr($src, $start, $end - $start);
                }
            }
        }

        // 5. autoassignun username_field_removal.php — entire file is one <script> block
        $auFile = $root . '/usersc/plugins/autoassignun/hooks/username_field_removal.php';
        if (is_file($auFile)) {
            $src   = (string) file_get_contents($auFile);
            $tag   = "<script>\n";
            $pos5  = strpos($src, $tag);
            if ($pos5 !== false) {
                $start = $pos5 + strlen($tag);
                $end   = strpos($src, "\n</script>", $start);
                if ($end !== false) {
                    $bodies['autoassignun/hooks/username_field_removal.php: username field hide'] =
                        "\n" . substr($src, $start, $end - $start) . "\n";
                }
            }
        }

        return $bodies;
    }
}
