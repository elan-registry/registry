<?php

declare(strict_types=1);

/**
 * Documentation Consistency Checker
 *
 * Catches the classes of documentation rot that a human reviewer reliably
 * misses, because each one looks correct in isolation:
 *
 *   1. Dead internal links   — a Markdown link to a file that does not exist
 *   2. Stale index tables    — docs/README.md and docs/development/README.md
 *                              drifting from the files actually on disk
 *   3. Stale ADR index       — adr/README.md status/title columns drifting
 *                              from each ADR file's own Status field
 *   4. Wrong repo/branch URLs— github.com/<old-owner>, /blob/master/
 *   5. Dead symbols          — identifiers documented as live API that no
 *                              longer exist anywhere in the codebase
 *
 * Rule 5 is the one that motivated this script: `getUserWithProfile()` was
 * removed in v2.26.2 and a regression test guards it in production code, but
 * the docs kept teaching it for three releases — including an agent
 * instruction telling Claude to call it.
 *
 * Usage:
 *   php scripts/check-docs.php            # check, human-readable output
 *   php scripts/check-docs.php --quiet    # only print failures
 *
 * Exit code: 0 = clean, 1 = problems found.
 *
 * @author Elan Registry Development Team
 */

final class DocsChecker
{
    /** Identifiers that must never appear as live API in documentation. */
    private const DEAD_SYMBOLS = [
        'getUserWithProfile' => 'removed in v2.26.2 (#1148) — use (new Owner($userId))->data()',
    ];

    /**
     * Paths exempt from the dropped-table rule.
     *
     * ADRs are historical records: they describe a decision as it was made, so
     * a mention of a since-dropped table is correct there — provided the
     * section carries a supersession note, which is checked separately by
     * review rather than by this script. Everything else — reference docs,
     * CLAUDE.md, agent instructions — must describe the schema as it is now.
     */
    private const DROPPED_TABLE_ALLOWED_PATHS = [
        'docs/development/adr/',
        'database/migrations/',
    ];

    /** Substrings in URLs that indicate a stale repository or branch. */
    private const BAD_URL_FRAGMENTS = [
        'unibrain1/elanregistry' => 'stale repo — use elan-registry/registry',
        'jimboone/elan-registry' => 'stale repo — use elan-registry/registry',
        'elan-registry/registry/blob/master/' => 'default branch is main, not master',
        'elan-registry/registry/tree/master/' => 'default branch is main, not master',
    ];

    /**
     * Lines that legitimately mention a dead symbol: the removal notes
     * themselves, and this checker's own definition of the rule.
     */
    private const DEAD_SYMBOL_ALLOWED = [
        'was removed in',
        'removed in v',
        'Removed in v',
        'no longer exists',
        'do not reintroduce',
    ];

    private string $root;
    private array $problems = [];
    private bool $quiet = false;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function run(array $args): int
    {
        $this->quiet = in_array('--quiet', $args, true);

        $this->checkDeadLinks();
        $this->checkDevIndex();
        $this->checkAdrIndex();
        $this->checkBadUrls();
        $this->checkDeadSymbols();
        $this->checkDroppedTables();

        return $this->report();
    }

