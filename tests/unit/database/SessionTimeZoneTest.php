<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use DateTimeImmutable;
use DateTimeZone;
use ElanRegistry\Database\SessionTimeZone;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\SessionTimeZoneException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for SessionTimeZone (#2589).
 *
 * The offset must follow DST, because PHP time in America/Los_Angeles moves
 * between -08:00 and -07:00 while the MySQL server zone does not.
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

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function offsetProvider(): array
    {
        return [
            'Los Angeles in winter (PST)' => ['America/Los_Angeles', '2027-01-15 12:00:00', '-08:00'],
            'Los Angeles in summer (PDT)' => ['America/Los_Angeles', '2027-07-15 12:00:00', '-07:00'],
            'Kolkata has a half-hour offset' => ['Asia/Kolkata', '2027-01-15 12:00:00', '+05:30'],
            'UTC' => ['UTC', '2027-01-15 12:00:00', '+00:00'],
        ];
    }

    #[DataProvider('offsetProvider')]
    public function testOffsetForReturnsTheZoneOffsetAtThatInstant(string $zoneName, string $instant, string $expected): void
    {
        $zone = new DateTimeZone($zoneName);

        $this->assertSame($expected, SessionTimeZone::offsetFor($zone, new DateTimeImmutable($instant, $zone)));
    }

    public function testInitCommandHasTheSetStatementFormat(): void
    {
        $this->assertMatchesRegularExpression(
            "/^SET time_zone = '[+-]\\d{2}:\\d{2}'\$/",
            SessionTimeZone::initCommand()
        );
    }

    public function testInitCommandUsesPhpsCurrentZone(): void
    {
        date_default_timezone_set('Asia/Kolkata');

        $this->assertSame("SET time_zone = '+05:30'", SessionTimeZone::initCommand());
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
        // from PHP's offset.
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

    public function testApplyPassesTheOffsetAsAParameter(): void
    {
        date_default_timezone_set('Asia/Kolkata');
        $db = $this->createMock(DatabaseInterface::class);
        $db->expects($this->once())
            ->method('query')
            ->with('SET time_zone = ?', ['+05:30'])
            ->willReturnSelf();
        $db->method('error')->willReturn(false);

        SessionTimeZone::apply($db);
    }

    public function testApplyThrowsWithTheOffsetWhenMysqlRejectsTheStatement(): void
    {
        date_default_timezone_set('Asia/Kolkata');
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('mock failure');

        $this->expectException(SessionTimeZoneException::class);
        $this->expectExceptionMessage('+05:30');

        SessionTimeZone::apply($db);
    }
}
