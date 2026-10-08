<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #1554: the real UserSpice Token/Input classes. users/ is gitignored, so the unit
 * tier has only stubs; the integration bootstrap loads the genuine classes.
 *
 * Extends plain TestCase: neither class uses the database, and requireDatabase()
 * would skip this coverage whenever the DB probe fails. CI does not run this tier (#1591).
 */
#[Group('integration')]
#[Group('security')]
final class TokenAndInputSecurityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalPost;

    /** @var array<string, mixed> */
    private array $originalGet;

    /** @var array<string, mixed> */
    private array $originalSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPost = $_POST;
        $this->originalGet = $_GET;
        $this->originalSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        $_GET = $this->originalGet;
        // Token::generate()/check() write $_SESSION['token'].
        $_SESSION = $this->originalSession;

        parent::tearDown();
    }

    public function testGeneratedTokenIsAccepted(): void
    {
        $token = Token::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertTrue(Token::check($token));
    }

    /**
     * The substitute stays hex so the token passes the format guard and reaches hash_equals().
     */
    public function testSingleCharacterTamperIsRejected(): void
    {
        $token = Token::generate();

        $lastChar = $token[strlen($token) - 1];
        $tampered = substr($token, 0, -1) . ($lastChar === 'a' ? 'b' : 'a');

        $this->assertNotSame($token, $tampered);
        $this->assertSame(strlen($token), strlen($tampered));
        $this->assertFalse(Token::check($tampered));
    }

    public function testWellFormedTokenIsRejectedWhenSessionHasNoToken(): void
    {
        unset($_SESSION['token']);

        $this->assertFalse(Token::check(bin2hex(random_bytes(32))));
    }

    public function testMissingOrMalformedTokenIsRejected(): void
    {
        Token::generate();

        $this->assertFalse(Token::check(null));
        $this->assertFalse(Token::check(''));
        $this->assertFalse(Token::check('not-a-token'));
        // Right length, wrong alphabet — fails ctype_xdigit()
        $this->assertFalse(Token::check(str_repeat('z', 64)));
    }

    public function testInputGetEncodesXssPayloads(): void
    {
        $_POST = [
            'comments' => '<script>alert("xss")</script>Safe comment',
            'website' => 'javascript:alert("xss")',
            'color' => '<img src=x onerror=alert("xss")>Red',
        ];

        $comments = Input::get('comments');
        $website = Input::get('website');
        $color = Input::get('color');

        $this->assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;Safe comment', $comments);
        $this->assertStringNotContainsString('<', $comments);

        $this->assertSame('&lt;img src=x onerror=alert(&quot;xss&quot;)&gt;Red', $color);
        $this->assertStringNotContainsString('<img', $color);

        // Encoding cannot neutralize a javascript: URL; protocol allowlisting must.
        $this->assertSame('javascript:alert(&quot;xss&quot;)', $website);
        $this->assertStringContainsString('javascript:', $website);
    }

    /**
     * Input::get() is not a SQL escaper; prepared statements and int casts are the defense.
     */
    public function testInputGetEncodesQuotesButNotSqlKeywords(): void
    {
        $_POST = [
            'chassis' => "'; DROP TABLE cars; --",
            'year' => "1970' OR '1'='1",
            'user_id' => "1; DELETE FROM users; --",
        ];

        $chassis = Input::get('chassis');
        $year = Input::get('year');
        $userId = Input::get('user_id');

        $this->assertSame('&#039;; DROP TABLE cars; --', $chassis);
        $this->assertStringContainsString('DROP TABLE', $chassis);

        $this->assertSame('1970&#039; OR &#039;1&#039;=&#039;1', $year);
        $this->assertStringNotContainsString("'", $year);

        $this->assertSame('1; DELETE FROM users; --', $userId);
        $this->assertStringContainsString('DELETE FROM', $userId);
    }

    /**
     * DataTables posts its search term as a nested array.
     */
    public function testInputGetEncodesNestedArrayValues(): void
    {
        $_POST = [
            'search' => ['value' => '<script>alert("xss")</script>test'],
        ];

        $searchData = Input::get('search');

        $this->assertIsArray($searchData);
        $this->assertArrayHasKey('value', $searchData);
        $this->assertSame(
            '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;test',
            $searchData['value']
        );
    }

    /**
     * Every POST handler uses Token::check(Input::get('csrf')).
     */
    public function testCsrfTokenRoundTripsThroughInputGet(): void
    {
        $token = Token::generate();

        $_POST = ['csrf' => $token];
        $this->assertSame($token, Input::get('csrf'));
        $this->assertTrue(Token::check(Input::get('csrf')));

        // Absent token: Input::get() returns its default, which the format guard rejects
        $_POST = [];
        $this->assertFalse(Token::check(Input::get('csrf')));
    }
}
