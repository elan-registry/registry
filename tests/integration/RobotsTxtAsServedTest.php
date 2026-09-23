<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * As-served robots.txt verification for Issue #1542.
 *
 * `tests/unit/system/RobotsTxtPolicyTest.php` reads the repo's own
 * `robots.txt` file and nothing else. That makes it structurally blind to an
 * edge/CDN layer (Cloudflare, in our case) injecting an *additional*
 * `User-agent` group into the response before it reaches a real crawler —
 * the file on disk is still correct, but the policy actually in force is not.
 * That blind spot has already produced two real bugs:
 *
 * - **#1537**: Cloudflare injected a second `User-agent: *` group carrying
 *   `Allow: /` alongside our `Disallow: /` on test.elanregistry.org. Both
 *   rules match `/` at prefix length 1 — an exact tie — and Google's
 *   documented "least restrictive wins" tie-break picks `Allow: /`. The
 *   intended blanket block on the Test host was never actually in effect.
 * - **#1541**: Cloudflare injected `Disallow: /` for 9 AI-bot tokens on
 *   production. Our own AI-bot group carries `Allow: /docs/` for those same
 *   tokens — not a tie, a strictly longer (more specific) prefix match — so
 *   plain RFC 9309 longest-prefix-match hands the win to our `Allow`,
 *   silently overriding Cloudflare's block.
 *
 * Both issues were closed by turning **off** Cloudflare's "Managed
 * robots.txt" dashboard toggle — a manual, out-of-repo setting that no code
 * review, diff, or CI check can observe. `robots.txt` and `robots-test.txt`
 * are structurally unchanged and still carry the exact shapes that produced
 * both bugs, so the risk is dormant, not eliminated. #1541's closing comment
 * asked for this test specifically, to catch a future re-enable of that
 * toggle (or any equivalent edge-injection feature, from any vendor).
 *
 * Design notes:
 *
 * - **Why test.elanregistry.org, not production.** Cloudflare's "Managed
 *   robots.txt" is a single per-zone toggle (per #1541's postmortem) — a
 *   re-enable hits both hosts at once, so the Test host is not a narrower
 *   check, it is a strictly more sensitive canary for the same event: its
 *   entire crawler policy is one blanket `Disallow: /` (`robots-test.txt`,
 *   swapped over `robots.txt` by `scripts/server-hooks/post-receive` on
 *   every Test deploy), so any injected group is both maximally consequential
 *   — the un-authenticated Test site relies solely on that block to stay
 *   unindexed — and trivially detectable against three expected lines.
 *   Residual gap: Cloudflare's AI Crawl Control can in principle be scoped
 *   per hostname, so a production-only re-enable would leave this test green
 *   while #1541 recurred verbatim on the host it originally affected. This
 *   test does not fetch production; closing that gap would mean adding a
 *   second live fetch against `elanregistry.org` / `PRODUCTION_ROBOTS_FILE`.
 * - **Why a live fetch and not a fixture.** A dashboard toggle has no
 *   observable artifact in this repo; fetching the real response is the only
 *   way to see its effect. A checked-in "what Cloudflare injects" fixture
 *   would be frozen at whatever that vendor shipped on the day it was
 *   written and would drift silently.
 * - **Why `tests/integration/`.** This test needs outbound network access,
 *   so it must never run in CI, `composer test:quick`, or
 *   `composer test:medium`. Directory placement alone achieves that (the
 *   tiers are directory-scoped). The class-level `live-network` group goes
 *   further: `phpunit-integration.xml` excludes that group, so this class is
 *   also skipped by `composer test:integration` and `composer test:full`,
 *   which would otherwise report red for environmental reasons — most
 *   commonly the Test host being unreachable, or a local `robots-test.txt`
 *   edit that has not been deployed there yet. Run it explicitly with:
 *     vendor/bin/phpunit -c phpunit-integration.xml --group live-network
 *   The live-fetch tests skip rather than fail when the network is
 *   unavailable; the data-provider tests below run unconditionally.
 * - **Deliberate scope limit.** `foreignGroups()` compares *parsed* groups,
 *   not raw bytes. Reformatting, comment edits, or trailing-whitespace churn
 *   in `robots-test.txt` will never trip it; only a semantically distinct
 *   `User-agent` group in the served response will. That is by design — a
 *   byte-diff would be noisy enough to get muted, which is exactly how this
 *   class of bug goes unnoticed in the first place.
 * - **Not every red run here is edge injection.** The live fetch is compared
 *   against *this branch's* `robots-test.txt`, so a genuine failure has two
 *   other plausible causes worth ruling out first: the local baseline was
 *   edited here but not yet deployed to test.elanregistry.org, or the Test
 *   host's `post-receive` robots.txt swap itself failed/drifted and is now
 *   serving production's file. Both produce the same "foreign group(s)
 *   detected" symptom as a real Cloudflare re-injection.
 *
 * The parser and rule evaluator here are a deliberate, self-contained
 * reimplementation rather than shared code with `RobotsTxtPolicyTest.php`:
 * that test's `findGroup()` returns only the *first* matching group, which
 * would make a second injected group for the same token invisible — the
 * precise failure mode this test exists to detect.
 *
 * @phpstan-type RobotsRule array{type: string, path: string}
 * @phpstan-type RobotsGroup array{agents: list<string>, rules: list<RobotsRule>}
 */
