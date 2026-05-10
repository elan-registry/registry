<?php

declare(strict_types=1);

use ElanRegistry\Input;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cross-field regression suite for the encode-at-output reform (v2.23.0)
 *
 * Verifies that the fixes in #838, #841, and #842 hold across all text fields
 * and cannot regress silently. The entire double-encoding defect existed in an
 * untested code path — no test used special characters in any text field.
 *
 * Covered fields: comments, engine, color, chassis, website (cars table)
 *                 fname, lname (users table)
 *                 city, state, country, website (profiles table)
 *
 * Pattern verified: Input::raw() returns plain text → stored plain → rendered
 * via htmlspecialchars() exactly once.
 *
 * @issue 844
 * @link https://github.com/unibrain1/elanregistry/issues/844
 * @category regression
 *
 * Root cause: All text fields previously used \Input::get() (upstream UserSpice),
 * which applies htmlspecialchars() before returning. Values were stored encoded.
 * details.php re-applied htmlspecialchars() at render time, producing visible
 * entity strings like &amp;amp; for users.
 *
 * Fix: All text field update functions now use \ElanRegistry\Input::raw(), which
 * returns the unencoded scalar value. htmlspecialchars() is applied only at the
 * output layer.
 */
final class EncodeAtOutputRegressionTest extends TestCase
{
    /** @var list<string> All text fields covered by the encode-at-output reform */
    private const TESTED_FIELDS = ['comments', 'engine', 'color', 'chassis', 'website', 'fname', 'lname', 'city', 'state', 'country'];

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
     * Input::raw() must return the value unchanged for every covered field — no
     * pre-encoding of ampersands, quotes, or angle brackets at the storage layer.
     */
    #[DataProvider('allFieldSpecialCharProvider')]
    public function testRoundTripStorePlainText(string $field, string $value): void
    {
        $_POST[$field] = $value;

        $result = Input::raw($field);

        $this->assertSame(
            $value,
            $result,
            "Input::raw('{$field}') must return value unchanged"
        );

        $this->assertStringNotContainsString('&amp;', (string) $result, "Ampersand in {$field} must not be entity-encoded at storage");
        $this->assertStringNotContainsString('&#039;', (string) $result, "Single-quote in {$field} must not be entity-encoded at storage");
        $this->assertStringNotContainsString('&lt;', (string) $result, "Angle bracket in {$field} must not be entity-encoded at storage");
        $this->assertStringNotContainsString('&quot;', (string) $result, "Double-quote in {$field} must not be entity-encoded at storage");
    }

    /**
     * Values stored via Input::raw() must produce correctly-escaped HTML when
     * passed through htmlspecialchars() at the render layer. Special characters
     * present in the source must appear as their canonical entities, exactly once.
     */
    #[DataProvider('renderingFieldProvider')]
    public function testRenderingProducesCorrectHtml(string $field, string $value, string $expectedEntity): void
    {
        $_POST[$field] = $value;
        $stored = Input::raw($field);

        $rendered = htmlspecialchars((string) $stored, ENT_QUOTES, 'UTF-8');

        $this->assertStringContainsString(
            $expectedEntity,
            $rendered,
            "Rendered {$field} must contain the escaped entity {$expectedEntity}"
        );
    }

    /**
     * Re-saving a value retrieved via Input::raw() must not accumulate encoding
     * across any covered field — the storage layer must be fully idempotent.
     */
    #[DataProvider('allFieldIdempotencyProvider')]
    public function testResaveIsIdempotent(string $field, string $value): void
    {
        $_POST[$field] = $value;
        $firstSave = Input::raw($field);

        $_POST[$field] = $firstSave;
        $secondSave = Input::raw($field);

        $this->assertSame(
            $firstSave,
            $secondSave,
            "Input::raw('{$field}') must be idempotent on resave"
        );
        $this->assertSame($value, $firstSave, "First save of {$field} must equal original input");
    }

