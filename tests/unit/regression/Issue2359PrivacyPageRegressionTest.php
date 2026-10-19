<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2359: `app/owner/privacy.php` discloses that the registry records the
 * delivery status of all registry mail, how long it keeps the records, and
 * what stays after an account is deleted.
 *
 * The test reads the policy text from the page source and checks key phrases
 * only, so a rewording that keeps the meaning does not fail it. Each phrase
 * pair sits within a short distance, so a phrase cannot match in an unrelated
 * paragraph. The page text has no HTML tags in the checks (tags are stripped
 * and white space is collapsed).
 *
 * @issue 2359
 * @link https://github.com/elan-registry/registry/issues/2359
 */
#[Group('regression')]
final class Issue2359PrivacyPageRegressionTest extends TestCase
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
            // Criterion 76: all registry mail, the four statuses.
            'records delivery status of all registry email' => ['/records the delivery status of all registry email/i'],
            'the four statuses' => ['/delivered, bounced, spam, or unsubscribed/i'],

            // Criterion 77: retention.
            'bounce, block and complaint records keep 24 months'
                => ['/complaint records \(hard bounce, blocked, invalid, spam, unsubscribe\).{0,40}24 months/i'],
            'soft-bounce, delivery, send and other records keep 90 days'
                => ['/Soft-bounce, delivery, send, and other records.{0,40}90 days/i'],
            'latest verification send keeps its date with no address'
                => ['/latest verification email\. It keeps its date but has no email address/i'],
            'latest verification send stays as long as the car exists' => ['/as long as the car exists/i'],
            'after car deletion the nightly clean-up removes it at 90 days'
                => ['/car is deleted, the next nightly clean-up removes the record once it is 90 days old/i'],
            'verification send record is removed with the account'
                => ['/also remove it when your account is deleted/i'],

            // Criterion 78: current state, the stored copy of the address.
            'bounce and block stay until a confirmed different address or an admin clears them'
                => ['/bounce, block, and opt-out state, with the last flagged address and its date\. A bounce or a block stays until you confirm a different email address or an administrator clears it/i'],
            'opt-out stays until the owner resumes or an admin clears it'
                => ['/opt-out stays until you use Resume registry email in Account Settings or an administrator clears it/i'],
            'state is removed on account deletion' => ['/clears it\. This state is removed when your account is deleted/i'],
            'profile keeps a copy of the last recorded address'
                => ['/profile keeps a copy of the last email address we recorded/i'],
            'the copy is for the address-change check' => ['/check whether your address changed/i'],
            'the copy is removed on account deletion'
                => ['/This copy is removed when your account is deleted/i'],

            // Criterion 79: log keeps the user ID and the flag name.
            'flag-change log keeps user ID and flag name, no address'
                => ['/site log records your user ID and the name of the flag\. It does not record your email address/i'],

            // Criterion 80: who can see it, how an owner clears a block.
            'admins and editors can see the state' => ['/administrators and editors can see this state/i'],
            'an owner clears a block by confirming a new address'
                => ['/clear the block by confirming a new email address/i'],

            // Criterion 81: Brevo, and the block that survives deletion.
            'Brevo processes the email' => ['/Brevo processes the email/i'],
            'a Brevo spam or unsubscribe block stays after account deletion'
                => ['/delete your account, a spam or unsubscribe block at Brevo stays/i'],

            // Issue #2360: the opt-out stops all registry email except account mail.
            'the opt-out stops all registry email except the three kinds of account mail'
                => ['/no login required\. The opt-out stops all registry email to you except account mail \(password reset, sign-in, and email change\)/i'],
            'the last-updated sentence names the opt-out change'
                => ['/Last updated: [A-Z][a-z]+ \d{1,2}, \d{4}\. This update explains that the email opt-out stops all registry email/i'],
        ];
    }

    #[DataProvider('keyPhraseProvider')]
    public function testPolicyStatesTheKeyPhrase(string $pattern): void
    {
        $this->assertMatchesRegularExpression($pattern, self::policy());
    }

    /** The old text promised a record "for as long as we need it". The new retention periods replace it. */
    public function testPolicyHasNoOpenEndedRetentionPromise(): void
    {
        $this->assertStringNotContainsString('for as long as we need it', self::policy());
    }

    /** Criterion 80 and #2360: "Last updated" and "Effective" change, and agree. */
    public function testEffectiveAndLastUpdatedDatesChangedAndAgree(): void
    {
        $policy = self::policy();

        $this->assertSame(1, preg_match('/Effective: ([A-Z][a-z]+ \d{1,2}, \d{4})/', $policy, $effective));
        $this->assertSame(1, preg_match('/Last updated: ([A-Z][a-z]+ \d{1,2}, \d{4})/', $policy, $updated));
        $this->assertSame($effective[1], $updated[1], 'The two dates describe the one revision');
        $this->assertNotSame('October 14, 2026', $updated[1], 'The date of the previous revision must change');
    }

    /** The privacy wording does not mention the test site, a mail trap or the like. */
    public function testPolicyDoesNotMentionTheTestEnvironment(): void
    {
        $this->assertDoesNotMatchRegularExpression('/mailtrap|test\.elanregistry|test site|shared account/i', self::policy());
    }

    /** The extraction itself must be able to fail: a policy without the disclosure must not match. */
    public function testKeyPhraseCheckFailsOnAPolicyWithoutTheDisclosure(): void
    {
        $old = 'We keep a record of whether verification emails were delivered, for as long as we need it.';

        $this->assertSame(0, preg_match('/records the delivery status of all registry email/i', $old));
        $this->assertSame(0, preg_match('/24 months/', $old));
    }
}
