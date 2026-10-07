<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RobotsTxtGroups;

/**
 * Pins the RobotsTxtGroups evaluator that RobotsTxtAsServedTest (#1542) uses.
 * The live test can only show the negative case while edge injection is off,
 * so a broken detector would otherwise go unnoticed until a real injection.
 *
 * @phpstan-import-type RobotsRule from RobotsTxtGroups
 * @phpstan-import-type RobotsGroup from RobotsTxtGroups
 */
#[Group('system')]
final class RobotsTxtGroupsTest extends TestCase
{
    /**
     * @param list<RobotsRule> $foreignRules
     */
    #[DataProvider('mergeScenariosProvider')]
    public function testMergedRulesResolveHistoricalBugShapes(
        string $localFile,
        string $userAgent,
        array $foreignRules,
        string $path,
        bool $expectedAllowed
    ): void {
        $groups = RobotsTxtGroups::parse(RobotsTxtGroups::readRootFile($localFile));
        $groups[] = ['agents' => [$userAgent], 'rules' => $foreignRules];

        $this->assertSame(
            $expectedAllowed,
            RobotsTxtGroups::resolve(RobotsTxtGroups::mergedRulesForAgent($groups, $userAgent), $path),
            sprintf('%s for %s given %s plus the injected group', $path, $userAgent, $localFile)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<RobotsRule>, 3: string, 4: bool}>
     */
    public static function mergeScenariosProvider(): array
    {
        return [
            // Equal prefix length: Allow wins the tie, so the Test host is crawlable.
            'exact tie: injected Allow beats our Disallow (#1537)' => [
                'robots-test.txt', '*', [['type' => 'allow', 'path' => '/']], '/', true,
            ],
            // Accepted outcome per #1541: our longer Allow: /docs/ wins over any blanket Disallow: /.
            'non-tie: our longer Allow beats a shorter injected Disallow (#1541)' => [
                'robots.txt', 'GPTBot', [['type' => 'disallow', 'path' => '/']], '/docs/index.html', true,
            ],
            // Without the injected group the result is ALLOWED, so this proves foreign rules are merged.
            'differential: a more specific injected rule flips the outcome' => [
                'robots.txt', 'GPTBot', [['type' => 'disallow', 'path' => '/docs/unlisted-subpage']],
                '/docs/unlisted-subpage', false,
            ],
        ];
    }

    /**
     * @param array<int, RobotsGroup> $liveGroups
     * @param array<int, RobotsGroup> $localGroups
     * @param list<RobotsGroup> $expectedForeign
     */
    #[DataProvider('foreignGroupsScenariosProvider')]
    public function testForeignGroupsDetectsStructuralDifferences(
        array $liveGroups,
        array $localGroups,
        array $expectedForeign
    ): void {
        $this->assertSame($expectedForeign, RobotsTxtGroups::foreignGroups($liveGroups, $localGroups));
    }

    /**
     * @return array<string, array{0: array<int, RobotsGroup>, 1: array<int, RobotsGroup>, 2: list<RobotsGroup>}>
     */
    public static function foreignGroupsScenariosProvider(): array
    {
        $localWildcard = ['agents' => ['*'], 'rules' => [['type' => 'disallow', 'path' => '/']]];
        $injectedBotGroup = ['agents' => ['GPTBot'], 'rules' => [['type' => 'disallow', 'path' => '/']]];
        $injectedPermissiveWildcard = ['agents' => ['*'], 'rules' => [['type' => 'allow', 'path' => '/']]];
        $wildcardWithExtraRule = ['agents' => ['*'], 'rules' => [
            ['type' => 'disallow', 'path' => '/'],
            ['type' => 'allow', 'path' => '/promo/'],
        ]];

        return [
            'new token: a group for an agent we never shipped is foreign (#1541)' => [
                [$localWildcard, $injectedBotGroup], [$localWildcard], [$injectedBotGroup],
            ],
            // A duplicate adds no rule we did not publish, so flagging it is a false positive.
            'identical: a duplicate of a local group is not foreign' => [
                [$localWildcard, $localWildcard], [$localWildcard], [],
            ],
            'two injections: every foreign group is returned, not just the first' => [
                [$localWildcard, $injectedBotGroup, $injectedPermissiveWildcard],
                [$localWildcard],
                [$injectedBotGroup, $injectedPermissiveWildcard],
            ],
            'same agents, extra rule: rules are part of the comparison' => [
                [$wildcardWithExtraRule], [$localWildcard], [$wildcardWithExtraRule],
            ],
        ];
    }
}