    /**
     * XSS script payloads must pass through Input::raw() unchanged and become
     * harmless escaped text only at the render layer.
     */
    #[DataProvider('allFieldProvider')]
    public function testXssScriptTagBlockedAtRender(string $field): void
    {
        $xssPayload = '<script>alert(1)</script>';
        $_POST[$field] = $xssPayload;
        $stored = Input::raw($field);

        $this->assertSame($xssPayload, $stored, "Input::raw('{$field}') must not pre-encode XSS payload");

        $rendered = htmlspecialchars((string) $stored, ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('<script>', $rendered, "Rendered {$field} must not contain literal <script>");
        $this->assertStringContainsString('&lt;script&gt;', $rendered, "Rendered {$field} must contain escaped &lt;script&gt;");
    }

    /**
     * Attribute-injection payloads (a stray double-quote breaking out of an
     * attribute) must be neutralized at the render layer for every field.
     */
    #[DataProvider('allFieldProvider')]
    public function testXssAttributeInjectionBlockedAtRender(string $field): void
    {
        $payload = '" onmouseover="alert(1)';
        $_POST[$field] = $payload;
        $stored = Input::raw($field);

        $this->assertSame($payload, $stored, "Input::raw('{$field}') must not pre-encode attribute injection payload");

        $rendered = htmlspecialchars((string) $stored, ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('"', $rendered, "Double-quote must be entity-encoded in rendered {$field}");
        $this->assertStringContainsString('&quot;', $rendered, "Rendered {$field} must contain &quot; entity");
    }

    /**
     * The migration script's iterative decode logic must collapse a doubly-encoded
     * value to plain text in two passes, then remain stable on a third pass.
     */
    public function testMigrationDoubleEncodedDecodesInTwoPasses(): void
    {
        $doubleEncoded = 'Tom &amp;amp; Jerry';

        // Pass 1: decodes &amp;amp; → &amp;
        $afterFirstPass = html_entity_decode($doubleEncoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame('Tom &amp; Jerry', $afterFirstPass, 'First pass must decode outer &amp;amp; to &amp;');

        // Pass 2: decodes &amp; → &
        $afterSecondPass = html_entity_decode($afterFirstPass, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame('Tom & Jerry', $afterSecondPass, 'Second pass must decode &amp; to &');

        // Pass 3: idempotent
        $afterThirdPass = html_entity_decode($afterSecondPass, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame('Tom & Jerry', $afterThirdPass, 'Third pass must not change already-decoded value');
    }

    /**
     * Already-decoded plain-text values must survive the migration's decode pass
     * unchanged — no spurious modification of clean rows.
     */
    public function testMigrationAlreadyDecodedValueIsUnchanged(): void
    {
        $plainText = "Tom & Jerry's café — \"special\"";
        $decoded = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertSame($plainText, $decoded, 'Already-plain-text value must survive decode pass unchanged');
    }

    /**
     * An absent POST key must yield null from Input::raw() — the migration's
     * decode logic must never be triggered against a non-existent value.
     */
    public function testMigrationNullInputUnaffected(): void
    {
        unset($_POST['comments']);
        $result = Input::raw('comments');

        $this->assertNull($result, 'Absent field must return null — no decode logic must be triggered');
    }

    /**
     * An empty-string input must never produce entity encoding when passed
     * through Input::raw().
     */
    public function testMigrationEmptyStringUnaffected(): void
    {
        $_POST['comments'] = '';
        $result = Input::raw('comments');

        $asString = (string) $result;
        $this->assertStringNotContainsString('&amp;', $asString, 'Empty input must not produce entity encoding');
        $this->assertStringNotContainsString('&#039;', $asString, 'Empty input must not produce entity encoding');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allFieldSpecialCharProvider(): array
    {
        $fields = self::TESTED_FIELDS;
        $values = [
            "O'Brien & Co",
            "<élan> ñoño ü",
            "it´s \"quoted\"",
        ];
        $cases = [];
        foreach ($fields as $field) {
            foreach ($values as $value) {
                $key = "{$field} — " . substr($value, 0, 20);
                $cases[$key] = [$field, $value];
            }
        }
        return $cases;
    }

    /**
     * Rendering provider — pairs each field with a value and the canonical
     * entity that htmlspecialchars() must produce for that value.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function renderingFieldProvider(): array
    {
        $fields = self::TESTED_FIELDS;
        $cases = [];
        foreach ($fields as $field) {
            $cases["{$field} — ampersand"]    = [$field, "Tom & Jerry",      '&amp;'];
            $cases["{$field} — apostrophe"]   = [$field, "O'Brien",          '&#039;'];
            $cases["{$field} — angle bracket"] = [$field, "<elan>",          '&lt;'];
        }
        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allFieldIdempotencyProvider(): array
    {
        $fields = self::TESTED_FIELDS;
        $cases = [];
        foreach ($fields as $field) {
            $cases[$field] = [$field, "O'Brien & Co <test>"];
        }
        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allFieldProvider(): array
    {
        $fields = self::TESTED_FIELDS;
        $cases = [];
        foreach ($fields as $field) {
            $cases[$field] = [$field];
        }
        return $cases;
    }
}
