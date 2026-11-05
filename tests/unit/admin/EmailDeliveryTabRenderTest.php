<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\PartialRenderHarness;
use Tests\Support\ScriptedDeliveryDb;

/**
 * Render tests for the admin Email Delivery tab (#2102).
 *
 * The test includes the real tab file and reads the HTML as a DOM. The
 * database is a scripted fake, so the rows that DeliveryLogRepository reads are
 * known. The tab calls the global functions dbi() and hasPerm(), which the unit
 * tier does not define. Each test runs in its own process and installs them
 * there, so no other unit test sees them.
 *
 * Set EMAIL_DELIVERY_TAB_PATH to a copy of the tab to render another version.
 *
 * @issue 2102
 */
#[Group('fast')]
final class EmailDeliveryTabRenderTest extends TestCase
{
    use PartialRenderHarness;

    private const TAB = __DIR__ . '/../../../app/admin/includes/tab-email_delivery.php';

    private const URL_ROOT = '/';

    /**
     * Install the globals that the tab reads. Runs only in a test process.
     */
    private static function installGlobals(ScriptedDeliveryDb $db, bool $isAdmin): void
    {
        $GLOBALS['edTestDb'] = $db;
        $GLOBALS['edTestIsAdmin'] = $isAdmin;
        $GLOBALS['mockLogEntries'] = [];
        require_once __DIR__ . '/../../Support/email-delivery-tab-globals.php';
    }

    /**
     * Render the tab with a scripted database.
     *
     * @param list<array<string, mixed>> $rows  Rows for loadWindow()
     * @param array<string, string>      $query $_GET values
     */
    private static function render(array $rows, array $query = [], bool $isAdmin = true, ?ScriptedDeliveryDb $db = null): string
    {
        $db ??= new ScriptedDeliveryDb($rows);
        self::installGlobals($db, $isAdmin);
        $_GET = $query;
        $_POST = [];

        return self::renderPartial(self::TAB, 'EMAIL_DELIVERY_TAB_PATH', [
            'us_url_root'   => self::URL_ROOT,
            'currentUserId' => 7,
        ]);
    }

    /** @return array<string, mixed> A loadWindow() row. */
    private static function row(array $o = []): array
    {
        return $o + [
            'id'              => 1,
            'message_id'      => '<msg-1@brevo>',
            'recipient_email' => 'owner@example.com',
            'tags'            => 'verification',
            'accepted_at'     => '2026-11-04 10:00:00',
            'event_id'        => null,
            'event'           => null,
            'occurred_at'     => null,
            'reason'          => null,
            'user_id'         => null,
            'car_id'          => null,
        ];
    }

    private static function nodes(DOMXPath $xp, string $query): DOMNodeList
    {
        $nodes = $xp->query($query);
        self::assertNotFalse($nodes);

        return $nodes;
    }

    private static function byTestId(DOMXPath $xp, string $id): DOMNodeList
    {
        return self::nodes($xp, '//*[@data-testid="' . $id . '"]');
    }

