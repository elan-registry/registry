<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\BrevoOverrideStub;

/**
 * Unit coverage for {@see BrevoOverrideStub::sweep()} (issue #2166).
 *
 * The sweep is deliberately byte-exact: it must delete the leaked 6-byte
 * stub and nothing else. These tests operate entirely inside a per-test
 * temp directory — never against the real
 * `usersc/plugins/sendinblue/override.php` path — so a bug in the matching
 * logic here cannot delete a developer's real override file.
 *
 * @package Tests\Unit\System
 * @see https://github.com/elan-registry/registry/issues/2166
 */
#[Group('system')]
final class BrevoOverrideStubTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/brevo-override-stub-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function test_sweep_deletesExactStub_returnsTrue(): void
    {
        $path = $this->tempDir . '/override.php';
        file_put_contents($path, BrevoOverrideStub::CONTENT);

        $result = BrevoOverrideStub::sweep($path);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($path);
    }

    // Reads the gitignored upstream sendinblue plugin, which CI never has.
    #[Group('requires-upstream-install')]
    public function test_sweep_realOverrideCopy_isKeptAndReturnsFalse(): void
    {
        $source = dirname(__DIR__, 3) . '/usersc/plugins/sendinblue/override.RENAME.php';
        $this->assertFileExists($source, 'Real override template must exist to copy for this test.');

        $path = $this->tempDir . '/override.php';
        $copied = copy($source, $path);
        $this->assertTrue($copied, 'Failed to copy the real override into the temp dir.');

        $result = BrevoOverrideStub::sweep($path);

        $this->assertFalse($result);
        $this->assertFileExists($path);
        $this->assertSame(file_get_contents($source), file_get_contents($path));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nearMissContentProvider(): array
    {
        return [
            'bare <?php with no trailing newline' => ["<?php"],
            'CRLF line ending' => ["<?php\r\n"],
            'double newline' => ["<?php\n\n"],
            'uppercase PHP tag, same length' => ["<?PHP\n"],
            'trailing space instead of newline' => ["<?php "],
            'leading newline instead of trailing' => ["\n<?php"],
        ];
    }

    #[DataProvider('nearMissContentProvider')]
    public function test_sweep_nearMissContent_isKeptAndReturnsFalse(string $content): void
    {
        $path = $this->tempDir . '/override.php';
        file_put_contents($path, $content);

        $result = BrevoOverrideStub::sweep($path);

        $this->assertFalse($result);
        $this->assertFileExists($path);
        $this->assertSame($content, file_get_contents($path));
    }

    public function test_sweep_missingFile_returnsFalseWithNoWarning(): void
    {
        $path = $this->tempDir . '/does-not-exist.php';

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $result = BrevoOverrideStub::sweep($path);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertSame([], $warnings, 'sweep() must not emit any PHP warning for a missing file.');
    }

    public function test_sweep_directoryAtPath_returnsFalseWithNoWarning(): void
    {
        $path = $this->tempDir . '/a-directory';
        mkdir($path);

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $result = BrevoOverrideStub::sweep($path);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertSame([], $warnings, 'sweep() must not emit any PHP warning for a directory path.');
        $this->assertDirectoryExists($path);
    }

    public function test_sweep_emptyFile_returnsFalse(): void
    {
        $path = $this->tempDir . '/override.php';
        file_put_contents($path, '');

        $result = BrevoOverrideStub::sweep($path);

        $this->assertFalse($result);
        $this->assertFileExists($path);
    }

    public function test_matches_exactStub_returnsTrue(): void
    {
        $path = $this->tempDir . '/override.php';
        file_put_contents($path, BrevoOverrideStub::CONTENT);

        $this->assertTrue(BrevoOverrideStub::matches($path));
    }

    #[DataProvider('nearMissContentProvider')]
    public function test_matches_nearMissContent_returnsFalse(string $content): void
    {
        $path = $this->tempDir . '/override.php';
        file_put_contents($path, $content);

        $this->assertFalse(BrevoOverrideStub::matches($path));
    }

    public function test_matches_missingFile_returnsFalseWithNoWarning(): void
    {
        $path = $this->tempDir . '/does-not-exist.php';

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $result = BrevoOverrideStub::matches($path);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertSame([], $warnings, 'matches() must not emit any PHP warning for a missing file.');
    }

    public function test_matches_directoryAtPath_returnsFalseWithNoWarning(): void
    {
        $path = $this->tempDir . '/a-directory';
        mkdir($path);

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });

        try {
            $result = BrevoOverrideStub::matches($path);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertSame([], $warnings, 'matches() must not emit any PHP warning for a directory path.');
    }

    /**
     * A stub that IS confirmed as our exact content but sits in a directory
     * that forbids unlink() is the case #2166 exists to surface: sweep() must
     * report false (nothing was removed) while matches() still reports true,
     * so the bootstrap can tell "not our stub" apart from "our stub, stuck"
     * and emit a WARNING instead of staying silent.
     */
    public function test_sweep_exactStubInNonWritableDirectory_isKeptAndMatchesReportsTrue(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root: directory write permission is not enforced');
        }

        $path = $this->tempDir . '/override.php';
        file_put_contents($path, BrevoOverrideStub::CONTENT);

        chmod($this->tempDir, 0555);
        try {
            $result = BrevoOverrideStub::sweep($path);

            $this->assertFalse($result, 'sweep() must not report success when unlink() is blocked.');
            $this->assertFileExists($path, 'The stub must survive an undeletable directory.');
            $this->assertTrue(
                BrevoOverrideStub::matches($path),
                'matches() must still confirm the stub so the bootstrap can warn about it.'
            );
        } finally {
            chmod($this->tempDir, 0755);
        }
    }

    // No deterministic test for the is_file()/filesize()/file_get_contents()/unlink()
    // concurrent-removal race: a custom stream wrapper cannot simulate it, because
    // is_file() on a wrapper path depends on the wrapper's url_stat(), not a real
    // filesystem race between two independent calls. Reliably forcing the file to
    // vanish between two specific internal calls of matches()/sweep() would require
    // a second real process racing this test, which is inherently flaky. The `@`
    // suppressions are covered by code review and the missing-file/directory tests
    // above, which exercise the same "no warning on a false result" contract.
}
