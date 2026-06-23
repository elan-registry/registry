<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/version.php';

/**
 * Tests for ApplicationVersion::tagOnly() and its underlying suffix-stripping
 * helper used by the public footer (issue #876).
 */
#[Group('system')]
#[Group('application-version')]
class ApplicationVersionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function suffixCases(): array
    {
        return [
            'plain release tag is returned unchanged' => ['v2.23.0', 'v2.23.0'],
            'git describe build suffix is stripped'   => ['v2.23.0-17-g9fd3946', 'v2.23.0'],
            'longer SHA suffix is stripped'           => ['v2.24.0-3-gabcdef0123', 'v2.24.0'],
            'rc tag without build suffix is preserved' => ['v2.24.0-rc.1', 'v2.24.0-rc.1'],
            'empty input falls back to unknown'       => ['', 'unknown'],
            'unknown sentinel is preserved'           => ['unknown', 'unknown'],
        ];
    }

    #[DataProvider('suffixCases')]
    public function testStripBuildSuffix(string $input, string $expected): void
    {
        $this->assertSame($expected, ApplicationVersion::stripBuildSuffix($input));
    }

    public function testTagOnlyReturnsNonEmptyShortTag(): void
    {
        $tag = ApplicationVersion::tagOnly();

        $this->assertNotSame('', $tag, 'tagOnly() must never return an empty string');
        $this->assertDoesNotMatchRegularExpression(
            '/-\d+-g[0-9a-f]+$/',
            $tag,
            'tagOnly() output must not retain the git describe build suffix'
        );
    }
}
