<?php

declare(strict_types=1);

use ElanRegistry\TypeHelpers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * currentUserId() is session-coupled, so its coverage is in
 * tests/integration/CurrentUserIdTest.php (#1599).
 */
#[Group('fast')]
final class TypeHelpersTest extends TestCase
{
    public function testToIntWithObjectProperty(): void
    {
        $obj = (object) ['id' => '42', 'name' => 'test'];
        $this->assertSame(42, TypeHelpers::toInt($obj));
    }

    public function testToIntWithObjectCustomProperty(): void
    {
        $obj = (object) ['user_id' => '7', 'name' => 'test'];
        $this->assertSame(7, TypeHelpers::toInt($obj, 'user_id'));
    }

    public function testToIntWithIntegerValue(): void
    {
        $this->assertSame(5, TypeHelpers::toInt(5));
    }

    public function testToIntWithNumericString(): void
    {
        $this->assertSame(123, TypeHelpers::toInt('123'));
    }

    public function testToIntWithObjectIntProperty(): void
    {
        $obj = (object) ['id' => 99];
        $this->assertSame(99, TypeHelpers::toInt($obj));
    }

    public function testToIntWithZeroInteger(): void
    {
        $this->assertSame(0, TypeHelpers::toInt(0));
    }

    public function testToIntWithZeroString(): void
    {
        $this->assertSame(0, TypeHelpers::toInt('0'));
    }

    public function testToIntWithDecimalStringTruncates(): void
    {
        $this->assertSame(12, TypeHelpers::toInt('12.9'));
    }

    public function testToIntThrowsOnBooleanTrue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TypeHelpers::toInt(true);
    }

    public function testToIntWithObjectNullProperty_throwsPropertyDoesNotExist(): void
    {
        // Intentional: isset() cannot tell a null property from a missing one.
        $obj = (object) ['id' => null];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Property 'id' does not exist on object");
        TypeHelpers::toInt($obj);
    }

    public function testToIntThrowsOnNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TypeHelpers::toInt(null);
    }

    public function testToIntThrowsOnEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TypeHelpers::toInt('');
    }

    public function testToIntThrowsOnNonNumericString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TypeHelpers::toInt('abc');
    }

    public function testToIntThrowsOnMissingProperty(): void
    {
        $obj = (object) ['name' => 'test'];
        $this->expectException(InvalidArgumentException::class);
        TypeHelpers::toInt($obj, 'id');
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
