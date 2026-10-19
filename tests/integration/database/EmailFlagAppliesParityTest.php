<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\EmailAddress;
use ElanRegistry\Car\DeliverabilityFlagLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The rule "a stored flag applies to the owner's current address" exists
 * twice. SQL form: CarRepository::emailFlagActiveSql() (FLAG_ACTIVE_BLOCKED,
 * FLAG_ACTIVE_BOUNCED). PHP form: EffectiveEmailFlags::flagApplies(). This
 * test keeps the two forms the same. It runs both forms, and the
 * effectiveEmailFlags() path that uses the PHP form, on the same real row,
 * and compares each with a literal answer.
 */
#[Group('integration')]
final class EmailFlagAppliesParityTest extends IntegrationTestCase
{
    /** The owner's address, normalized. The `users.email` of most rows is this value. */
    private const CURRENT = 'parity@example.com';

    /**
     * @return array<string, array{0: int, 1: string|null, 2: string, 3: bool}>
     *         Flag value, stored `_address`, `users.email`, and the literal answer
     */
    public static function rowProvider(): array
    {
        return [
            'flag 0, no address'                          => [0, null, self::CURRENT, false],
            'flag 0, same address'                        => [0, self::CURRENT, self::CURRENT, false],
            'flag 0, different address'                   => [0, 'other@example.com', self::CURRENT, false],
            'flag 1, NULL address fails closed'           => [1, null, self::CURRENT, true],
            'flag 1, empty address fails closed'          => [1, '', self::CURRENT, true],
            'flag 1, spaces-only address fails closed'    => [1, '   ', self::CURRENT, true],
            'flag 1, same address'                        => [1, self::CURRENT, self::CURRENT, true],
            'flag 1, same address in upper case'          => [1, 'PARITY@Example.COM', self::CURRENT, true],
            'flag 1, same address with spaces'            => [1, '  parity@example.com  ', self::CURRENT, true],
            'flag 1, same address with tab and newline'   => [1, "\tparity@example.com\n", self::CURRENT, true],
            'flag 1, same address with vertical tab and CR' => [1, "\x0Bparity@example.com\r", self::CURRENT, true],
            'flag 1, different address'                   => [1, 'other@example.com', self::CURRENT, false],
            'flag 1, address that holds the current one'  => [1, 'xparity@example.com', self::CURRENT, false],
            'flag 1, users.email in mixed case and spaced, same address' => [1, self::CURRENT, ' Parity@Example.COM ', true],
            'flag 1, users.email in mixed case, other address'           => [1, 'other@example.com', 'Parity@Example.COM', false],
        ];
    }

    /**
     * The SQL form of the rule, for one flag of one owner
     */
    private function sqlForm(string $flagName, int $userId): bool
    {
        $result = $this->db->query(
            'SELECT (' . CarRepository::emailFlagActiveSql($flagName) . ') AS applies
               FROM profiles p INNER JOIN users u ON u.id = p.user_id
              WHERE p.user_id = ?',
            [$userId]
        );
        $this->assertFalse($this->db->error(), 'SQL form failed: ' . $this->db->errorString());
        $row = $result->first();
        $this->assertIsObject($row, 'The SQL form must return the fixture row');

        return (int) $row->applies === 1;
    }

    #[DataProvider('rowProvider')]
    public function testSqlFormAndPhpFormAgreeOnTheSameRow(int $flag, ?string $address, string $usersEmail, bool $expected): void
    {
        $userId = $this->createTestUser([], true);
        $this->setUserEmailRaw($userId, $usersEmail);

        foreach ([DeliverabilityFlagLog::FLAG_BLOCKED, DeliverabilityFlagLog::FLAG_BOUNCED] as $flagName) {
            $this->db->query(
                "UPDATE profiles SET {$flagName} = ?, {$flagName}_address = ? WHERE user_id = ?",
                [$flag, $address, $userId]
            );
            $this->assertFalse($this->db->error(), 'Test setup: profile update failed: ' . $this->db->errorString());

            $sql = $this->sqlForm($flagName, $userId);
            $php = EffectiveEmailFlags::flagApplies($flag, $address, EmailAddress::normalize($usersEmail));
            $effective = (new CarRepository($this->db))->effectiveEmailFlags($userId);
            $viaRepository = $flagName === DeliverabilityFlagLog::FLAG_BLOCKED ? $effective->blocked : $effective->bounced;

            $this->assertSame($expected, $sql, "{$flagName}: the SQL form");
            $this->assertSame($expected, $php, "{$flagName}: the PHP form");
            $this->assertSame($sql, $php, "{$flagName}: the SQL and PHP forms must agree");
            $this->assertSame($expected, $viaRepository, "{$flagName}: effectiveEmailFlags()");

            // Reset so the next flag is tested alone.
            $this->db->query(
                "UPDATE profiles SET {$flagName} = 0, {$flagName}_address = NULL WHERE user_id = ?",
                [$userId]
            );
        }
    }
}
