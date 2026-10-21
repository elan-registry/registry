<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1875: `app/owner/privacy.php` names healthchecks.io as a processor.
 * Healthchecks.io monitors the scheduled tasks. A ping tells it the task name
 * and the run status, and it receives no personal data.
 *
 * The test reads the policy text from the page source and checks key phrases
 * only, so a rewording that keeps the meaning does not fail it. Each phrase
 * pair sits within a short distance, so a phrase cannot match in an unrelated
 * paragraph. The page text has no HTML tags in the checks (tags are stripped
 * and white space is collapsed).
 *
 * @issue 1875
 * @link https://github.com/elan-registry/registry/issues/1875
 */
#[Group('regression')]
final class Issue1875PrivacyPageRegressionTest extends TestCase
{
    private const PAGE = __DIR__ . '/../../../app/owner/privacy.php';

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

    /**
     * @return array<string, array{string}> Criterion label => regular expression
     */
    public static function keyPhraseProvider(): array
    {
        return [
            'names healthchecks.io as a monitoring service'
                => ['/healthchecks\.io, a monitoring service/i'],
            'told when a scheduled task finishes or fails'
                => ['/scheduled task.{0,40}finishes or fails/i'],
            'receives the task name and run status'
                => ['/Healthchecks\.io receives the task name and its run status/i'],
            'names the operator and country'
                => ['/run by SIA Monkey See Monkey Do in Latvia/i'],
            'receives no personal data'
                => ['/run status.{0,60}It does not receive any personal data/i'],
        ];
    }

    #[DataProvider('keyPhraseProvider')]
    public function testPolicyStatesTheKeyPhrase(string $pattern): void
    {
        $this->assertMatchesRegularExpression($pattern, self::policy());
    }

    /** "Last updated" and "Effective" change with this revision, and agree. */
    public function testEffectiveAndLastUpdatedDatesChangedAndAgree(): void
    {
        $policy = self::policy();

        $this->assertSame(1, preg_match('/Effective: ([A-Z][a-z]+ \d{1,2}, \d{4})/', $policy, $effective));
        $this->assertSame(1, preg_match('/Last updated: ([A-Z][a-z]+ \d{1,2}, \d{4})/', $policy, $updated));
        $this->assertSame($effective[1], $updated[1], 'The two dates describe the one revision');
        $this->assertNotSame('October 18, 2026', $updated[1], 'The date of the previous revision must change');
    }
}
