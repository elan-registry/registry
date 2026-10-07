<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VerificationSecurityTest extends TestCase
{
    /**
     * \Token is the bootstrap-unit.php stub, not UserSpice's class. Real CSRF
     * crypto is covered by integration/TokenAndInputSecurityTest.
     */
    public function testCSRFTokenValidation(): void
    {
        $validToken = Token::generate();
        $this->assertTrue(Token::check($validToken));

        $invalidToken = 'invalid_token_' . uniqid();
        $this->assertFalse(Token::check($invalidToken));
    }
    
    public function testVerificationCodeSanitization(): void
    {
        $cleanCode = 'abc123def456';
        $dirtyCode = '<script>alert("xss")</script>abc123def456';
        
        $sanitized = htmlspecialchars(strip_tags($dirtyCode), ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString($cleanCode, $sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
        
        $htmlCode = 'test&<>"\'';
        $sanitized = htmlspecialchars($htmlCode, ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString('&amp;', $sanitized);
        $this->assertStringContainsString('&lt;', $sanitized);
        $this->assertStringContainsString('&gt;', $sanitized);
    }
    
    /** Stub only; see integration/TokenAndInputSecurityTest. */
    public function testCSRFTokenUniqueness(): void
    {
        $tokens = [];
        for ($i = 0; $i < 10; $i++) {
            $tokens[] = Token::generate();
        }
        $this->assertCount(10, array_unique($tokens));
    }

    // MD5 allowlist regex /^[0-9a-f]{32}$/i (#1148)

    #[DataProvider('validVerificationCodeProvider')]
    public function testMd5AllowlistAcceptsValidCodes(string $code): void
    {
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/i', $code),
            "Expected allowlist to accept: {$code}");
    }

    /** @return array<string, array{string}> */
    public static function validVerificationCodeProvider(): array
    {
        return [
            'lowercase MD5'  => [md5('test')],
            'uppercase MD5'  => [strtoupper(md5('test'))],
            'mixed case MD5' => ['Abc123def456abc123def456abc12345'],
            'all zeros'      => [str_repeat('0', 32)],
            'all f'          => [str_repeat('f', 32)],
        ];
    }

    #[DataProvider('invalidVerificationCodeProvider')]
    public function testMd5AllowlistRejectsInvalidCodes(string $code): void
    {
        $this->assertSame(0, preg_match('/^[0-9a-f]{32}$/i', $code),
            "Expected allowlist to reject: " . addcslashes($code, "\n\r\0"));
    }

    /** @return array<string, array{string}> */
    public static function invalidVerificationCodeProvider(): array
    {
        return [
            'empty string'        => [''],
            '31 hex chars'        => [str_repeat('a', 31)],
            '33 hex chars'        => [str_repeat('a', 33)],
            'non-hex chars'       => ['gggggggggggggggggggggggggggggggg'],
            'with newline'        => [str_repeat('a', 31) . "\n"],
            'with null byte'      => [str_repeat('a', 31) . "\0"],
            'path traversal'      => ['../../../etc/passwd00000000000000'],
            'XSS payload'         => ['<script>alert(1)</script>0000000'],
        ];
    }
}