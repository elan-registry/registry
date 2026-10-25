<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2384: `app/owner/privacy.php` states the 90-day limit on the email
 * service log, and its revision note names the nightly deletion that now
 * keeps that promise.
 *
 * The checks read the policy as plain text (tags stripped, white space
 * collapsed) and match key phrases only, so a rewording that keeps the
 * meaning does not fail them.
 *
 * @issue 2384
 * @link https://github.com/elan-registry/registry/issues/2384
 */
#[Group('regression')]
final class Issue2384PrivacyPageRegressionTest extends TestCase
{
    private const PAGE = __DIR__ . '/../../../app/owner/privacy.php';

    /** The date of the revision before #2384. */
    private const PREVIOUS_REVISION = 'October 21, 2026';

    private static ?string $policy = null;

    /** The policy as plain text. */
    private static function policy(): string
    {
        if (self::$policy !== null) {
            return self::$policy;
        }

        $source = file_get_contents(self::PAGE);
        self::assertIsString($source, 'The privacy page must be readable');
        $found = preg_match("/<<<'GUIDEHTML'\n(.*?)\nGUIDEHTML;/s", $source, $match);
        self::assertSame(1, $found, 'The privacy page must keep its policy in the GUIDEHTML nowdoc');

        $text = html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8');
        self::$policy = trim((string) preg_replace('/\s+/', ' ', $text));

        return self::$policy;
    }

    public function testLogSentenceStatesTheNinetyDayLimitAndTheDeletion(): void
    {
        $this->assertMatchesRegularExpression(
            '/email service log.{0,80}no more than 90 days\.\s*After that, we delete the address/i',
            self::policy()
        );
    }

    public function testLastUpdatedNoteNamesTheNightlyDeletionOfTheAddress(): void
    {
        $found = preg_match('/Last updated: ([A-Z][a-z]+ \d{1,2}, \d{4})\.(.{0,300})/', self::policy(), $match);
        $this->assertSame(1, $found, 'The page must have a dated Last updated note');

        $note = $match[2];
        $this->assertMatchesRegularExpression('/delete.{0,40}recipient address/i', $note);
        $this->assertMatchesRegularExpression('/email service log/i', $note);
        $this->assertMatchesRegularExpression('/90 days/i', $note);
        $this->assertMatchesRegularExpression('/nightly/i', $note);
    }

    public function testEffectiveAndLastUpdatedDatesAgreeAndChanged(): void
    {
        $policy = self::policy();

        $this->assertSame(1, preg_match('/Effective: ([A-Z][a-z]+ \d{1,2}, \d{4})/', $policy, $effective));
        $this->assertSame(1, preg_match('/Last updated: ([A-Z][a-z]+ \d{1,2}, \d{4})/', $policy, $updated));
        $this->assertSame($effective[1], $updated[1], 'The two dates describe the one revision');
        $this->assertNotSame(self::PREVIOUS_REVISION, $updated[1], 'The date of the previous revision must change');
    }
}
