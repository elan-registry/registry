<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression guardrail for Issue #1585: DB collaborators are typed as DatabaseInterface
 *
 * Issue #1585 extracted `ElanRegistry\DatabaseInterface` and the thin
 * `ElanRegistry\Database\DbAdapter` wrapper, retyped every production class that
 * takes a database collaborator from the concrete UserSpice `\DB` to the
 * interface, and deleted the shared global mock shell that the unit suite used
 * to declare in `tests/bootstrap-unit.php`.
 *
 * Every part of that fix is invisible to ordinary behavioural tests — a new
 * class typed against the concrete `\DB`, a re-introduced global mock shell, or
 * a call to a `\DB` method that was never added to the interface, would all
 * compile and pass every existing test while quietly restoring the untestable
 * coupling (or the latent runtime fatal) this issue removed. These three static
 * source scans fail CI the moment any of those anti-patterns reappears.
 *
 * @issue 1585
 * @link https://github.com/elan-registry/registry/issues/1585
 * @category architecture
 */
#[Group('regression')]
final class DatabaseInterfaceUsageRegressionTest extends TestCase
{
    /**
     * Matches the concrete `DB` / `\DB` class used as a parameter or property
     * type, including the nullable (`?DB $x`) and nullable-union (`DB|null $x`)
     * spellings.
     *
     * False-positive guards:
     * - The lookbehind rejects a preceding word character or backslash, so
     *   `DatabaseInterface`, `DbAdapter`, and `MyDB $x` never match.
     * - Only horizontal whitespace is allowed between the type and the `$`, so
     *   a comment or string ending in the word "DB" followed by a new line that
     *   begins with a variable does not match.
     * - `use DB;`, `new DB(`, `DB::`, and `instanceof DB` are not type-hint
     *   positions and are intentionally out of scope.
     */
    private const CONCRETE_DB_TYPEHINT = '/(?<![\w\\\\$])\??\\\\?DB(?:[ \t]*\|[ \t]*(?:null|false))?[ \t]+\$/';

    /**
     * Matches a method call on a receiver that is a `DatabaseInterface` by
     * construction, capturing the method name:
     *
     * - `dbi()->foo(` — the helper's return type *is* `DatabaseInterface`.
     * - `$this->db->foo(` / `$this->_db->foo(` — the two property-name
     *   conventions used by the migrated classes (`CarRepository` uses `$this->db`,
     *   `Car` uses `$this->_db`). A concrete `\DB` property type is already
     *   banned by `testProductionCodeDoesNotTypeHintConcreteDb`, so a database
     *   property reached this way can only be the interface (or the loose
     *   `object` hint that a couple of collaborators still use, which is passed
     *   an interface instance in practice).
     */
    private const INTERFACE_RECEIVER_CALL = '/(?:dbi\(\)|\$this->_?db)->([A-Za-z_][A-Za-z0-9_]*)[ \t]*\(/';

    /**
     * Matches a method call on a bare `$db` variable, capturing the method name.
     *
     * Only applied to files that satisfy both `DATABASE_INTERFACE_IMPORT` and
     * (negatively) `RAW_DB_SINGLETON_ASSIGNMENT` — see the test docblock.
     */
    private const AMBIENT_DB_CALL = '/\$db->([A-Za-z_][A-Za-z0-9_]*)[ \t]*\(/';

    /**
     * Marks a file as participating in the interface-typed pattern. A file that
     * never imports `DatabaseInterface` only ever sees the ambient UserSpice
     * `$db` global, which is a real `\DB` and may legitimately call `\DB` methods
     * the narrow interface does not expose.
     */
    private const DATABASE_INTERFACE_IMPORT = '/^use\s+ElanRegistry\\\\DatabaseInterface;/m';

    /**
     * A local re-assignment of `$db` to the raw `\DB` singleton. Files doing this
     * (e.g. `app/admin/scripts/maintenance/21-Fix-Page-Permissions.php`) hold a
     * concrete `\DB` in `$db` even though they also import the interface for an
     * unrelated collaborator, so their `$db->` calls are exempt.
     */
    private const RAW_DB_SINGLETON_ASSIGNMENT = '/\$db\s*=\s*\\\\?DB::getInstance\s*\(/';

    /**
     * Class declarations that would reintroduce the shared global mock shell #1585
     * deleted. A class constant (not a local array in the test method) so the
     * positive-control test below can assert against the exact same patterns the
     * scan uses, rather than a separately-typed copy that could silently drift.
     *
     * @var list<string>
     */
    private const BANNED_TEST_DECLARATIONS = [
        '/\bclass\s+DB\b/',
        '/\bclass\s+QueryResult\b/',
    ];

