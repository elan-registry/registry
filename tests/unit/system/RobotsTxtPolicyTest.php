<?php

declare(strict_types=1);

use ElanRegistry\SitemapService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #1413: the AI-bot group's Allow/Disallow lines depend on order
 * (longest-prefix match), so an edit can silently change what is reachable.
 * This checks the policy, not whether crawlers obey it.
 */
#[Group('system')]
class RobotsTxtPolicyTest extends TestCase
{
    private const ROBOTS_FILE = 'robots.txt';
    private const ROBOTS_TEST_FILE = 'robots-test.txt';
    private const LLMS_FILE = 'llms.txt';
    private const LLMS_TEST_FILE = 'llms-test.txt';

    /** Represents every crawler in the AI-bot group. */
    private const AI_BOT = 'GPTBot';

    private string $rootDir = '';

    /** @var array<int, array{agents: list<string>, rules: list<array{type: string, path: string}>}> */
    private array $groups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = dirname(__DIR__, 3);

        $filePath = $this->rootDir . '/' . self::ROBOTS_FILE;
        $this->assertFileExists($filePath, self::ROBOTS_FILE . ' must exist (Issue #1413)');

        $this->groups = $this->parseRobotsTxt((string)file_get_contents($filePath));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function pathAllowanceProvider(): array
    {
        return [
            // Allowed: what /llms.txt says AI crawlers may index.
            'car detail page' => ['/app/owner/cars/details.php?car_id=1', true],
            'docs hub' => ['/docs/', true],
            'nested reference doc' => ['/docs/reference/paint-colors.php', true],
            'car histories' => ['/docs/stories/', true],
            // Blocked by an override: the case most likely to regress.
            'login-gated edit page' => ['/app/owner/cars/edit.php', false],
            'owner contact form' => ['/app/owner/contact/', false],
            'admin area' => ['/app/admin/', false],
            'api endpoint' => ['/app/api/cars/list.php', false],
        ];
    }

    #[DataProvider('pathAllowanceProvider')]
    public function testAiBotPathAllowance(string $path, bool $expectedAllowed): void
    {
        $isAllowed = $this->isAllowed(self::AI_BOT, $path);

        $this->assertSame(
            $expectedAllowed,
            $isAllowed,
            sprintf(
                "%s must be %s for %s (Issue #1413)",
                $path,
                $expectedAllowed ? 'allowed' : 'disallowed',
                self::AI_BOT
            )
        );
    }

    public function testDefaultUserAgentGroupIsUnchanged(): void
    {
        $this->assertFalse($this->isAllowed('SomeSearchEngine', '/users/'));
        $this->assertFalse($this->isAllowed('SomeSearchEngine', '/app/admin/'));
        $this->assertFalse($this->isAllowed('SomeSearchEngine', '/app/owner/cars/edit.php'));
        $this->assertTrue($this->isAllowed('SomeSearchEngine', '/app/owner/cars/details.php?car_id=1'));
    }

    /**
     * A sitemap URL blocked by robots.txt gives a Search Console error.
     * STATIC_PAGES is read by reflection so the list cannot drift.
     */
    public function testSitemapStaticPagesAreCrawlable(): void
    {
        $reflection = new \ReflectionClass(SitemapService::class);
        /** @var list<array{path: string, changefreq: string, priority: string}> $staticPages */
        $staticPages = $reflection->getConstant('STATIC_PAGES');
        $this->assertNotEmpty($staticPages, 'SitemapService::STATIC_PAGES must be non-empty for this test to be meaningful');

        foreach ($staticPages as $page) {
            $this->assertTrue(
                $this->isAllowed('Googlebot', $page['path']),
                "{$page['path']} is listed in SitemapService::STATIC_PAGES but blocked by robots.txt's " .
                    "default group — Google would report \"Submitted URL blocked by robots.txt\" for this sitemap entry"
            );
        }
    }

    /**
     * llms.txt must not contradict the enforced policy. Not set equality:
     * llms.txt may list a path that a broader robots.txt rule covers.
     */
    public function testLlmsTxtAgreesWithRobotsTxtAiBotPolicy(): void
    {
        $llmsPath = $this->rootDir . '/' . self::LLMS_FILE;
        $this->assertFileExists($llmsPath, self::LLMS_FILE . ' must exist (Issue #1413)');

        $llms = $this->parseLlmsTxt((string)file_get_contents($llmsPath));
        $this->assertNotEmpty($llms['allow'], 'llms.txt ## Allow section must list at least one path');
        $this->assertNotEmpty($llms['disallow'], 'llms.txt ## Disallow section must list at least one path');

        foreach ($llms['allow'] as $path) {
            $this->assertTrue(
                $this->isAllowed(self::AI_BOT, $path),
                "llms.txt claims $path is allowed, but robots.txt's AI-bot group blocks it (Issue #1413)"
            );
        }
        foreach ($llms['disallow'] as $path) {
            $this->assertFalse(
                $this->isAllowed(self::AI_BOT, $path),
                "llms.txt claims $path is disallowed, but robots.txt's AI-bot group allows it (Issue #1413)"
            );
        }
    }

