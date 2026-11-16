<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RobotsTxtGroups;

/**
 * Regression test for Issue #2246: Google indexed test.elanregistry.org.
 * Test now allows crawl and sends `X-Robots-Tag: noindex` from .htaccess,
 * only for the Test host. Production must stay indexable.
 *
 * @issue 2246
 * @link https://github.com/elan-registry/registry/issues/2246
 * @category regression
 */
#[Group('regression')]
final class Issue2246TestHostNoindexRegressionTest extends TestCase
{
    private const HTACCESS = __DIR__ . '/../../../.htaccess';

    /**
     * @return list<string> trimmed directive lines, without blank and comment lines
     */
    private function directiveLines(): array
    {
        $contents = file_get_contents(self::HTACCESS);
        $this->assertIsString($contents, '.htaccess must be readable');
        $lines = array_map('trim', preg_split('/\R/', $contents) ?: []);

        return array_values(preg_grep('/^[^#]/', $lines) ?: []);
    }

    /**
     * @return array{regex: string, var: string}
     */
    private function hostRule(): array
    {
        $matches = [];
        foreach ($this->directiveLines() as $line) {
            if (preg_match('/^SetEnvIfNoCase\s+Host\s+(\S+)\s+(\S+)$/', $line, $matches) === 1) {
                return ['regex' => $matches[1], 'var' => $matches[2]];
            }
        }
        $this->fail('.htaccess must contain a `SetEnvIfNoCase Host <regex> <VAR>` line (#2246).');
    }

    /**
     * @return list<string>
     */
    private function robotsTagLines(): array
    {
        return array_values(array_filter(
            $this->directiveLines(),
            static fn (string $line): bool => stripos($line, 'X-Robots-Tag') !== false
        ));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function hostProvider(): array
    {
        return [
            'test host' => ['test.elanregistry.org', true],
            'test host, mixed case' => ['TEST.ElanRegistry.org', true],
            'production apex' => ['elanregistry.org', false],
            'production www' => ['www.elanregistry.org', false],
            'localhost' => ['localhost', false],
            'localhost with port' => ['localhost:8001', false],
            'suffix attack' => ['test.elanregistry.org.evil.com', false],
            'prefix attack' => ['xtest.elanregistry.org', false],
        ];
    }

    #[DataProvider('hostProvider')]
    public function testHostRegexMatchesOnlyTheTestHost(string $host, bool $expected): void
    {
        $regex = $this->hostRule()['regex'];

        // '~' is not in the Host regex, so it is a safe PCRE delimiter.
        $this->assertSame(
            $expected,
            preg_match('~' . $regex . '~i', $host) === 1,
            sprintf('Host "%s" against /%s/i', $host, $regex)
        );
    }

    public function testNoindexHeaderIsConditionalOnTheHostVariable(): void
    {
        $var = $this->hostRule()['var'];
        $noindex = array_values(array_filter(
            $this->robotsTagLines(),
            static fn (string $line): bool => str_starts_with($line, 'Header always set X-Robots-Tag')
        ));

        $this->assertNotEmpty($noindex, '.htaccess must set X-Robots-Tag with `Header always set` (#2246).');
        foreach ($noindex as $line) {
            $this->assertStringContainsString('noindex', $line);
            $this->assertStringEndsWith('env=' . $var, $line);
        }
    }

    public function testEveryRobotsTagLineIsConditionalOnTheHostVariable(): void
    {
        $var = $this->hostRule()['var'];
        $lines = $this->robotsTagLines();
        $this->assertNotEmpty($lines, '.htaccess must contain an X-Robots-Tag line (#2246).');

        // A missing, negated (env=!VAR) or other-variable condition could make production noindex.
        foreach ($lines as $line) {
            $this->assertStringEndsWith(
                ' env=' . $var,
                $line,
                'Every X-Robots-Tag line must depend on the Test host variable, or production can become noindex: '
                    . $line
            );
        }
    }

    public function testOnlyTheHostRuleSetsTheHostVariable(): void
    {
        $var = $this->hostRule()['var'];
        // Count every directive that names the variable, whatever sets it
        // (SetEnv*, BrowserMatch*, RewriteRule [E=]), except the Header lines that read it.
        $setters = array_values(array_filter(
            $this->directiveLines(),
            static fn (string $line): bool => preg_match('/\b' . preg_quote($var, '/') . '\b/i', $line) === 1
                && preg_match('/^Header\s/i', $line) !== 1
        ));

        $this->assertCount(
            1,
            $setters,
            "Only the SetEnvIfNoCase Host line may set {$var}. Another setter can make production noindex: "
                . implode(' | ', $setters)
        );
    }

    /**
     * A Disallow hides the noindex header from Google on the paths it covers.
     * Google follows its most specific group, so no group may disallow anything.
     */
    public function testRobotsTestFileHasNoDisallowRule(): void
    {
        $groups = RobotsTxtGroups::parse(RobotsTxtGroups::readRootFile('deploy/test-overrides/robots-test.txt'));
        $disallows = array_filter(
            array_merge(...array_column($groups, 'rules')),
            static fn (array $rule): bool => $rule['type'] === 'disallow' && $rule['path'] !== ''
        );

        $this->assertSame([], array_values($disallows), 'robots-test.txt must not Disallow any path (#2246).');
    }
}
