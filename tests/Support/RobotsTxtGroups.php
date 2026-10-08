<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * robots.txt group parser and rule evaluator for RobotsTxtAsServedTest.
 *
 * Separate from RobotsTxtPolicyTest::findGroup(), which returns only the first
 * matching group: an injected second group for the same token (#1537, #1541)
 * would be invisible to it.
 *
 * @phpstan-type RobotsRule array{type: string, path: string}
 * @phpstan-type RobotsGroup array{agents: list<string>, rules: list<RobotsRule>}
 */
final class RobotsTxtGroups
{
    /**
     * Parse robots.txt text into User-agent groups (RFC 9309 §2.1/§2.2).
     *
     * @return array<int, RobotsGroup>
     */
    public static function parse(string $content): array
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
     * Union of the rules of every group that names the agent; if no group
     * names it, the union of every `*` group (RFC 9309 §2.2.1).
     *
     * @param array<int, RobotsGroup> $groups
     * @return list<RobotsRule>
     */
    public static function mergedRulesForAgent(array $groups, string $userAgent): array
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
     * Longest-prefix match (RFC 9309 §2.2.2). On a length tie, Allow wins.
     * A path that no rule matches is allowed.
     *
     * Known gap: `*` and `$` (§2.2.3) are not supported. Neither robots file
     * uses them, and the live foreign-group check still flags such a group.
     *
     * @param list<RobotsRule> $rules
     */
    public static function resolve(array $rules, string $path): bool
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
     * Groups in $groups that have no identical group in $baseline. The
     * comparison uses parsed structure, so formatting and comment changes do
     * not cause a false positive.
     *
     * @param array<int, RobotsGroup> $groups
     * @param array<int, RobotsGroup> $baseline
     * @return array<int, RobotsGroup>
     */
    public static function foreignGroups(array $groups, array $baseline): array
    {
        $foreign = [];
        foreach ($groups as $group) {
            if (!in_array($group, $baseline, true)) {
                $foreign[] = $group;
            }
        }

        return $foreign;
    }

    /**
     * Read a robots file from the repository root.
     */
    public static function readRootFile(string $fileName): string
    {
        $path = dirname(__DIR__, 2) . '/' . $fileName;
        if (!is_file($path)) {
            throw new RuntimeException($fileName . ' must exist at the repo root');
        }

        return (string) file_get_contents($path);
    }
}
