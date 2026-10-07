<?php

declare(strict_types=1);

use ElanRegistry\Input;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Input::raw() is the storage-safe alternative to upstream \Input::get(),
 * which HTML-encodes and so caused double encoding at output.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
#[Group('input')]
final class ElanRegistryInputTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalPost = [];

    /** @var array<string, mixed> */
    private array $originalGet = [];

    // phpunit.xml sets backupGlobals="false", so superglobals are restored by hand.
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

    #[Group('fast')]
    public function test_raw_post_value_is_not_html_encoded(): void
    {
        $_POST = ['name' => "O'Brien"];
        $_GET  = [];

        $result = Input::raw('name');

        $this->assertSame("O'Brien", $result);
        $this->assertStringNotContainsString('&#039;', (string) $result);
    }

    #[Group('fast')]
    public function test_raw_prefers_post_over_get_when_both_present(): void
    {
        $_POST = ['field' => 'post-value'];
        $_GET  = ['field' => 'get-value'];

        $result = Input::raw('field');

        $this->assertSame('post-value', $result);
    }

    #[Group('fast')]
    public function test_raw_get_value_is_not_html_encoded(): void
    {
        $_POST = [];
        $_GET  = ['q' => "O'Brien"];

        $result = Input::raw('q');

        $this->assertSame("O'Brien", $result);
        $this->assertStringNotContainsString('&#039;', (string) $result);
    }

    #[Group('fast')]
    public function test_raw_returns_get_value_when_not_in_post(): void
    {
        $_POST = [];
        $_GET  = ['source' => 'registry'];

        $result = Input::raw('source');

        $this->assertSame('registry', $result);
    }

    #[Group('fast')]
    public function test_raw_returns_null_when_key_absent_from_post_and_get(): void
    {
        $_POST = [];
        $_GET  = [];

        $result = Input::raw('nonexistent');

        $this->assertNull($result);
    }

    #[Group('fast')]
    public function test_raw_returns_null_when_key_absent_but_other_keys_present(): void
    {
        $_POST = ['other_field' => 'value'];
        $_GET  = ['another_field' => 'value'];

        $result = Input::raw('missing_key');

        $this->assertNull($result);
    }

    /**
     * isset() is false for null, so a null POST value counts as absent.
     */
    #[Group('fast')]
    public function test_raw_returns_null_when_post_value_is_null(): void
    {
        $_POST = ['field' => null];
        $_GET  = [];

        $result = Input::raw('field');

        $this->assertNull($result);
    }

    #[Group('fast')]
    public function test_raw_trims_whitespace_by_default(): void
    {
        $_POST = ['chassis' => '  50L 1234  '];
        $_GET  = [];

        $result = Input::raw('chassis');

        $this->assertSame('50L 1234', $result);
    }

    #[Group('fast')]
    public function test_raw_trims_leading_whitespace_from_get_value(): void
    {
        $_POST = [];
        $_GET  = ['color' => "\t  Yellow\n"];

        $result = Input::raw('color');

        $this->assertSame('Yellow', $result);
    }

    #[Group('fast')]
    public function test_raw_preserves_whitespace_when_trim_is_false(): void
    {
        $_POST = ['comments' => '  Keep my spaces  '];
        $_GET  = [];

        $result = Input::raw('comments', false);

        $this->assertSame('  Keep my spaces  ', $result);
    }

    #[Group('fast')]
    public function test_raw_preserves_whitespace_when_trim_is_false_on_get_value(): void
    {
        $_POST = [];
        $_GET  = ['note' => '  padded  '];

        $result = Input::raw('note', false);

        $this->assertSame('  padded  ', $result);
    }

    #[Group('fast')]
    public function test_raw_preserves_internal_whitespace(): void
    {
        $_POST = ['name' => '  Lotus   Elan  '];
        $_GET  = [];

        $trimmed = Input::raw('name', true);
        $this->assertSame('Lotus   Elan', $trimmed);

        $untrimmed = Input::raw('name', false);
        $this->assertSame('  Lotus   Elan  ', $untrimmed);
    }

    #[Group('fast')]
    public function test_raw_does_not_encode_ampersand(): void
    {
        $_POST = ['description' => 'Lotus & Lotus'];
        $_GET  = [];

        $result = Input::raw('description');

        $this->assertSame('Lotus & Lotus', $result);
        $this->assertStringNotContainsString('&amp;', (string) $result);
    }

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

    #[Group('fast')]
    public function test_raw_does_not_encode_double_quotes(): void
    {
        $_POST = ['model' => '"Special Edition"'];
        $_GET  = [];

        $result = Input::raw('model');

        $this->assertSame('"Special Edition"', $result);
        $this->assertStringNotContainsString('&quot;', (string) $result);
    }

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

    /**
     * An empty string clears a field and must stay distinct from a missing key (null).
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

    #[Group('fast')]
    public function test_raw_casts_non_string_post_value_to_string(): void
    {
        $_POST = ['year' => 1969];
        $_GET  = [];

        $result = Input::raw('year');

        $this->assertIsString($result);
        $this->assertSame('1969', $result);
    }

    #[Group('fast')]
    public function test_existsPost_returns_true_when_post_is_non_empty(): void
    {
        $_POST['x'] = 'value';
        $this->assertTrue(Input::existsPost());
    }

    #[Group('fast')]
    public function test_existsPost_returns_false_when_post_is_empty(): void
    {
        $_POST = [];
        $this->assertFalse(Input::existsPost());
    }

    #[Group('fast')]
    public function test_existsPost_with_key_returns_true_when_key_present(): void
    {
        $_POST['x'] = 'value';
        $this->assertTrue(Input::existsPost('x'));
    }

    #[Group('fast')]
    public function test_existsPost_with_key_returns_false_when_key_absent(): void
    {
        $_POST = [];
        $this->assertFalse(Input::existsPost('missing'));
    }

    /**
     * existsPost() with no key is true here, because $_POST is non-empty.
     */
    #[Group('fast')]
    public function test_existsPost_with_key_returns_false_when_key_is_null(): void
    {
        $_POST = ['x' => null];
        $this->assertFalse(Input::existsPost('x'));
    }

    #[Group('fast')]
    public function test_existsPost_no_key_and_key_form_agree_when_key_present(): void
    {
        $_POST = ['x' => 'value'];
        $this->assertTrue(Input::existsPost());
        $this->assertTrue(Input::existsPost('x'));
    }

    #[Group('fast')]
    public function test_existsGet_returns_true_when_get_is_non_empty(): void
    {
        $_GET['x'] = 'value';
        $this->assertTrue(Input::existsGet());
    }

    #[Group('fast')]
    public function test_existsGet_returns_false_when_get_is_empty(): void
    {
        $_GET = [];
        $this->assertFalse(Input::existsGet());
    }

    #[Group('fast')]
    public function test_existsGet_with_key_returns_true_when_key_present(): void
    {
        $_GET['x'] = 'value';
        $this->assertTrue(Input::existsGet('x'));
    }

    #[Group('fast')]
    public function test_existsGet_with_key_returns_false_when_key_absent(): void
    {
        $_GET = [];
        $this->assertFalse(Input::existsGet('missing'));
    }

    /**
     * existsGet() with no key is true here, because $_GET is non-empty.
     */
    #[Group('fast')]
    public function test_existsGet_with_key_returns_false_when_key_is_null(): void
    {
        $_GET = ['x' => null];
        $this->assertFalse(Input::existsGet('x'));
    }

    /**
     * The unit bootstrap stubs \Input::get() without encoding, so this checks
     * delegation only. TokenAndInputSecurityTest (integration) covers encoding.
     */
    #[Group('fast')]
    public function test_get_delegates_to_upstream_input_get(): void
    {
        $_POST['x'] = "Tom & Jerry's";
        $result = Input::get('x');
        $this->assertSame("Tom & Jerry's", (string)$result);
    }

    /**
     * The second parameter is a trim flag. Use `Input::raw('field') ?? 'fallback'` for a default.
     */
    #[Group('fast')]
    public function test_raw_second_param_is_trim_flag_not_default(): void
    {
        $_POST['x'] = '  hello  ';
        $this->expectException(\TypeError::class);
        // @phpstan-ignore argument.type (intentional type violation; asserts strict_types=1 throws TypeError at runtime for a non-bool second argument)
        Input::raw('x', 'fallback');
    }
}