    /**
     * Production directories that must never type a collaborator as concrete `\DB`.
     *
     * @var list<string>
     */
    private const PRODUCTION_DIRS = ['app', 'usersc'];

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 3);
    }

    /**
     * Production code must depend on `ElanRegistry\DatabaseInterface`, never on
     * the concrete UserSpice `\DB` class, so collaborators stay substitutable
     * and unit-testable without a live PDO connection.
     */
    public function testProductionCodeDoesNotTypeHintConcreteDb(): void
    {
        $violations = [];

        foreach ($this->getProductionPhpFiles() as $file) {
            $content = file_get_contents($file);

            if ($content !== false && preg_match(self::CONCRETE_DB_TYPEHINT, $content) === 1) {
                $violations[] = $this->relativePath($file);
            }
        }

        $this->assertEmpty(
            $violations,
            "Concrete \\DB type-hint found in production code — use ElanRegistry\\DatabaseInterface instead (#1585).\n"
                . 'Offending files: ' . implode(', ', $violations)
        );
    }

    /**
     * The unit suite must build small, purpose-built test doubles against
     * `DatabaseInterface`. A shared global `DB` (or its `QueryResult` companion)
     * mock shell is the exact coupling #1585 removed and must not return.
     */
    public function testTestSuiteDeclaresNoGlobalDbMockShell(): void
    {
        $violations = [];

        foreach ($this->getTestPhpFiles() as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            foreach (self::BANNED_TEST_DECLARATIONS as $pattern) {
                if (preg_match($pattern, $content) === 1) {
                    $violations[] = $this->relativePath($file);
                    break;
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Global DB/QueryResult mock shell re-declared under tests/ — build a DatabaseInterface double instead (#1585).\n"
                . 'Offending files: ' . implode(', ', $violations)
        );
    }

    /**
     * Typing a collaborator as `DatabaseInterface` only helps if the code also
     * *calls* the interface. The narrow interface omits plenty of real `\DB`
     * methods (`findById()`, `deleteById()`, `cell()`, `tableExists()`, …), and a
     * call to one of them on an interface-typed collaborator parses fine, passes
     * PHPStan's baseline, and only fatals at runtime when the collaborator really
     * is a `DatabaseInterface` instance. #1585 found exactly this bug class in
     * `app/owner/contact/owner.php` (a call to a `\DB`-only method), which this
     * guardrail exists to catch for interface-typed receivers going forward. That
     * specific file is not itself covered: its `$db` is the ambient page-scope
     * global, which stays a real `\DB` and never imports `DatabaseInterface`, so
     * it falls outside every pattern this test checks — see the scoping note
     * below and `getProductionPhpFiles()`'s docblock. The fix there was a direct
     * code change (the file now calls `\DB::get()`, which real `\DB` has).
     *
     * The canonical method list comes from reflection on the interface, never a
     * hardcoded copy, so adding a method to `DatabaseInterface` automatically
     * widens what this test permits.
     *
     * Precision tradeoff — like the sibling scans, this is a source-text scan, not
     * type inference, so it deliberately trades completeness for zero false
     * positives:
     * - `dbi()->` and `$this->db->` / `$this->_db->` receivers are checked in
     *   every production file; both are interface-typed by construction.
     * - A bare `$db->` is checked only in files that import `DatabaseInterface`
     *   and do not re-assign `$db` from the raw `\DB` singleton. Without that
     *   scoping the ambient UserSpice `$db` global — a real `\DB` in ~80 page and
     *   script files — would produce noise for every legitimate extra-`\DB` call.
     * - Chained calls (`$db->query(...)->results()`) are out of scope; unpicking
     *   nested parentheses with a regex costs more than it protects, and the
     *   chain head is already covered.
     *
     * Verified clean against the codebase by inspection at the time of writing:
     * the scan reaches 353 production files with no unexplained extra-`\DB`
     * calls. (A prior exception here, `$db->deleteById()` in
     * `app/admin/verify/send_email.php`, no longer applies — that file was
     * deleted in #1613.)
     */
    public function testInterfaceTypedDbCallsOnlyUseInterfaceMethods(): void
    {
        $interfaceMethods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(DatabaseInterface::class))->getMethods()
        );

        $this->assertNotEmpty($interfaceMethods, 'Reflection returned no DatabaseInterface methods.');

        $violations = [];

        foreach ($this->getProductionPhpFiles() as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $patterns = [self::INTERFACE_RECEIVER_CALL];

            if (
                preg_match(self::DATABASE_INTERFACE_IMPORT, $content) === 1
                && preg_match(self::RAW_DB_SINGLETON_ASSIGNMENT, $content) !== 1
            ) {
                $patterns[] = self::AMBIENT_DB_CALL;
            }

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $content, $matches);

                foreach ($matches[1] as $calledMethod) {
                    if (!in_array($calledMethod, $interfaceMethods, true)) {
                        $violations[] = $this->relativePath($file) . " calls {$calledMethod}()";
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Call to a \\DB method that is not on ElanRegistry\\DatabaseInterface (#1585).\n"
                . "Add the method to the interface and DbAdapter, or use an interface method instead.\n"
                . implode("\n", $violations)
        );
    }

    /**
     * Positive control for the three scans above: each regex must actually match a
     * known-bad sample, not just fail to match the (currently clean) codebase. Without
     * this, a regex that silently stops matching anything — a typo'd escape, an
     * accidentally-anchored pattern — would make every `assertEmpty($violations)` above
     * pass forever having found nothing to look at.
     */
    public function testGuardrailPatternsDetectKnownViolations(): void
    {
        $this->assertSame(
            1,
            preg_match(self::CONCRETE_DB_TYPEHINT, 'private DB $db;'),
            'CONCRETE_DB_TYPEHINT must match a bare concrete \\DB type-hint'
        );
        $this->assertSame(
            1,
            preg_match(self::CONCRETE_DB_TYPEHINT, 'public function __construct(?DB $db) {}'),
            'CONCRETE_DB_TYPEHINT must match a nullable concrete \\DB type-hint'
        );

        $this->assertSame(
            1,
            preg_match(self::BANNED_TEST_DECLARATIONS[0], 'class DB { public function query() {} }'),
            'BANNED_TEST_DECLARATIONS[0] must match a re-declared global DB class'
        );
        $this->assertSame(
            1,
            preg_match(self::BANNED_TEST_DECLARATIONS[1], 'class QueryResult {}'),
            'BANNED_TEST_DECLARATIONS[1] must match a re-declared global QueryResult class'
        );

        $this->assertSame(
            ['dbi()->query(', 'query'],
            (function (): array {
                preg_match(self::INTERFACE_RECEIVER_CALL, 'dbi()->query($sql);', $matches);
                return $matches;
            })(),
            'INTERFACE_RECEIVER_CALL must match and capture a dbi()->method() call'
        );
        $this->assertSame(
            ['$db->deleteById(', 'deleteById'],
            (function (): array {
                preg_match(self::AMBIENT_DB_CALL, '$db->deleteById($id);', $matches);
                return $matches;
            })(),
            'AMBIENT_DB_CALL must match and capture a bare $db->method() call'
        );
    }

    /**
     * All production PHP files subject to the concrete-`\DB` ban.
     *
     * Excluded by design:
     * - `usersc/classes/Database/DbAdapter.php` — the sanctioned exception; the
     *   adapter's whole job is to wrap the real `\DB` behind the interface.
     * - `usersc/plugins/ai_prompts/**\/*.md.php` — `__halt_compiler()`-guarded
     *   markdown documentation whose PHP is illustrative prose snippets.
     * - Third-party plugin vendor trees and hidden directories.
     *
     * @return list<string>
     */
    private function getProductionPhpFiles(): array
    {
        $excludedPaths = [
            '/usersc/classes/Database/DbAdapter.php',
            '/vendor/',
        ];

        $files = [];

        foreach (self::PRODUCTION_DIRS as $dir) {
            foreach ($this->iteratePhpFiles($this->projectRoot . '/' . $dir) as $path) {
                if ($this->hasDotSegment($path)) {
                    continue;
                }

                foreach ($excludedPaths as $excluded) {
                    if (str_contains($path, $excluded)) {
                        continue 2;
                    }
                }

                if (str_contains($path, '/usersc/plugins/ai_prompts/') && str_contains($path, '.md.php')) {
                    continue;
                }

                $files[] = $path;
            }
        }

        // Tripwire: every scan in this class relies on this list being non-empty. A
        // broken exclusion filter or a projectRoot miscalculation would otherwise make
        // every "no violations found" assertion pass vacuously, having scanned nothing.
        $this->assertNotEmpty($files, 'No production PHP files found — the file scan is broken.');

        return $files;
    }

    /**
     * All PHP files under `tests/`, excluding this guardrail itself — its own
     * banned-pattern list would otherwise be reported as a violation.
     *
     * @return list<string>
     */
    private function getTestPhpFiles(): array
    {
        $files = [];

        foreach ($this->iteratePhpFiles($this->projectRoot . '/tests') as $path) {
            if ($path === __FILE__ || str_contains($path, '/vendor/') || $this->hasDotSegment($path)) {
                continue;
            }

            $files[] = $path;
        }

        // Tripwire: see the matching assertion in getProductionPhpFiles().
        $this->assertNotEmpty($files, 'No test PHP files found — the file scan is broken.');

        return $files;
    }

    /**
     * @return list<string> Absolute paths of every `.php` file under $directory
     */
    private function iteratePhpFiles(string $directory): array
    {
        $this->assertDirectoryExists($directory);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function relativePath(string $absolutePath): string
    {
        return str_replace($this->projectRoot . '/', '', $absolutePath);
    }

    /**
     * Whether any path segment relative to the project root is dot-prefixed
     * (e.g. `.git`, `.claude`). Checked against the path *relative to
     * $this->projectRoot*, not the absolute path — a checkout located under a
     * dot-prefixed ancestor directory (e.g. Claude Code's own
     * `.claude/worktrees/<name>/` convention) must not cause every file in the
     * scan to match this exclusion. See #2012.
     */
    private function hasDotSegment(string $absolutePath): bool
    {
        foreach (explode('/', $this->relativePath($absolutePath)) as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }
}