#[Group('integration')]
#[Group('live-network')]
final class RobotsTxtAsServedTest extends TestCase
{
    private const LIVE_URL = 'https://test.elanregistry.org/robots.txt';
    private const LOCAL_BASELINE_FILE = 'robots-test.txt';
    private const PRODUCTION_ROBOTS_FILE = 'robots.txt';

    /** @var array<int, RobotsGroup> */
    private static array $liveGroups = [];

    /** @var array<int, RobotsGroup> */
    private static array $localGroups = [];

    /** Non-null when the live fetch could not be completed; the reason is reported as a skip. */
    private static ?string $fetchSkipReason = null;

    /**
     * Fetches the live robots.txt exactly once for the whole class — the
     * assertions below are all read-only views of the same response, and
     * re-fetching per test would multiply network calls for no added signal.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!function_exists('curl_init')) {
            self::$fetchSkipReason = 'ext-curl is not available — cannot fetch ' . self::LIVE_URL;
            return;
        }

        $ch = curl_init(self::LIVE_URL);
        if ($ch === false) {
            self::$fetchSkipReason = 'curl_init() failed for ' . self::LIVE_URL;
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // MAXREDIRS bounds the *number* of hops (no unbounded redirect
            // chains); PROTOCOLS/REDIR_PROTOCOLS restrict the *scheme* to
            // HTTPS only. Neither constrains the destination *host* — a
            // single HTTPS→HTTPS redirect elsewhere would still be followed.
            // The effective-host check after curl_exec() below is what
            // guards against that.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 5,
            // robots.txt is expected to be a handful of lines; a captive
            // portal, misroute, or compromised edge returning something
            // enormous should fail cleanly (into the existing $body ===
            // false skip path) instead of buffering an unbounded response
            // into memory.
            CURLOPT_MAXFILESIZE => 65536,
            // Deliberately synthetic: never spoof a real crawler's UA,
            // since the edge layer may serve a different robots.txt to
            // recognized bots and we want the response an ordinary client
            // sees.
            CURLOPT_USERAGENT => 'ElanRegistry-IntegrationTest/1.0 (+https://elanregistry.org)',
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            self::$fetchSkipReason = sprintf(
                'Live fetch of %s failed: cURL error (%d) %s',
                self::LIVE_URL,
                curl_errno($ch),
                curl_error($ch)
            );
            return;
        }

        // The actual cross-host guard: CURLOPT_MAXREDIRS/CURLOPT_PROTOCOLS
        // bound hop count and scheme but not destination, so a redirect to a
        // different HTTPS host would be followed silently and its robots.txt
        // compared against our baseline — every group misreported as "foreign".
        // An unexpected host is an environmental anomaly, not a policy
        // finding, so it skips rather than fails.
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $effectiveHost = parse_url($effectiveUrl, PHP_URL_HOST);

        if ($effectiveHost !== parse_url(self::LIVE_URL, PHP_URL_HOST)) {
            self::$fetchSkipReason = sprintf(
                'Live fetch of %s was redirected to an unexpected host (%s) — comparing that host\'s '
                    . 'robots.txt against %s would misreport its groups as edge injection',
                self::LIVE_URL,
                is_string($effectiveHost) && $effectiveHost !== '' ? $effectiveHost : $effectiveUrl,
                self::LOCAL_BASELINE_FILE
            );
            return;
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            self::$fetchSkipReason = sprintf('Live fetch of %s failed: HTTP %d', self::LIVE_URL, $httpCode);
            return;
        }

        $body = (string)$body;
        if (trim($body) === '') {
            self::$fetchSkipReason = 'Live fetch of ' . self::LIVE_URL . ' returned an empty body';
            return;
        }

        $liveGroups = self::parseRobotsTxt($body);

        // A 200 whose body contains no User-agent line at all is not
        // robots.txt — it is a challenge page, WAF interstitial, or misroute.
        // Left unguarded it would fall through as a "successful" fetch with
        // zero groups, and foreignGroups([], ...) would return [] — a silent
        // green in the very test that exists to catch edge injection.
        if ($liveGroups === []) {
            self::$fetchSkipReason = 'Live fetch of ' . self::LIVE_URL . ' returned HTTP 200 but the body did '
                . 'not parse into any User-agent group — likely a challenge page, interstitial, or misroute, '
                . 'not real robots.txt content';
            return;
        }

        self::$liveGroups = $liveGroups;
        self::$localGroups = self::parseRobotsTxt(self::readRootFile(self::LOCAL_BASELINE_FILE));
    }

    /**
     * The Test host's whole reason for having its own robots.txt is a blanket
     * block. If `/` is reachable for `User-agent: *` in the *served*
     * response, the block is not actually in force — the #1537 failure mode.
     */
    public function testDefaultGroupDisallowsRootPathAsServed(): void
    {
        $this->skipIfLiveFetchUnavailable();

        $rules = self::mergedRulesForAgent(self::$liveGroups, '*');

        $this->assertFalse(
            self::resolve($rules, '/'),
            "test.elanregistry.org's live robots.txt must fully block '/' for User-agent: * — if this fails, "
                . "a permissive group (e.g. Cloudflare's Managed robots.txt) has likely been injected ahead of "
                . 'our own, recreating the #1537 tie-break bug where an injected `Allow: /` ties our `Disallow: /` '
                . 'at prefix length 1 and wins on least-restrictive-wins.'
        );
    }

