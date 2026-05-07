<?php

declare(strict_types=1);

use ElanRegistry\Input;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for ElanRegistry\Input::raw()
 *
 * Validates that Input::raw() returns raw POST/GET values without HTML encoding,
 * making it the storage-safe alternative to the upstream UserSpice \Input::get()
 * which applies htmlspecialchars() causing double-encoding when values are later
 * escaped again at the output context before they reach the database.
 *
 * @see usersc/classes/Input.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
#[Group('input')]
final class ElanRegistryInputTest extends TestCase
{
    /**
     * Snapshot of $_POST before each test so it can be fully restored in tearDown.
     *
     * @var array<string, mixed>
     */
    private array $originalPost = [];

    /**
     * Snapshot of $_GET before each test so it can be fully restored in tearDown.
     *
     * @var array<string, mixed>
     */
    private array $originalGet = [];

    /**
     * Capture the superglobals before every test so tearDown can restore them.
     *
     * phpunit.xml sets backupGlobals="false", so we manage superglobals manually.
     */
    protected function setUp(): void
    {
        $this->originalPost = $_POST;
        $this->originalGet  = $_GET;
    }

    /**
     * Restore superglobals to their pre-test state after every test.
     *
     * Ensures full isolation: no test leaks POST/GET state into a later test.
     */
    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        $_GET  = $this->originalGet;
    }

    // -------------------------------------------------------------------------
    // POST behaviour
    // -------------------------------------------------------------------------

    /**
     * raw() returns a POST value exactly as submitted — no HTML encoding applied.
     *
     * The upstream UserSpice \Input::get() runs htmlspecialchars(), turning
     * apostrophes into &#039;. Input::raw() must not do that; the plain string
     * must survive intact so it can be safely bound to a prepared statement.
     */
    #[Group('fast')]
    public function test_raw_post_value_is_not_html_encoded(): void
    {
        $_POST = ['name' => "O'Brien"];
        $_GET  = [];

        $result = Input::raw('name');

        $this->assertSame("O'Brien", $result);
        $this->assertStringNotContainsString('&#039;', (string) $result);
    }

    /**
     * raw() reads from $_POST when the key exists in both POST and GET.
     *
     * POST takes priority over GET (matches typical form submission semantics).
     */
    #[Group('fast')]
    public function test_raw_prefers_post_over_get_when_both_present(): void
    {
        $_POST = ['field' => 'post-value'];
        $_GET  = ['field' => 'get-value'];

        $result = Input::raw('field');

        $this->assertSame('post-value', $result);
    }

    // -------------------------------------------------------------------------
    // GET behaviour
    // -------------------------------------------------------------------------

    /**
     * raw() returns a GET value exactly as submitted — no HTML encoding applied.
     *
     * Query-string values containing special characters (e.g. apostrophes in
     * a search term) must pass through unmodified so they can be stored or
     * compared without corruption.
     */
    #[Group('fast')]
    public function test_raw_get_value_is_not_html_encoded(): void
    {
        $_POST = [];
        $_GET  = ['q' => "O'Brien"];

        $result = Input::raw('q');

        $this->assertSame("O'Brien", $result);
        $this->assertStringNotContainsString('&#039;', (string) $result);
    }

    /**
     * raw() falls back to $_GET when the key is absent from $_POST.
     */
    #[Group('fast')]
    public function test_raw_returns_get_value_when_not_in_post(): void
    {
        $_POST = [];
        $_GET  = ['source' => 'registry'];

        $result = Input::raw('source');

        $this->assertSame('registry', $result);
    }

    // -------------------------------------------------------------------------
    // Absent key behaviour
    // -------------------------------------------------------------------------

    /**
     * raw() returns null when the key is absent from both $_POST and $_GET.
     *
     * Callers rely on this null to detect missing input without requiring a
     * default-value argument.
     */
    #[Group('fast')]
    public function test_raw_returns_null_when_key_absent_from_post_and_get(): void
    {
        $_POST = [];
        $_GET  = [];

        $result = Input::raw('nonexistent');

        $this->assertNull($result);
    }

    /**
     * raw() returns null when POST and GET contain other keys but not the requested one.
     */
    #[Group('fast')]
    public function test_raw_returns_null_when_key_absent_but_other_keys_present(): void
    {
        $_POST = ['other_field' => 'value'];
        $_GET  = ['another_field' => 'value'];

        $result = Input::raw('missing_key');

        $this->assertNull($result);
    }

    /**
     * raw() returns null when the POST key exists but its value is null.
     *
     * PHP's isset() returns false for null values, so a null entry in $_POST
     * is treated as absent. The method falls through to $_GET; if the key is
     * also absent there, null is returned.
     */
    #[Group('fast')]
    public function test_raw_returns_null_when_post_value_is_null(): void
    {
        $_POST = ['field' => null];
        $_GET  = [];

        $result = Input::raw('field');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Whitespace trimming (default: $trim = true)
    // -------------------------------------------------------------------------

    /**
     * raw() trims leading and trailing whitespace by default.
     *
     * Users inadvertently add spaces; stripping them before storage prevents
     * duplicate records that differ only in whitespace.
     */
    #[Group('fast')]
    public function test_raw_trims_whitespace_by_default(): void
    {
        $_POST = ['chassis' => '  50L 1234  '];
        $_GET  = [];

        $result = Input::raw('chassis');

        $this->assertSame('50L 1234', $result);
    }

    /**
     * raw() trims leading whitespace from a GET value by default.
     */
    #[Group('fast')]
    public function test_raw_trims_leading_whitespace_from_get_value(): void
    {
        $_POST = [];
        $_GET  = ['color' => "\t  Yellow\n"];

        $result = Input::raw('color');

        $this->assertSame('Yellow', $result);
    }

    // -------------------------------------------------------------------------
    // Whitespace preservation ($trim = false)
    // -------------------------------------------------------------------------

    /**
     * raw() preserves leading and trailing whitespace when $trim is false.
     *
     * Some fields (e.g. free-form comments with intentional indentation) must
     * not have whitespace stripped by the input layer.
     */
    #[Group('fast')]
    public function test_raw_preserves_whitespace_when_trim_is_false(): void
    {
        $_POST = ['comments' => '  Keep my spaces  '];
        $_GET  = [];

        $result = Input::raw('comments', false);

        $this->assertSame('  Keep my spaces  ', $result);
    }

    /**
     * raw() preserves leading and trailing whitespace on a GET value when $trim is false.
     *
     * Confirms the $trim = false contract applies to GET values, not only POST.
     */
    #[Group('fast')]
    public function test_raw_preserves_whitespace_when_trim_is_false_on_get_value(): void
    {
        $_POST = [];
        $_GET  = ['note' => '  padded  '];

        $result = Input::raw('note', false);

        $this->assertSame('  padded  ', $result);
    }

    /**
     * raw() preserves internal whitespace regardless of $trim.
     *
     * Trimming only removes leading/trailing whitespace; it must never
     * collapse internal whitespace (e.g. multi-word names).
     */
    #[Group('fast')]
    public function test_raw_preserves_internal_whitespace(): void
    {
        $_POST = ['name' => '  Lotus   Elan  '];
        $_GET  = [];

        // With $trim = true, only leading/trailing is removed
        $trimmed = Input::raw('name', true);
        $this->assertSame('Lotus   Elan', $trimmed);

        // With $trim = false, the full value survives intact
        $untrimmed = Input::raw('name', false);
        $this->assertSame('  Lotus   Elan  ', $untrimmed);
    }

    // -------------------------------------------------------------------------
    // Special characters — no HTML encoding
    // -------------------------------------------------------------------------

    /**
     * raw() returns ampersands, angle brackets, and double-quotes unencoded.
     *
     * These characters appear legitimately in car descriptions, URLs, and
     * owner comments. Encoding them before storage would corrupt data and
     * produce double-encoding bugs when the value is later output through
     * a template that also escapes for HTML.
     */
    #[Group('fast')]
    public function test_raw_does_not_encode_ampersand(): void
    {
        $_POST = ['description' => 'Lotus & Lotus'];
        $_GET  = [];

        $result = Input::raw('description');

        $this->assertSame('Lotus & Lotus', $result);
        $this->assertStringNotContainsString('&amp;', (string) $result);
    }

    /**
     * raw() returns less-than and greater-than characters unencoded.
     */
    #[Group('fast')]
    public function test_raw_does_not_encode_angle_brackets(): void
    {
        $_POST = ['note' => '1 < 2 > 0'];
        $_GET  = [];

        $result = Input::raw('note');

        $this->assertSame('1 < 2 > 0', $result);
        $this->assertStringNotContainsString('&lt;',  (string) $result);
        $this->assertStringNotContainsString('&gt;',  (string) $result);
    }

    /**
     * raw() returns double-quote characters unencoded.
     */
    #[Group('fast')]
    public function test_raw_does_not_encode_double_quotes(): void
    {
        $_POST = ['model' => '"Special Edition"'];
        $_GET  = [];

        $result = Input::raw('model');

        $this->assertSame('"Special Edition"', $result);
        $this->assertStringNotContainsString('&quot;', (string) $result);
    }

    /**
     * raw() returns a value containing all five htmlspecialchars characters
     * (&, <, >, ", ') as plain text with none of them encoded.
     *
     * This is the core behavioural contract that distinguishes Input::raw()
     * from UserSpice \Input::get(): raw storage-safe input, not display-safe.
     */
    #[Group('fast')]
    public function test_raw_does_not_encode_any_html_special_chars(): void
    {
        $rawValue = 'O\'Brien & "Lotus" <S4>';
        $_POST    = ['field' => $rawValue];
        $_GET     = [];

        $result = Input::raw('field');

        $this->assertSame($rawValue, $result);
        $this->assertStringNotContainsString('&#039;', (string) $result);
        $this->assertStringNotContainsString('&amp;',  (string) $result);
        $this->assertStringNotContainsString('&quot;', (string) $result);
        $this->assertStringNotContainsString('&lt;',   (string) $result);
        $this->assertStringNotContainsString('&gt;',   (string) $result);
    }

    // -------------------------------------------------------------------------
    // Return type contract
    // -------------------------------------------------------------------------

    /**
     * raw() returns a string (not null) when the key is present, even for an empty value.
     *
     * An empty string is a legitimate submitted value (e.g. clearing a field)
     * and must be distinguished from a missing key (which returns null).
     */
    #[Group('fast')]
    public function test_raw_returns_string_for_empty_posted_value(): void
    {
        $_POST = ['website' => ''];
        $_GET  = [];

        $result = Input::raw('website');

        $this->assertNotNull($result);
        $this->assertIsString($result);
        $this->assertSame('', $result);
    }

    /**
     * raw() casts non-string scalar POST values to string before returning.
     *
     * PHP superglobals always contain strings in real HTTP requests, but in
     * tests the array may hold integers. The explicit (string) cast inside
     * raw() ensures callers always receive a string or null.
     */
    #[Group('fast')]
    public function test_raw_casts_non_string_post_value_to_string(): void
    {
        $_POST = ['year' => 1969];
        $_GET  = [];

        $result = Input::raw('year');

        $this->assertIsString($result);
        $this->assertSame('1969', $result);
    }
}
