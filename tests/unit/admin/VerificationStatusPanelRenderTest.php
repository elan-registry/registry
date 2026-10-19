<?php

declare(strict_types=1);

use ElanRegistry\Car\VerificationTabStatus;
use ElanRegistry\Cron\CronJobEnabledState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Render tests for the Verification tab status panel partial (#2330, #2364).
 *
 * The partial renders from a VerificationTabStatus. These tests include the
 * real partial and read the HTML as a DOM. No test reads the source text.
 * The place of the panel in the tab (directly before Automatic Sending) is
 * a property of tab-verification.php. AdminVerificationTabRenderTest checks
 * it against the real tab.
 *
 * @issue 2330
 * @issue 2364
 */
#[Group('fast')]
final class VerificationStatusPanelRenderTest extends TestCase
{
    private const PARTIAL = __DIR__ . '/../../../app/admin/includes/verification-status-panel.php';

    private const ROW_ORDER = [
        'brevo', 'cron', 'webhook', 'unmatched', 'reconciliation', 'suppression_sync', 'cron_failures',
    ];

    /**
     * @return array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable}
     */
    private static function job(
        CronJobEnabledState $state = CronJobEnabledState::ENABLED,
        ?string $ranAt = '2026-10-18 02:00:00',
        ?string $failedAt = null
    ): array {
        return [
            'state'         => $state,
            'lastRunAt'     => $ranAt !== null ? new DateTimeImmutable($ranAt) : null,
            'lastFailureAt' => $failedAt !== null ? new DateTimeImmutable($failedAt) : null,
        ];
    }

    /**
     * A healthy status, with any value replaced by $o.
     *
     * @param array<string, mixed> $o
     */
    private static function build(array $o = []): VerificationTabStatus
    {
        $v = $o + [
            'probeFailed'    => false,
            'enabled'        => true,
            'brevoReason'    => null,
            'cronReady'      => true,
            'unmatched'      => 0,
            'reconciliation' => self::job(),
            'failureLog'     => ['count' => 0, 'recent' => []],
        ];

        return new VerificationTabStatus(
            $v['probeFailed'],
            $v['enabled'],
            $v['brevoReason'],
            $v['cronReady'],
            new DateTimeImmutable('2026-10-19 14:05:00'),
            $v['unmatched'],
            $v['reconciliation'],
            self::job(),
            self::job(),
            ['counts' => ['sent' => 1, 'skipped' => 0, 'failed' => 0], 'unreadable' => false],
            $v['failureLog']
        );
    }

    /** Include the partial the way the tab does and return its HTML. */
    private static function renderHtml(VerificationTabStatus $vsStatus, ?bool $vsCanToggle = true): string
    {
        // Null leaves the flag unset, as an including scope that forgot it would.
        if ($vsCanToggle === null) {
            unset($vsCanToggle);
        }

        // Lets a reviewer prove a test can fail: point the test at a copy of an older partial.
        $override = getenv('VERIFICATION_STATUS_PANEL_PATH');
        $file     = is_string($override) && $override !== '' ? $override : self::PARTIAL;

        // A PHP warning or deprecation inside the view is a defect, not noise.
        set_error_handler(static function (int $severity, string $message, string $errFile, int $line): never {
            throw new ErrorException($message, 0, $severity, $errFile, $line);
        });
        ob_start();
        try {
            include $file;
        } finally {
            $html = (string) ob_get_clean();
            restore_error_handler();
        }

        return $html;
    }

    private static function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    /** @return list<string> */
    private static function rowKeys(DOMXPath $xp): array
    {
        $keys = [];
        $nodes = $xp->query('//dd[@data-status-row]');
        self::assertNotFalse($nodes);
        foreach ($nodes as $node) {
            self::assertInstanceOf(DOMElement::class, $node);
            $keys[] = $node->getAttribute('data-status-row');
        }

        return $keys;
    }

    private static function rowText(DOMXPath $xp, string $key): string
    {
        $nodes = $xp->query('//dd[@data-status-row="' . $key . '"]');
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length, "Expected exactly one row {$key}");

        return preg_replace('/\s+/', ' ', trim((string) $nodes->item(0)?->textContent)) ?? '';
    }

    /** @return array<string, array{VerificationTabStatus, bool}> */
    public static function healthStateProvider(): array
    {
        return [
            'healthy'     => [self::build(), true],
            'not healthy' => [self::build(['cronReady' => false, 'unmatched' => 3]), false],
        ];
    }

    #[DataProvider('healthStateProvider')]
    public function test_panel_rendersTheSameRowsInTheSameOrderInBothStates(VerificationTabStatus $status, bool $healthy): void
    {
        $this->assertSame($healthy, $status->allHealthy(), 'Fixture is in the wrong health state');

        $xp = self::dom(self::renderHtml($status));

        $this->assertSame(self::ROW_ORDER, self::rowKeys($xp));
        $this->assertSame(1, $xp->query('//details[@id="verificationStatusPanel"]')?->length);
        $this->assertStringContainsString(
            'System Status & Feature Switch',
            (string) $xp->query('//details/summary/h5')?->item(0)?->textContent
        );
        $this->assertSame(1, $xp->query('//input[@id="verificationEnabledSwitch"]')?->length);
    }

    public function test_panel_healthy_isClosedAndShowsAllHealthy(): void
    {
        $xp = self::dom(self::renderHtml(self::build()));

        $details = $xp->query('//details[@id="verificationStatusPanel"]')?->item(0);
        $this->assertInstanceOf(DOMElement::class, $details);
        $this->assertFalse($details->hasAttribute('open'));
        $this->assertStringContainsString('All healthy', (string) $xp->query('//details/summary')?->item(0)?->textContent);
        $this->assertStringNotContainsString('Needs attention', (string) $xp->query('//details/summary')?->item(0)?->textContent);
    }

    public function test_panel_notHealthy_isOpenAndShowsNeedsAttention(): void
    {
        $xp = self::dom(self::renderHtml(self::build(['unmatched' => 2])));

        $details = $xp->query('//details[@id="verificationStatusPanel"]')?->item(0);
        $this->assertInstanceOf(DOMElement::class, $details);
        $this->assertTrue($details->hasAttribute('open'));
        $this->assertStringContainsString('Needs attention', (string) $xp->query('//details/summary')?->item(0)?->textContent);
        $this->assertStringNotContainsString('All healthy', (string) $xp->query('//details/summary')?->item(0)?->textContent);
    }

    public function test_panel_brevoReasonIsEscapedAndShownInFull(): void
    {
        $reason = 'The Brevo plugin is version <1.7.0> & "old". Version 1.8.0 or later is required.';

        $html = self::renderHtml(self::build(['brevoReason' => $reason]));
        $xp   = self::dom($html);

        $this->assertStringNotContainsString('<1.7.0>', $html, 'The reason must not reach the page unescaped');
        $this->assertStringContainsString('&lt;1.7.0&gt;', $html);
        $this->assertStringContainsString('Not ready', self::rowText($xp, 'brevo'));
        $this->assertStringContainsString($reason, self::rowText($xp, 'brevo'));
    }

    public function test_panel_markupInTheReasonNeverBecomesAnElement(): void
    {
        $xp = self::dom(self::renderHtml(self::build(['brevoReason' => '<script>alert(1)</script><img src=x onerror=alert(2)>'])));

        $this->assertSame(0, $xp->query('//script')?->length);
        $this->assertSame(0, $xp->query('//img')?->length);
    }

    public function test_panel_switchReasonIsEscapedToo(): void
    {
        $status = self::build(['enabled' => false, 'brevoReason' => 'Bad <b>key</b>']);

        $html = self::renderHtml($status);

        $this->assertStringNotContainsString('<b>key</b>', $html);
        $this->assertStringContainsString('Verification cannot be enabled until Brevo is ready.', $html);
        $this->assertStringContainsString('Bad &lt;b&gt;key&lt;/b&gt;', $html);
    }

    public function test_panel_switchIsCheckedWhenEnabledAndDisabledForANonAdmin(): void
    {
        $admin  = self::dom(self::renderHtml(self::build(), true))->query('//input[@id="verificationEnabledSwitch"]')?->item(0);
        $viewer = self::dom(self::renderHtml(self::build(), false))->query('//input[@id="verificationEnabledSwitch"]')?->item(0);

        $this->assertInstanceOf(DOMElement::class, $admin);
        $this->assertInstanceOf(DOMElement::class, $viewer);
        $this->assertTrue($admin->hasAttribute('checked'));
        $this->assertFalse($admin->hasAttribute('disabled'));
        $this->assertTrue($viewer->hasAttribute('disabled'));
    }

    public function test_panel_withoutTheToggleFlag_failsClosedToReadOnly(): void
    {
        $html = self::renderHtml(self::build(), null);

        $input = self::dom($html)->query('//input[@id="verificationEnabledSwitch"]')?->item(0);
        $this->assertInstanceOf(DOMElement::class, $input);
        $this->assertTrue($input->hasAttribute('disabled'));
    }

    public function test_panel_eachRowCarriesItsBadgeAndDetail(): void
    {
        $xp = self::dom(self::renderHtml(self::build(['unmatched' => 3])));

        $this->assertStringContainsString('Ready', self::rowText($xp, 'brevo'));
        $this->assertStringContainsString('Running last seen Oct 19, 2026 2:05 PM', self::rowText($xp, 'cron'));
        $this->assertStringContainsString('Live', self::rowText($xp, 'webhook'));
        $this->assertStringStartsWith('3 ', self::rowText($xp, 'unmatched'));
        $this->assertStringContainsString('Ran last ran Oct 18, 2026 2:00 AM', self::rowText($xp, 'reconciliation'));
    }

    /** The "Unavailable" branch for the unreadable counter and the failure log. */
    #[Group('regression')]
    public function test_panel_unreadableValuesRenderUnavailableNotZero(): void
    {
        $status = self::build(['unmatched' => null, 'failureLog' => null]);

        $xp = self::dom(self::renderHtml($status));

        $this->assertStringStartsWith('Unavailable', self::rowText($xp, 'unmatched'));
        $this->assertStringStartsWith('Unavailable', self::rowText($xp, 'cron_failures'));
        $this->assertFalse($status->allHealthy());
    }

    #[Group('regression')]
    public function test_panel_neverRanJobRendersNoLastRanLine(): void
    {
        $xp = self::dom(self::renderHtml(self::build(['reconciliation' => self::job(CronJobEnabledState::ENABLED, null)])));

        $this->assertSame('Never run', self::rowText($xp, 'reconciliation'));
    }

    #[Group('regression')]
    public function test_panel_failureEntriesRenderEscapedUnderTheFailuresRow(): void
    {
        $status = self::build(['failureLog' => ['count' => 2, 'recent' => [
            ['loggedAt' => '2026-10-19 02:00:00', 'message' => "Job <b>x</b> failed"],
            ['loggedAt' => '2026-10-18 02:00:00', 'message' => 'Job y failed'],
        ]]]);

        $html = self::renderHtml($status);
        $xp   = self::dom($html);

        $items = $xp->query('//dd[@data-status-row="cron_failures"]//li');
        $this->assertNotFalse($items);
        $this->assertSame(2, $items->length);
        $this->assertStringContainsString('2026-10-19 02:00:00', (string) $items->item(0)?->textContent);
        $this->assertStringContainsString('Job <b>x</b> failed', (string) $items->item(0)?->textContent);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_panel_noFailureEntries_rendersNoList(): void
    {
        $xp = self::dom(self::renderHtml(self::build()));

        $this->assertSame(0, $xp->query('//dd[@data-status-row="cron_failures"]//li')?->length);
    }
}
