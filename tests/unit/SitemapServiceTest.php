<?php

declare(strict_types=1);

use ElanRegistry\CanonicalUrl;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\SitemapService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SitemapService (#1373)
 */
#[Group('fast')]
#[Group('unit')]
final class SitemapServiceTest extends TestCase
{
    private const BASE_URL = 'https://elanregistry.org';

    /**
     * @param list<object{id: int, mtime: string}> $cars
     */
    private function makeService(array $cars = []): SitemapService
    {
        $stubRepo = $this->createStub(CarRepository::class);
        $stubRepo->method('getAllForSitemap')->willReturn($cars);

        return new SitemapService($stubRepo);
    }

    public function testBuildXmlProducesWellFormedXmlDocument(): void
    {
        $service = $this->makeService();
        $xml     = $service->buildXml(self::BASE_URL);

        $this->assertStringStartsWith('<?xml version="1.0"', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'Sitemap output must be well-formed XML');
    }

    public function testBuildXmlIncludesAllStaticPages(): void
    {
        $service = $this->makeService();
        $xml     = $service->buildXml(self::BASE_URL);

        $this->assertStringContainsString(
            '<loc>' . self::BASE_URL . '/app/owner/cars/</loc>',
            $xml,
        );
        $this->assertStringContainsString(
            '<loc>' . self::BASE_URL . '/docs/guides/</loc>',
            $xml,
        );
        $this->assertStringContainsString(
            '<loc>' . self::BASE_URL . '/app/owner/reports/statistics.php</loc>',
            $xml,
        );

        $sitemap = simplexml_load_string($xml);
        $this->assertNotFalse($sitemap);
        $sitemap->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = $sitemap->xpath('//s:url');
        // 15 static pages + 0 cars in this scenario
        $this->assertCount(15, $urls);
    }

    public function testBuildXmlIncludesAllReferencePdfAssetUrls(): void
    {
        $service = $this->makeService();
        $xml     = $service->buildXml(self::BASE_URL);

        $pdfPaths = [
            '/docs/reference/assets/Elan_26_36_Workshop_Manual.pdf',
            '/docs/reference/assets/elan_s1_s2_coupe_masterpartslist.pdf',
            '/docs/reference/assets/2016%20Jan%20Elan%20Engine%20Types.pdf',
            '/docs/reference/assets/2019_Jan_The_Elan_Super_Safety.pdf',
            '/docs/reference/assets/All%20Elan%20and%20Elan%20Plus%202%20Paint%20Codes.pdf',
            '/docs/reference/assets/Engine%20number%20breakdown%20%28Miles%20Wilkins%29.pdf',
            '/docs/reference/assets/2014%20Jul%20Elan%20Gearknobs.pdf',
            '/docs/reference/assets/2014%20Oct%20Elan%20and%20Plus%202%20Steering%20Wheels.pdf',
            '/docs/reference/assets/Lotus%20Elan%20Plus%202%20serial%20numbers.pdf',
        ];

        foreach ($pdfPaths as $path) {
            $this->assertStringContainsString(
                '<loc>' . self::BASE_URL . $path . '</loc>',
                $xml,
            );
        }
    }

    public function testBuildXmlIncludesOneUrlPerCar(): void
    {
        $cars = [
            (object) ['id' => 101, 'mtime' => '2026-03-15 10:30:00'],
            (object) ['id' => 202, 'mtime' => '2025-12-01 00:00:00'],
            (object) ['id' => 303, 'mtime' => '2024-07-04 23:59:59'],
        ];
        $service = $this->makeService($cars);
        $xml     = $service->buildXml(self::BASE_URL);

        foreach ($cars as $car) {
            $this->assertStringContainsString(
                '<loc>' . self::BASE_URL . '/app/owner/cars/details.php?car_id=' . $car->id . '</loc>',
                $xml,
            );
        }

        $this->assertStringContainsString('<lastmod>2026-03-15</lastmod>', $xml);
        $this->assertStringContainsString('<lastmod>2025-12-01</lastmod>', $xml);
        $this->assertStringContainsString('<lastmod>2024-07-04</lastmod>', $xml);

        $sitemap = simplexml_load_string($xml);
        $this->assertNotFalse($sitemap);
        $sitemap->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = $sitemap->xpath('//s:url');
        // 15 static pages + 3 cars
        $this->assertCount(18, $urls);
    }

    public function testBuildXmlExcludesFactoryAndPrivacyPages(): void
    {
        $cars = [
            (object) ['id' => 1, 'mtime' => '2026-01-01 00:00:00'],
        ];
        $service = $this->makeService($cars);
        $xml     = $service->buildXml(self::BASE_URL);

        $this->assertStringNotContainsString('factory.php', $xml);
        $this->assertStringNotContainsString('privacy.php', $xml);
    }

    public function testBuildXmlWithNoCarsProducesOnlyStaticPages(): void
    {
        $service = $this->makeService([]);
        $xml     = $service->buildXml(self::BASE_URL);

        $sitemap = simplexml_load_string($xml);
        $this->assertNotFalse($sitemap, 'Sitemap output must be well-formed XML even with zero cars');
        $sitemap->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = $sitemap->xpath('//s:url');

        $this->assertCount(15, $urls);
        $this->assertStringNotContainsString('details.php?car_id=', $xml);
    }

    /**
     * A sitemap URL must be the page's own canonical, or search engines get
     * two conflicting signals (#1960).
     */
    public function testBuildXmlStaticUrlsMatchTheirCanonicalUrls(): void
    {
        $sitemap = simplexml_load_string($this->makeService([])->buildXml(self::BASE_URL));
        $this->assertNotFalse($sitemap);
        $sitemap->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locs = $sitemap->xpath('//s:url/s:loc');
        $this->assertNotEmpty($locs);

        foreach ($locs as $loc) {
            $url = (string) $loc;
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->assertSame(CanonicalUrl::build(self::BASE_URL, $path), $url);
        }
    }

    public function testCarEntryHasMonthlyChangefreqAndPointEightPriority(): void
    {
        $cars = [
            (object) ['id' => 42, 'mtime' => '2026-05-20 12:00:00'],
        ];
        $service = $this->makeService($cars);
        $xml     = $service->buildXml(self::BASE_URL);

        $sitemap = simplexml_load_string($xml);
        $this->assertNotFalse($sitemap);
        $sitemap->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $carUrls = $sitemap->xpath('//s:url[s:loc="' . self::BASE_URL . '/app/owner/cars/details.php?car_id=42"]');

        $this->assertCount(1, $carUrls);
        $carUrl = $carUrls[0];
        $this->assertSame('monthly', (string) $carUrl->changefreq);
        $this->assertSame('0.8', (string) $carUrl->priority);
    }
}