    private static function text(DOMXPath $xp): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $xp->document->textContent)) ?? '';
    }

    #[RunInSeparateProcess]
    public function testEmptyResultShowsTheEmptyTextAndNoPercentage(): void
    {
        $html = self::render([]);
        $xp = self::dom($html);

        $empty = self::byTestId($xp, 'email-delivery-empty');
        $this->assertSame(1, $empty->length);
        $this->assertSame('No mail matches these filters', trim((string) $empty->item(0)?->textContent));
        $this->assertSame(0, self::byTestId($xp, 'email-delivery-table')->length);
        $this->assertSame(1, self::byTestId($xp, 'email-delivery-counts')->length);
        $this->assertStringNotContainsString('%', self::text($xp), 'The tab shows no percentage');
    }

    #[RunInSeparateProcess]
    public function testRowsWithEventsShowNoPercentage(): void
    {
        $xp = self::dom(self::render([
            self::row(['event_id' => 1, 'event' => 'delivered', 'occurred_at' => '2026-11-04 10:01:00']),
        ]));

        $this->assertSame(1, self::byTestId($xp, 'email-delivery-table')->length);
        $this->assertStringNotContainsString('%', self::text($xp));
    }

    #[RunInSeparateProcess]
    public function testPluginLinkPointsAtTheDeliveryLogsPageAndNamesMasterAccounts(): void
    {
        $xp = self::dom(self::render([]));

        $link = self::byTestId($xp, 'email-delivery-plugin-link');
        $this->assertSame(1, $link->length);
        $this->assertSame(
            '/usersc/plugins/sendinblue/delivery_logs.php',
            self::nodes($xp, '//a[@data-testid="email-delivery-plugin-link"]/@href')->item(0)?->nodeValue
        );
        $this->assertStringContainsString('master accounts only', self::text($xp));
    }

    #[RunInSeparateProcess]
    public function testRetentionTextNamesTheConfiguredDays(): void
    {
        // The unit bootstrap defines the constant as 90. A literal keeps the
        // expected text independent of the tab.
        $text = self::text(self::dom(self::render([])));

        $this->assertStringContainsString('retention window, 90 days.', $text);
    }

    #[RunInSeparateProcess]
    public function testCutoffPassedToTheQueryIsTheRetentionWindow(): void
    {
        $db = new ScriptedDeliveryDb([]);
        $before = new DateTimeImmutable('-90 days');
        self::render([], [], true, $db);
        $after = new DateTimeImmutable('-90 days');

        $this->assertNotNull($db->windowParams, 'The window query ran');
        $cutoff = new DateTimeImmutable((string) $db->windowParams[0]);
        $this->assertGreaterThanOrEqual($before->getTimestamp() - 1, $cutoff->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp() + 1, $cutoff->getTimestamp());
    }

    #[RunInSeparateProcess]
    public function testHostileAddressTagAndReasonAreEscaped(): void
    {
        $evil = '<script>alert(1)</script>';
        $html = self::render([
            self::row([
                'recipient_email' => $evil . '@example.com',
                'tags'            => $evil,
                'event_id'        => 5,
                'event'           => 'hard_bounce',
                'occurred_at'     => '2026-11-04 10:05:00',
                'reason'          => $evil,
                'user_id'         => 42,
            ]),
        ]);
        $xp = self::dom($html);

        $this->assertSame(0, self::nodes($xp, '//script')->length, 'No script element reaches the DOM');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;@example.com', $html);
        $this->assertStringContainsString($evil . '@example.com', self::text($xp), 'The visitor reads the address as text');
    }

    #[RunInSeparateProcess]
    public function testRowWithEventShowsOutcomeTimeReasonOwnerAndCarLinks(): void
    {
        $xp = self::dom(self::render([
            self::row([
                'event_id'    => 9,
                'event'       => 'hard_bounce',
                'occurred_at' => '2026-11-04 10:07:00',
                'reason'      => 'mailbox does not exist',
                'user_id'     => 42,
                'car_id'      => 1234,
            ]),
        ]));

        $cells = self::nodes($xp, '//table[@data-testid="email-delivery-table"]/tbody/tr/td');
        $this->assertSame(9, $cells->length);
        $cell = static fn(int $i): string => trim(preg_replace('/\s+/', ' ', (string) $cells->item($i)?->textContent) ?? '');

        $this->assertSame('owner@example.com', $cell(1));
        $this->assertSame('Hard bounce', $cell(5));
        $this->assertSame('2026-11-04 10:07', $cell(6));
        $this->assertSame('mailbox does not exist', $cell(7));
        $this->assertSame('<msg-1@brevo>', $cell(8));

        $tr = '//table[@data-testid="email-delivery-table"]/tbody/tr';
        $this->assertSame(
            '/app/admin/index.php?tab=owner-mgmt&owner_id=42',
            self::nodes($xp, $tr . '/td[3]/a/@href')->item(0)?->nodeValue
        );
        $this->assertSame(
            '/app/owner/cars/details.php?car_id=1234',
            self::nodes($xp, $tr . '/td[4]/a/@href')->item(0)?->nodeValue
        );
        $this->assertSame('1234', trim((string) self::nodes($xp, $tr . '/td[4]/a')->item(0)?->textContent));
    }

    #[RunInSeparateProcess]
    public function testEventWithUserIdZeroShowsTheOutcomeAndNoOwnerOrCarLink(): void
    {
        // An event for an address that no single user holds has user_id 0 (#2611).
        $xp = self::dom(self::render([
            self::row([
                'event_id'    => 9,
                'event'       => 'delivered',
                'occurred_at' => '2026-11-04 10:07:00',
                'user_id'     => 0,
                'car_id'      => 0,
            ]),
        ]));

        $tbody = '//table[@data-testid="email-delivery-table"]/tbody';
        $this->assertStringContainsString('Delivered', (string) self::nodes($xp, $tbody . '/tr')->item(0)?->textContent);
        $this->assertSame(0, self::nodes($xp, $tbody . '//a')->length);
        $this->assertStringNotContainsString('owner #0', (string) self::nodes($xp, $tbody)->item(0)?->textContent);
    }

    #[RunInSeparateProcess]
    public function testRowWithoutEventShowsNoOutcomeAndNoLinks(): void
    {
        $xp = self::dom(self::render([self::row()]));

        $row = self::nodes($xp, '//table[@data-testid="email-delivery-table"]/tbody/tr');
        $this->assertSame(1, $row->length);
        $this->assertStringContainsString('No outcome', (string) $row->item(0)?->textContent);
        $this->assertSame(0, self::nodes($xp, '//table[@data-testid="email-delivery-table"]/tbody//a')->length);
    }

    /** @return array<string, array{string, string}> */
    public static function eventLabelProvider(): array
    {
        return [
            'delivered'      => ['delivered', 'Delivered'],
            'hard bounce'    => ['hard_bounce', 'Hard bounce'],
            'soft bounce'    => ['soft_bounce', 'Soft bounce'],
            'blocked'        => ['blocked', 'Blocked'],
            'unsubscribed'   => ['unsubscribed', 'Unsubscribed'],
            'spam complaint' => ['spam', 'Spam complaint'],
        ];
    }

    #[DataProvider('eventLabelProvider')]
    #[RunInSeparateProcess]
    public function testEventNameShowsItsOutcomeLabelInTheBadge(string $event, string $label): void
    {
        $xp = self::dom(self::render([
            self::row(['event_id' => 1, 'event' => $event, 'occurred_at' => '2026-11-04 10:01:00']),
        ]));

        $badge = self::nodes($xp, '//table[@data-testid="email-delivery-table"]//span[contains(@class,"badge")]');
        $this->assertSame(1, $badge->length);
        $this->assertSame($label, trim((string) $badge->item(0)?->textContent));
    }

    #[RunInSeparateProcess]
    public function testRecipientFilterIsBoundIntoTheQuery(): void
    {
        $db = new ScriptedDeliveryDb([]);
        self::render([], ['email' => 'who@example.com', 'tag' => 'verification'], true, $db);

        $this->assertNotNull($db->windowParams);
        $this->assertContains('who@example.com', $db->windowParams);
        $this->assertContains('verification', $db->windowParams);
        $this->assertStringNotContainsString('who@example.com', (string) $db->windowSql, 'Values are bound, never inlined');
    }

    #[RunInSeparateProcess]
    public function testCombinedFiltersBindBothValuesAndShowOnlyTheDeliveredRow(): void
    {
        $db = new ScriptedDeliveryDb([
            self::row(['id' => 1, 'message_id' => '<m-delivered>', 'event_id' => 1, 'event' => 'delivered', 'occurred_at' => '2026-11-04 10:01:00']),
            self::row(['id' => 2, 'message_id' => '<m-bounce>', 'event_id' => 2, 'event' => 'hard_bounce', 'occurred_at' => '2026-11-04 10:02:00']),
            self::row(['id' => 3, 'message_id' => '<m-none>']),
        ]);
        $xp = self::dom(self::render([], [
            'email'   => 'who@example.com',
            'tag'     => 'verification',
            'outcome' => 'delivered',
        ], true, $db));

        $this->assertStringContainsString('m.recipient_email = ?', (string) $db->windowSql);
        $this->assertStringContainsString('FIND_IN_SET(?, m.tags) > 0', (string) $db->windowSql);
        $this->assertMatchesRegularExpression(
            '/m\.accepted_at >= \? AND m\.recipient_email = \? AND FIND_IN_SET\(\?, m\.tags\) > 0/',
            (string) $db->windowSql,
            'All filters must match together, not either one'
        );
        $this->assertNotNull($db->windowParams);
        $this->assertSame(['who@example.com', 'verification'], array_slice($db->windowParams, 1));

        $codes = self::nodes($xp, '//table[@data-testid="email-delivery-table"]/tbody/tr//code');
        $this->assertSame(1, $codes->length);
        $this->assertSame('<m-delivered>', $codes->item(0)?->textContent);
    }

    #[RunInSeparateProcess]
    public function testPagerLinksKeepTheFiltersAndPreviousOnPageOneHasNoLink(): void
    {
        $rows = [];
        for ($i = 1; $i <= 51; $i++) {
            $rows[] = self::row(['id' => $i, 'message_id' => "<m-{$i}>"]);
        }
        $xp = self::dom(self::render($rows, ['email' => 'who@example.com', 'tag' => 'verification', 'hours' => '5']));

        $pager = '//nav[@data-testid="email-delivery-pager"]';
        $href = (string) self::nodes($xp, $pager . '//a[normalize-space(.)="2"]/@href')->item(0)?->nodeValue;
        $this->assertStringStartsWith('?', $href);
        parse_str(substr($href, 1), $params);
        $this->assertSame([
            'tab'     => 'email-delivery',
            'email'   => 'who@example.com',
            'tag'     => 'verification',
            'outcome' => 'all',
            'hours'   => '5',
            'page'    => '2',
        ], $params);

        $this->assertSame(0, self::nodes($xp, $pager . '//a[normalize-space(.)="Previous"]')->length);
        $previous = self::nodes($xp, $pager . '//li[contains(@class,"disabled")]/span[@class="page-link"][normalize-space(.)="Previous"]');
        $this->assertSame(1, $previous->length);
        $this->assertSame(0, self::nodes($xp, $pager . '//*[@href][normalize-space(.)="Previous"]')->length);
    }

    #[RunInSeparateProcess]
    public function testOldPluginTableWithoutTagsHidesTheTagFilterAndColumn(): void
    {
        $db = new ScriptedDeliveryDb([self::row(['tags' => null])]);
        $db->hasTagsColumn = false;
        $xp = self::dom(self::render([], ['tag' => 'verification'], true, $db));

        $this->assertSame(0, self::nodes($xp, '//*[@id="emailDeliveryTag"]')->length);
        $this->assertStringContainsString('NULL AS tags', (string) $db->windowSql);
        $this->assertNotNull($db->windowParams);
        $this->assertNotContains('verification', $db->windowParams);
        $this->assertSame(1, self::nodes($xp, '//table[@data-testid="email-delivery-table"]')->length);
        $this->assertSame(0, self::nodes($xp, '//table[@data-testid="email-delivery-table"]//th[normalize-space(.)="Tags"]')->length);
        $this->assertSame(8, self::nodes($xp, '//table[@data-testid="email-delivery-table"]/tbody/tr/td')->length);
    }

    #[RunInSeparateProcess]
    public function testIgnoredInvalidEmailStaysInTheInputAndIsMarkedInvalid(): void
    {
        $db = new ScriptedDeliveryDb([]);
        $xp = self::dom(self::render([], ['email' => 'not-an-address'], true, $db));

        $input = self::nodes($xp, '//input[@id="emailDeliveryRecipient"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $input);
        $this->assertSame('not-an-address', $input->getAttribute('value'));
        $this->assertContains('is-invalid', explode(' ', $input->getAttribute('class')));
        $this->assertSame('emailDeliveryRecipientHelp', $input->getAttribute('aria-describedby'));
        $this->assertSame(1, self::nodes($xp, '//div[@id="emailDeliveryRecipientHelp"]')->length);
        $this->assertNotNull($db->windowParams);
        $this->assertNotContains('not-an-address', $db->windowParams, 'The tab ignores the invalid address');
    }

    #[RunInSeparateProcess]
    public function testNonAdminRendersNothingAndReadsNothing(): void
    {
        $db = new ScriptedDeliveryDb([self::row()]);
        $html = self::render([], [], false, $db);

        $this->assertSame('', trim($html));
        $this->assertSame([], $db->sqls, 'No query ran for a non-admin');
    }

    #[RunInSeparateProcess]
    public function testMissingPluginTableShowsThePluginMissingAlert(): void
    {
        $db = new ScriptedDeliveryDb([]);
        $db->tableExists = false;
        $xp = self::dom(self::render([], [], true, $db));

        $this->assertSame(1, self::byTestId($xp, 'email-delivery-plugin-missing')->length);
        $this->assertSame(0, self::byTestId($xp, 'email-delivery-table')->length);
        $this->assertSame(0, self::byTestId($xp, 'email-delivery-empty')->length);
    }

    #[RunInSeparateProcess]
    public function testDatabaseFaultShowsTheErrorAndLogsNoDriverText(): void
    {
        $db = new ScriptedDeliveryDb([]);
        $db->failTableCheckWith = "SQLSTATE secret-driver-text for leak@example.com";
        $html = self::render([], [], true, $db);
        $xp = self::dom($html);

        $this->assertSame(1, self::byTestId($xp, 'email-delivery-error')->length);
        $this->assertSame(0, self::byTestId($xp, 'email-delivery-plugin-missing')->length);
        $this->assertSame(0, self::byTestId($xp, 'email-delivery-table')->length);
        $this->assertStringNotContainsString('secret-driver-text', $html);
        $logs = $GLOBALS['mockLogEntries'];
        $this->assertCount(1, $logs);
        $this->assertStringNotContainsString('secret-driver-text', (string) $logs[0]['message']);
        $this->assertStringNotContainsString('leak@example.com', (string) $logs[0]['message']);
    }

    #[RunInSeparateProcess]
    public function testPagerAppearsOnlyWhenThereAreMoreThanOnePageOfMail(): void
    {
        $rows = [];
        for ($i = 1; $i <= 51; $i++) {
            $rows[] = self::row(['id' => $i, 'message_id' => "<m-{$i}>"]);
        }
        $xp = self::dom(self::render($rows));

        $this->assertSame(1, self::byTestId($xp, 'email-delivery-pager')->length);
        $this->assertSame(50, self::nodes($xp, '//table[@data-testid="email-delivery-table"]/tbody/tr')->length);
        $this->assertStringContainsString('Total: 51', self::text($xp));
    }
}
