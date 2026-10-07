<?php

declare(strict_types=1);

use ElanRegistry\Car\CarValidator;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1055: a website with a valid scheme but no host was stored verbatim.
 * CarValidatorTest covers the other malformed and non-http(s) URLs.
 *
 * @issue 1055
 * @link https://github.com/unibrain1/elanregistry/issues/1055
 */
#[Group('regression')]
final class Issue1055RegressionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function schemeWithoutHostProvider(): array
    {
        return [
            'https scheme only' => ['https://'],
            'http scheme only'  => ['http:'],
        ];
    }

    #[DataProvider('schemeWithoutHostProvider')]
    public function testSchemeWithoutHostIsRejected(string $url): void
    {
        $this->expectException(CarValidationException::class);

        (new CarValidator())->validateAndSanitizeFields(['website' => $url], false);
    }
}
