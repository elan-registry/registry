<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use ElanRegistry\Database\SessionTimeZone;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\SessionTimeZoneException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for SessionTimeZone (#2589).
 *
 * The session must use PHP's named zone, not a numeric offset. A named zone
 * follows DST, so TIMESTAMP rows from winter and summer both read correctly.
 */
#[Group('fast')]
final class SessionTimeZoneTest extends TestCase
{
    private string $originalZone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalZone);
        parent::tearDown();
    }

    public function testInitCommandHasTheSetStatementFormat(): void
    {
        $this->assertMatchesRegularExpression(
            "/^SET time_zone = '[A-Za-z0-9\\/_+-]+'\$/",
            SessionTimeZone::initCommand()
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zoneProvider(): array
    {
        return [
            'Los Angeles' => ['America/Los_Angeles'],
            'Kolkata' => ['Asia/Kolkata'],
        ];
    }

    #[DataProvider('zoneProvider')]
    public function testInitCommandUsesPhpsNamedZone(string $zone): void
    {
        date_default_timezone_set($zone);

        $this->assertSame("SET time_zone = '{$zone}'", SessionTimeZone::initCommand());
    }

    public function testAppTimezoneMatchesTheZoneThatInitPhpSets(): void
    {
        $init = file_get_contents(dirname(__DIR__, 3) . '/users/init.php');
        $this->assertIsString($init);

        $matched = preg_match('/\$timezone_string\s*=\s*[\'"]([^\'"]+)[\'"];\s*date_default_timezone_set\(\s*\$timezone_string\s*\)/', $init, $matches);

        $this->assertSame(1, $matched, 'users/init.php must set the zone through $timezone_string');
        $this->assertSame($matches[1], SessionTimeZone::APP_TIMEZONE);
    }

    public function testLoaderAppliesTheSessionZoneBeforeAnyInclude(): void
    {
        // Source check, so CI catches a removed hook in every month. The
        // integration probe catches it only while the server zone differs
        // from PHP's current UTC offset.
        $loader = file_get_contents(dirname(__DIR__, 3) . '/usersc/includes/loader.php');
        $this->assertIsString($loader);

        $hook = strpos($loader, 'SessionTimeZone::apply(dbi());');
        $firstInclude = strpos($loader, 'require_once');

        $this->assertNotFalse($hook, 'usersc/includes/loader.php must call SessionTimeZone::apply(dbi())');
        $this->assertNotFalse($firstInclude);
        $this->assertLessThan($firstInclude, $hook, 'The hook must run before the first require_once');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function rawPdoEntryPointProvider(): array
    {
        return [
            'phinx' => [
                'phinx.php',
                'date_default_timezone_set(SessionTimeZone::APP_TIMEZONE)',
                "'mysql_attr_init_command' => SessionTimeZone::initCommand()",
            ],
            'log-deployment' => [
                'scripts/log-deployment.php',
                'date_default_timezone_set(\\ElanRegistry\\Database\\SessionTimeZone::APP_TIMEZONE)',
                'Pdo\\Mysql::ATTR_INIT_COMMAND => \\ElanRegistry\\Database\\SessionTimeZone::initCommand()',
            ],
        ];
    }

    #[DataProvider('rawPdoEntryPointProvider')]
    public function testRawPdoEntryPointSetsTheZoneAndTheInitCommand(string $path, string $zoneCall, string $initCommandOption): void
    {
        // These files bypass users/init.php, so they must set both clocks themselves.
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        $this->assertIsString($source);

        $zonePos = strpos($source, $zoneCall);
        $initPos = strpos($source, $initCommandOption);

        $this->assertNotFalse($zonePos, "{$path} must set the PHP zone");
        $this->assertNotFalse($initPos, "{$path} must pass the init command");
        // initCommand() reads the PHP zone when it runs, so the zone must be set first.
        $this->assertLessThan($initPos, $zonePos, "{$path} must set the PHP zone before initCommand()");
    }

    public function testApplyPassesTheNamedZoneAsAParameter(): void
    {
        date_default_timezone_set('Asia/Kolkata');
        $db = $this->createMock(DatabaseInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->with('SET time_zone = ?', ['Asia/Kolkata'])
            ->willReturnSelf();
        $db->method('error')->willReturn(false);

        SessionTimeZone::apply($db);
    }

    public function testApplyThrowsWithTheZoneWhenMysqlRejectsTheStatement(): void
    {
        date_default_timezone_set('Asia/Kolkata');
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('mock failure');

        $this->expectException(SessionTimeZoneException::class);
        $this->expectExceptionMessage('Asia/Kolkata');

        SessionTimeZone::apply($db);
    }
}
