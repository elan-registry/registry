<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * scripts/log-deployment.php (#1424), run as a real subprocess because it is a
 * standalone CLI script. A subprocess does not inherit this process's $_ENV, so
 * DB_* values are passed with putenv(); otherwise it would use the real .env.
 */
#[Group('integration')]
#[Group('deployment')]
final class LogDeploymentScriptTest extends IntegrationTestCase
{
    private const SCRIPT_PATH = __DIR__ . '/../../scripts/log-deployment.php';

    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private ?int $insertedLogId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }

        if ($this->databaseConnected && $this->insertedLogId !== null) {
            try {
                $this->db->query('DELETE FROM logs WHERE id = ?', [$this->insertedLogId]);
            } catch (RuntimeException $e) {
            }
            $this->insertedLogId = null;
        }

        parent::tearDown();
    }

    public function testWritesExpectedDeploymentRow(): void
    {
        $this->exposeTestDatabaseToSubprocess();

        $version = 'v2.29.0-test';
        $environment = 'Test';
        $branch = 'milestone/v2.29.0';
        $gitHash = 'abcdef1234567890';

        [$returnCode, $output] = $this->runScript([$version, $environment, $branch, $gitHash]);

        $this->assertSame(0, $returnCode, 'Script must exit 0 on success. Output: ' . implode("\n", $output));

        $row = $this->db->query(
            "SELECT * FROM logs WHERE logtype = 'Deployment' ORDER BY id DESC LIMIT 1"
        )->first();

        // DB::first() returns [], not null, when no row matches.
        $this->assertIsObject($row, 'Expected a Deployment row to be inserted');
        $this->insertedLogId = (int)$row->id;

        $expectedLognote = "Deployed {$version} (" . substr($gitHash, 0, 8) . ") to {$environment} on branch {$branch}";
        $this->assertSame($expectedLognote, $row->lognote);
        $this->assertSame(0, (int)$row->user_id);
        $this->assertSame('', $row->ip);
    }

    public function testMissingArgumentsFailsNonFatallyWithoutWritingARow(): void
    {
        $this->exposeTestDatabaseToSubprocess();

        $lastIdBefore = $this->latestDeploymentLogId();

        [$returnCode, $output] = $this->runScript(['v2.29.0-test']);

        // Capture any row before asserting, so a failing regression leaves no orphan row.
        $lastIdAfter = $this->latestDeploymentLogId();
        if ($lastIdAfter !== $lastIdBefore) {
            $this->insertedLogId = $lastIdAfter;
        }

        $this->assertSame(
            0,
            $returnCode,
            'Script must exit 0 even on internal failure (non-fatal contract). Output: ' . implode("\n", $output)
        );
        $this->assertSame($lastIdBefore, $lastIdAfter, 'No row should be written when required arguments are missing');
    }

    private function latestDeploymentLogId(): ?int
    {
        // DB::first() returns [], not null, when no row matches.
        $row = $this->db->query(
            "SELECT id FROM logs WHERE logtype = 'Deployment' ORDER BY id DESC LIMIT 1"
        )->first();
        return is_object($row) ? (int)$row->id : null;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: list<string>}
     */
    private function runScript(array $args): array
    {
        $command = 'php ' . escapeshellarg(self::SCRIPT_PATH);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $command .= ' 2>&1';

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        return [$returnCode, $output];
    }

    /**
     * Without this, the subprocess falls back to the real .env, not the test schema.
     */
    private function exposeTestDatabaseToSubprocess(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== null && $value !== '') {
                putenv("$var=$value");
            }
        }
    }
}
