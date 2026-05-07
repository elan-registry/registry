<?php

declare(strict_types=1);

use ElanRegistry\Input;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #838: owner comments double-encoded on save
 *
 * Ensures ElanRegistry\Input::raw() returns unencoded values so that special
 * characters stored via updateComments() are not HTML-encoded in the database,
 * preventing double-encoding at the display layer.
 *
 * @issue 838
 * @link https://github.com/unibrain1/elanregistry/issues/838
 * @category regression
 *
 * Root cause: updateComments() previously called \Input::get('comments') (upstream
 * UserSpice), which applies htmlspecialchars() before returning. The database
 * therefore stored already-encoded text. details.php:308 re-applied
 * htmlspecialchars() at render time, producing visible entity strings like
 * &amp;amp; for users.
 *
 * Fix: switched to \ElanRegistry\Input::raw('comments'), which returns the
 * unencoded scalar value. htmlspecialchars() is applied only at the output
 * (display) layer, where it belongs.
 */
final class Issue838RegressionTest extends TestCase
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
     * Input::raw() must return the literal value without HTML-encoding any characters.
     *
     * Verifies that the fix (using Input::raw() instead of \Input::get()) prevents
     * htmlspecialchars() from being applied to data before it reaches the database.
     */
    public function testRawInputDoesNotEncodeSpecialCharacters(): void
    {
        $original = "O'Brien & Co élan ñoño";

        $_POST['comments'] = $original;

        $result = Input::raw('comments');

        $this->assertSame(
            $original,
            $result,
            'Input::raw() must return the value unchanged — no &amp; or &#039; encoding'
        );

        // Confirm the two entities htmlspecialchars() would produce for & and ' are absent
        $this->assertStringNotContainsString('&amp;', (string) $result, 'Ampersand must not be entity-encoded');
        $this->assertStringNotContainsString('&#039;', (string) $result, 'Single-quote must not be entity-encoded');
    }

    /**
     * A comment consisting solely of "0" must not be silently discarded.
     *
     * The fix changed the empty-value guard from a truthy check (where PHP treats
     * "0" as falsy) to an explicit !== null && !== '' check. This verifies "0"
     * is preserved as a non-empty comment value.
     */
    public function testLiteralZeroCommentIsNotDiscarded(): void
    {
        $_POST['comments'] = '0';

        $result = Input::raw('comments');

        $this->assertSame('0', $result, 'Input::raw() must return "0" unchanged');
        $this->assertNotNull($result, '"0" must not be treated as absent');
        $this->assertNotSame('', $result, '"0" must pass the !== "" guard in updateComments()');
    }

    /**
     * Re-saving a value retrieved via Input::raw() must not accumulate encoding.
     *
     * The upstream \Input::get() bug caused double-encoding: first save wrote
     * "&amp;" to the database; a second save (read → store → read) would then
     * write "&amp;amp;". Input::raw() must be idempotent across round-trips.
     */
    public function testRawInputIsIdempotentOnResave(): void
    {
        $original = "Tom & Jerry's café — \"special\" édition";

        // First save: simulate user submitting the form
        $_POST['comments'] = $original;
        $firstSave = Input::raw('comments');

        // Second save: simulate re-saving the stored value (e.g. edit-without-change)
        $_POST['comments'] = $firstSave;
        $secondSave = Input::raw('comments');

        $this->assertSame(
            $firstSave,
            $secondSave,
            'Input::raw() must be idempotent — no additional encoding accumulates on re-save'
        );

        // Both round-trips must also equal the original input
        $this->assertSame($original, $firstSave, 'First save must equal original input');
        $this->assertSame($original, $secondSave, 'Second save must equal original input');
    }

    /**
     * XSS vectors stored via Input::raw() are properly escaped only at render time.
     *
     * Demonstrates the correct single-encoding pattern: store raw in the database,
     * apply htmlspecialchars() at the HTML output layer. A <script> tag must reach
     * the database intact but be neutralised when rendered.
     */
    public function testXssInputEscapedCorrectlyAtDisplayLayer(): void
    {
        $xssPayload = '<script>alert(1)</script>';

        $_POST['comments'] = $xssPayload;
        $stored = Input::raw('comments');

        // The raw stored value must still contain the tag (not pre-encoded)
        $this->assertSame(
            $xssPayload,
            $stored,
            'Input::raw() must not encode the payload before storage'
        );

        // Display layer applies htmlspecialchars() exactly once
        $rendered = htmlspecialchars((string) $stored, ENT_QUOTES, 'UTF-8');

        $this->assertStringNotContainsString(
            '<script>',
            $rendered,
            'Rendered output must not contain a literal <script> tag'
        );

        $this->assertStringContainsString(
            '&lt;script&gt;',
            $rendered,
            'Rendered output must contain the properly escaped entity &lt;script&gt;'
        );
    }
}
