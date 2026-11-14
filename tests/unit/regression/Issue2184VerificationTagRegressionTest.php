<?php

declare(strict_types=1);

use ElanRegistry\Car\VerificationTabStatus;
use ElanRegistry\Cron\CronJobEnabledState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\PartialRenderHarness;

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
 * fourth argument. CarVerificationSendServiceTest checks the `$opts` value
 * that reaches email() at run time. This test checks that the admin status
 * panel shows the escaped reason when the Brevo plugin is not ready.
 *
 * @category regression
 * @issue 2184
 * @link https://github.com/elan-registry/registry/issues/2184
 */
#[Group('regression')]
final class Issue2184VerificationTagRegressionTest extends TestCase
{
    use PartialRenderHarness;

    /**
     * The status panel must show the actual reason, escaped, so an admin
     * with a pre-1.8.0 plugin is not told to check a key and file that are fine.
     */
    public function testStatusPanelRendersTheEscapedBrevoNotReadyReason(): void
    {
        $reason = 'The Brevo plugin is version <1.7.0>. Version 1.8.0 or later is required, '
            . 'because older versions drop the verification tag.';
        $job = [
            'state'         => CronJobEnabledState::ENABLED,
            'lastRunAt'     => new DateTimeImmutable('2026-10-18 02:00:00'),
            'lastFailureAt' => null,
        ];
        $vsStatus = new VerificationTabStatus(
            false, true, $reason, true, null, 0, $job, $job, $job, null, ['count' => 0, 'recent' => []], 5
        );

        $html = self::renderPartial(
            dirname(__DIR__, 3) . '/app/admin/includes/verification-status-panel.php',
            'VERIFICATION_STATUS_PANEL_PATH',
            ['vsStatus' => $vsStatus, 'vsCanToggle' => true]
        );
        $brevoRow = self::dom($html)->query('//dd[@data-status-row="brevo"]')?->item(0);

        $this->assertNotNull($brevoRow, 'The panel must render a Brevo row');
        $this->assertStringContainsString($reason, (string) $brevoRow->textContent);
        $this->assertStringContainsString('&lt;1.7.0&gt;', $html);
        $this->assertStringNotContainsString('<1.7.0>', $html);
    }
}
