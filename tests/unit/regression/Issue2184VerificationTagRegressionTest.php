<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for #2184: verification email went out without its Brevo tag.
 *
 * CarVerificationSendService called `email($to, $subject, $body)` with no
 * `$opts`, so the sendinblue override sent the mail untagged and the Brevo
 * webhook, which then filtered on the `car_verification` tag, never recorded
 * it. Since #2359 the webhook records every event of our mail without a tag
 * filter. The tag still matters: only a `car_verification`-tagged event with
 * no user counts in the dashboard's unmatched-recipient counter, and Brevo
 * statistics group the mail by it. The fix passes `['tags' => AppConstants::VERIFICATION_EMAIL_TAG]` as the
 * fourth argument. `$opts` is optional, so a later edit that drops it still
 * runs and still sends mail. This test fails if the service source no longer
 * passes the tag. CarVerificationSendServiceTest checks the `$opts` value
 * that reaches email() at run time.
 *
 * @category regression
 * @issue 2184
 * @link https://github.com/elan-registry/registry/issues/2184
 */
#[Group('regression')]
final class Issue2184VerificationTagRegressionTest extends TestCase
{
    public function testSendServicePassesTheVerificationTagToEmail(): void
    {
        // tests/unit/regression/ is three levels below the project root.
        $targetFile = dirname(__DIR__, 3) . '/usersc/classes/Car/CarVerificationSendService.php';
        $this->assertFileExists($targetFile);
        $source = file_get_contents($targetFile);
        $this->assertIsString($source);

        $this->assertStringContainsString(
            "['tags' => AppConstants::VERIFICATION_EMAIL_TAG]",
            $source,
            'CarVerificationSendService must pass $opts with the verification tag to email() (#2184)'
        );
    }

    /**
     * The verification tab must show the actual reason, escaped, so an admin
     * with a pre-1.8.0 plugin is not told to check a key and file that are fine.
     */
    public function testVerificationTabRendersTheEscapedBrevoNotReadyReason(): void
    {
        $tabFile = dirname(__DIR__, 3) . '/app/admin/includes/tab-verification.php';
        $this->assertFileExists($tabFile);
        $content = file_get_contents($tabFile);
        $this->assertIsString($content);

        $this->assertStringContainsString('$vsSettings->brevoNotReadyReason()', $content);
        $this->assertStringContainsString('$vsBrevoReasonText = $vsBrevoNotReadyReason ??', $content);
        $this->assertStringContainsString('vsEsc($vsBrevoReasonText)', $content);
    }
}
