<?php

declare(strict_types=1);

use ElanRegistry\TypeHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * currentUserId() is session-coupled, so its coverage is in
 * tests/integration/CurrentUserIdTest.php (#1599).
 */
#[Group('fast')]
final class TypeHelpersTest extends TestCase
{
    /**
     * The optional third column is the property name. Rows without it call
     * toInt() with no property argument, so the 'id' default is under test.
     *
     * @return array<string, array{0: mixed, 1: int, 2?: string}>
     */
    public static function toIntValidInputProvider(): array
    {
        return [
            'object default property numeric string' => [(object) ['id' => '42', 'name' => 'test'], 42],
            'object custom property' => [(object) ['user_id' => '7', 'name' => 'test'], 7, 'user_id'],
            'integer' => [5, 5],
            'numeric string' => ['123', 123],
            'object int property' => [(object) ['id' => 99], 99],
            'zero integer' => [0, 0],
            'zero string' => ['0', 0],
            'decimal string truncates' => ['12.9', 12],
        ];
    }

    #[DataProvider('toIntValidInputProvider')]
    public function testToIntReturnsInteger(mixed $value, int $expected, string ...$property): void
    {
        $this->assertSame($expected, TypeHelpers::toInt($value, ...$property));
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function toIntInvalidInputProvider(): array
    {
        return [
            'boolean true' => [true, null],
            // Intentional: isset() cannot tell a null property from a missing one.
            'object null property' => [(object) ['id' => null], "Property 'id' does not exist on object"],
            'null' => [null, null],
            'empty string' => ['', null],
            'non-numeric string' => ['abc', null],
            'object missing property' => [(object) ['name' => 'test'], null],
        ];
    }

    #[DataProvider('toIntInvalidInputProvider')]
    public function testToIntThrowsOnInvalidInput(mixed $value, ?string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        if ($message !== null) {
            $this->expectExceptionMessage($message);
        }
        TypeHelpers::toInt($value, 'id');
    }

    /**
     * The unit bootstrap defines its own dbInt() stub, so the real one from
     * custom_functions.php runs in a subprocess. An empty server_globals.php
     * under a temporary root stands in for the framework include at the end
     * of that file (#1599).
     */
    public function testRealDbIntMatchesTypeHelpersToInt(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $fakeRoot = sys_get_temp_dir() . '/dbint_' . bin2hex(random_bytes(6)) . '/';
        mkdir($fakeRoot . 'usersc/includes', 0700, true);
        touch($fakeRoot . 'usersc/includes/server_globals.php');

        $harness = <<<'PHP'
            <?php
            declare(strict_types=1);
            require $argv[1] . '/vendor/autoload.php';
            $abs_us_root = $argv[2];
            $us_url_root = '';
            require $argv[1] . '/usersc/includes/custom_functions.php';
            $inputs = [
                [(object) ['id' => '42'], 'id'], [(object) ['user_id' => 7], 'user_id'],
                [5, 'id'], ['123', 'id'], ['12.9', 'id'], ['0', 'id'],
                [true, 'id'], [null, 'id'], ['', 'id'], ['abc', 'id'],
                [(object) ['id' => null], 'id'], [(object) ['name' => 'x'], 'id'],
            ];
            $run = static function (callable $fn, array $args): string {
                try {
                    return 'int:' . $fn(...$args);
                } catch (Throwable $e) {
                    return get_class($e) . ':' . $e->getMessage();
                }
            };
            $results = [];
            foreach ($inputs as $args) {
                $results[] = [$run('dbInt', $args), $run([ElanRegistry\TypeHelpers::class, 'toInt'], $args)];
            }
            echo json_encode($results);
            PHP;
        $harnessFile = $fakeRoot . 'harness.php';
        file_put_contents($harnessFile, $harness);

        try {
            $process = proc_open(
                [PHP_BINARY, $harnessFile, $projectRoot, $fakeRoot],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr);
        } finally {
            unlink($harnessFile);
            unlink($fakeRoot . 'usersc/includes/server_globals.php');
            rmdir($fakeRoot . 'usersc/includes');
            rmdir($fakeRoot . 'usersc');
            rmdir($fakeRoot);
        }

        $results = json_decode($stdout, true);
        $this->assertIsArray($results, $stdout);
        $this->assertCount(12, $results);
        $this->assertSame('int:42', $results[0][0]);
        foreach ($results as $index => [$dbInt, $toInt]) {
            $this->assertSame($toInt, $dbInt, "Input #{$index}");
        }
    }
}
