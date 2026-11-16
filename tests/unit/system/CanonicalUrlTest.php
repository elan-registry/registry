<?php

declare(strict_types=1);

use ElanRegistry\CanonicalUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #1960: the canonical URL must not carry request input that the page did
 * not validate, and equal resources must give byte-identical canonicals.
 */
#[Group('system')]
#[Group('head-tags')]
class CanonicalUrlTest extends TestCase
{
    private const ORIGIN = 'https://elanregistry.org';
    private const DETAILS = '/app/owner/cars/details.php';

    public function testBuildWithNoQueryReturnsUrlWithoutQueryString(): void
    {
        $this->assertSame(
            'https://elanregistry.org/docs/reference/paint-colors.php',
            CanonicalUrl::build(self::ORIGIN, '/docs/reference/paint-colors.php')
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function scriptPathProvider(): array
    {
        return [
            'root index.php becomes /'        => ['/index.php', 'https://elanregistry.org/'],
            'nested index.php becomes dir'    => ['/app/owner/cars/index.php', 'https://elanregistry.org/app/owner/cars/'],
            'notindex.php stays unchanged'    => ['/x/notindex.php', 'https://elanregistry.org/x/notindex.php'],
            'index.php as a directory stays'  => ['/index.php/x.php', 'https://elanregistry.org/index.php/x.php'],
            'empty script path becomes /'     => ['', 'https://elanregistry.org/'],
            'root path stays /'               => ['/', 'https://elanregistry.org/'],
        ];
    }

    #[DataProvider('scriptPathProvider')]
    public function testBuildNormalisesScriptPath(string $scriptPath, string $expected): void
    {
        $this->assertSame($expected, CanonicalUrl::build(self::ORIGIN, $scriptPath));
    }

    public function testBuildAddsQueryFromArray(): void
    {
        $this->assertSame(
            'https://elanregistry.org/app/owner/cars/details.php?car_id=952',
            CanonicalUrl::build(self::ORIGIN, self::DETAILS, ['car_id' => 952])
        );
    }

    public function testBuildKeepsQueryParameterOrder(): void
    {
        $this->assertSame(
            'https://elanregistry.org/docs/pdf-viewer.php?subdir=reference&doc=All%20Elan%20Paint%20Codes.pdf',
            CanonicalUrl::build(
                self::ORIGIN,
                '/docs/pdf-viewer.php',
                ['subdir' => 'reference', 'doc' => 'All Elan Paint Codes.pdf']
            )
        );
    }

    /**
     * @return array<string, array{0: array<string, int|string|null>, 1: string}>
     */
    public static function encodedQueryProvider(): array
    {
        return [
            'markup in value'      => [['doc' => '"><script>alert(1)</script>'], '?doc=%22%3E%3Cscript%3Ealert%281%29%3C%2Fscript%3E'],
            'ampersand and equals' => [['doc' => 'a&b=c'], '?doc=a%26b%3Dc'],
            'hash and question'    => [['doc' => 'a#b?c'], '?doc=a%23b%3Fc'],
            'newline in value'     => [['doc' => "a\nb"], '?doc=a%0Ab'],
            'space in value'       => [['doc' => 'Mag _issue_50.pdf'], '?doc=Mag%20_issue_50.pdf'],
            'plus in value'        => [['doc' => 'a+b'], '?doc=a%2Bb'],
            'markup in key'        => [['<k>' => 'v'], '?%3Ck%3E=v'],
        ];
    }

    /**
     * @param array<string, int|string|null> $query
     */
    #[DataProvider('encodedQueryProvider')]
    public function testBuildEncodesSpecialCharactersInQuery(array $query, string $expectedQuery): void
    {
        $url = CanonicalUrl::build(self::ORIGIN, self::DETAILS, $query);

        $this->assertSame(self::ORIGIN . self::DETAILS . $expectedQuery, $url);
        foreach (['"', '<', '>', "\n", ' '] as $raw) {
            $this->assertStringNotContainsString($raw, $url);
        }
    }

    /**
     * @return array<string, array{0: array<string, int|string|null>}>
     */
    public static function emptyQueryProvider(): array
    {
        return [
            'empty array'     => [[]],
            'only null value' => [['car_id' => null]],
        ];
    }

    /**
     * @param array<string, int|string|null> $query
     */
    #[DataProvider('emptyQueryProvider')]
    public function testBuildWithEmptyQueryAddsNoQuestionMark(array $query): void
    {
        $this->assertSame(
            self::ORIGIN . self::DETAILS,
            CanonicalUrl::build(self::ORIGIN, self::DETAILS, $query)
        );
    }

    public function testBuildGivesIdenticalUrlForEquivalentCarIds(): void
    {
        $expected = 'https://elanregistry.org/app/owner/cars/details.php?car_id=952';

        foreach (['0000952', '952-spam', '952'] as $raw) {
            $this->assertSame(
                $expected,
                CanonicalUrl::build(self::ORIGIN, self::DETAILS, ['car_id' => (int) $raw]),
                "car_id '$raw' must give the canonical for car 952"
            );
        }
    }
}
