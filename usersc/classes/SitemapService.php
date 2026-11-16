<?php

declare(strict_types=1);

namespace ElanRegistry;

use ElanRegistry\Car\CarRepository;

/**
 * Builds the public sitemap.xml body for elanregistry.org (#1373).
 */
class SitemapService
{
    /** @var list<array{path: string, changefreq: string, priority: string}> */
    private const STATIC_PAGES = [
        ['path' => '/app/owner/cars/', 'changefreq' => 'weekly', 'priority' => '0.9'],
        ['path' => '/app/owner/reports/statistics.php', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ['path' => '/docs/reference/paint-colors.php', 'changefreq' => 'yearly', 'priority' => '0.7'],
        ['path' => '/docs/reference/', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ['path' => '/docs/reference/identification-guide.php', 'changefreq' => 'yearly', 'priority' => '0.6'],
        ['path' => '/docs/guides/', 'changefreq' => 'monthly', 'priority' => '0.5'],
        ['path' => '/docs/reference/assets/Elan_26_36_Workshop_Manual.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['path' => '/docs/reference/assets/elan_s1_s2_coupe_masterpartslist.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['path' => '/docs/reference/assets/2016%20Jan%20Elan%20Engine%20Types.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['path' => '/docs/reference/assets/2019_Jan_The_Elan_Super_Safety.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['path' => '/docs/reference/assets/All%20Elan%20and%20Elan%20Plus%202%20Paint%20Codes.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['path' => '/docs/reference/assets/Engine%20number%20breakdown%20%28Miles%20Wilkins%29.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['path' => '/docs/reference/assets/2014%20Jul%20Elan%20Gearknobs.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['path' => '/docs/reference/assets/2014%20Oct%20Elan%20and%20Plus%202%20Steering%20Wheels.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['path' => '/docs/reference/assets/Lotus%20Elan%20Plus%202%20serial%20numbers.pdf', 'changefreq' => 'yearly', 'priority' => '0.4'],
    ];

    public function __construct(private CarRepository $carRepository) {}

    /**
     * @param string $baseUrl Origin to prefix every URL with (e.g. https://elanregistry.org)
     * @return string Well-formed sitemap.xml document body
     */
    public function buildXml(string $baseUrl): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach (self::STATIC_PAGES as $page) {
            $this->writeUrl($xml, $baseUrl . $page['path'], $page['changefreq'], $page['priority']);
        }

        foreach ($this->carRepository->getAllForSitemap() as $car) {
            $this->writeUrl(
                $xml,
                $baseUrl . '/app/owner/cars/details.php?car_id=' . $car->id,
                'monthly',
                '0.8',
                $car->mtime,
            );
        }

        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory();
    }

    private function writeUrl(\XMLWriter $xml, string $loc, string $changefreq, string $priority, ?string $lastmod = null): void
    {
        $xml->startElement('url');
        $xml->writeElement('loc', $loc);
        if ($lastmod !== null) {
            $xml->writeElement('lastmod', date('Y-m-d', strtotime($lastmod)));
        }
        $xml->writeElement('changefreq', $changefreq);
        $xml->writeElement('priority', $priority);
        $xml->endElement();
    }
}
