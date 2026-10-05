<?php

declare(strict_types=1);

use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\EmailTemplate;
use ElanRegistry\LogCategories;
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

    /** Temporary image root, one per test; stands in for userimages/. */
    private string $imageRoot;

    protected function setUp(): void
    {
        // tests/bootstrap-unit.php's logger() stub appends to this global.
        $GLOBALS['mockLogEntries'] = [];
        $this->imageRoot = sys_get_temp_dir() . '/elan-composer-test-' . bin2hex(random_bytes(8));
        mkdir($this->imageRoot, 0777, true);
        $this->composer = new CarVerificationEmailComposer(new EmailTemplate(), $this->imageRoot);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->imageRoot);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Create an empty file at {imageRoot}/{carId}/{name}.
     */
    private function touchFile(int $carId, string $name): void
    {
        $dir = $this->imageRoot . '/' . $carId;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $name, '');
    }

    /**
     * Create a photo's base file and, unless told not to, its -resized-300
     * variant. $base must end in ".jpg".
     */
    private function seedPhoto(int $carId, string $base, bool $withVariant = true): void
    {
        $this->touchFile($carId, $base);
        if ($withVariant) {
            $this->touchFile($carId, preg_replace('/\.jpg\z/', '-resized-300.jpg', $base) ?? $base);
        }
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

    public function testFooterContainsOptOutAnchorWithActionOptoutAndVericode(): void
    {
        $html = $this->composer->compose($this->carFixture(), $this->ownerFixture(), self::VERICODE)['html'];

        $expectedHref = $this->composer->optOutUrl(self::VERICODE);
        $this->assertStringContainsString('action=optout', $expectedHref);
        $this->assertStringContainsString(self::VERICODE, $expectedHref);

        $this->assertStringContainsString(
            htmlspecialchars($expectedHref, ENT_QUOTES, 'UTF-8'),
            $html
        );
        $this->assertStringNotContainsString('er-no-track', $html);
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
        // Seed a displayable photo so Photos is in the thumbnail state and logs nothing.
        $this->seedPhoto(42, 'a.jpg');
        $car = $this->carFixture(['website' => 'https://example.com']);
        $html = $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];

        $this->assertStringNotContainsString('Not yet provided', $html);
        $this->assertStringNotContainsString('still blank', $html);
    }

    public function testWebsiteHighlightedRowEscapesMaliciousValueEvenWhenNonBlank(): void
    {
        // Seed a displayable photo so Photos is in the thumbnail state and logs nothing.
        $this->seedPhoto(42, 'a.jpg');
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
        // Seed a displayable photo so Photos is in the thumbnail state and logs nothing.
        $this->seedPhoto(42, 'a.jpg');
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
        // Seed a displayable photo so Photos is in the thumbnail state and logs nothing.
        $this->seedPhoto(42, 'a.jpg');
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
        // Seed a displayable photo so Photos is in the thumbnail state and logs nothing.
        $this->seedPhoto(42, 'a.jpg');
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
    // 19. Photos row — 300px linked thumbnail vs. plain count vs. highlighted fallback (#1894)
    // ------------------------------------------------------------------

    public function testPhotosThumbnailUsesThe300pxVariant(): void
    {
        $this->seedPhoto(2203, 'abc123.jpg');
        $car  = $this->carFixture(['id' => 2203, 'image' => json_encode(['abc123.jpg'])]);
        $html = $this->composeHtml($car);
        $row  = $this->photosRow($html);

        $this->assertStringContainsString('/userimages/2203/abc123-resized-300.jpg', $row);
        $this->assertStringNotContainsString('-resized-100', $html);
        $img = $this->photoImg($row);
        $this->assertSame('300', $img->getAttribute('width'));
        $this->assertFalse($img->hasAttribute('height'), 'Variant aspect ratio varies, so no fixed height');
    }

    public function testPhotosThumbnailSrcIsAbsolute(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $html = $this->composeHtml($this->carFixture(['image' => json_encode(['a.jpg'])]));

        $src   = $this->photoImg($this->photosRow($html))->getAttribute('src');
        $parts = parse_url($src);
        $this->assertIsArray($parts);
        $this->assertArrayHasKey('scheme', $parts);
        $this->assertArrayHasKey('host', $parts);
        $this->assertStringStartsWith(getBaseUrl(), $src);
        $this->assertSame(getBaseUrl() . '/userimages/42/a-resized-300.jpg', $src);
    }

    public function testPhotosAltTextNamesTheCar(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $html = $this->composeHtml($this->carFixture(['image' => json_encode(['a.jpg'])]));

        $this->assertStringContainsString('alt="1969 Lotus Elan S4 SE FHC, British Racing Green"', $html);
    }

    public function testPhotosAltTextEscapesDoubleQuoteInColor(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $car  = $this->carFixture(['color' => 'Red "Signal"', 'image' => json_encode(['a.jpg'])]);
        $html = $this->composeHtml($car);
        $row  = $this->photosRow($html);

        $this->assertStringContainsString('Red &quot;Signal&quot;', $row);
        $this->assertSame(
            '1969 Lotus Elan S4 SE FHC, Red "Signal"',
            $this->photoImg($row)->getAttribute('alt'),
            'The attribute must round-trip through an HTML parser unchanged'
        );
    }

    public function testPhotosAltTextEscapesMarkupInVariant(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $car  = $this->carFixture(['variant' => '"><script>alert(1)</script>', 'image' => json_encode(['a.jpg'])]);
        $html = $this->composeHtml($car);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertSame(
            '1969 Lotus Elan S4 "><script>alert(1)</script> FHC, British Racing Green',
            $this->photoImg($this->photosRow($html))->getAttribute('alt')
        );
    }

    public function testPhotosAltTextOmitsBlankParts(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $car  = $this->carFixture(['variant' => '', 'color' => '', 'image' => json_encode(['a.jpg'])]);
        $html = $this->composeHtml($car);

        $this->assertSame(
            '1969 Lotus Elan S4 FHC',
            $this->photoImg($this->photosRow($html))->getAttribute('alt')
        );
    }

    public function testPhotosAltTextOmitsBlankYearAndSeries(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $car  = $this->carFixture(['year' => null, 'series' => '  ', 'image' => json_encode(['a.jpg'])]);
        $html = $this->composeHtml($car);

        $this->assertSame(
            'Lotus Elan SE FHC, British Racing Green',
            $this->photoImg($this->photosRow($html))->getAttribute('alt')
        );
    }

    public function testPhotosLinkPointsToDetailsPageAndCountsOnlyFilesOnDisk(): void
    {
        // a.jpg: base + variant. b.jpg: base only. c.jpg: nothing on disk.
        $this->seedPhoto(2203, 'a.jpg');
        $this->seedPhoto(2203, 'b.jpg', false);
        $car  = $this->carFixture(['id' => 2203, 'image' => json_encode(['a.jpg', 'b.jpg', 'c.jpg'])]);
        $row  = $this->photosRow($this->composeHtml($car));

        $this->assertSame(
            getBaseUrl() . '/app/owner/cars/details.php?car_id=2203',
            $this->photoLink($row)->getAttribute('href')
        );
        $this->assertStringContainsString('View all 2 photos →', $row);
        $this->assertStringNotContainsString('View all 3', $row);
    }

    public function testPhotosLinkTextIsSingularForOnePhoto(): void
    {
        // Two entries, one file on disk: the count is 1, not 2.
        $this->seedPhoto(42, 'only.jpg');
        $row = $this->photosRow(
            $this->composeHtml($this->carFixture(['image' => json_encode(['only.jpg', 'ghost.jpg'])]))
        );

        $this->assertStringContainsString('View photo →', $row);
        $this->assertStringNotContainsString('View all', $row);
    }

    public function testPhotosPrimarySkipsEntryWhoseBaseFileIsMissing(): void
    {
        $this->seedPhoto(42, 'second.jpg');
        $this->seedPhoto(42, 'third.jpg');
        $car = $this->carFixture(['image' => json_encode(['first.jpg', 'second.jpg', 'third.jpg'])]);
        $row = $this->photosRow($this->composeHtml($car));

        $this->assertStringContainsString('/userimages/42/second-resized-300.jpg', $row);
        $this->assertStringNotContainsString('first-resized-300', $row);
        $this->assertStringContainsString('View all 2 photos →', $row);
    }

    // State b: photos listed, no thumbnail possible -> plain "N photos on file".

    public function testPhotosPlainCountWhenNoBaseFileExistsOnDisk(): void
    {
        $car = $this->carFixture(['image' => json_encode(['alpha-shot.jpg', 'beta-shot.jpg'])]);

        $this->assertPhotosPlainCount($this->composeHtml($car), '2 photos on file', ['alpha-shot', 'beta-shot']);
    }

    public function testPhotosPlainCountWhenOnlyTheVariantExists(): void
    {
        $this->touchFile(42, 'alpha-shot-resized-300.jpg');
        $car = $this->carFixture(['image' => json_encode(['alpha-shot.jpg'])]);

        $this->assertPhotosPlainCount($this->composeHtml($car), '1 photo on file', ['alpha-shot']);
    }

    public function testPhotosPlainCountWhenBaseExistsButThe300pxVariantDoesNot(): void
    {
        $this->seedPhoto(42, 'alpha-shot.jpg', false);
        // A 100px variant must not stand in for the missing 300px one.
        $this->touchFile(42, 'alpha-shot-resized-100.jpg');
        $car = $this->carFixture(['image' => json_encode(['alpha-shot.jpg'])]);

        $this->assertPhotosPlainCount($this->composeHtml($car), '1 photo on file', ['alpha-shot']);
    }

    public function testPhotosPlainCountWhenPrimaryLacksVariantEvenIfALaterPhotoIsComplete(): void
    {
        // The primary rule does not skip to a later photo that has a variant.
        $this->seedPhoto(42, 'alpha-shot.jpg', false);
        $this->seedPhoto(42, 'beta-shot.jpg');
        $car = $this->carFixture(['image' => json_encode(['alpha-shot.jpg', 'beta-shot.jpg'])]);

        $this->assertPhotosPlainCount($this->composeHtml($car), '2 photos on file', ['alpha-shot', 'beta-shot']);
    }

    public function testPhotosPlainCountIsSingularForOneListedPhoto(): void
    {
        $car  = $this->carFixture(['image' => json_encode(['alpha-shot.jpg'])]);
        $html = $this->composeHtml($car);

        $this->assertPhotosPlainCount($html, '1 photo on file', ['alpha-shot']);
        $this->assertStringNotContainsString('1 photos', $html);
    }

    public function testPhotosPlainCountCountsOnlySafeEntries(): void
    {
        $car = $this->carFixture(['image' => json_encode(['../evil-shot.jpg', 'alpha-shot.jpg'])]);

        $this->assertPhotosPlainCount($this->composeHtml($car), '1 photo on file', ['alpha-shot', 'evil-shot']);
    }

    // State c logging, and no logging when nothing is wrong.

    public function testPhotosLogsNothingWhenAThumbnailIsShown(): void
    {
        $this->seedPhoto(42, 'alpha-shot.jpg');
        $this->composeHtml($this->carFixture(['image' => json_encode(['alpha-shot.jpg'])]));

        $this->assertSame([], $GLOBALS['mockLogEntries']);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function emptyImageValueProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace'   => ['   '],
            'null'         => [null],
            // Stored after the last photo is removed: the normal no-photo state.
            'empty array'  => ['[]'],
            // A JSON scalar is not a list. The landing page finds no photo in it either.
            'JSON scalar'  => ['"a.jpg"'],
            'JSON number'  => ['42'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyImageValueProvider')]
    public function testPhotosLogsNothingWhenImageValueIsEmpty(?string $image): void
    {
        // Files exist on disk, so only the cars.image value can cause the fallback.
        $this->seedPhoto(42, 'a.jpg');

        $this->assertPhotosFallback($this->composeHtml($this->carFixture(['image' => $image])));

        $this->assertSame([], $GLOBALS['mockLogEntries']);
    }

    public function testPhotosLogsOnceWhenImageValueHasOnlyUnsafeEntries(): void
    {
        $car = $this->carFixture(['image' => json_encode(['../../etc/passwd', 'sub/evil-shot.jpg'])]);

        $this->assertPhotosFallback($this->composeHtml($car));
        $this->assertSingleFileErrorLog(['passwd', 'evil-shot', 'etc/']);
    }

    public function testPhotosLogsOnceWhenImageValueIsNonEmptyButHasNoSafeEntry(): void
    {
        $this->assertPhotosFallback($this->composeHtml($this->carFixture(['image' => 'not json at all'])));

        $this->assertSingleFileErrorLog(['not json']);
    }

    // Legacy image formats decode like CarImageProcessor.

    public function testPhotosLegacyCommaSeparatedValueShowsThumbnailAndCount(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $this->seedPhoto(42, 'b.jpg');
        $row = $this->photosRow($this->composeHtml($this->carFixture(['image' => 'a.jpg,b.jpg'])));

        $this->assertStringContainsString('/userimages/42/a-resized-300.jpg', $row);
        $this->assertStringContainsString('View all 2 photos →', $row);
        $this->assertSame([], $GLOBALS['mockLogEntries']);
    }

    public function testPhotosLegacyBareFilenameShowsThumbnail(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $row = $this->photosRow($this->composeHtml($this->carFixture(['image' => 'a.jpg'])));

        $this->assertStringContainsString('/userimages/42/a-resized-300.jpg', $row);
        $this->assertStringContainsString('View photo →', $row);
    }

    // Wrong-typed alt-text inputs (year and color).

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function wrongTypedAltInputProvider(): array
    {
        return [
            'year array'   => [['year' => [1969]], 'Lotus Elan S4 SE FHC, British Racing Green'],
            'year object'  => [['year' => new \stdClass()], 'Lotus Elan S4 SE FHC, British Racing Green'],
            'color array'  => [['color' => ['red']], '1969 Lotus Elan S4 SE FHC'],
            'color object' => [['color' => new \stdClass()], '1969 Lotus Elan S4 SE FHC'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrongTypedAltInputProvider')]
    public function testPhotosAltTextDropsWrongTypedFieldWithoutWarning(array $overrides, string $expectedAlt): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $car = $this->carFixture($overrides + ['image' => json_encode(['a.jpg'])]);

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $html = $this->composeHtml($car);
        } finally {
            restore_error_handler();
        }

        $this->assertSame($expectedAlt, $this->photoImg($this->photosRow($html))->getAttribute('alt'));
    }

    // Default image root.

    public function testDefaultImageRootIsTheRepositoryUserimagesDirectory(): void
    {
        $composer = new CarVerificationEmailComposer();
        $prop     = new \ReflectionProperty(CarVerificationEmailComposer::class, 'imageRoot');
        $actual   = $prop->getValue($composer);

        $expected = realpath(dirname(__DIR__, 4) . '/userimages');
        $this->assertIsString($expected, 'The repository must have a userimages/ directory');
        $this->assertIsString($actual);
        $this->assertSame($expected, realpath($actual));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeFilenameProvider(): array
    {
        return [
            'path traversal to a system file' => ['../../etc/passwd'],
            'traversal to a real image'       => ['../evil.jpg'],
            'subdirectory'                    => ['sub/evil.jpg'],
            'no extension'                    => ['noextension'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeFilenameProvider')]
    public function testPhotosFallbackWhenTheOnlyEntryIsUnsafe(string $name): void
    {
        // Plant files the unsafe name would reach if it were not rejected:
        // basename($name) resolves inside the car's own folder.
        $this->seedPhoto(42, 'evil.jpg');
        $this->seedPhoto(42, 'noextension', false);
        $this->touchFile(42, 'noextension-resized-300.');
        $car = $this->carFixture(['image' => json_encode([$name])]);

        $this->assertPhotosFallback($this->composeHtml($car));
    }

    public function testPhotosSkipsUnsafeEntryAndUsesTheNextValidOne(): void
    {
        $this->seedPhoto(42, 'evil.jpg');
        $this->seedPhoto(42, 'good.jpg');
        $car = $this->carFixture(['image' => json_encode(['../evil.jpg', 'good.jpg'])]);
        $row = $this->photosRow($this->composeHtml($car));

        $this->assertStringContainsString('/userimages/42/good-resized-300.jpg', $row);
        $this->assertStringNotContainsString('evil', $row);
        $this->assertStringContainsString('View photo →', $row, 'The unsafe entry must not be counted');
    }

    public function testPhotosSkipsMalformedEntriesAndUsesTheNextValidOne(): void
    {
        $this->seedPhoto(42, 'second.jpg');
        $car = $this->carFixture(['image' => json_encode([[], 123, null, 'second.jpg'])]);
        $row = $this->photosRow($this->composeHtml($car));

        $this->assertStringContainsString('/userimages/42/second-resized-300.jpg', $row);
        $this->assertStringContainsString('View photo →', $row);
        $this->assertStringNotContainsString('Not yet provided', $row);
    }

    public function testPhotosFallbackWhenAllEntriesAreMalformed(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $car = $this->carFixture(['image' => json_encode([[], 123, null])]);

        $this->assertPhotosFallback($this->composeHtml($car));
    }

    public function testBlankPhotosIsNamedBeforeWebsiteInTheCallout(): void
    {
        $car  = $this->carFixture(['website' => '', 'image' => '[]']);
        $html = $this->composeHtml($car);

        $this->assertSame(1, substr_count($html, 'still blank'));
        $this->assertMatchesRegularExpression(
            '/2 fields above are still blank.{0,60}Photos and Website, highlighted above/s',
            $html
        );
    }

    public function testPhotosPresentIsNotNamedInTheCalloutOrHighlighted(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $html = $this->composeHtml($this->carFixture(['website' => '']));

        $this->assertMatchesRegularExpression(
            '/1 field above is still blank.{0,20}Website, highlighted above/s',
            $html
        );
        $this->assertStringNotContainsString('#FFF9E0', $this->photosRow($html));
    }

    public function testPhotosHandlesWrongTypedImageValueWithoutError(): void
    {
        $this->seedPhoto(42, 'a.jpg');

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            // A non-string `image` (corrupt row, or a driver returning a native
            // array/int) must fall back, not throw a TypeError.
            foreach ([5, ['a.jpg'], true, 1.5] as $bad) {
                $html = $this->composeHtml($this->carFixture(['image' => $bad]));
                $this->assertPhotosFallback($html);
            }
        } finally {
            restore_error_handler();
        }
    }

    public function testPhotosHandlesIntegerYearAndColorWithoutError(): void
    {
        $this->seedPhoto(42, 'a.jpg');

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $car  = $this->carFixture(['year' => 1969, 'color' => 7, 'image' => json_encode(['a.jpg'])]);
            $html = $this->composeHtml($car);

            $this->assertSame(
                '1969 Lotus Elan S4 SE FHC, 7',
                $this->photoImg($this->photosRow($html))->getAttribute('alt')
            );
        } finally {
            restore_error_handler();
        }
    }

    public function testPhotosHandlesMissingImageAndIdPropertiesWithoutError(): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $car = $this->carFixture();
            unset($car->image, $car->id);

            $this->assertPhotosFallback($this->composeHtml($car));
        } finally {
            restore_error_handler();
        }
    }

    public function testComposeReturnsOnlySubjectAndHtmlAndEmbedsNoImageData(): void
    {
        $this->seedPhoto(42, 'a.jpg');
        $result = $this->composer->compose(
            $this->carFixture(['image' => json_encode(['a.jpg'])]),
            $this->ownerFixture(),
            self::VERICODE
        );

        $keys = array_keys($result);
        sort($keys);
        $this->assertSame(['html', 'subject'], $keys);
        $this->assertStringNotContainsString('cid:', $result['html']);
        $this->assertStringNotContainsString('data:image', $result['html']);
        $this->assertStringContainsString('/userimages/42/a-resized-300.jpg', $result['html']);
    }

    // ------------------------------------------------------------------
    // Helpers for section 19
    // ------------------------------------------------------------------

    private function composeHtml(object $car): string
    {
        return $this->composer->compose($car, $this->ownerFixture(), self::VERICODE)['html'];
    }

    /**
     * The one detail-row table that holds the "Photos:" label.
     */
    private function photosRow(string $html): string
    {
        $label = strpos($html, 'Photos:');
        $this->assertIsInt($label, 'Photos row must be present');
        $start = strrpos(substr($html, 0, $label), '<table');
        $end   = strpos($html, '</table>', $label);
        $this->assertIsInt($start);
        $this->assertIsInt($end);

        return substr($html, $start, $end + strlen('</table>') - $start);
    }

    private function parseFragment(string $fragment): \DOMDocument
    {
        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $fragment);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $doc;
    }

    private function photoImg(string $row): \DOMElement
    {
        $img = $this->parseFragment($row)->getElementsByTagName('img')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $img, 'Photos row must contain an <img>');

        return $img;
    }

    private function photoLink(string $row): \DOMElement
    {
        $a = $this->parseFragment($row)->getElementsByTagName('a')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $a, 'Photos row must contain a link');

        return $a;
    }

    /**
     * Highlighted "Not yet provided" Photos cell, no photo URL anywhere in the
     * email, and the callout names Photos (the only blank field in the fixture).
     */
    private function assertPhotosFallback(string $html): void
    {
        $row = $this->photosRow($html);

        $this->assertStringContainsString('Not yet provided', $row);
        $this->assertStringContainsString('background-color: #FFF9E0', $row);
        $this->assertStringNotContainsString('<img', $row);
        // The branded header logo is its own <img>; only a photo has a
        // userimages/ src.
        $this->assertStringNotContainsString('/userimages/', $html);
        $this->assertMatchesRegularExpression(
            '/1 field above is still blank.{0,20}Photos, highlighted above/s',
            $html
        );
    }

    /**
     * State b: plain "N photos on file" text. No highlight, no image, no photo
     * URL, Photos not named in the callout, and exactly one FileError log entry
     * with neither a filename nor a filesystem path in its message.
     *
     * @param list<string> $forbiddenInLog Fragments that must not appear in the log message
     */
    private function assertPhotosPlainCount(string $html, string $expectedText, array $forbiddenInLog): void
    {
        $row = $this->photosRow($html);

        $this->assertStringContainsString($expectedText, $row);
        $this->assertStringNotContainsString('#FFF9E0', $row);
        $this->assertStringNotContainsString('Not yet provided', $row);
        $this->assertStringNotContainsString('<img', $row);
        $this->assertStringNotContainsString('/userimages/', $html);
        $this->assertStringNotContainsString('still blank', $html);
        $this->assertStringNotContainsString('Photos, highlighted', $html);

        $this->assertSingleFileErrorLog($forbiddenInLog);
    }

    /**
     * Exactly one FileError log entry. Its message names car 42 and holds no
     * raw filename fragment, temp directory path, or image root.
     *
     * @param list<string> $forbiddenFragments
     */
    private function assertSingleFileErrorLog(array $forbiddenFragments = []): void
    {
        $entries = $GLOBALS['mockLogEntries'];
        $this->assertIsArray($entries);
        $this->assertCount(1, $entries);

        $entry = $entries[0];
        $this->assertSame(LogCategories::LOG_CATEGORY_FILE_ERROR, $entry['category']);

        $message = (string) $entry['message'];
        // The car id is what an operator needs to act on the entry.
        $this->assertStringContainsString('car 42 ', $message);
        foreach ([...$forbiddenFragments, $this->imageRoot, sys_get_temp_dir(), 'elan-composer-test'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $message);
        }
    }
}