    /**
     * Vendor-agnostic edge-injection detector: every group in the served
     * response must be one we actually shipped. No hardcoded Cloudflare
     * marker text and no hardcoded AI-bot token list, so this catches the
     * #1541 shape (an injected group for tokens we already cover) and any
     * future equivalent from any vendor, not just the two we have seen.
     */
    public function testNoForeignGroupsPresentInLiveRobotsTxt(): void
    {
        $this->skipIfLiveFetchUnavailable();

        $foreign = self::foreignGroups(self::$liveGroups, self::$localGroups);

        $this->assertSame(
            [],
            $foreign,
            sprintf(
                "%s served User-agent group(s) that are not in this repo's %s:\n%s\n\n"
                    . 'Investigate edge injection (Cloudflare Managed robots.txt, AI Crawl Control, or an '
                    . 'equivalent feature) before assuming the policy in this repo is the policy actually in '
                    . 'force — that assumption is what produced #1537 and #1541. An injected group can flip the '
                    . 'outcome either way depending on prefix lengths, so its mere presence is the finding.',
                self::LIVE_URL,
                self::LOCAL_BASELINE_FILE,
                self::formatGroupsForMessage($foreign)
            )
        );
    }

    /**
     * The reverse direction of the same structural diff: a group we shipped
     * that is *missing* from the served response. Edge layers and broken
     * deploys can drop content as easily as they can add it — a `post-receive`
     * robots.txt swap that half-failed, or a cache serving a stale/truncated
     * file — and dropping our only `Disallow: /` group is just as bad as
     * injecting a permissive one. Both arrays are already in memory, so this
     * costs no extra network call.
     */
    public function testNoLocalGroupsMissingFromLiveRobotsTxt(): void
    {
        $this->skipIfLiveFetchUnavailable();

        $missing = self::foreignGroups(self::$localGroups, self::$liveGroups);

        $this->assertSame(
            [],
            $missing,
            sprintf(
                "This repo's %s declares User-agent group(s) that %s is not serving:\n%s\n\n"
                    . "Investigate the Test host's post-receive robots.txt swap (it may have failed, leaving "
                    . 'production\'s robots.txt in place) or an edge cache serving a stale or truncated '
                    . 'response, before assuming the shipped policy is the policy actually in force.',
                self::LOCAL_BASELINE_FILE,
                self::LIVE_URL,
                self::formatGroupsForMessage($missing)
            )
        );
    }

