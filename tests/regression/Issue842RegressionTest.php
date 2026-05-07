<?php

declare(strict_types=1);

use ElanRegistry\Input;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #842: user and profile text fields double-encoded on save
 *
 * Mirrors the coverage from Issue841RegressionTest (car text fields) but for
 * user/profile free-text fields. usersc/user_settings.php covers all six fields
 * (fname, lname, city, state, country, website); usersc/join.php covers fname and
 * lname only (the registration form does not collect the others).
 *
 * All affected fields were previously stored via \Input::get(), which applies
 * htmlspecialchars() before returning. This PR switched them to
 * \ElanRegistry\Input::raw() so that special characters are preserved in the
 * database and escaped only at render time.
 *
 * @issue 842
 * @link https://github.com/unibrain1/elanregistry/issues/842
 * @category regression
 *
 * Root cause mirrors #841: the save handlers called \Input::get() (upstream
 * UserSpice), which applies htmlspecialchars() before returning. Values like
 * "O'Brien" were stored as "O&#039;Brien", and re-saved values accumulated
 * additional encoding.
 *
 * Fix: switched affected fields to \ElanRegistry\Input::raw(), which returns
 * the unencoded scalar value. htmlspecialchars() is applied only at the output
 * (display) layer, where it belongs.
 */
final class Issue842RegressionTest extends TestCase
{
    /** @var array<string, mixed> Original $_POST state saved before each test */
    private array $originalPost = [];

    /** @var array<string, mixed> Original $_GET state saved before each test */
    private array $originalGet = [];

    protected function setUp(): void
    {
        $this->originalPost = $_POST;
        $this->originalGet  = $_GET;
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        $_GET  = $this->originalGet;
    }

    /**
     * Input::raw() must return literal values for all six fields without HTML-encoding.
     *
     * @dataProvider specialCharacterFieldProvider
     */
    public function testRawInputDoesNotEncodeSpecialCharacters(string $field, string $value): void
    {
        $_POST[$field] = $value;

        $result = Input::raw($field);

        $this->assertSame(
            $value,
            $result,
            "Input::raw('{$field}') must return the value unchanged — no &amp; or &#039; encoding"
        );

        $this->assertStringNotContainsString('&amp;', (string) $result, "Ampersand in {$field} must not be entity-encoded");
        $this->assertStringNotContainsString('&#039;', (string) $result, "Single-quote in {$field} must not be entity-encoded");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function specialCharacterFieldProvider(): array
    {
        return [
            'fname with apostrophe'   => ['fname',   "O'Brien"],
            'fname with ampersand'    => ['fname',   "Jean & Luc"],
            'lname with apostrophe'   => ['lname',   "D'Angelo"],
            'city with apostrophe'    => ['city',    "L'Isle-Adam"],
            'city with ampersand'     => ['city',    "Bath & Wells"],
            'state with ampersand'    => ['state',   "Provence & Alpes"],
            'country with ampersand'  => ['country', "Trinidad & Tobago"],
            'website with ampersand'  => ['website', "https://example.com/page?a=1&b=2"],
        ];
    }

    /**
     * A literal "0" value must not be silently discarded for any of the six fields.
     *
     * The updater functions changed from a truthy guard (where PHP treats "0" as falsy)
     * to an explicit !== null && !== '' check. This verifies "0" is treated as a valid value.
     *
     * @dataProvider zeroValueFieldProvider
     */
    public function testLiteralZeroIsNotDiscarded(string $field): void
    {
        $_POST[$field] = '0';

        $result = Input::raw($field);

        $this->assertSame('0', $result, "Input::raw('{$field}') must return \"0\" unchanged");
        $this->assertNotNull($result, '"0" must not be treated as absent');
        $this->assertNotSame('', $result, "\"0\" must pass the !== \"\" guard in update" . ucfirst($field) . "()");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zeroValueFieldProvider(): array
    {
        return [
            'fname'   => ['fname'],
            'lname'   => ['lname'],
            'city'    => ['city'],
            'state'   => ['state'],
            'country' => ['country'],
            'website' => ['website'],
        ];
    }

    /**
     * Re-saving a value retrieved via Input::raw() must not accumulate encoding.
     *
     * @dataProvider idempotencyFieldProvider
     */
    public function testRawInputIsIdempotentOnResave(string $field, string $value): void
    {
        $_POST[$field] = $value;
        $firstSave = Input::raw($field);

        $_POST[$field] = $firstSave;
        $secondSave = Input::raw($field);

        $this->assertSame(
            $firstSave,
            $secondSave,
            "Input::raw('{$field}') must be idempotent — no additional encoding accumulates on re-save"
        );
        $this->assertSame($value, $firstSave, "First save of {$field} must equal original input");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function idempotencyFieldProvider(): array
    {
        return [
            'fname'   => ['fname',   "O'Brien"],
            'lname'   => ['lname',   "D'Angelo & Sons"],
            'city'    => ['city',    "L'Isle-Adam"],
            'state'   => ['state',   "Provence & Alpes"],
            'country' => ['country', "Trinidad & Tobago"],
            'website' => ['website', "https://example.com?a=1&b=2"],
        ];
    }

    /**
     * XSS vectors stored via Input::raw() must be escaped only at render time.
     *
     * @dataProvider xssFieldProvider
     */
    public function testXssInputEscapedCorrectlyAtDisplayLayer(string $field): void
    {
        $xssPayload = '<script>alert(1)</script>';

        $_POST[$field] = $xssPayload;
        $stored = Input::raw($field);

        $this->assertSame(
            $xssPayload,
            $stored,
            "Input::raw('{$field}') must not encode the payload before storage"
        );

        $rendered = htmlspecialchars((string) $stored, ENT_QUOTES, 'UTF-8');

        $this->assertStringNotContainsString('<script>', $rendered, "Rendered {$field} must not contain a literal <script> tag");
        $this->assertStringContainsString('&lt;script&gt;', $rendered, "Rendered {$field} must contain the properly escaped entity &lt;script&gt;");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function xssFieldProvider(): array
    {
        return [
            'fname'   => ['fname'],
            'lname'   => ['lname'],
            'city'    => ['city'],
            'state'   => ['state'],
            'country' => ['country'],
            'website' => ['website'],
        ];
    }
}
