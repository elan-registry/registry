<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `app/owner/privacy.php` must keep the policy statements of these issues:
 *
 * - #1875: healthchecks.io is a processor. It monitors the scheduled tasks,
 *   receives the task name and the run status, and receives no personal data.
 * - #2088: the registry sends car-verification request emails through Brevo,
 *   and the email service log keeps a recipient address for no more than
 *   90 days.
 * - #2359: the registry records the delivery status of all registry mail,
 *   states how long it keeps the records, and states what stays after an
 *   account is deleted.
 * - #2384: the email service log sentence states the 90-day limit, and the
 *   revision note names the daily deletion that keeps that promise.
 *
 * The test reads the policy text from the page source and checks key phrases
 * only, so a rewording that keeps the meaning does not fail it. Each phrase
 * pair sits within a short distance, so a phrase cannot match in an unrelated
 * paragraph. The page text has no HTML tags in the checks (tags are stripped
 * and white space is collapsed).
 *
 * @issue 1875
 * @issue 2088
 * @issue 2359
 * @issue 2384
 * @link https://github.com/elan-registry/registry/issues/1875
 * @link https://github.com/elan-registry/registry/issues/2088
 * @link https://github.com/elan-registry/registry/issues/2359
 * @link https://github.com/elan-registry/registry/issues/2384
 */
