<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PhpBuiltinServer;

/**
 * #1960 and #1528: render real pages under `php -S` and read the head tags.
 *
 * The canonical, og:url, twitter:url and the JSON-LD url must not carry the
 * raw query string. The car details page must have a per-car title and meta
 * description, and a car that does not load must get a real 302.
 */
#[Group('integration')]
#[Group('page-metadata')]
final class HeadTagsCanonicalRenderTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private const DETAILS_PATH = '/app/owner/cars/details.php';
    private const PAINT_COLORS_PATH = '/docs/reference/paint-colors.php';
    private const PDF_VIEWER_PATH = '/docs/pdf-viewer.php';
    private const KNOWN_PDF = 'Elan_26_36_Workshop_Manual.pdf';
    private const STORIES_PDF = 'Mag _issue_50_p12-15_Barry-Shapecraft.pdf';
    private const VERIFY_CAR_PATH = '/app/verify/verify_car.php';
    private const CAR_INDEX_SUFFIX = 'app/owner/cars/index.php';

    private static ?PhpBuiltinServer $server = null;
    private static string $projectRoot = '';

    private int $carId = 0;
    private int $otherCarId = 0;
    private string $chassis = '';
    private string $otherChassis = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$projectRoot = dirname(__DIR__, 2);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->exposeTestDatabaseToEnvironment();
        self::$server ??= PhpBuiltinServer::start(self::$projectRoot, self::routerBody());

        // S4 / FHC / 36 is a row in car_models, so the title keeps the series
        // and variant. The parent tearDown() deletes both cars and the owner.
        $ownerId = $this->createTestUser();
        $this->chassis = sprintf('36/%05d', random_int(0, 99999));
        $this->carId = $this->createTestCar($ownerId, [
            'year' => 1969,
            'model' => 'S4|FHC|36',
            'series' => 'S4',
            'variant' => 'FHC',
            'type' => '36',
            'chassis' => $this->chassis,
        ]);
        $this->otherChassis = sprintf('50/%05d', random_int(0, 99999));
        $this->otherCarId = $this->createTestCar($ownerId, [
            'year' => 1971,
            'model' => '+2S|FHC|50',
            'series' => '+2S',
            'variant' => 'FHC',
            'type' => '50',
            'chassis' => $this->otherChassis,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // The verify_car.php test can add rate-limit rows. Clear them so
            // that runs stay independent.
            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'verification_code_attempt'");
        }

        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Server lifecycle (Tests\Support\PhpBuiltinServer owns the process)
    // ------------------------------------------------------------------

    /**
     * The router serves only the pages under test. Each page uses relative
     * requires, so the router changes to the page's directory first.
     */
    private static function routerBody(): string
    {
        $root = var_export(self::$projectRoot, true);
        $pages = var_export(
            [self::DETAILS_PATH, self::PAINT_COLORS_PATH, self::PDF_VIEWER_PATH, self::VERIFY_CAR_PATH],
            true
        );

        return <<<PHP
        require {$root} . '/vendor/autoload.php';
        \\Dotenv\\Dotenv::createMutable({$root}, '.env.test.local')->load();
        \$routerPath = (string) parse_url((string) (\$_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!in_array(\$routerPath, {$pages}, true)) {
            http_response_code(404);
            return;
        }
        chdir(dirname({$root} . \$routerPath));
        require {$root} . \$routerPath;
        PHP;
    }

    private function exposeTestDatabaseToEnvironment(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /**
     * The origin that the page builds. server_globals.php trusts the host
     * `localhost` and adds the local port.
     */
    private function origin(): string
    {
        return 'http://localhost:' . self::$server->port();
    }

    /**
     * @return array{status: int, body: string, location: ?string}
     */
    private function get(string $path, string $queryString = ''): array
    {
        $url = self::$server->url($path) . ($queryString === '' ? '' : '?' . $queryString);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            // An untrusted host gives no canonical at all, so send a trusted one.
            CURLOPT_HTTPHEADER => ['Host: localhost:' . self::$server->port()],
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException('curl request to ' . $path . ' failed: ' . curl_error($ch));
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr((string) $response, 0, $headerSize);

        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $matches) === 1) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'body' => substr((string) $response, $headerSize), 'location' => $location];
    }

    /**
     * Return the head tags that carry a URL or a title, decoded.
     *
     * @return array{canonical: ?string, og_url: ?string, twitter_url: ?string, json_ld_url: ?string,
     *     title: ?string, description: ?string, og_title: ?string, twitter_title: ?string}
     */
    private function headTags(string $html): array
    {
        $attribute = static function (string $pattern) use ($html): ?string {
            return preg_match($pattern, $html, $m) === 1
                ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : null;
        };

        $jsonLdUrl = null;
        if (preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m) === 1) {
            $schema = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
            $jsonLdUrl = is_array($schema) && is_string($schema['url'] ?? null) ? $schema['url'] : null;
        }

        return [
            'canonical' => $attribute('~<link rel="canonical" href="([^"]*)">~'),
            'og_url' => $attribute('~<meta property="og:url" content="([^"]*)">~'),
            'twitter_url' => $attribute('~<meta name="twitter:url" content="([^"]*)">~'),
            'json_ld_url' => $jsonLdUrl,
            'title' => $attribute('~<title>(.*?)</title>~s'),
            'description' => $attribute('~<meta name="description" content="([^"]*)">~'),
            'og_title' => $attribute('~<meta property="og:title" content="([^"]*)">~'),
            'twitter_title' => $attribute('~<meta name="twitter:title" content="([^"]*)">~'),
        ];
    }

    /**
     * Return the markup before `</head>`.
     */
    private function head(string $html): string
    {
        $end = strpos($html, '</head>');
        $this->assertNotFalse($end, 'The page must render a </head> tag');

        return substr($html, 0, $end);
    }

    private function expectedDetailsCanonical(int $carId): string
    {
        return $this->origin() . self::DETAILS_PATH . '?car_id=' . $carId;
    }

    // ------------------------------------------------------------------
    // Canonical URL (#1960)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}> Query templates; {id} is the fixture car ID
     */
    public static function detailsQueryVariantProvider(): array
    {
        return [
            'plain id' => ['car_id={id}'],
            'crafted suffix' => ['car_id={id}-spam'],
            'padded integer' => ['car_id=000{id}'],
            'tracking parameter' => ['car_id={id}&utm_source=newsletter'],
        ];
    }

    #[DataProvider('detailsQueryVariantProvider')]
    public function testDetailsCanonicalOgTwitterAndJsonLdUrlKeepOnlyTheCarId(string $queryTemplate): void
    {
        $response = $this->get(self::DETAILS_PATH, str_replace('{id}', (string) $this->carId, $queryTemplate));

        $this->assertSame(200, $response['status'], 'details.php must render the fixture car');
        $tags = $this->headTags($response['body']);
        $expected = $this->expectedDetailsCanonical($this->carId);

        $this->assertSame($expected, $tags['canonical'], 'rel=canonical must keep only the validated car_id');
        $this->assertSame($expected, $tags['og_url'], 'og:url must equal the canonical');
        $this->assertSame($expected, $tags['twitter_url'], 'twitter:url must equal the canonical');
        $this->assertSame($expected, $tags['json_ld_url'], 'the JSON-LD url must equal the canonical');
    }

    public function testDetailsPaddedAndPlainIdGiveByteIdenticalCanonicalTags(): void
    {
        $padded = $this->headTags($this->get(self::DETAILS_PATH, 'car_id=000' . $this->carId)['body']);
        $plain = $this->headTags($this->get(self::DETAILS_PATH, 'car_id=' . $this->carId)['body']);

        $this->assertNotNull($plain['canonical'], 'details.php must emit rel=canonical');
        $this->assertSame($plain['canonical'], $padded['canonical']);
    }

    public function testPaintColorsCanonicalDropsTrackingQueryString(): void
    {
        $response = $this->get(self::PAINT_COLORS_PATH, 'utm_source=test');

        $this->assertSame(200, $response['status']);
        $tags = $this->headTags($response['body']);
        $this->assertSame($this->origin() . self::PAINT_COLORS_PATH, $tags['canonical']);
        $this->assertSame($tags['canonical'], $tags['og_url']);
        $this->assertSame($tags['canonical'], $tags['twitter_url']);
    }

    public function testPdfViewerCanonicalKeepsSubdirAndDocForKnownDocument(): void
    {
        $response = $this->get(
            self::PDF_VIEWER_PATH,
            http_build_query(['subdir' => 'reference', 'doc' => self::KNOWN_PDF, 'utm_source' => 'test'])
        );

        $this->assertSame(200, $response['status']);
        $tags = $this->headTags($response['body']);
        $this->assertSame(
            $this->origin() . self::PDF_VIEWER_PATH . '?subdir=reference&doc=' . self::KNOWN_PDF,
            $tags['canonical']
        );
        $this->assertSame($tags['canonical'], $tags['og_url']);
        $this->assertSame($tags['canonical'], $tags['twitter_url']);
    }

    public function testPdfViewerCanonicalKeepsSubdirAndDocForStoriesDocument(): void
    {
        $response = $this->get(
            self::PDF_VIEWER_PATH,
            'subdir=stories&doc=' . rawurlencode(self::STORIES_PDF) . '&utm_source=test'
        );

        $this->assertSame(200, $response['status']);
        $tags = $this->headTags($response['body']);
        $this->assertSame(
            $this->origin() . self::PDF_VIEWER_PATH . '?subdir=stories&doc=' . rawurlencode(self::STORIES_PDF),
            $tags['canonical'],
            'A stories PDF must keep its own canonical, with a space as %20'
        );
        $this->assertSame($tags['canonical'], $tags['og_url']);
        $this->assertSame($tags['canonical'], $tags['twitter_url']);
    }

    /**
     * @return array<string, array{string, string, int}> subdir, doc and expected HTTP status
     */
    public static function pdfViewerUnservedDocumentProvider(): array
    {
        return [
            'unknown document' => ['reference', 'no-such-document.pdf', 404],
            'crafted document' => ['reference', 'x.pdf"><b>', 404],
            // The later fallback finds the file under stories/ and redirects to it.
            'stories document under reference' => ['reference', self::STORIES_PDF, 301],
            'unknown subdir' => ['assets', self::KNOWN_PDF, 404],
        ];
    }

    #[DataProvider('pdfViewerUnservedDocumentProvider')]
    public function testPdfViewerCanonicalFallsBackToBarePathForUnservedDocument(string $subdir, string $doc, int $status): void
    {
        $response = $this->get(self::PDF_VIEWER_PATH, 'subdir=' . rawurlencode($subdir) . '&doc=' . rawurlencode($doc));

        $this->assertSame($status, $response['status']);

        $tags = $this->headTags($response['body']);
        $expected = $this->origin() . self::PDF_VIEWER_PATH;
        $this->assertSame($expected, $tags['canonical']);
        $this->assertSame($expected, $tags['og_url']);
        $this->assertSame($expected, $tags['twitter_url']);
    }

    public function testVerifyCarCanonicalOmitsTheVericode(): void
    {
        $vericode = bin2hex(random_bytes(16));
        $this->db->query(
            'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
            [hashVericode($vericode), date('Y-m-d H:i:s', strtotime('-10 days')), $this->carId]
        );
        $this->assertFalse($this->db->error(), 'Test setup: failed to seed the vericode');

        $response = $this->get(self::VERIFY_CAR_PATH, 'vericode=' . $vericode . '&car_id=' . $this->carId);

        $this->assertSame(200, $response['status']);
        $tags = $this->headTags($response['body']);
        $expected = $this->origin() . self::VERIFY_CAR_PATH;
        $this->assertSame($expected, $tags['canonical']);
        $this->assertSame($expected, $tags['og_url']);
        $this->assertSame($expected, $tags['twitter_url']);
        $this->assertStringNotContainsString($vericode, $this->head($response['body']), 'The vericode must not appear in <head>');
    }

    // ------------------------------------------------------------------
    // Not-found redirect (#1960, #1528)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCarIdProvider(): array
    {
        return [
            'not a number' => ['car_id=abc'],
            'no leading digits' => ['car_id=-spam'],
            'no such car' => ['car_id=999999999'],
            'empty car_id' => ['car_id='],
            'tracking parameter only' => ['utm_source=newsletter'],
            'no query string' => [''],
        ];
    }

    #[DataProvider('invalidCarIdProvider')]
    public function testDetailsInvalidCarIdSendsRealRedirectWithNoPerCarTitle(string $queryString): void
    {
        $response = $this->get(self::DETAILS_PATH, $queryString);

        $this->assertSame(302, $response['status'], 'A car that does not load must get an HTTP 302');
        $this->assertNotNull($response['location']);
        $this->assertStringEndsWith(self::CAR_INDEX_SUFFIX, (string) $response['location']);
        $this->assertStringNotContainsString('Lotus Elan', $response['body'], 'A redirect must not carry a per-car title');
        $this->assertStringNotContainsString('Chassis', $response['body']);
    }

    // ------------------------------------------------------------------
    // Per-car title and description (#1528)
    // ------------------------------------------------------------------

    public function testDetailsTitleDescriptionAndSocialTitlesArePerCar(): void
    {
        $tags = $this->headTags($this->get(self::DETAILS_PATH, 'car_id=' . $this->carId)['body']);
        $expectedTitle = '1969 Lotus Elan S4 (FHC), Chassis ' . $this->chassis;

        $this->assertNotNull($tags['title']);
        $this->assertStringStartsWith($expectedTitle, trim((string) $tags['title']));
        $this->assertSame(
            'Registry record for a 1969 Lotus Elan S4 (FHC), chassis ' . $this->chassis
            . ': ownership history, factory build data and photos.',
            $tags['description']
        );
        $this->assertSame($expectedTitle, $tags['og_title'], 'og:title must carry the per-car title');
        $this->assertSame($expectedTitle, $tags['twitter_title'], 'twitter:title must carry the per-car title');
    }

    public function testDetailsTwoCarsGetDifferentTitlesAndDescriptions(): void
    {
        $first = $this->headTags($this->get(self::DETAILS_PATH, 'car_id=' . $this->carId)['body']);
        $second = $this->headTags($this->get(self::DETAILS_PATH, 'car_id=' . $this->otherCarId)['body']);

        $this->assertStringContainsString($this->otherChassis, (string) $second['og_title']);
        $this->assertNotSame($first['title'], $second['title']);
        $this->assertNotSame($first['description'], $second['description']);
    }

    public function testDetailsMarkupInCarFieldsNeverReachesTheHead(): void
    {
        $carId = $this->createTestCar($this->createTestUser(), [
            'year' => 1969,
            'series' => '<b>S4</b>',
            'variant' => 'FHC',
            'type' => '36',
            'chassis' => '<b>x</b>',
        ]);

        $response = $this->get(self::DETAILS_PATH, 'car_id=' . $carId);

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('<b>', $this->head($response['body']));
        $tags = $this->headTags($response['body']);
        $this->assertStringStartsWith('1969 Lotus Elan, Registry car #' . $carId, trim((string) $tags['title']));
    }

    public function testDetailsTitleLeavesOutSeriesAndVariantNotInCarModels(): void
    {
        $chassis = sprintf('36/%05d', random_int(0, 99999));
        $carId = $this->createTestCar($this->createTestUser(), [
            'year' => 1969,
            'series' => 'S9',
            'variant' => 'ZZZ',
            'type' => '36',
            'chassis' => $chassis,
        ]);

        $tags = $this->headTags($this->get(self::DETAILS_PATH, 'car_id=' . $carId)['body']);

        $this->assertSame('1969 Lotus Elan, Chassis ' . $chassis, $tags['og_title']);
        $this->assertStringStartsWith('1969 Lotus Elan, Chassis ' . $chassis, trim((string) $tags['title']));
    }
}
