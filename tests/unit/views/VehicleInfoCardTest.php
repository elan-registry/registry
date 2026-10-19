<?php

declare(strict_types=1);

use ElanRegistry\Car\EffectiveEmailFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rendered tests for the Sold row (issue #1900) and the Verified and Email on
 * file rows (issue #1897) of app/views/cars/_vehicle_info_card.php.
 *
 * Each test includes the card under ob_start() and parses the output with
 * DOMDocument/DOMXPath, so the assertions read the DOM and not the HTML text.
 */
#[Group('fast')]
#[Group('unit')]
final class VehicleInfoCardTest extends TestCase
{
    private const CARD = __DIR__ . '/../../../app/views/cars/_vehicle_info_card.php';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['mockLogEntries'] = [];
    }

    /** @return list<array<string, mixed>> */
    private static function logEntries(): array
    {
        $entries = $GLOBALS['mockLogEntries'] ?? [];
        self::assertIsArray($entries);

        return array_values($entries);
    }

    /**
     * Include a view file with the given variables and return its output.
     *
     * @param array<string, mixed> $vars
     */
    private static function render(string $file, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        // Lets a reviewer prove a test can fail: point the card at a copy of an older partial.
        $override = getenv('VEHICLE_INFO_CARD_PATH');
        if ($file === self::CARD && is_string($override) && $override !== '') {
            $file = $override;
        }
        // A PHP warning or deprecation inside the view is a defect, not noise:
        // PHPUnit would only list it as a warning and the test would still pass.
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
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><html><body><div id="root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    /** @return DOMElement */
    private function firstElement(DOMXPath $xp, string $query): DOMElement
    {
        $nodes = $xp->query($query);
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "No node for {$query}");
        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private function nodeCount(DOMXPath $xp, string $query): int
    {
        $nodes = $xp->query($query);
        $this->assertNotFalse($nodes);

        return $nodes->length;
    }

    /** Datetime string at a whole-day offset before now. */
    private static function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    /**
     * Build a car row. The default row is fresh through owner_last_updated, so
     * the card finds valid freshness dates and does not log.
     *
     * @param array<string, mixed> $overrides
     * @return object
     */
    private static function carData(array $overrides = []): object
    {
        return (object) array_merge([
            'year' => 1971, 'series' => 'S4', 'variant' => 'SE', 'type' => 'FHC',
            'chassis' => '7110123456', 'color' => 'Red', 'engine' => 'Twin Cam',
            'comments' => '', 'ctime' => '2020-01-02 03:04:05', 'mtime' => '2021-02-03 04:05:06',
            'solddate' => null, 'last_verified' => null,
            'owner_last_updated' => self::daysAgo(10),
        ], $overrides);
    }

    /** Dates far in the future are always fresh, so a fixed literal never goes stale. */
    private const FOREVER_FRESH = '2999-03-05 10:00:00';

    /** Render the card for a car and return the parsed DOM. */
    private static function card(object $car, array $vars = []): DOMXPath
    {
        return self::dom(self::render(self::CARD, array_merge(['carData' => $car], $vars)));
    }

    private const VERIFIED_DD = '//dt[starts-with(normalize-space(.),"Verified")]/following-sibling::dd[1]';
    private const EMAIL_DD = '//dt[starts-with(normalize-space(.),"Email on file")]/following-sibling::dd[1]';

    public function test_vehicleInfoCard_withSoldDate_showsSoldStampAndLongDate(): void
    {
        $xp = self::dom(self::render(self::CARD, [
            'carData'      => self::carData(['solddate' => '2025-03-14']),
            'purchaseDate' => null,
            'soldDate'     => new DateTime('2025-03-14'),
        ]));

        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Sold"]'));
        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Sold Date")]'));
        $dd = $this->firstElement($xp, '//dt[normalize-space(.)="Sold"]/following-sibling::dd[1]');
        $stamp = $xp->query('.//span[contains(@class,"er-badge--sold") and contains(@class,"er-badge--stamp")]', $dd);
        $this->assertNotFalse($stamp);
        $this->assertSame(1, $stamp->length);
        $this->assertStringContainsString('March 14, 2025', $dd->textContent);
    }

    public function test_vehicleInfoCard_withoutSoldDate_hasNoSoldRowAndNoBadge(): void
    {
        $xp = self::dom(self::render(self::CARD, [
            'carData'      => self::carData(),
            'purchaseDate' => new DateTime('2019-06-01'),
            'soldDate'     => null,
        ]));

        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Purchase Date"]'));
        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Sold")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[contains(@class,"er-badge--sold")]'));
    }

    public function test_vehicleInfoCard_withNoDates_hasNoSoldRow(): void
    {
        $xp = self::dom(self::render(self::CARD, ['carData' => self::carData()]));

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Sold")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[contains(@class,"er-badge--sold")]'));
        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Chassis"]'));
    }

    public function test_vehicleInfoCard_withoutCarData_rendersNothing(): void
    {
        $this->assertSame('', trim(self::render(self::CARD, ['soldDate' => new DateTime('2025-03-14')])));
        $this->assertCount(1, self::logEntries());
        $this->assertStringContainsString('null', (string) self::logEntries()[0]['message']);
    }

    public function test_vehicleInfoCard_withNonObjectCarData_logsOnce(): void
    {
        self::render(self::CARD, ['carData' => ['not' => 'an object']]);

        $this->assertCount(1, self::logEntries());
        $this->assertSame(
            \ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            self::logEntries()[0]['category']
        );
        $this->assertStringContainsString('array', (string) self::logEntries()[0]['message']);
    }

    public function test_vehicleInfoCard_withCarData_doesNotLog(): void
    {
        self::render(self::CARD, ['carData' => self::carData(), 'soldDate' => new DateTime('2025-03-14')]);

        $this->assertSame([], self::logEntries());
    }

    // ------------------------------------------------------------------
    // Registry dates (ctime, mtime)
    // ------------------------------------------------------------------

    private const ADDED_STRONG = '//small[normalize-space(.)="Added to Registry"]/following-sibling::strong[1]';
    private const UPDATED_STRONG = '//small[normalize-space(.)="Last Updated"]/following-sibling::strong[1]';

    public function test_registryDates_validDates_showShortDates(): void
    {
        $xp = self::card(self::carData());

        $this->assertSame('Jan 2, 2020', trim($this->firstElement($xp, self::ADDED_STRONG)->textContent));
        $this->assertSame('Feb 3, 2021', trim($this->firstElement($xp, self::UPDATED_STRONG)->textContent));
        $this->assertSame([], self::logEntries());
    }

    /** @return array<string, array{string}> */
    public static function registryDateColumnProvider(): array
    {
        return [
            'ctime' => ['ctime'],
            'mtime' => ['mtime'],
        ];
    }

    #[DataProvider('registryDateColumnProvider')]
    public function test_registryDates_zeroDate_isEmptyWithoutLog(string $column): void
    {
        $xp = self::card(self::carData([$column => '0000-00-00 00:00:00']));

        $query = $column === 'ctime' ? self::ADDED_STRONG : self::UPDATED_STRONG;
        $this->assertSame('', trim($this->firstElement($xp, $query)->textContent));
        $this->assertSame([], self::logEntries());
    }

    #[DataProvider('registryDateColumnProvider')]
    public function test_registryDates_badDate_isEmptyAndLogsCarIdAndColumn(string $column): void
    {
        $xp = self::card(self::carData(['id' => 812, $column => 'garbage']));

        $query = $column === 'ctime' ? self::ADDED_STRONG : self::UPDATED_STRONG;
        $this->assertSame('', trim($this->firstElement($xp, $query)->textContent));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(
            \ElanRegistry\LogCategories::LOG_CATEGORY_CAR_ERRORS,
            self::logEntries()[0]['category']
        );
        $this->assertStringContainsString(
            "_vehicle_info_card.php: car 812 has {$column} 'garbage', which is not a valid date.",
            (string) self::logEntries()[0]['message']
        );
    }

    // ------------------------------------------------------------------
    // Verified row (#1897)
    // ------------------------------------------------------------------

    public function test_verifiedRow_freshConfirmed_showsStampAndLastConfirmedLiteralDate(): void
    {
        $xp = self::card(self::carData(['last_verified' => self::FOREVER_FRESH, 'owner_last_updated' => self::daysAgo(900)]));

        $dd = $this->firstElement($xp, self::VERIFIED_DD);
        $this->assertSame(1, $xp->query('.//span[contains(@class,"er-badge--verified") and contains(@class,"er-badge--stamp")]', $dd)?->length);
        $this->assertStringContainsString('Last confirmed March 5, 2999', $dd->textContent);
        $this->assertStringNotContainsString('Current since', $dd->textContent);
        $this->assertSame([], self::logEntries());
    }

    public function test_verifiedRow_freshCurrent_showsStampAndCurrentSinceLiteralDate(): void
    {
        $xp = self::card(self::carData(['last_verified' => null, 'owner_last_updated' => self::FOREVER_FRESH]));

        $dd = $this->firstElement($xp, self::VERIFIED_DD);
        $this->assertSame(1, $xp->query('.//span[contains(@class,"er-badge--verified")]', $dd)?->length);
        $this->assertStringContainsString('Current since March 5, 2999', $dd->textContent);
        $this->assertStringNotContainsString('Last confirmed', $dd->textContent);
    }

    public function test_verifiedRow_stale_showsEmptySquareAndNotSpecifiedWithoutStamp(): void
    {
        $xp = self::card(self::carData(['owner_last_updated' => self::daysAgo(800)]));

        $dd = $this->firstElement($xp, self::VERIFIED_DD);
        $this->assertSame(1, $xp->query('.//i[contains(@class,"fa-square")]', $dd)?->length);
        $em = $xp->query('.//em[contains(@class,"text-muted")]', $dd);
        $this->assertNotFalse($em);
        $this->assertSame(1, $em->length);
        $this->assertSame('Not specified', trim((string) $em->item(0)?->textContent));
        $this->assertSame(0, $xp->query('.//*[contains(@class,"er-badge")]', $dd)?->length);
        $this->assertSame([], self::logEntries());
    }

    public function test_verifiedRow_360DaysInside_370DaysOutside(): void
    {
        $inside = self::card(self::carData(['owner_last_updated' => self::daysAgo(360)]));
        $outside = self::card(self::carData(['owner_last_updated' => self::daysAgo(370)]));

        $this->assertSame(1, $this->nodeCount($inside, '//*[contains(@class,"er-badge--verified")]'));
        $this->assertSame(0, $this->nodeCount($outside, '//*[contains(@class,"er-badge--verified")]'));
    }

    public function test_verifiedRow_soldCar_hasNoVerifiedRowAndKeepsSoldRow(): void
    {
        $xp = self::card(
            self::carData(['solddate' => '2025-03-14', 'owner_last_updated' => self::daysAgo(2)]),
            ['soldDate' => new DateTime('2025-03-14')]
        );

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Verified")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[contains(@class,"er-badge--verified")]'));
        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Sold"]'));
        $this->assertStringContainsString(
            'March 14, 2025',
            $this->firstElement($xp, '//dt[normalize-space(.)="Sold"]/following-sibling::dd[1]')->textContent
        );
    }

    public function test_verifiedRow_rendersWithNoPurchaseDateAndNoSoldDate(): void
    {
        $xp = self::card(self::carData(), ['purchaseDate' => null, 'soldDate' => null]);

        $this->assertSame(1, $this->nodeCount($xp, '//dt[starts-with(normalize-space(.),"Verified")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Purchase Date")]'));
        $this->assertSame(1, $this->nodeCount($xp, '//*[contains(normalize-space(.),"Ownership & History") and not(*[contains(normalize-space(.),"Ownership & History")])]'));
    }

    public function test_verifiedRow_sitsInDlRowWithColumnClasses(): void
    {
        $xp = self::card(self::carData());

        $this->assertSame(1, $this->nodeCount($xp, '//dl[contains(@class,"row")]/dt[contains(@class,"col-sm-4") and starts-with(normalize-space(.),"Verified")]'));
        $this->assertSame(1, $this->nodeCount($xp, '//dl[contains(@class,"row")]/dt[starts-with(normalize-space(.),"Verified")]/following-sibling::dd[1][contains(@class,"col-sm-8")]'));
    }

    public function test_verifiedRow_hasNoInputElement(): void
    {
        $xp = self::card(self::carData(), ['viewerIsRegistryAdmin' => true]);

        $this->assertSame(0, $this->nodeCount($xp, '//input | //select | //textarea | //form'));
    }

    public function test_verifiedRow_helpButton_isAccessible(): void
    {
        $xp = self::card(self::carData());

        $button = $this->firstElement($xp, '//dt[starts-with(normalize-space(.),"Verified")]/button[@type="button"]');
        $this->assertSame('tooltip', $button->getAttribute('data-bs-toggle'));
        $this->assertNotSame('', trim($button->getAttribute('data-bs-title')));
        $this->assertNotSame('', trim($button->getAttribute('aria-label')));
        $this->assertFalse($button->hasAttribute('aria-hidden'));
        $this->assertNotSame('-1', $button->getAttribute('tabindex'));
        $this->assertSame(
            "The owner confirmed, added, or updated this car's record in the last 12 months.",
            $button->getAttribute('data-bs-title')
        );
    }

    public function test_verifiedRow_noSessionGlobals_stillRenders(): void
    {
        $hadSession = array_key_exists('_SESSION', $GLOBALS);
        $hadUser = array_key_exists('user', $GLOBALS);
        $savedSession = $GLOBALS['_SESSION'] ?? null;
        $savedUser = $GLOBALS['user'] ?? null;
        unset($GLOBALS['_SESSION'], $GLOBALS['user']);
        try {
            $xp = self::card(self::carData());
        } finally {
            if ($hadSession) {
                $GLOBALS['_SESSION'] = $savedSession;
            }
            if ($hadUser) {
                $GLOBALS['user'] = $savedUser;
            }
        }

        $this->assertSame(1, $this->nodeCount($xp, '//*[contains(@class,"er-badge--verified")]'));
    }

    /** @return array<string, array{mixed}> */
    public static function badFreshnessProvider(): array
    {
        return [
            'owner int'          => [['owner_last_updated' => 5]],
            'owner null'         => [['owner_last_updated' => null]],
            'owner garbage'      => [['owner_last_updated' => 'garbage']],
            'last_verified int'  => [['last_verified' => 1700000000]],
            'last_verified junk' => [['last_verified' => 'garbage']],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('badFreshnessProvider')]
    public function test_verifiedRow_badFreshnessData_showsStaleTreatmentAndLogsOnce(array $override): void
    {
        $xp = self::card(self::carData($override));

        $dd = $this->firstElement($xp, self::VERIFIED_DD);
        $this->assertSame(0, $xp->query('.//*[contains(@class,"er-badge")]', $dd)?->length);
        $this->assertStringContainsString('Not specified', $dd->textContent);
        $this->assertCount(1, self::logEntries());
    }

    // ------------------------------------------------------------------
    // Email on file row (#1897)
    // ------------------------------------------------------------------

    private const SECRET_EMAIL = 'secret.owner@example.com';
    private const SECRET_BOUNCE = 'bounced.address@example.org';

    /**
     * The flags that apply to the owner now, as CarRepository::effectiveEmailFlags()
     * returns them (#2356). The keys name the stored columns, and a truthy
     * value (1 or '1') means the flag applies. The repository applies the
     * address match, tested in CarRepositoryTest, so the card only reads it.
     *
     * @param array<string, mixed> $overrides
     */
    private static function ownerState(array $overrides = []): EffectiveEmailFlags
    {
        $o = array_merge([
            'email_opted_out' => 0,
            'email_blocked' => 0,
            'email_blocked_address' => null,
            'email_bounced' => 0,
            'email_bounced_address' => null,
            'email_opted_out_at' => null,
            'email_blocked_at' => null,
            'email_bounced_at' => null,
        ], $overrides);

        return new EffectiveEmailFlags(
            true,
            (int) $o['email_opted_out'] === 1,
            $o['email_opted_out_at'],
            (int) $o['email_blocked'] === 1,
            $o['email_blocked_address'],
            $o['email_blocked_at'],
            null,
            (int) $o['email_bounced'] === 1,
            $o['email_bounced_address'],
            $o['email_bounced_at'],
            null
        );
    }

    /**
     * Card variables for a car whose owner has a bounce and/or a block. The
     * flags come from the owner profile (#2101). The car row holds only the
     * addresses that must never appear in the output.
     *
     * @return array<string, mixed>
     */
    private static function flaggedVars(bool $bounced, bool $blocked): array
    {
        return [
            'carData' => self::carData(['email' => self::SECRET_EMAIL]),
            'ownerEmailState' => self::ownerState([
                'email_bounced' => $bounced ? 1 : 0,
                'email_bounced_address' => $bounced ? self::SECRET_BOUNCE : null,
                'email_blocked' => $blocked ? 1 : 0,
                'email_blocked_address' => $blocked ? self::SECRET_BOUNCE : null,
            ]),
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function notStrictTrueProvider(): array
    {
        return [
            'unset'      => [[]],
            'false'      => [['viewerIsRegistryAdmin' => false]],
            'int 1'      => [['viewerIsRegistryAdmin' => 1]],
            'string "1"' => [['viewerIsRegistryAdmin' => '1']],
            'null'       => [['viewerIsRegistryAdmin' => null]],
        ];
    }

    /** @param array<string, mixed> $vars */
    #[DataProvider('notStrictTrueProvider')]
    public function test_emailRow_viewerNotStrictlyTrue_hasNoRow(array $vars): void
    {
        $html = self::render(self::CARD, array_merge(self::flaggedVars(true, true), $vars));
        $xp = self::dom($html);

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Email on file")]'));
        $this->assertStringNotContainsString('Bounced', $html);
        $this->assertStringNotContainsString('Blocked', $html);
        $this->assertStringNotContainsString('Opted out', $html);
    }

    /**
     * The row stays hidden when $viewerIsRegistryAdmin is false, also on a
     * flagged car. The card does not read user_id, so this test cannot tell
     * the car's owner from any other viewer. The Playwright test "Email on
     * file row: owner who is not an admin" in car-verified-row.spec.js covers
     * the real owner case.
     */
    public function test_emailRow_viewerNotRegistryAdmin_flaggedCar_hasNoRow(): void
    {
        $vars = self::flaggedVars(false, true);
        $vars['carData'] = self::carData(['email' => self::SECRET_EMAIL, 'user_id' => 42]);
        $xp = self::card($vars['carData'], array_merge($vars, ['viewerIsRegistryAdmin' => false]));

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Email on file")]'));
    }

    /**
     * An owner who is not an admin and a guest both get $viewerIsRegistryAdmin
     * false or unset. Every state is set, so only the viewer check hides the row.
     */
    public function test_emailRow_ownerAndGuest_seeNoStateNamesDatesOrTooltips(): void
    {
        $state = self::ownerState([
            'email_opted_out' => 1, 'email_opted_out_at' => '2025-03-05 10:00:00',
            'email_bounced' => 1, 'email_bounced_at' => '2024-01-09 10:00:00',
            'email_blocked' => 1, 'email_blocked_at' => '2023-12-31 10:00:00',
        ]);
        $car = self::carData(['user_id' => 42]);

        foreach (['owner' => ['viewerIsRegistryAdmin' => false], 'guest' => []] as $who => $vars) {
            $html = self::render(self::CARD, array_merge(['carData' => $car, 'ownerEmailState' => $state], $vars));
            $xp = self::dom($html);

            $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Email on file")]'), $who);
            $this->assertSame(0, $this->nodeCount($xp, '//button[contains(@aria-label,"Opted out") or contains(@aria-label,"Bounced") or contains(@aria-label,"Blocked") or contains(@aria-label,"Email on file")]'), $who);
            foreach (['Opted out', 'Bounced', 'Blocked', 'March 5, 2025', 'January 9, 2024', 'December 31, 2023'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$who}: {$needle}");
            }
        }
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function adminFlagProvider(): array
    {
        return [
            'bounced only'        => [['email_bounced' => 1], ['Bounced']],
            'blocked only'        => [['email_blocked' => 1], ['Blocked']],
            'opted out only'      => [['email_opted_out' => 1], ['Opted out']],
            'bounced and blocked' => [['email_bounced' => 1, 'email_blocked' => 1], ['Bounced', 'Blocked']],
            'all three'           => [['email_opted_out' => 1, 'email_bounced' => 1, 'email_blocked' => 1], ['Opted out', 'Bounced', 'Blocked']],
            'string flags'        => [['email_bounced' => '1', 'email_opted_out' => '1'], ['Opted out', 'Bounced']],
        ];
    }

    /**
     * @param array<string, mixed> $flags
     * @param list<string>         $expected The state names in display order
     */
    #[DataProvider('adminFlagProvider')]
    public function test_emailRow_admin_showsOnlyTheFlagsSetOnTheOwnerProfile(array $flags, array $expected): void
    {
        $xp = self::card(
            self::carData(),
            ['viewerIsRegistryAdmin' => true, 'ownerEmailState' => self::ownerState($flags)]
        );

        $this->assertSame($expected, $this->stateNames($xp));
        $this->assertSame(
            count($expected),
            $this->nodeCount($xp, self::EMAIL_DD . '//button[@aria-label]')
        );
        $this->assertSame(0, $this->nodeCount($xp, '//*[contains(.,"Suppressed")]'));
        $this->assertSame(1, $this->nodeCount($xp, '//dl[contains(@class,"row")]/dt[contains(@class,"col-sm-4") and starts-with(normalize-space(.),"Email on file")]'));
    }

    /**
     * The state names in the Email on file value, in document order. Each
     * name is the first word group of its span, before any date.
     *
     * @return list<string>
     */
    private function stateNames(DOMXPath $xp): array
    {
        $names = [];
        $spans = $xp->query(self::EMAIL_DD . '/span');
        $this->assertNotFalse($spans);
        foreach ($spans as $span) {
            $text = trim(preg_replace('/\s+/', ' ', $span->textContent) ?? '');
            $this->assertSame(1, preg_match('/^(Opted out|Bounced|Blocked)\b/', $text, $m), "Unexpected span text: {$text}");
            $names[] = $m[1];
        }

        return $names;
    }

    /** @return array<string, array{string, string, string}> */
    public static function stateDateProvider(): array
    {
        return [
            'opted out' => ['email_opted_out', 'Opted out', 'March 5, 2025'],
            'bounced'   => ['email_bounced', 'Bounced', 'January 9, 2024'],
            'blocked'   => ['email_blocked', 'Blocked', 'December 31, 2023'],
        ];
    }

    #[DataProvider('stateDateProvider')]
    public function test_emailRow_state_showsItsOwnDateInLongFormat(string $column, string $name, string $expectedDate): void
    {
        $dates = [
            'email_opted_out' => '2025-03-05 10:11:12',
            'email_bounced'   => '2024-01-09 00:00:00',
            'email_blocked'   => '2023-12-31 23:59:59',
        ];
        $xp = self::card(self::carData(), [
            'viewerIsRegistryAdmin' => true,
            'ownerEmailState' => self::ownerState([
                $column => 1,
                $column . '_at' => $dates[$column],
            ]),
        ]);

        $span = $this->firstElement($xp, self::EMAIL_DD . '/span');
        $this->assertSame("{$name} {$expectedDate}", trim(preg_replace('/\s+/', ' ', $span->textContent) ?? ''));
        $this->assertSame([], self::logEntries());
    }

    public function test_emailRow_allThreeStates_eachShowsItsOwnDate(): void
    {
        $xp = self::card(self::carData(), [
            'viewerIsRegistryAdmin' => true,
            'ownerEmailState' => self::ownerState([
                'email_opted_out' => 1, 'email_opted_out_at' => '2025-03-05 10:00:00',
                'email_bounced' => 1, 'email_bounced_at' => '2024-01-09 10:00:00',
                'email_blocked' => 1, 'email_blocked_at' => '2023-12-31 10:00:00',
            ]),
        ]);

        $texts = [];
        $spans = $xp->query(self::EMAIL_DD . '/span');
        $this->assertNotFalse($spans);
        foreach ($spans as $span) {
            $texts[] = trim(preg_replace('/\s+/', ' ', $span->textContent) ?? '');
        }
        $this->assertSame(
            ['Opted out March 5, 2025', 'Bounced January 9, 2024', 'Blocked December 31, 2023'],
            $texts
        );
    }

    /** @return array<string, array{string, string}> */
    public static function emptyDateProvider(): array
    {
        $out = [];
        foreach (['email_opted_out' => 'Opted out', 'email_bounced' => 'Bounced', 'email_blocked' => 'Blocked'] as $col => $name) {
            $out["{$name} NULL"]      = [$col, $name, null];
            $out["{$name} empty"]     = [$col, $name, ''];
            $out["{$name} zero date"] = [$col, $name, '0000-00-00 00:00:00'];
        }

        return $out;
    }

    #[DataProvider('emptyDateProvider')]
    public function test_emailRow_stateWithNoDate_showsNameOnlyAndDoesNotLog(string $column, string $name, ?string $at): void
    {
        $xp = self::card(self::carData(), [
            'viewerIsRegistryAdmin' => true,
            'ownerEmailState' => self::ownerState([$column => 1, $column . '_at' => $at]),
        ]);

        $span = $this->firstElement($xp, self::EMAIL_DD . '/span');
        $this->assertSame($name, trim(preg_replace('/\s+/', ' ', $span->textContent) ?? ''));
        $this->assertDoesNotMatchRegularExpression('/\d/', $span->textContent);
        $this->assertSame([], self::logEntries());
    }

    /** A date that DateTime cannot parse shows no date, logs once, and puts no markup in the output. */
    public function test_emailRow_stateWithUnparsableDate_showsNoDateAndLogsOnce(): void
    {
        $html = self::render(self::CARD, [
            'carData' => self::carData(),
            'viewerIsRegistryAdmin' => true,
            'ownerEmailState' => self::ownerState(['email_bounced' => 1, 'email_bounced_at' => '<script>x</script>"\'']),
        ]);
        $xp = self::dom($html);

        $this->assertSame(['Bounced'], $this->stateNames($xp));
        $this->assertSame(0, $this->nodeCount($xp, '//script'));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertCount(1, self::logEntries());
        $this->assertStringContainsString('email_bounced_at', (string) self::logEntries()[0]['message']);
    }

    public function test_emailRow_adminNoFlagOnTheProfile_hasNoRow(): void
    {
        $xp = self::card(self::carData(), ['viewerIsRegistryAdmin' => true, 'ownerEmailState' => self::ownerState()]);

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Email on file")]'));
    }

    /** No profile state (no profile row, or a failed lookup) hides the row. */
    public function test_emailRow_adminWithNoOwnerState_hasNoRow(): void
    {
        $xp = self::card(self::carData(), ['viewerIsRegistryAdmin' => true, 'ownerEmailState' => null]);

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Email on file")]'));
    }

    /** An owner with no profile row has no flag, so the row is hidden. */
    public function test_emailRow_adminWithNoProfileOwner_hasNoRow(): void
    {
        $xp = self::card(
            self::carData(),
            ['viewerIsRegistryAdmin' => true, 'ownerEmailState' => EffectiveEmailFlags::none()]
        );

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Email on file")]'));
    }

    /** Only an EffectiveEmailFlags counts. A raw profile object, with stored columns, shows no row. */
    public function test_emailRow_ignoresARawProfileObject(): void
    {
        $xp = self::card(
            self::carData(),
            ['viewerIsRegistryAdmin' => true, 'ownerEmailState' => (object) ['email_bounced' => 1, 'email_blocked' => 1, 'email_opted_out' => 1]]
        );

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Email on file")]'));
    }

    /**
     * The profile is the only source. A car row that still holds the removed
     * per-car columns must not show a flag.
     */
    public function test_emailRow_ignoresPerCarFlagColumns(): void
    {
        $xp = self::card(
            self::carData(['email_bounced' => 1, 'email_blocked' => 1, 'email_opted_out' => 1]),
            ['viewerIsRegistryAdmin' => true, 'ownerEmailState' => self::ownerState()]
        );

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Email on file")]'));
    }

    private function tooltip(DOMXPath $xp, string $ariaLabel): string
    {
        return $this->firstElement($xp, '//button[@aria-label="' . $ariaLabel . '"]')->getAttribute('data-bs-title');
    }

    private static function allThreeVars(): array
    {
        return [
            'carData' => self::carData(),
            'viewerIsRegistryAdmin' => true,
            'ownerEmailState' => self::ownerState([
                'email_opted_out' => 1, 'email_bounced' => 1, 'email_blocked' => 1,
            ]),
        ];
    }

    public function test_emailRow_tooltips_areDistinctAndNamedForTheirState(): void
    {
        $vars = self::allThreeVars();
        $xp = self::card($vars['carData'], $vars);

        $optedOut = $this->tooltip($xp, 'What Opted out means');
        $bounced = $this->tooltip($xp, 'What Bounced means');
        $blocked = $this->tooltip($xp, 'What Blocked means');
        $label = $this->tooltip($xp, 'What Email on file means');

        $this->assertCount(4, array_unique([$optedOut, $bounced, $blocked, $label]));
        $this->assertSame(
            'The owner stopped all registry email except account email. The owner can lift the opt-out with '
            . 'Resume registry email in Account Settings, or an admin can lift it.',
            $optedOut
        );
        $this->assertSame(
            "Email to this owner's address bounced. The registry does not send verification email, messages from other owners, "
            . 'or messages from an admin to this owner. The owner can lift the bounce by confirming a different email address, '
            . 'or an admin can lift it.',
            $bounced
        );
        $this->assertSame(
            "This owner's address is blocked. The registry does not send verification email or messages from other owners to this owner. "
            . 'A message from an admin is still sent. The owner can lift the block by confirming a different email address, '
            . 'or an admin can lift it.',
            $blocked
        );
        $this->assertSame(
            'Only admins and editors see this row. It shows why the registry does not send verification email '
            . 'or messages from other owners to this owner.',
            $label
        );
    }

    public function test_emailRow_noTooltipNamesClear(): void
    {
        $vars = self::allThreeVars();
        $xp = self::card($vars['carData'], $vars);

        $buttons = $xp->query(
            '//dt[starts-with(normalize-space(.),"Email on file")]//button | ' . self::EMAIL_DD . '//button'
        );
        $this->assertNotFalse($buttons);
        $this->assertSame(4, $buttons->length);
        foreach ($buttons as $button) {
            $this->assertInstanceOf(DOMElement::class, $button);
            $this->assertStringNotContainsString('Clear', $button->getAttribute('data-bs-title'));
            $this->assertStringNotContainsString('Suppress', $button->getAttribute('data-bs-title'));
        }
    }

    /** The apostrophe in a tooltip must leave the attribute as an entity, so it cannot end the attribute early. */
    public function test_emailRow_tooltipsAreAttributeEscapedInRawHtml(): void
    {
        $vars = self::allThreeVars();
        $html = self::render(self::CARD, $vars);

        $this->assertSame(
            1,
            preg_match_all('/data-bs-title="([^"]*)"/', $html, $m, PREG_SET_ORDER) > 0 ? 1 : 0
        );
        $emailTitles = array_filter(
            array_column($m, 1),
            static fn (string $t): bool => str_contains($t, 'owner') && str_contains($t, 'registry')
        );
        $this->assertNotEmpty($emailTitles);
        foreach ($emailTitles as $title) {
            $this->assertStringNotContainsString("'", $title);
        }
        $this->assertStringContainsString("Email to this owner&#039;s address bounced.", $html);
        $this->assertStringContainsString("This owner&#039;s address is blocked.", $html);
        $this->assertStringNotContainsString("owner's address", $html);
    }

    public function test_emailRow_soldCarAsAdmin_withFlag_showsRowAndNoVerifiedRow(): void
    {
        $vars = self::flaggedVars(true, false);
        $vars['carData'] = self::carData(['email' => self::SECRET_EMAIL, 'solddate' => '2025-03-14']);
        $xp = self::card(
            $vars['carData'],
            array_merge($vars, ['viewerIsRegistryAdmin' => true, 'soldDate' => new DateTime('2025-03-14')])
        );

        $this->assertSame(1, $this->nodeCount($xp, '//dt[starts-with(normalize-space(.),"Email on file")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Verified")]'));
    }

    /** @return array<string, array{bool}> */
    public static function viewerProvider(): array
    {
        return ['admin' => [true], 'non-admin' => [false]];
    }

    #[DataProvider('viewerProvider')]
    public function test_emailRow_neverOutputsEmailOrBouncedAddress(bool $admin): void
    {
        $html = self::render(self::CARD, array_merge(self::flaggedVars(true, true), [
            'viewerIsRegistryAdmin' => $admin,
        ]));

        $this->assertStringNotContainsString(self::SECRET_EMAIL, $html);
        $this->assertStringNotContainsString(self::SECRET_BOUNCE, $html);
        $this->assertStringNotContainsString('secret.owner', $html);
        $this->assertStringNotContainsString('bounced.address', $html);
    }
}
