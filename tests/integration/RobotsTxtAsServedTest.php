<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RobotsTxtGroups;

/**
 * #1542: compare the robots.txt that test.elanregistry.org serves with this
 * repo's robots-test.txt. RobotsTxtPolicyTest reads only the file on disk, so
 * it cannot see a group that an edge layer injects (#1537, #1541: Cloudflare
 * "Managed robots.txt", a dashboard toggle that no code review can see).
 *
 * The Test host is the most sensitive canary: its whole policy is one
 * `Disallow: /`. Residual gap: a production-only re-enable is not detected.
 *
 * Needs outbound network. The `live-network` group is excluded from
 * phpunit-integration.xml. Run it with:
 *   vendor/bin/phpunit -c phpunit-integration.xml --group live-network
 *
 * A failure can also mean that a local robots-test.txt edit is not yet
 * deployed, or that the Test host's post-receive robots.txt swap failed.
 *
 * Evaluator logic is pinned in tests/unit/system/RobotsTxtGroupsTest.php.
 *
 * @phpstan-import-type RobotsGroup from RobotsTxtGroups
 */
#[Group('integration')]
#[Group('live-network')]
final class RobotsTxtAsServedTest extends TestCase
{
    private const LIVE_URL = 'https://test.elanregistry.org/robots.txt';
    private const LOCAL_BASELINE_FILE = 'robots-test.txt';

    /** @var array<int, RobotsGroup> */
    private static array $liveGroups = [];

    /** @var array<int, RobotsGroup> */
    private static array $localGroups = [];

    /** Non-null when the live fetch could not be completed; the reason is reported as a skip. */
    private static ?string $fetchSkipReason = null;

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
            // These limit hop count and scheme, not the destination host.
            // The effective-host check after curl_exec() guards the host.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 5,
            // Do not buffer an unbounded response from a captive portal or misroute.
            CURLOPT_MAXFILESIZE => 65536,
            // Never spoof a crawler UA: the edge may serve crawlers a different file.
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

        // A redirect to another host would report every group as foreign.
        // That is an environment problem, not a policy finding, so skip.
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

        $liveGroups = RobotsTxtGroups::parse($body);

        // A 200 with no User-agent group is a challenge page or misroute.
        // Without this skip, foreignGroups([]) returns [] and the test is a false green.
        if ($liveGroups === []) {
            self::$fetchSkipReason = 'Live fetch of ' . self::LIVE_URL . ' returned HTTP 200 but the body did '
                . 'not parse into any User-agent group — likely a challenge page, interstitial, or misroute, '
                . 'not real robots.txt content';
            return;
        }

        self::$liveGroups = $liveGroups;
        self::$localGroups = RobotsTxtGroups::parse(RobotsTxtGroups::readRootFile(self::LOCAL_BASELINE_FILE));
    }

    public function testDefaultGroupDisallowsRootPathAsServed(): void
    {
        $this->skipIfLiveFetchUnavailable();

        $rules = RobotsTxtGroups::mergedRulesForAgent(self::$liveGroups, '*');

        $this->assertFalse(
            RobotsTxtGroups::resolve($rules, '/'),
            "test.elanregistry.org's live robots.txt must fully block '/' for User-agent: * — if this fails, "
                . "a permissive group (e.g. Cloudflare's Managed robots.txt) has likely been injected ahead of "
                . 'our own, recreating the #1537 tie-break bug where an injected `Allow: /` ties our `Disallow: /` '
                . 'at prefix length 1 and wins on least-restrictive-wins.'
        );
    }

    /**
     * Flags any served group that we did not ship, from any vendor. The
     * presence of the group is the finding, not its effect.
     */
    public function testNoForeignGroupsPresentInLiveRobotsTxt(): void
    {
        $this->skipIfLiveFetchUnavailable();

        $foreign = RobotsTxtGroups::foreignGroups(self::$liveGroups, self::$localGroups);

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
     * A dropped `Disallow: /` group (failed deploy swap, stale cache) is as
     * bad as an injected permissive group.
     */
    public function testNoLocalGroupsMissingFromLiveRobotsTxt(): void
    {
        $this->skipIfLiveFetchUnavailable();

        $missing = RobotsTxtGroups::foreignGroups(self::$localGroups, self::$liveGroups);

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

    private function skipIfLiveFetchUnavailable(): void
    {
        if (self::$fetchSkipReason !== null) {
            $this->markTestSkipped(self::$fetchSkipReason);
        }
    }

    /**
     * @param array<int, RobotsGroup> $groups
     */
    private static function formatGroupsForMessage(array $groups): string
    {
        return (string)json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