#[Group('regression')]
final class PrivacyPagePolicyRegressionTest extends TestCase
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
     * @return array<string, array{string}> Issue and criterion label => regular expression
     */
    public static function keyPhraseProvider(): array
    {
        return [
            // #1875: healthchecks.io.
            '[1875] names healthchecks.io as a monitoring service'
                => ['/healthchecks\.io, a monitoring service/i'],
            '[1875] told when a scheduled task finishes or fails'
                => ['/scheduled task.{0,40}finishes or fails/i'],
            '[1875] receives the task name and run status'
                => ['/Healthchecks\.io receives the task name and its run status/i'],
            '[1875] names the operator and country'
                => ['/run by SIA Monkey See Monkey Do in Latvia/i'],
            '[1875] receives no personal data'
                => ['/run status.{0,60}It does not receive any personal data/i'],

            // #2088: verification emails through Brevo.
            '[2088] verification emails go through Brevo'
                => ['/send car-verification request emails through Brevo/i'],
            '[2088] the email service log keeps a recipient address for no more than 90 days'
                => ['/email service log.{0,60}recipient address.{0,60}(no more than 90 days|90 days or less)/i'],

            // #2359 criterion 76: all registry mail, the four statuses.
            '[2359] records delivery status of all registry email'
                => ['/records the delivery status of all registry email/i'],
            '[2359] the four statuses' => ['/delivered, bounced, spam, or unsubscribed/i'],

            // #2359 criterion 77: retention.
            '[2359] bounce, block and complaint records keep 24 months'
                => ['/complaint records \(hard bounce, blocked, invalid, spam, unsubscribe\).{0,40}24 months/i'],
            '[2359] soft-bounce, delivery, send and other records keep 90 days'
                => ['/Soft-bounce, delivery, send, and other records.{0,40}90 days/i'],
            '[2359] latest verification send keeps its date with no address'
                => ['/latest verification email\. It keeps its date but has no email address/i'],
            '[2359] latest verification send stays as long as the car exists' => ['/as long as the car exists/i'],
            '[2359] after car deletion the nightly clean-up removes it at 90 days'
                => ['/car is deleted, the next nightly clean-up removes the record once it is 90 days old/i'],
            '[2359] verification send record is removed with the account'
                => ['/also remove it when your account is deleted/i'],

            // #2359 criterion 78: current state, the stored copy of the address.
            '[2359] bounce and block stay until a confirmed different address or an admin clears them'
                => ['/bounce, block, and opt-out state, with the last flagged address and its date\. A bounce or a block stays until you confirm a different email address or an administrator clears it/i'],
            '[2359] opt-out stays until the owner resumes or an admin clears it'
                => ['/opt-out stays until you use Resume registry email in Account Settings or an administrator clears it/i'],
            '[2359] state is removed on account deletion'
                => ['/clears it\. This state is removed when your account is deleted/i'],
            '[2359] profile keeps a copy of the last recorded address'
                => ['/profile keeps a copy of the last email address we recorded/i'],
            '[2359] the copy is for the address-change check' => ['/check whether your address changed/i'],
            '[2359] the copy is removed on account deletion'
                => ['/This copy is removed when your account is deleted/i'],

            // #2359 criterion 79: log keeps the user ID and the flag name.
            '[2359] flag-change log keeps user ID and flag name, no address'
                => ['/site log records your user ID and the name of the flag\. It does not record your email address/i'],

            // #2359 criterion 80: who can see it, how an owner clears a block.
            '[2359] admins and editors can see the state' => ['/administrators and editors can see this state/i'],
            '[2359] an owner clears a block by confirming a new address'
                => ['/clear the block by confirming a new email address/i'],

            // #2359 criterion 81: Brevo, and the block that survives deletion.
            '[2359] Brevo processes the email' => ['/Brevo processes the email/i'],
            '[2359] a Brevo spam or unsubscribe block stays after account deletion'
                => ['/delete your account, a spam or unsubscribe block at Brevo stays/i'],

            // #2360 (checked with #2359): the opt-out stops all registry email except account mail.
            '[2359] the opt-out stops all registry email except the three kinds of account mail'
                => ['/no login required\. The opt-out stops all registry email to you except account mail \(password reset, sign-in, and email change\)/i'],

            // #2384: the email service log sentence.
            '[2384] log sentence states the 90-day limit and the deletion'
                => ['/email service log.{0,80}no more than 90 days\.\s*After that, we delete the address/i'],
        ];
    }

    #[DataProvider('keyPhraseProvider')]
    public function testPolicyStatesTheKeyPhrase(string $pattern): void
    {
        $this->assertMatchesRegularExpression($pattern, self::policy());
    }

    /** "Last updated" and "Effective" describe the one revision. */
    public function testEffectiveDateEqualsLastUpdatedDate(): void
    {
        $policy = self::policy();

        $this->assertSame(1, preg_match('/Effective: ([A-Z][a-z]+ \d{1,2}, \d{4})/', $policy, $effective));
        $this->assertSame(1, preg_match('/Last updated: ([A-Z][a-z]+ \d{1,2}, \d{4})/', $policy, $updated));
        $this->assertSame($effective[1], $updated[1], 'The two dates describe the one revision');
    }

    /** #2088: the old text said verification emails were not sent yet. */
    public function testPolicyNoLongerSaysVerificationEmailsAreNotSent(): void
    {
        $this->assertStringNotContainsString('have not started sending', self::policy());
    }

    /** #2359: the old text promised a record "for as long as we need it". The new retention periods replace it. */
    public function testPolicyHasNoOpenEndedRetentionPromise(): void
    {
        $this->assertStringNotContainsString('for as long as we need it', self::policy());
    }

    /** #2359: the privacy wording does not mention the test site, a mail trap or the like. */
    public function testPolicyDoesNotMentionTheTestEnvironment(): void
    {
        $this->assertDoesNotMatchRegularExpression('/mailtrap|test\.elanregistry|test site|shared account/i', self::policy());
    }

    /** #2384: the revision note names the daily deletion of the recipient address. */
    public function testLastUpdatedNoteNamesTheDailyDeletionOfTheAddress(): void
    {
        $found = preg_match('/Last updated: ([A-Z][a-z]+ \d{1,2}, \d{4})\.(.{0,300})/', self::policy(), $match);
        $this->assertSame(1, $found, 'The page must have a dated Last updated note');

        $note = $match[2];
        $this->assertMatchesRegularExpression('/delete.{0,40}recipient address/i', $note);
        $this->assertMatchesRegularExpression('/email service log/i', $note);
        $this->assertMatchesRegularExpression('/90 days/i', $note);
        $this->assertMatchesRegularExpression('/daily/i', $note);
    }
}
