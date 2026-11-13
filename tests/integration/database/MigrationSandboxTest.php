<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandbox.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checks the name guard of the migration sandbox harness (#2167).
 *
 * The guard is the one check that stops a sandbox run from dropping the
 * shared dev schema. The sandbox test classes exercise the rest of the
 * harness on each run.
 */
#[Group('integration')]
#[Group('migration')]
final class MigrationSandboxTest extends TestCase
{
    public function testNameGuardRefusesSharedAndNonMigtestNames(): void
    {
        $guard = new MigrationSandbox();
        $sharedName = MigrationSandbox::env('DB_NAME');
        $this->assertNotSame('', $sharedName, 'DB_NAME must be set by the integration bootstrap');

        $refused = [
            $sharedName,
            strtoupper($sharedName),
            'elanregi_spice',
            'elanregi_migtest_',
            'elanregi_migtest_abc_def',
            'elanregi_migtest_1_x`; DROP DATABASE elanregi_spice; --',
            'other_migtest_1700000000_abc',
            "elanregi_migtest_1_abc\n",
        ];
        $existedBefore = array_map(fn (string $name): bool => $this->schemaExists($name), $refused);

        foreach ($refused as $name) {
            try {
                $guard->dropSchema($name);
                $this->fail("dropSchema() must refuse {$name}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Refusing DDL', $e->getMessage());
            }
        }

        $this->assertTrue($this->schemaExists($sharedName), 'The shared schema must still exist');
        foreach ($refused as $index => $name) {
            $this->assertSame($existedBefore[$index], $this->schemaExists($name), "{$name} must be unchanged");
        }
    }

    private function schemaExists(string $name): bool
    {
        $statement = (MigrationSandbox::defaultPdoFactory())(null)->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $statement->execute([$name]);

        return (int) $statement->fetchColumn() === 1;
    }
}
