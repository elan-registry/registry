<?php

declare(strict_types=1);

use ElanRegistry\Car\EmailEventApplier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for vsStatusChip(), the Status-column chip logic defined inside
 * app/admin/includes/tab-verification.php (#1896, #2101).
 *
 * vsStatusChip() is a plain function guarded by function_exists(), not a
 * class method. This loads the exact function source (same source-slice +
 * temp-file extraction AdminVerificationQueueUrlStateTest uses) and calls the
 * real function.
 *
 * Since #2101 the owner's profile flags decide the chip: no profile, then
 * blocked, then opted out, then the latest event. The chip no longer reads
 * suppression events or EmailNoticeBuilder.
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
            . "use ElanRegistry\\Car\\EmailEventApplier;\n";
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

    private static function event(string $eventName, string $occurredAt): object
    {
        return (object) ['event' => $eventName, 'occurred_at' => $occurredAt];
    }

    public function testOwnerBlockedShowsBrevoComplaintWithStoredCause(): void
    {
        $chip = vsStatusChip(null, true, true, 'spam', false);

        $this->assertSame('complaint', $chip['kind']);
        $this->assertSame('Brevo complaint', $chip['label']);
        $this->assertSame('spam', $chip['reason']);
    }

    /**
     * Every email_blocked cause keeps the "Brevo complaint" label until
     * #2361 separates the states. The cause only fills the reason text.
     */
    public function testBlockedWithUnsubscribedCauseStillShowsBrevoComplaintNotOptedOut(): void
    {
        $chip = vsStatusChip(null, true, true, 'unsubscribed', false);

        $this->assertSame('complaint', $chip['kind']);
        $this->assertSame('Brevo complaint', $chip['label']);
        $this->assertSame('unsubscribed', $chip['reason']);
    }

    public function testBlockedWithNoKnownCauseShowsCauseUnknown(): void
    {
        $chip = vsStatusChip(null, true, true, null, false);

        $this->assertSame('complaint', $chip['kind']);
        $this->assertSame('cause unknown', $chip['reason']);
    }

    public function testOwnerOptedOutShowsOptedOut(): void
    {
        $chip = vsStatusChip(null, true, false, null, true);

        $this->assertSame('opted_out', $chip['kind']);
        $this->assertSame('Opted out', $chip['label']);
    }

    public function testBlockedWinsOverOptedOut(): void
    {
        $chip = vsStatusChip(null, true, true, 'spam', true);

        $this->assertSame('complaint', $chip['kind']);
    }

    public function testOwnerFlagWinsOverALatestDeliveredEvent(): void
    {
        $chip = vsStatusChip(self::event('delivered', '2026-09-01 10:00:00'), true, false, null, true);

        $this->assertSame('opted_out', $chip['kind']);
    }

    public function testNoProfileShowsNoProfileChipWithoutAReason(): void
    {
        $chip = vsStatusChip(null, false, true, 'spam', true);

        $this->assertSame('no_profile', $chip['kind']);
        $this->assertSame('No profile', $chip['label']);
        $this->assertSame('', $chip['reason']);
    }

    public function testLiveSpamEventOnANonSuppressedCarStillShowsSpamComplaint(): void
    {
        // A live 'spam' event on an owner with no block flag yet takes the
        // match() path and keeps its own, more specific label.
        $chip = vsStatusChip(self::event('spam', '2026-09-01 10:00:00'));

        $this->assertSame('spam', $chip['kind']);
        $this->assertSame('Spam complaint', $chip['label']);
    }

    public function testHardBounceEventShowsBounced(): void
    {
        $event = (object) ['event' => 'hard_bounce', 'occurred_at' => '2026-09-01 10:00:00', 'reason' => 'mailbox full'];

        $chip = vsStatusChip($event);

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

        $chip = vsStatusChip($event);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('Bounced', $chip['label']);
    }

    public function testUnrecognizedEventNameRendersAsADefaultChip(): void
    {
        $event = (object) ['event' => 'deferred', 'occurred_at' => '2026-09-01 10:00:00'];

        $chip = vsStatusChip($event);

        $this->assertSame('other', $chip['kind']);
        $this->assertSame('Deferred', $chip['label']);
    }

    public function testNoEventAndAndNoFlagShowsEmptyChip(): void
    {
        $chip = vsStatusChip(null);

        $this->assertSame('', $chip['kind']);
        $this->assertSame('', $chip['label']);
    }
}