    /**
     * Pins the merge/resolve algorithm against the two historical bug shapes
     * using the repo's real robots files plus a synthetic injected group.
     *
     * Runs unconditionally with no network access — if the live tests above
     * are skipping (offline developer, Test host down), these still prove the
     * evaluator itself has not regressed, so a future green run of the live
     * tests means something.
     *
     * @param list<RobotsRule> $foreignRules
     */
    #[DataProvider('mergeAlgorithmScenariosProvider')]
    public function testMergeAlgorithmHandlesHistoricalBugShapes(
        string $localFile,
        string $userAgent,
        array $foreignRules,
        string $path,
        bool $expectedAllowed,
        string $rationale
    ): void {
        $groups = self::parseRobotsTxt(self::readRootFile($localFile));
        $groups[] = ['agents' => [$userAgent], 'rules' => $foreignRules];

        $rules = self::mergedRulesForAgent($groups, $userAgent);

        $this->assertSame(
            $expectedAllowed,
            self::resolve($rules, $path),
            sprintf(
                '%s is expected to be %s for %s given %s plus the injected group. %s',
                $path,
                $expectedAllowed ? 'ALLOWED' : 'DISALLOWED',
                $userAgent,
                $localFile,
                $rationale
            )
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<RobotsRule>, 3: string, 4: bool, 5: string}>
     */
    public static function mergeAlgorithmScenariosProvider(): array
    {
        return [
            'exact tie: injected Allow beats our Disallow (#1537 shape)' => [
                self::LOCAL_BASELINE_FILE,
                '*',
                [['type' => 'allow', 'path' => '/']],
                '/',
                true,
                'Both rules match at prefix length 1, an exact tie, and RFC 9309 / Google resolve ties as '
                    . "least-restrictive-wins — so the injected `Allow: /` defeats robots-test.txt's `Disallow: /` "
                    . 'and the Test host is fully crawlable. This is #1537 exactly: the file on disk was never '
                    . 'wrong, the served policy was.',
            ],
            'non-tie: our longer Allow beats a shorter injected hard Disallow (#1541 shape — historically-accurate, not an evaluator bug)'
                => [
                    self::PRODUCTION_ROBOTS_FILE,
                    'GPTBot',
                    [['type' => 'disallow', 'path' => '/']],
                    '/docs/index.html',
                    true,
                    "Our `Allow: /docs/` (length 6) is a strictly longer prefix than the injected `Disallow: /` "
                        . '(length 1), so longest-prefix-match hands us the win outright — no tie-break involved. '
                        . 'ALLOWED is the correct, spec-compliant, and currently-accepted outcome per #1541\'s '
                        . 'postmortem: restructuring robots.txt so an edge-injected block could not be overridden '
                        . 'was proposed and explicitly rejected in favour of fixing the Cloudflare dashboard '
                        . 'toggle. Note this row is deliberately *insensitive* to the injected group: robots.txt\'s '
                        . 'own GPTBot group already ends in a literal `Disallow: /`, so the injected rule is a '
                        . 'byte-identical duplicate and the outcome is ALLOWED with or without it. That '
                        . 'insensitivity is precisely what #1541 found — our `Allow: /docs/` unconditionally '
                        . 'permits /docs/ regardless of any externally-injected blanket `Disallow: /`, tied or '
                        . 'not — so this row pins historical behaviour rather than exercising the merge. The row '
                        . 'below is the differential check that the merge machinery works; detection of this '
                        . 'shape in production is testNoForeignGroupsPresentInLiveRobotsTxt(), which catches the '
                        . 'injected group by its presence, never by its effect.',
                ],
            'differential: an injected rule more specific than ours does flip the outcome (proves foreign groups are merged)'
                => [
                    self::PRODUCTION_ROBOTS_FILE,
                    'GPTBot',
                    [['type' => 'disallow', 'path' => '/docs/unlisted-subpage']],
                    '/docs/unlisted-subpage',
                    false,
                    'The injected `Disallow: /docs/unlisted-subpage` (length 22) is strictly longer than every '
                        . 'other rule in robots.txt\'s GPTBot group that actually prefixes this path — `Allow: '
                        . '/docs/` (length 6) and the catch-all `Disallow: /` (length 1); the group\'s other, '
                        . 'longer rules (e.g. `Disallow: /app/owner/cars/edit.php`) simply don\'t match this URL — '
                        . 'so longest-prefix-match resolves to DISALLOWED — and it can only do so if the fabricated '
                        . 'foreign group was genuinely merged in. This row is the one that proves the merge '
                        . 'machinery incorporates a foreign group\'s rules with full precedence rather than '
                        . 'quietly discarding them: drop the injected group and the local `Allow: /docs/` wins, '
                        . 'resolving ALLOWED instead. A future regression in mergedRulesForAgent() that silently '
                        . 'ignored non-local groups — the exact blind spot #1537 and #1541 exploited — would fail '
                        . 'here and nowhere else in this provider.',
                ],
        ];
    }

    /**
     * Pins foreignGroups() — the actual edge-injection detector — against
     * fabricated group arrays.
     *
     * Runs unconditionally with no network access, and deliberately does not
     * touch self::$liveGroups, self::$localGroups, or the real robots files.
     * The live test that exercises this detector can only ever demonstrate the
     * *negative* case while Cloudflare's toggle stays off, so without this a
     * regression in the detector itself — a lost strict comparison, a
     * key-order mismatch between two parseRobotsTxt() calls — would go
     * unnoticed until a real re-injection, i.e. exactly when it matters.
     *
     * @param array<int, RobotsGroup> $liveGroups
     * @param array<int, RobotsGroup> $localGroups
     * @param list<RobotsGroup> $expectedForeign
     */
    #[DataProvider('foreignGroupsScenariosProvider')]
    public function testForeignGroupsDetectsStructuralDifferences(
        array $liveGroups,
        array $localGroups,
        array $expectedForeign,
        string $rationale
    ): void {
        $this->assertSame($expectedForeign, self::foreignGroups($liveGroups, $localGroups), $rationale);
    }

    /**
     * @return array<string, array{0: array<int, RobotsGroup>, 1: array<int, RobotsGroup>, 2: list<RobotsGroup>, 3: string}>
     */
    public static function foreignGroupsScenariosProvider(): array
    {
        $localWildcard = ['agents' => ['*'], 'rules' => [['type' => 'disallow', 'path' => '/']]];
        $injectedBotGroup = ['agents' => ['GPTBot'], 'rules' => [['type' => 'disallow', 'path' => '/']]];
        $injectedPermissiveWildcard = ['agents' => ['*'], 'rules' => [['type' => 'allow', 'path' => '/']]];

        return [
            'new token: a group for an agent we never shipped is foreign (#1541 shape)' => [
                [$localWildcard, $injectedBotGroup],
                [$localWildcard],
                [$injectedBotGroup],
                'GPTBot appears in the served response but in no local group, so the whole group is foreign — '
                    . 'this is #1541 verbatim, an edge layer adding a group for tokens we did not ship.',
            ],
            'identical: a byte-for-byte duplicate of a local group is not foreign' => [
                [$localWildcard, $localWildcard],
                [$localWildcard],
                [],
                'in_array() is a value comparison, not an identity or positional one, so a second copy of a '
                    . 'group we already ship matches the local baseline and is not reported. That is deliberate: '
                    . 'a duplicate group carries no rule we did not already publish, so it is semantically inert '
                    . 'and flagging it would be a false positive.',
            ],
            'two injections: every foreign group is returned, not just the first' => [
                [$localWildcard, $injectedBotGroup, $injectedPermissiveWildcard],
                [$localWildcard],
                [$injectedBotGroup, $injectedPermissiveWildcard],
                'The detector must report the full set — a first-match-and-stop implementation would hide the '
                    . 'second injection, and #1537 (a permissive `*` group) and #1541 (an AI-bot group) are '
                    . 'independent enough that an edge layer could plausibly add both at once.',
            ],
            'same agents, extra rule: rules are part of the comparison, not just tokens' => [
                [['agents' => ['*'], 'rules' => [
                    ['type' => 'disallow', 'path' => '/'],
                    ['type' => 'allow', 'path' => '/promo/'],
                ]]],
                [$localWildcard],
                [['agents' => ['*'], 'rules' => [
                    ['type' => 'disallow', 'path' => '/'],
                    ['type' => 'allow', 'path' => '/promo/'],
                ]]],
                'Rules appended to an existing group are a distinct injection shape from a wholly new group, and '
                    . 'a token-only comparison would miss it entirely. Here the appended `Allow: /promo/` is a '
                    . 'longer prefix than our `Disallow: /`, so it would punch a hole straight through the Test '
                    . "host's blanket block.",
            ],
        ];
    }

    private function skipIfLiveFetchUnavailable(): void
    {
        if (self::$fetchSkipReason !== null) {
            $this->markTestSkipped(self::$fetchSkipReason);
        }
    }

    private static function readRootFile(string $fileName): string
    {
        $path = dirname(__DIR__, 2) . '/' . $fileName;
        self::assertFileExists($path, $fileName . ' must exist at the repo root');

        return (string)file_get_contents($path);
    }

    /**
     * Parses raw robots.txt text into User-agent groups. Blank lines and
     * comments are skipped; a `User-agent` line that follows any directive
     * starts a new group (per RFC 9309's group syntax, §2.1/§2.2), while
     * consecutive `User-agent` lines accumulate into one group's token list.
     *
     * @return array<int, RobotsGroup>
     */
    private static function parseRobotsTxt(string $content): array
    {
        /** @var array<int, RobotsGroup> $groups */
        $groups = [];
        /** @var list<string> $pendingAgents */
        $pendingAgents = [];
        /** @var list<RobotsRule> $currentRules */
        $currentRules = [];

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $matches) === 1) {
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

    /**
     * Group merge per RFC 9309 §2.2.1: a crawler obeys the union of every
     * group naming it, falling back to the union of every `*` group only when
     * no group names it directly.
     *
     * Merging *all* matching groups (rather than returning the first, as
     * RobotsTxtPolicyTest::findGroup() does) is the whole point of this
     * helper — a second, injected group for a token we already cover is
     * exactly what #1537 and #1541 were, and a first-match-wins lookup would
     * silently discard it.
     *
     * @param array<int, RobotsGroup> $groups
     * @return list<RobotsRule>
     */
    private static function mergedRulesForAgent(array $groups, string $userAgent): array
    {
        /** @var list<RobotsRule> $exactRules */
        $exactRules = [];
        /** @var list<RobotsRule> $wildcardRules */
        $wildcardRules = [];
        $hasExactMatch = false;

        foreach ($groups as $group) {
            $matchesAgent = false;
            $matchesWildcard = false;
            foreach ($group['agents'] as $agent) {
                if (strcasecmp($agent, $userAgent) === 0) {
                    $matchesAgent = true;
                }
                if ($agent === '*') {
                    $matchesWildcard = true;
                }
            }

            if ($matchesAgent) {
                $hasExactMatch = true;
                $exactRules = array_merge($exactRules, $group['rules']);
            }
            if ($matchesWildcard) {
                $wildcardRules = array_merge($wildcardRules, $group['rules']);
            }
        }

        return $hasExactMatch ? $exactRules : $wildcardRules;
    }

    /**
     * Longest-prefix-match per RFC 9309 §2.2.2: among all rules whose path
     * prefixes the URL, the longest wins outright regardless of type. On an
     * exact length tie, Allow beats Disallow (Google's documented
     * least-restrictive-wins tie-break) but never the reverse — the tie-break
     * asymmetry that made #1537 possible. A path matched by nothing is
     * allowed.
     *
     * Known gap: RFC 9309 §2.2.3 special characters (`*` mid-path wildcard,
     * `$` end-of-path anchor) are not implemented — matching here is a plain
     * literal prefix via str_starts_with(). Neither robots.txt nor
     * robots-test.txt uses them today, and Cloudflare's historical injected
     * block used a literal `Disallow: /` / `Allow: /`, so this gap doesn't
     * hide the two known bug shapes. It does mean a hypothetical injected
     * `Allow: /*` or `Allow: /$` would not register here as reachable — but
     * testNoForeignGroupsPresentInLiveRobotsTxt() still catches it, since
     * that check flags the injected group by its mere presence, not by
     * whatever effect resolve() computes for it.
     *
     * @param list<RobotsRule> $rules
     */
    private static function resolve(array $rules, string $path): bool
    {
        $bestLength = -1;
        $bestIsAllow = true;

        foreach ($rules as $rule) {
            if ($rule['path'] === '' || !str_starts_with($path, $rule['path'])) {
                continue;
            }

            $length = strlen($rule['path']);
            if ($length > $bestLength) {
                $bestLength = $length;
                $bestIsAllow = $rule['type'] === 'allow';
                continue;
            }
            if ($length === $bestLength && $rule['type'] === 'allow') {
                $bestIsAllow = true;
            }
        }

        return $bestLength === -1 ? true : $bestIsAllow;
    }

    /**
     * Returns every served group with no identical counterpart in the local
     * baseline — i.e. groups this repo did not ship. Comparison is on parsed
     * structure (agent tokens plus ordered rules), never raw bytes, so
     * formatting and comment drift can't produce a false positive.
     *
     * The parameter names describe the primary direction, but the operation
     * is a generic ordered set-difference — testNoLocalGroupsMissingFromLive-
     * RobotsTxt() calls it with the arguments swapped to detect groups we
     * shipped that are absent from the served response.
     *
     * @param array<int, RobotsGroup> $liveGroups
     * @param array<int, RobotsGroup> $localGroups
     * @return array<int, RobotsGroup>
     */
    private static function foreignGroups(array $liveGroups, array $localGroups): array
    {
        $foreign = [];
        foreach ($liveGroups as $liveGroup) {
            if (!in_array($liveGroup, $localGroups, true)) {
                $foreign[] = $liveGroup;
            }
        }

        return $foreign;
    }

    /**
     * Renders a foreignGroups() result for an assertion failure message.
     *
     * @param array<int, RobotsGroup> $groups
     */
    private static function formatGroupsForMessage(array $groups): string
    {
        return (string)json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
