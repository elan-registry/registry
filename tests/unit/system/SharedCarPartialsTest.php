<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #2495: app/owner/cars/details.php and app/views/cars/_verify_landing.php
 * share the car section partials. These are source-text checks: neither
 * page can be required from PHPUnit. The integration test
 * VerifyCarLandingPageTest checks the rendered landing page.
 */
#[Group('system')]
#[Group('unit')]
final class SharedCarPartialsTest extends TestCase
{
    private const DETAILS = 'app/owner/cars/details.php';
    private const LANDING = 'app/views/cars/_verify_landing.php';
    private const LANDING_PARTIALS = [
        '_owner_info_card.php', '_location_map_card.php', '_photos_card.php', '_location_map_scripts.php',
    ];
    private const FACTORY_PARTIAL = '_factory_data_card.php';

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);

        return $content;
    }

    /** @return array<string, array{string}> */
    public static function pageProvider(): array
    {
        return ['details page' => [self::DETAILS], 'verify landing' => [self::LANDING]];
    }

    /** @return array<string, array{string, list<string>}> */
    public static function pagePartialsProvider(): array
    {
        return [
            'details page'   => [self::DETAILS, [...self::LANDING_PARTIALS, self::FACTORY_PARTIAL]],
            'verify landing' => [self::LANDING, self::LANDING_PARTIALS],
        ];
    }

    private static function includePattern(string $partial): string
    {
        return '/include(_once)?\s+[^;]*' . preg_quote($partial, '/') . '/';
    }

    /**
     * @param list<string> $partials
     */
    #[DataProvider('pagePartialsProvider')]
    public function testPageIncludesSharedSectionPartials(string $page, array $partials): void
    {
        $content = $this->source($page);

        foreach ($partials as $partial) {
            $this->assertMatchesRegularExpression(
                self::includePattern($partial),
                $content,
                "{$page} must include {$partial}"
            );
            $this->assertFileExists(dirname(__DIR__, 3) . '/app/views/cars/' . $partial);
        }
    }

    /**
     * The landing has no Factory Data section. Only the details page shows it.
     */
    public function testVerifyLandingDoesNotIncludeFactoryDataCard(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            self::includePattern(self::FACTORY_PARTIAL),
            $this->source(self::LANDING),
            'The verify landing must not include ' . self::FACTORY_PARTIAL
        );
    }

    #[DataProvider('pageProvider')]
    public function testPageDoesNotInlineSectionMarkup(string $page): void
    {
        $content = $this->source($page);

        foreach (['id="map"', 'fa-images', 'carDetailsMapConfig', 'Owner Name'] as $needle) {
            $this->assertStringNotContainsString($needle, $content, "{$page} must not inline {$needle}; the partial owns it");
        }
    }

    public function testPartialsHoldTheSectionMarkup(): void
    {
        $dir = dirname(__DIR__, 3) . '/app/views/cars/';
        $this->assertStringContainsString('id="map"', (string) file_get_contents($dir . '_location_map_card.php'));
        $this->assertStringContainsString('fa-images', (string) file_get_contents($dir . '_photos_card.php'));
        $this->assertStringContainsString('carDetailsMapConfig', (string) file_get_contents($dir . '_location_map_scripts.php'));
        $this->assertStringContainsString('Owner Name', (string) file_get_contents($dir . '_owner_info_card.php'));
    }

    public function testDetailsPageShowsOwnerIdOnTheOwnerCard(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$showOwnerId\s*=\s*true;\s*include[^;]*_owner_info_card\.php/',
            $this->source(self::DETAILS),
            'details.php must set $showOwnerId = true just before it includes the owner card'
        );
    }

    public function testPhotosCardCallsDisplayCarousel(): void
    {
        $this->assertStringContainsString(
            '<?= ElanRegistry\CarView::displayCarousel($car) ?>',
            $this->source('app/views/cars/_photos_card.php'),
            'The call must be an output tag, not only a docblock mention'
        );
    }
}
