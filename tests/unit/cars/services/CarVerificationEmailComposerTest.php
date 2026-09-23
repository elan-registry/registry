<?php

declare(strict_types=1);

use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\EmailTemplate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarVerificationEmailComposer (#1882/#1883).
 *
 * No DB access — the composer takes plain stdClass fixtures shaped exactly
 * like verify_car.php's own $carData/$owner objects (see that file's
 * verifyHistoryFields() and the composer's own compose() docblock) and an
 * EmailTemplate instance, which itself only needs getBaseUrl() (mocked in
 * tests/bootstrap-unit.php).
 *
 * XSS coverage is the #1 requirement here (#1882's core acceptance
 * criterion): every free-form value must render escaped and a raw
 * `<script>` tag must never appear in the output.
 */
#[Group('fast')]
final class CarVerificationEmailComposerTest extends TestCase
{
    private CarVerificationEmailComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new CarVerificationEmailComposer(new EmailTemplate());
    }

    /**
     * Full, valid fixture matching every property verify_car.php's dispatch
     * section builds for $carData (see verifyHistoryFields()'s field list and
     * CarVerificationEmailComposer::compose()'s docblock).
     *
     * @return object{
     *   id: int, year: int, type: string, chassis: string, chassis_override: int,
     *   series: string, variant: string, color: string, purchasedate: string,
     *   solddate: ?string, image: ?string, website: string, email_suppressed: int
     * }
     */
    private function carFixture(array $overrides = []): object
    {
        return (object) array_merge([
            'id'                => 42,
            'year'              => 1969,
            'type'              => 'FHC',
            'chassis'           => '45/1234567',
            'chassis_override'  => 0,
            'series'            => 'S4',
            'variant'           => 'SE',
            'color'             => 'British Racing Green',
            'purchasedate'      => '2015-05-01',
            'solddate'          => null,
            'image'             => json_encode(['a.jpg', 'b.jpg']),
            'website'           => 'https://example.com/my-elan',
            'email_suppressed'  => 0,
        ], $overrides);
    }

    /**
     * @return object{
     *   id: int, fname: string, lname: string, email: string,
     *   city: string, state: string, country: string, join_date: string
     * }
     */
    private function ownerFixture(array $overrides = []): object
    {
        return (object) array_merge([
            'id'        => 7,
            'fname'     => 'Jane',
            'lname'     => 'Doe',
            'email'     => 'jane@example.com',
            'city'      => 'Portland',
            'state'     => 'OR',
            'country'   => 'USA',
            'join_date' => '2020-01-15',
        ], $overrides);
    }

    private const VERICODE = 'abcdef0123456789abcdef0123456789';

    // ------------------------------------------------------------------
    // 1. Basic shape
    // ------------------------------------------------------------------

    public function testComposeReturnsSubjectAndHtmlKeys(): void
    {
        $result = $this->composer->compose($this->carFixture(), $this->ownerFixture(), self::VERICODE);

        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('html', $result);
        $this->assertIsString($result['subject']);
        $this->assertIsString($result['html']);
        $this->assertStringStartsWith('<!DOCTYPE html>', $result['html']);
        $this->assertStringContainsString('</html>', $result['html']);
        $this->assertNotSame('', trim($result['subject']));
    }

    // ------------------------------------------------------------------
    // 2. Content block order
    // ------------------------------------------------------------------

    public function testContentBlocksAppearInCorrectOrder(): void
    {
        $html = $this->composer->compose($this->carFixture(), $this->ownerFixture(), self::VERICODE)['html'];

        $greeting  = strpos($html, 'Hello');
        $signoff   = strpos($html, 'Jim, aka The Registrar');
        $buttonRow = strpos($html, '>Verify<');
        $ownerBox  = strpos($html, 'Owner Information');
        $carBox    = strpos($html, 'Car Information');
        $cta       = strpos($html, 'Login and Review or Update Your Car Record');
        $footer    = strpos($html, 'Stop sending me these');
        $expiry    = strpos($html, 'These links are valid for');

        foreach (['greeting' => $greeting, 'signoff' => $signoff, 'buttonRow' => $buttonRow,
                  'ownerBox' => $ownerBox, 'carBox' => $carBox, 'cta' => $cta,
                  'footer' => $footer, 'expiry' => $expiry] as $name => $pos) {
            $this->assertIsInt($pos, "Expected block '{$name}' to be present in the rendered HTML");
        }

        $this->assertTrue($greeting < $signoff, 'greeting must precede sign-off');
        $this->assertTrue($signoff < $buttonRow, 'sign-off must precede the Verify/Sold button row');
        $this->assertTrue($buttonRow < $ownerBox, 'button row must precede Owner Information');
        $this->assertTrue($ownerBox < $carBox, 'Owner Information must precede Car Information');
        $this->assertTrue($carBox < $cta, 'Car Information must precede the CTA button');
        $this->assertTrue($cta < $footer, 'CTA must precede the footer opt-out link');
        $this->assertTrue($footer < $expiry, 'footer must precede the expiry notice');
    }

    // ------------------------------------------------------------------
    // 3. Verify + Sold render as one createButtonRow() table
    // ------------------------------------------------------------------

    public function testVerifyAndSoldRenderInsideOneButtonRowTable(): void
    {
        $html = $this->composer->compose($this->carFixture(), $this->ownerFixture(), self::VERICODE)['html'];

        // The Review & Update CTA also uses <a class="btn btn-primary">, so
        // isolate the specific two-cell button-row table by locating it via
        // its btn-row-cell markers rather than counting all <table> tags.
        // The footer's "Stop sending me these" opt-out <a> also carries no
        // btn-row-cell class, so this count is specific to createButtonRow().
        $rowCellCount = substr_count($html, 'class="btn-row-cell"');
        $this->assertSame(
            2,
            $rowCellCount,
            'Verify and Sold must render as exactly 2 btn-row-cell entries from one createButtonRow() table'
        );

        // Both buttons must be present as button-row cells (createButtonRow()'s
        // own markup, distinct from createButton()'s single-button <div> wrapper
        // used elsewhere for the CTA).
        $this->assertStringContainsString('>Verify<', $html);
        $this->assertStringContainsString('>Sold<', $html);

        // Neither Owner Information nor Car Information (the next two blocks)
        // may appear between the two btn-row-cell entries — proving they sit
        // in one row/table rather than two stacked createButton() blocks with
        // other content interleaved.
        $firstCell  = strpos($html, 'class="btn-row-cell"');
        $secondCell = strpos($html, 'class="btn-row-cell"', $firstCell + 1);
        $this->assertIsInt($firstCell);
        $this->assertIsInt($secondCell);
        $between = substr($html, $firstCell, $secondCell - $firstCell);
        $this->assertStringNotContainsString('Owner Information', $between);
        $this->assertStringNotContainsString('Car Information', $between);
    }

    // ------------------------------------------------------------------
    // 4. XSS: owner lname
    // ------------------------------------------------------------------

    public function testOwnerLastNameXssIsEscaped(): void
    {
        $owner = $this->ownerFixture(['lname' => 'Smith<script>alert(1)</script>']);
        $html = $this->composer->compose($this->carFixture(), $owner, self::VERICODE)['html'];

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(
            htmlspecialchars('Smith<script>alert(1)</script>', ENT_QUOTES, 'UTF-8'),
            $html
        );
    }

    // ------------------------------------------------------------------
    // 5. XSS: car website (website lives on $carData, not $owner)
    // ------------------------------------------------------------------

    public function testCarWebsiteXssIsEscaped(): void
    {
        $malicious = '"><script>alert(1)</script>';
        $car = $this->carFixture(['website' => $malicious]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(htmlspecialchars($malicious, ENT_QUOTES, 'UTF-8'), $html);
    }

    // ------------------------------------------------------------------
    // 6. XSS: chassis, both override paths
    // ------------------------------------------------------------------

    public function testChassisXssIsEscapedWhenOverridden(): void
    {
        $malicious = '45/1<script>alert(1)</script>"\'';
        $car = $this->carFixture(['chassis' => $malicious, 'chassis_override' => 1]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(htmlspecialchars($malicious, ENT_QUOTES, 'UTF-8'), $html);
        // The override path still renders the badge.
        $this->assertStringContainsString('Please double-check', $html);
    }

    public function testChassisXssIsEscapedWhenNotOverridden(): void
    {
        $malicious = '45/1<script>alert(1)</script>"\'';
        $car = $this->carFixture(['chassis' => $malicious, 'chassis_override' => 0]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(htmlspecialchars($malicious, ENT_QUOTES, 'UTF-8'), $html);
    }

    // ------------------------------------------------------------------
    // 7. Greeting uses fname
    // ------------------------------------------------------------------

    public function testGreetingUsesFirstName(): void
    {
        $owner = $this->ownerFixture(['fname' => 'Zaphod']);
        $html = $this->composer->compose($this->carFixture(), $owner, self::VERICODE)['html'];

        $this->assertStringContainsString('Hello <strong>Zaphod</strong>,', $html);
    }

    // ------------------------------------------------------------------
    // 8. Owner Information field labels
    // ------------------------------------------------------------------

    public function testOwnerInformationBoxContainsAllRequiredLabels(): void
    {
        $html = $this->composer->compose($this->carFixture(), $this->ownerFixture(), self::VERICODE)['html'];

        foreach (['User ID', 'First Name', 'Last Name', 'Email', 'City', 'State', 'Country', 'Join Date'] as $label) {
            $this->assertStringContainsString($label . ':', $html, "Owner Information must contain label '{$label}'");
        }
    }

    // ------------------------------------------------------------------
    // 9. Car Information field labels
    // ------------------------------------------------------------------

    public function testCarInformationBoxContainsAllRequiredLabels(): void
    {
        $html = $this->composer->compose($this->carFixture(), $this->ownerFixture(), self::VERICODE)['html'];

        foreach ([
            'Car ID', 'Year', 'Type', 'Chassis', 'Series', 'Variant', 'Color',
            'Purchase Date', 'Sold Date', 'Photos', 'Website',
        ] as $label) {
            $this->assertStringContainsString($label . ':', $html, "Car Information must contain label '{$label}'");
        }
    }

    // ------------------------------------------------------------------
    // 10. Exactly one CTA, href resolves to edit page with correct car id
    // ------------------------------------------------------------------

    public function testExactlyOneReviewAndUpdateButtonWithCorrectEditUrl(): void
    {
        $car = $this->carFixture(['id' => 999]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $count = substr_count($html, 'Login and Review or Update Your Car Record');
        $this->assertSame(1, $count, 'Exactly one "Login and Review or Update Your Car Record" CTA must be present');

        $expectedUrl = $this->composer->editUrl(999);
        $this->assertStringContainsString(
            htmlspecialchars($expectedUrl, ENT_QUOTES, 'UTF-8'),
            $html
        );
        $this->assertStringContainsString('app/owner/cars/edit.php?car_id=999', $expectedUrl);
    }

    // ------------------------------------------------------------------
    // 11. Footer opt-out anchor
    // ------------------------------------------------------------------

    public function testFooterContainsOptOutAnchorWithActionOptoutVericodeAndNoTrackClass(): void
    {
        $html = $this->composer->compose($this->carFixture(), $this->ownerFixture(), self::VERICODE)['html'];

        $expectedHref = $this->composer->optOutUrl(self::VERICODE);
        $this->assertStringContainsString('action=optout', $expectedHref);
        $this->assertStringContainsString(self::VERICODE, $expectedHref);

        $this->assertStringContainsString(
            htmlspecialchars($expectedHref, ENT_QUOTES, 'UTF-8'),
            $html
        );
        $this->assertStringContainsString(
            'class="' . CarVerificationEmailComposer::NO_TRACK_LINK_CLASS . '"',
            $html
        );
        $this->assertStringContainsString('Stop sending me these', $html);
    }

    // ------------------------------------------------------------------
    // 12. Expiry notice
    // ------------------------------------------------------------------

    public function testExpiryNoticeStatesSixtyDaysAndReusability(): void
    {
        $html = $this->composer->compose($this->carFixture(), $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('valid for 60 days', $html);
        $this->assertStringContainsString('more than once', $html);
    }

    // ------------------------------------------------------------------
    // 13. Drift guard
    // ------------------------------------------------------------------

    /**
     * CarVerificationEmailComposer::LINK_TTL_DAYS must stay equal to
     * VERIFY_LINK_TTL_DAYS in app/verify/verify_car.php (currently 60,
     * confirmed by grep). If either constant changes without the other, this
     * test — and the composer's own expiry-notice copy — will drift out of
     * sync with the landing page's actual enforcement.
     */
    public function testLinkTtlDaysIsSixtyAndMatchesVerifyCarPhp(): void
    {
        $verifyCarSource = file_get_contents(__DIR__ . '/../../../../app/verify/verify_car.php');
        $this->assertIsString($verifyCarSource);
        $this->assertMatchesRegularExpression(
            '/const\s+VERIFY_LINK_TTL_DAYS\s*=\s*' . CarVerificationEmailComposer::LINK_TTL_DAYS . '\s*;/',
            $verifyCarSource,
            'CarVerificationEmailComposer::LINK_TTL_DAYS must equal VERIFY_LINK_TTL_DAYS in app/verify/verify_car.php'
        );
    }

    // ------------------------------------------------------------------
    // 14. Missing/null fields render fallback text
    // ------------------------------------------------------------------

    public function testMissingCarFieldsRenderNotSpecifiedFallback(): void
    {
        $car = $this->carFixture([
            'series'       => null,
            'variant'      => '',
            'purchasedate' => null,
            'solddate'     => null,
        ]);

        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('Not specified', $html);
        $this->assertStringNotContainsString('>null<', $html);
        $this->assertStringNotContainsString('&gt;null&lt;', $html);
    }

    public function testMissingCarFieldsDoNotProduceNullLiteralOrNotices(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $car = $this->carFixture(['series' => null, 'variant' => null, 'color' => null]);
            $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];
            $this->assertStringNotContainsString('"null"', $html);
        } finally {
            restore_error_handler();
        }
    }

    // ------------------------------------------------------------------
    // 15. URL builder shapes
    // ------------------------------------------------------------------

    public function testUrlBuildersProduceExpectedQueryStringShapes(): void
    {
        $verify  = $this->composer->verifyUrl(self::VERICODE);
        $sold    = $this->composer->soldUrl(self::VERICODE);
        $optOut  = $this->composer->optOutUrl(self::VERICODE);
        $edit    = $this->composer->editUrl(123);

        $this->assertStringContainsString('/app/verify/verify_car.php?vericode=' . self::VERICODE . '&action=verify', $verify);
        $this->assertStringContainsString('/app/verify/verify_car.php?vericode=' . self::VERICODE . '&action=sold', $sold);
        $this->assertStringContainsString('/app/verify/verify_car.php?vericode=' . self::VERICODE . '&action=optout', $optOut);
        $this->assertStringContainsString('/app/owner/cars/edit.php?car_id=123', $edit);
    }

    // ------------------------------------------------------------------
    // 16. Conditional chassis-mismatch content
    // ------------------------------------------------------------------

    public function testChassisOverrideBadgeAndExplainerBoxPresentWhenOverridden(): void
    {
        $car = $this->carFixture(['chassis_override' => 1]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('Please double-check', $html, 'Badge must be present when chassis_override=1');
        $this->assertStringContainsString('About the Chassis Number', $html, 'Explainer box must be present when chassis_override=1');
    }

    public function testChassisOverrideBadgeAndExplainerBoxAbsentWhenNotOverridden(): void
    {
        $car = $this->carFixture(['chassis_override' => 0]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('Please double-check', $html, 'Badge must be absent when chassis_override=0');
        $this->assertStringNotContainsString('About the Chassis Number', $html, 'Explainer box must be absent when chassis_override=0');
    }

    public function testChassisOverrideBadgeAndExplainerBoxAbsentWhenPropertyMissing(): void
    {
        $car = $this->carFixture();
        unset($car->chassis_override);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('Please double-check', $html);
        $this->assertStringNotContainsString('About the Chassis Number', $html);
    }

    // ------------------------------------------------------------------
    // 17. Conditional blank-website content
    // ------------------------------------------------------------------

    public function testBlankWebsiteHighlightedRowAndCalloutPresentWhenBlank(): void
    {
        $car = $this->carFixture(['website' => '']);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('Not yet provided', $html);
        // Highlighted-row styling per EmailTemplate::createDetailRow()'s $highlighted contract.
        $this->assertStringContainsString('#FFF9E0', $html);
        $this->assertStringContainsString('#B8860B', $html);
        $this->assertStringContainsString('still blank', $html, '"field still blank" callout must be present');
    }

    public function testBlankWebsiteHighlightedRowAndCalloutAbsentWhenNonBlank(): void
    {
        $car = $this->carFixture(['website' => 'https://example.com']);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('Not yet provided', $html);
        $this->assertStringNotContainsString('still blank', $html);
    }

    public function testWebsiteHighlightedRowEscapesMaliciousValueEvenWhenNonBlank(): void
    {
        $malicious = '"><script>alert(1)</script>';
        $car = $this->carFixture(['website' => $malicious]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(htmlspecialchars($malicious, ENT_QUOTES, 'UTF-8'), $html);
        // Non-blank website must not get the highlighted-row treatment.
        $this->assertStringNotContainsString('still blank', $html);
    }

    public function testBlankColorGetsHighlightedRowAndSingularCallout(): void
    {
        $car = $this->carFixture(['color' => '']);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('#FFF9E0', $html);
        $this->assertMatchesRegularExpression(
            '/1 field above is still blank.{0,20}Color, highlighted above/s',
            $html
        );
    }

    public function testMultipleBlankFieldsAllHighlightedAndAllNamedInOneCallout(): void
    {
        $car = $this->carFixture(['color' => '', 'purchasedate' => '', 'website' => '']);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        // Exactly one callout, not one per field.
        $this->assertSame(1, substr_count($html, 'still blank'));
        $this->assertMatchesRegularExpression(
            '/3 fields above are still blank.{0,60}Color, Purchase Date and Website, highlighted above/s',
            $html
        );
    }

    public function testStructuralFieldsNeverGetHighlightedEvenWhenBlank(): void
    {
        // Year/Type/Chassis/Series are near-mandatory identifiers, not
        // optional details — a car record with these blank is a data
        // problem, not something the email should invite the owner to
        // casually fill in the way it does for Color/Variant/Purchase
        // Date/Website.
        $car = $this->carFixture(['year' => null, 'type' => '', 'series' => '']);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('still blank', $html);
        $this->assertStringNotContainsString('#FFF9E0', $html);
    }

    // ------------------------------------------------------------------
    // 18. Structured-input hardening — missing properties
    // ------------------------------------------------------------------

    /**
     * The composer reads every $carData/$owner property via `??` (see
     * fieldOrDefault()'s '?? null' call sites and the direct '?? ""'/'?? 0'
     * usages throughout compose()/ownerRows()/carRows()), so a completely
     * missing property (not merely null) is expected to render the same
     * 'Not specified' fallback rather than throwing or emitting a PHP
     * warning for undefined property access.
     */
    public function testComposeHandlesOwnerMissingEmailPropertyGracefully(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $owner = $this->ownerFixture();
            unset($owner->email);

            $result = $this->composer->compose($this->carFixture(), $owner, self::VERICODE);

            $this->assertStringContainsString('Not specified', $result['html']);
        } finally {
            restore_error_handler();
        }
    }

    public function testComposeHandlesCarMissingChassisOverridePropertyGracefully(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $car = $this->carFixture();
            unset($car->chassis_override);

            $result = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE);

            $this->assertStringNotContainsString('About the Chassis Number', $result['html']);
        } finally {
            restore_error_handler();
        }
    }

    public function testComposeHandlesCarMissingWebsitePropertyGracefully(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $car = $this->carFixture();
            unset($car->website);

            $result = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE);

            $this->assertStringContainsString('Not yet provided', $result['html']);
        } finally {
            restore_error_handler();
        }
    }

    // ------------------------------------------------------------------
    // 19. Photos row — thumbnail + link vs. plain-text fallback
    // ------------------------------------------------------------------

    public function testPhotosRowRendersThumbnailAndViewAllLinkWhenPhotosExist(): void
    {
        $car = $this->carFixture(['id' => 2203, 'image' => json_encode(['abc123.jpg', 'def456.jpg', 'ghi789.jpg'])]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('abc123-resized-100.jpg', $html);
        $this->assertStringContainsString('/userimages/2203/', $html);
        $this->assertStringContainsString('View all 3 photos', $html);
        $this->assertStringContainsString('/app/owner/cars/details.php?car_id=2203', $html);
    }

    public function testPhotosRowSingularLinkTextForOnePhoto(): void
    {
        $car = $this->carFixture(['image' => json_encode(['only.jpg'])]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('View photo', $html);
        $this->assertStringNotContainsString('View all 1 photo', $html);
    }

    public function testPhotosRowShowsNoneOnFileWhenImageIsEmpty(): void
    {
        $car = $this->carFixture(['image' => '']);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('None on file', $html);
        // The branded header logo is its own <img>, present regardless — only
        // the photo-thumbnail <img> (identifiable by its userimages/ src) must
        // be absent here.
        $this->assertStringNotContainsString('/userimages/', $html);
    }

    public function testPhotosRowHandlesFilenameWithoutExtension(): void
    {
        $car = $this->carFixture(['image' => json_encode(['noextension'])]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('noextension-resized-100', $html);
    }

    public function testPhotosRowFallsBackToCountWhenFirstFilenameIsMalformed(): void
    {
        // Structured-input hardening: the first array entry present but not
        // a usable string (e.g. a nested array from a corrupt cars.image
        // value) must not reach string-interpolation logic — it falls back
        // to the plain photo-count text rather than throwing or emitting a
        // broken thumbnail URL built from non-string data.
        $car = $this->carFixture(['image' => json_encode([[], 'second.jpg'])]);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringContainsString('2 photos on file', $html);
        $this->assertStringNotContainsString('/userimages/', $html);
    }
}
