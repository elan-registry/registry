<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\LogCategories;
use ElanRegistry\SitemapService;

/**
 * Public sitemap.xml (#1373) — served at /sitemap.xml via an .htaccess
 * internal rewrite (no redirect; crawler-visible URL stays /sitemap.xml).
 *
 * No auth, no CSRF, no rate limit: this must be freely, repeatedly fetchable
 * by search-engine crawlers, unlike every other file in app/api/shared/.
 */

require_once '../../../users/init.php';

try {
    $service = new SitemapService(new CarRepository(dbi()));
    // getBaseUrl(), not $current_origin: it includes $us_url_root, and it falls
    // back to the email.verify_url setting when the request's host is not one
    // this application serves.
    $xml = $service->buildXml(getBaseUrl());

    header('Content-Type: application/xml; charset=UTF-8');
    // No auth, no rate limit (see class docblock above) — cache-control lets
    // Cloudflare edge-cache this instead of hitting the DB on every crawl.
    header('Cache-Control: public, max-age=3600');
    echo $xml;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/xml; charset=UTF-8');
    logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'sitemap.xml generation failed: ' . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
}
