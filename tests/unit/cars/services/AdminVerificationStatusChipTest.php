<?php

declare(strict_types=1);

use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailNoticeBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for vsStatusChip(), the Status-column chip logic defined inside
 * app/admin/includes/tab-verification.php (#1896).
 *
 * vsStatusChip() is a plain function guarded by function_exists(), not a
 * class method — there is nothing to new up. This loads the exact function
 * source (same source-slice + temp-file extraction
 * AdminVerificationQueueUrlStateTest uses for app/admin/index.php) and calls
 * the real function, rather than reimplementing its logic for comparison.
 *
 * Covers the Blocking fix where a suppressed car's chip disagreed with the
 * owner-facing "email paused" account notice for an `unsubscribed`-caused
 * suppression: the chip used to add an extra `=== 'spam'` check on top of
 * EmailNoticeBuilder::resolveSuppressionCause(), which the notice does not
 * have, so the two disagreed on the label for that one case. The chip now
 * labels purely from resolveSuppressionCause()'s cause, same as the notice.
 */
final class AdminVerificationStatusChipTest extends TestCase
{
    /**
     * Extract and load vsStatusChip() from app/admin/includes/tab-verification.php.
     */
    private static function loadVsStatusChip(): void
    {
        if (function_exists('vsStatusChip')) {
            return;
        }

        $scriptPath = __DIR__ . '/../../../../app/admin/includes/tab-verification.php';
        $source = file_get_contents($scriptPath);
        if ($source === false) {
            throw new RuntimeException('Could not read ' . $scriptPath);
        }

        $startMarker = "if (!function_exists('vsStatusChip'))";
        $endMarker = '// Dashboard summary counts.';

        $startPos = strpos($source, $startMarker);
        $endPos = strpos($source, $endMarker);

        if ($startPos === false || $endPos === false || $endPos <= $startPos) {
            throw new RuntimeException(
                'Could not locate vsStatusChip() in ' . $scriptPath
                . ' — the file may have been restructured; update loadVsStatusChip() to match.'
            );
        }

        $slice = substr($source, $startPos, $endPos - $startPos);

        $tempFile = tempnam(sys_get_temp_dir(), 'vsStatusChip_');
        if ($tempFile === false) {
            throw new RuntimeException('Could not create temp file for vsStatusChip() extraction');
        }

        $preamble = "<?php\ndeclare(strict_types=1);\n"
            . "use ElanRegistry\\Car\\EmailEventApplier;\n"
            . "use ElanRegistry\\Car\\EmailNoticeBuilder;\n";
        $written = file_put_contents($tempFile, $preamble . $slice);
        if ($written === false) {
            unlink($tempFile);
            throw new RuntimeException('Could not write extracted vsStatusChip() source to temp file');
        }

        try {
            require $tempFile;
        } finally {
            unlink($tempFile);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::loadVsStatusChip();
    }

    private static function suppressionEvent(string $eventName, string $occurredAt): object
    {
        return (object) ['event' => $eventName, 'occurred_at' => $occurredAt];
    }

    private static function suppressedHistRow(string $timestamp): object
    {
        return (object) ['timestamp' => $timestamp];
    }

    public function testSpamSuppressionShowsBrevoComplaint(): void
    {
        $chip = vsStatusChip(
            null,
            true,
            self::suppressionEvent('spam', '2026-09-01 10:00:00'),
            null
        );

        $this->assertSame('complaint', $chip['kind']);
        $this->assertSame('Brevo complaint', $chip['label']);
    }

    /**
     * The exact case the Blocking fix covers: an `unsubscribed` suppression
     * event is also CAUSE_BREVO_COMPLAINT per resolveSuppressionCause() (it
     * does not distinguish spam from unsubscribed), and
     * _email_paused_notice.php shows "Brevo complaint" for it. Before the
     * fix, vsStatusChip()'s extra `=== 'spam'` check made the chip show
     * "Opted out" instead, disagreeing with the notice.
     */
    public function testUnsubscribedSuppressionAlsoShowsBrevoComplaintNotOptedOut(): void
    {
        $chip = vsStatusChip(
            null,
            true,
            self::suppressionEvent('unsubscribed', '2026-09-01 10:00:00'),
            null
        );

        $this->assertSame(
            EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT,
            EmailNoticeBuilder::resolveSuppressionCause(
                self::suppressionEvent('unsubscribed', '2026-09-01 10:00:00'),
                null
            )['cause'],
            'Sanity: resolveSuppressionCause() must still call this CAUSE_BREVO_COMPLAINT, '
            . 'or this test is no longer exercising the disagreement case'
        );
        $this->assertSame('complaint', $chip['kind']);
        $this->assertSame('Brevo complaint', $chip['label']);
    }

    public function testOwnerOptOutWithNoSuppressionEventShowsOptedOut(): void
    {
        $chip = vsStatusChip(
            null,
            true,
            null,
            self::suppressedHistRow('2026-09-01 10:00:00')
        );

        $this->assertSame('opted_out', $chip['kind']);
        $this->assertSame('Opted out', $chip['label']);
    }

    /**
     * A suppression event older than the latest EMAIL SUPPRESSED history row
     * is a stale, since-cleared event (see EmailNoticeBuilder's docblock):
     * the current suppression is an opt-out, not the old Brevo complaint.
     */
    public function testStaleSuppressionEventOlderThanHistoryShowsOptedOut(): void
    {
        $chip = vsStatusChip(
            null,
            true,
            self::suppressionEvent('spam', '2026-01-01 00:00:00'),
            self::suppressedHistRow('2026-09-01 10:00:00')
        );

        $this->assertSame('opted_out', $chip['kind']);
    }

    public function testLiveSpamEventOnANonSuppressedCarStillShowsSpamComplaint(): void
    {
        // Not the suppressed branch: a live 'spam' event on a car not yet
        // flagged suppressed. This is a different code path (the match()
        // below the suppressed check) and keeps its own, more specific label.
        $chip = vsStatusChip(self::suppressionEvent('spam', '2026-09-01 10:00:00'), false, null, null);

        $this->assertSame('spam', $chip['kind']);
        $this->assertSame('Spam complaint', $chip['label']);
    }

    public function testHardBounceEventShowsBounced(): void
    {
        $event = (object) ['event' => 'hard_bounce', 'occurred_at' => '2026-09-01 10:00:00', 'reason' => 'mailbox full'];

        $chip = vsStatusChip($event, false, null, null);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('Bounced', $chip['label']);
    }

    /**
     * invalid_email is a HARD_BOUNCE_EVENTS member, so it must render
     * "Bounced" via the in_array() check before the match() reaches its
     * default case — not "Invalid email" (see the EMAIL_SYSTEM.md fix for
     * this same confusion).
     */
    public function testInvalidEmailEventShowsBouncedNotADefaultChip(): void
    {
        $this->assertContains(
            'invalid_email',
            EmailEventApplier::HARD_BOUNCE_EVENTS,
            'Sanity: invalid_email must still be a hard-bounce event for this test to be meaningful'
        );

        $event = (object) ['event' => 'invalid_email', 'occurred_at' => '2026-09-01 10:00:00'];

        $chip = vsStatusChip($event, false, null, null);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('Bounced', $chip['label']);
    }

    public function testUnrecognizedEventNameRendersAsADefaultChip(): void
    {
        $event = (object) ['event' => 'deferred', 'occurred_at' => '2026-09-01 10:00:00'];

        $chip = vsStatusChip($event, false, null, null);

        $this->assertSame('other', $chip['kind']);
        $this->assertSame('Deferred', $chip['label']);
    }

    public function testNoEventAndNotSuppressedShowsEmptyChip(): void
    {
        $chip = vsStatusChip(null, false, null, null);

        $this->assertSame('', $chip['kind']);
        $this->assertSame('', $chip['label']);
    }
}