    /**
     * Rule 1 — every relative Markdown link in docs/ resolves to a real file.
     */
    private function checkDeadLinks(): void
    {
        foreach ($this->markdownFiles() as $file) {
            $dir = dirname($file);
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $i => $line) {
                if (!preg_match_all('/\]\(([^)#:]+\.md)(#[^)]*)?\)/', $line, $m)) {
                    continue;
                }

                foreach ($m[1] as $target) {
                    if (str_starts_with($target, 'http')) {
                        continue;
                    }

                    $resolved = $this->resolvePath($dir, $target);
                    if (!file_exists($resolved)) {
                        $this->problem(
                            'dead-link',
                            $this->rel($file) . ':' . ($i + 1),
                            "links to missing file: {$target}"
                        );
                    }
                }
            }
        }
    }

    /**
     * Rule 2 — docs/development/README.md lists every doc in that directory.
     */
    private function checkDevIndex(): void
    {
        $indexPath = $this->root . '/docs/development/README.md';
        if (!file_exists($indexPath)) {
            return;
        }

        $index = (string) file_get_contents($indexPath);
        $onDisk = glob($this->root . '/docs/development/*.md') ?: [];

        foreach ($onDisk as $doc) {
            $name = basename($doc);
            if ($name === 'README.md') {
                continue;
            }

            if (!str_contains($index, $name)) {
                $this->problem(
                    'stale-index',
                    'docs/development/README.md',
                    "does not list {$name}, which exists on disk"
                );
            }
        }
    }

    /**
     * Rule 3 — adr/README.md's table agrees with each ADR's own Status field,
     * and lists every ADR present on disk.
     */
    private function checkAdrIndex(): void
    {
        $adrDir = $this->root . '/docs/development/adr';
        $indexPath = $adrDir . '/README.md';
        if (!file_exists($indexPath)) {
            return;
        }

        $index = (string) file_get_contents($indexPath);
        $adrs = glob($adrDir . '/ADR-*.md') ?: [];
        sort($adrs);

        foreach ($adrs as $adr) {
            $name = basename($adr);
            $body = (string) file_get_contents($adr);

            if (!preg_match('/^ADR-(\d+)/', $name, $numMatch)) {
                continue;
            }
            $num = 'ADR-' . $numMatch[1];

            // Present in the index at all?
            if (!str_contains($index, $name)) {
                $this->problem(
                    'stale-adr-index',
                    'docs/development/adr/README.md',
                    "does not list {$name}"
                );
                continue;
            }

            // Filename should reflect the ADR's own title.
            if (preg_match('/^#\s*ADR-\d+:\s*(.+)$/m', $body, $titleMatch)) {
                $slug = $this->slugify(trim($titleMatch[1]));
                $fileSlug = preg_replace('/^ADR-\d+-/', '', basename($name, '.md'));

                if ($fileSlug !== null && !$this->slugsOverlap($slug, $fileSlug)) {
                    $this->problem(
                        'adr-title-mismatch',
                        "docs/development/adr/{$name}",
                        "filename slug '{$fileSlug}' does not match its title '" . trim($titleMatch[1]) . "'"
                    );
                }
            }

            // Status in the file vs. status in the index row.
            $fileStatus = $this->adrStatus($body);
            if ($fileStatus === null) {
                continue;
            }

            foreach (explode("\n", $index) as $row) {
                if (!str_contains($row, $name)) {
                    continue;
                }
                if (stripos($row, $fileStatus) === false) {
                    $this->problem(
                        'adr-status-mismatch',
                        'docs/development/adr/README.md',
                        "{$num} is '{$fileStatus}' in its own file but the index row disagrees"
                    );
                }
                break;
            }
        }
    }

    /**
     * Rule 4 — no stale repository or branch URLs.
     */
    private function checkBadUrls(): void
    {
        foreach ($this->markdownFiles() as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $i => $line) {
                foreach (self::BAD_URL_FRAGMENTS as $bad => $why) {
                    if (str_contains($line, $bad)) {
                        $this->problem(
                            'stale-url',
                            $this->rel($file) . ':' . ($i + 1),
                            "{$bad} — {$why}"
                        );
                    }
                }
            }
        }
    }

    /**
     * Rule 5 — documentation must not teach identifiers that no longer exist.
     */
    private function checkDeadSymbols(): void
    {
        foreach ($this->markdownFiles(true) as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $i => $line) {
                foreach (self::DEAD_SYMBOLS as $symbol => $why) {
                    if (!str_contains($line, $symbol)) {
                        continue;
                    }

                    // A removal note may wrap onto the following line, so
                    // consider the mention plus its immediate continuation.
                    $context = $line . ' ' . ($lines[$i + 1] ?? '');

                    foreach (self::DEAD_SYMBOL_ALLOWED as $allowed) {
                        if (str_contains($context, $allowed)) {
                            continue 2;
                        }
                    }

                    $this->problem(
                        'dead-symbol',
                        $this->rel($file) . ':' . ($i + 1),
                        "{$symbol}() — {$why}"
                    );
                }
            }
        }
    }

    /**
     * Rule 6 — documentation must not present a dropped table as current.
     *
     * The list of dropped tables is derived from the migrations themselves
     * rather than hard-coded, so the next `dropTable()` is caught without
     * anyone remembering to update this script. A mention is allowed when the
     * same line marks it as removed/dropped — that is a supersession note, not
     * a stale claim.
     */
    private function checkDroppedTables(): void
    {
        $dropped = $this->droppedTables();
        if ($dropped === []) {
            return;
        }

        foreach ($this->markdownFiles(true) as $file) {
            $rel = $this->rel($file);

            foreach (self::DROPPED_TABLE_ALLOWED_PATHS as $allowedPath) {
                if (str_starts_with($rel, $allowedPath)) {
                    continue 2;
                }
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $i => $line) {
                foreach ($dropped as $table) {
                    if (!preg_match('/\b' . preg_quote($table, '/') . '\b/', $line)) {
                        continue;
                    }

                    // A removal note is usually a short block — a heading plus
                    // a paragraph of explanation — so the word "dropped" may
                    // sit several lines from a given mention. Scan a window
                    // around the line rather than just its neighbour.
                    $from = max(0, $i - 8);
                    $context = implode(' ', array_slice($lines, $from, 17));
                    if (preg_match('/\b(dropped|removed|no longer|superseded)\b/i', $context)) {
                        continue;
                    }

                    $this->problem(
                        'dropped-table',
                        $rel . ':' . ($i + 1),
                        "`{$table}` was dropped by a migration but is documented as current"
                    );
                }
            }
        }
    }

    /**
     * Table names passed to dropTable() in any migration.
     *
     * @return list<string>
     */
    private function droppedTables(): array
    {
        $tables = [];

        foreach (glob($this->root . '/database/migrations/*.php') ?: [] as $migration) {
            $body = (string) file_get_contents($migration);
            // Phinx's table builder …
            if (preg_match_all('/->dropTable\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $body, $m)) {
                foreach ($m[1] as $table) {
                    $tables[$table] = true;
                }
            }

            // … and raw DDL, which is how non-reversible drops are written.
            if (preg_match_all('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([a-z0-9_]+)`?/i', $body, $m)) {
                foreach ($m[1] as $table) {
                    $tables[$table] = true;
                }
            }
        }

        // A table recreated by a LATER migration is not dropped. Only later
        // migrations count — a down() method in the same file recreates the
        // table on rollback, which does not mean it currently exists.
        $migrations = glob($this->root . '/database/migrations/*.php') ?: [];
        sort($migrations);

        foreach ($tables as $table => $_) {
            $droppedIn = '';
            foreach ($migrations as $migration) {
                $body = (string) file_get_contents($migration);
                if (preg_match('/(->dropTable\(\s*[\'"]|DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?)' . preg_quote($table, '/') . '\b/i', $body)) {
                    $droppedIn = $migration;
                }
            }

            foreach ($migrations as $migration) {
                if ($migration <= $droppedIn) {
                    continue;
                }
                $body = (string) file_get_contents($migration);
                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?' . preg_quote($table, '/') . '\b/i', $body)
                    || preg_match('/->table\(\s*[\'"]' . preg_quote($table, '/') . '[\'"].*?->create\(\)/is', $body)) {
                    unset($tables[$table]);
                    break;
                }
            }
        }

        // The migration scan above is the whole check: `database/migrations/`
        // is the schema-of-record, so a table that no later migration recreates
        // is genuinely dropped.

        return array_keys($tables);
    }

    /**
     * All Markdown files under docs/. When $includeAgents is true, also the
     * agent and command instruction files, which are documentation for Claude
     * and rot the same way.
     *
     * docs/plans/ is excluded: it is gitignored scratch space for sprint
     * plans, FRDs and per-issue plan files, which are deleted as work lands
     * and routinely point at documents that have already been removed. This
     * checker exists to keep committed documentation honest — holding
     * throwaway working notes to that standard would block every commit.
     *
     * @return list<string>
     */
    private function markdownFiles(bool $includeAgents = false): array
    {
        $files = array_values(array_filter(
            $this->globRecursive($this->root . '/docs', '*.md'),
            fn (string $path): bool => !str_starts_with(
                $path,
                $this->root . '/docs/plans/'
            )
        ));

        if ($includeAgents) {
            $files = array_merge(
                $files,
                $this->globRecursive($this->root . '/.claude/agents', '*.md'),
                $this->globRecursive($this->root . '/.claude/commands', '*.md'),
            );

            $claudeMd = $this->root . '/CLAUDE.md';
            if (file_exists($claudeMd)) {
                $files[] = $claudeMd;
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function globRecursive(string $dir, string $pattern): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && fnmatch($pattern, $entry->getFilename())) {
                $found[] = $entry->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function adrStatus(string $body): ?string
    {
        if (!preg_match('/^##\s*Status\s*$(.*?)^##\s/ms', $body . "\n## ", $m)) {
            return null;
        }

        $block = trim(strip_tags($m[1]));
        foreach (['Superseded', 'Deprecated', 'Accepted', 'In Review', 'Planned'] as $status) {
            if (stripos($block, $status) !== false) {
                return $status;
            }
        }

        return null;
    }

    private function slugify(string $text): string
    {
        $slug = strtolower($text);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * A filename slug is usually an abbreviation of the title, so require
     * meaningful shared vocabulary rather than an exact match.
     */
    private function slugsOverlap(string $titleSlug, string $fileSlug): bool
    {
        $stop = ['the', 'a', 'an', 'for', 'to', 'of', 'and', 'with', 'use', 'via', 'as'];
        $titleWords = array_diff(explode('-', $titleSlug), $stop);
        $fileWords = array_diff(explode('-', $fileSlug), $stop);

        if ($fileWords === []) {
            return true;
        }

        $shared = array_intersect($titleWords, $fileWords);

        return count($shared) >= (int) ceil(count($fileWords) / 2);
    }

    private function resolvePath(string $dir, string $target): string
    {
        $path = $dir . '/' . $target;
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    private function rel(string $path): string
    {
        return str_replace($this->root . '/', '', $path);
    }

    private function problem(string $kind, string $where, string $what): void
    {
        $this->problems[$kind][] = "{$where}\n      {$what}";
    }

    private function report(): int
    {
        if ($this->problems === []) {
            if (!$this->quiet) {
                echo "Documentation checks passed.\n";
            }

            return 0;
        }

        $total = 0;
        foreach ($this->problems as $kind => $items) {
            echo "\n{$kind} (" . count($items) . "):\n";
            foreach ($items as $item) {
                echo "  - {$item}\n";
                $total++;
            }
        }

        echo "\n{$total} documentation problem(s) found.\n";

        return 1;
    }
}

exit((new DocsChecker(dirname(__DIR__)))->run($argv));