    /**
     * post-receive swaps these in on Test deploys. Test has no basic auth, so
     * without them it gets indexed.
     */
    public function testTestEnvironmentLockdownFilesExist(): void
    {
        $this->assertFileExists(
            $this->rootDir . '/' . self::ROBOTS_TEST_FILE,
            self::ROBOTS_TEST_FILE . ' must exist — post-receive swaps it over robots.txt on Test deploys'
        );
        $this->assertFileExists(
            $this->rootDir . '/' . self::LLMS_TEST_FILE,
            self::LLMS_TEST_FILE . ' must exist — post-receive swaps it over llms.txt on Test deploys (Issue #1413)'
        );
    }

    /**
     * Longest prefix wins (RFC 9309 §2.2.2); no dedicated group falls back to `*`.
     * On a tie the first rule wins, not Google's least-restrictive rule. No
     * current rule pair ties, but a new equal-length pair could.
     */
    private function isAllowed(string $userAgent, string $path): bool
    {
        $group = $this->findGroup($userAgent) ?? $this->findGroup('*');
        $this->assertNotNull($group, "No matching group (not even '*') found for $userAgent");

        $bestLength = -1;
        $bestType = 'allow';
        foreach ($group['rules'] as $rule) {
            if ($rule['path'] === '' || strncmp($path, $rule['path'], strlen($rule['path'])) !== 0) {
                continue;
            }
            $length = strlen($rule['path']);
            if ($length > $bestLength) {
                $bestLength = $length;
                $bestType = $rule['type'];
            }
        }

        return $bestType !== 'disallow';
    }

    /**
     * @return array{agents: list<string>, rules: list<array{type: string, path: string}>}|null
     */
    private function findGroup(string $userAgent): ?array
    {
        foreach ($this->groups as $group) {
            if (in_array($userAgent, $group['agents'], true)) {
                return $group;
            }
        }
        return null;
    }

    /**
     * @return array<int, array{agents: list<string>, rules: list<array{type: string, path: string}>}>
     */
    private function parseRobotsTxt(string $content): array
    {
        /** @var array<int, array{agents: list<string>, rules: list<array{type: string, path: string}>}> $groups */
        $groups = [];
        /** @var list<string> $pendingAgents */
        $pendingAgents = [];
        /** @var list<array{type: string, path: string}> $currentRules */
        $currentRules = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $matches) === 1) {
                // A User-agent line after rules starts a new group (RFC 9309 §2.2.1).
                if ($currentRules !== []) {
                    $groups[] = ['agents' => $pendingAgents, 'rules' => $currentRules];
                    $pendingAgents = [];
                    $currentRules = [];
                }
                $pendingAgents[] = trim($matches[1]);
                continue;
            }

            if (preg_match('/^(Allow|Disallow):\s*(.*)$/i', $line, $matches) === 1) {
                $currentRules[] = ['type' => strtolower($matches[1]), 'path' => trim($matches[2])];
            }
        }
        if ($pendingAgents !== []) {
            $groups[] = ['agents' => $pendingAgents, 'rules' => $currentRules];
        }

        return $groups;
    }

    /** @return array{allow: list<string>, disallow: list<string>} */
    private function parseLlmsTxt(string $content): array
    {
        $allow = [];
        $disallow = [];
        $currentSection = null;

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim((string)$line);

            if (preg_match('/^##\s*(Allow|Disallow)\s*$/i', $line, $matches) === 1) {
                $currentSection = strtolower($matches[1]);
                continue;
            }
            if ($line !== '' && str_starts_with($line, '#')) {
                $currentSection = null;
                continue;
            }
            if ($currentSection === null || !str_starts_with($line, '-')) {
                continue;
            }

            if (preg_match('/^-\s*(\S+)/', $line, $matches) === 1) {
                if ($currentSection === 'allow') {
                    $allow[] = $matches[1];
                } else {
                    $disallow[] = $matches[1];
                }
            }
        }

        return ['allow' => $allow, 'disallow' => $disallow];
    }
}
