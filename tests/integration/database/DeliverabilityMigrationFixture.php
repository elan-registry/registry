<?php

declare(strict_types=1);

/**
 * Pre-migration fixture for the #2101 sandbox tests.
 *
 * It writes one owner for each case of migration 20261012000000 into a sandbox
 * that stands at version 20261007005444. All values are literals, so a test
 * takes its expected values from here and not from the code under test.
 *
 * Every owner has an ID from 101 up, and car ID = owner ID * 10 (a second car
 * of an owner ends in 1). A `users.join_date` is 2024-02-DD where DD is the
 * owner ID minus 100.
 *
 * | Owner | Case |
 * | ----- | ---- |
 * | 101 | bounce on the current address (case and spaces differ) |
 * | 102 | bounce on an old address |
 * | 103 | complaint (spam event) |
 * | 104 | opted out and complained (unsubscribed event) |
 * | 105 | unknown cause, after an owner Resume |
 * | 106 | car-level opt-out only |
 * | 107 | previous owner of car 1070, who opted out on it |
 * | 108 | new owner of car 1070 (old complaint stays with the old address) |
 * | 109 | bounce, no profiles row, two cars |
 * | 110 | noowner account |
 * | 111 | unknown cause, after an admin Clear Suppression |
 * | 112 | car owner with no flag and no profiles row |
 * | 113 | car 1130 has a NULL user_id (no user 113) |
 * | 114 | bounced profile with an event |
 * | 115 | bounced profile with an EMAIL BOUNCED history row only |
 * | 116 | bounced profile with no event and no history row |
 * | 117 | car bounce with a NULL bounced address |
 */
final class DeliverabilityMigrationFixture
{
    /** Owners that have a profiles row before the migration. */
    public const OWNERS_WITH_PROFILE = [101, 102, 103, 104, 105, 106, 107, 108, 111, 114, 115, 116, 117];

    /** Owners that get a profiles row from the migration. */
    public const OWNERS_WITHOUT_PROFILE = [109, 112];

    public const MTIME = '2026-01-01 00:00:00';

    public static function seed(\PDO $pdo): void
    {
        self::seedUsers($pdo);
        self::seedProfiles($pdo);
        self::seedCars($pdo);
        self::seedEvents($pdo);
        self::seedHistory($pdo);
    }

    public static function joinDate(int $userId): string
    {
        return sprintf('2024-02-%02d 00:00:00', $userId - 100);
    }

    private static function seedUsers(\PDO $pdo): void
    {
        $emails = [
            101 => '  Bounce.Current@Example.test',
            102 => 'new102@example.test',
            103 => 'complaint103@example.test',
            104 => 'both104@example.test',
            105 => 'unknown105@example.test',
            106 => 'caropt106@example.test',
            107 => 'oldowner107@example.test',
            108 => 'newowner108@example.test',
            109 => 'noprofile109@example.test',
            110 => 'noowner@example.test',
            111 => 'adminclear111@example.test',
            112 => 'cleanonly112@example.test',
            114 => 'event114@example.test',
            115 => 'hist115@example.test',
            116 => 'nomatch116@example.test',
            117 => 'null117@example.test',
        ];
        foreach ($emails as $id => $email) {
            self::insert($pdo, 'users', [
                'id'          => $id,
                'permissions' => 1,
                'email'       => $email,
                'username'    => $id === 110 ? 'noowner' : 'user' . $id,
                'created'     => '2024-01-01 00:00:00',
                'join_date'   => self::joinDate($id),
            ]);
        }
    }

    private static function seedProfiles(\PDO $pdo): void
    {
        $flags = [
            104 => ['email_suppressed' => 1],
            114 => ['email_bounced' => 1, 'email_bounced_address' => 'event114@example.test'],
            115 => ['email_bounced' => 1, 'email_bounced_address' => 'hist115@example.test'],
            116 => ['email_bounced' => 1, 'email_bounced_address' => 'nomatch116@example.test'],
        ];
        foreach (self::OWNERS_WITH_PROFILE as $id) {
            // bio, city, state and country are NOT NULL with no default: the sandbox connection is strict.
            self::insert($pdo, 'profiles', [
                'user_id' => $id,
                'bio'     => '',
                'city'    => '',
                'state'   => '',
                'country' => '',
            ] + ($flags[$id] ?? []));
        }
    }

    private static function seedCars(\PDO $pdo): void
    {
        // car id => [user id, car email, bounced, bounced address, suppressed]
        $cars = [
            1010 => [101, '  Bounce.Current@Example.test', 1, 'bounce.current@example.test ', 0],
            1020 => [102, 'new102@example.test', 1, 'old102@example.test', 0],
            1030 => [103, 'complaint103@example.test', 0, null, 1],
            1040 => [104, 'both104@example.test', 0, null, 1],
            1050 => [105, 'unknown105@example.test', 0, null, 1],
            1060 => [106, 'caropt106@example.test', 0, null, 1],
            1070 => [108, 'newowner108@example.test', 0, null, 1],
            1090 => [109, 'noprofile109@example.test', 1, 'noprofile109@example.test', 0],
            1091 => [109, 'noprofile109@example.test', 0, null, 0],
            1100 => [110, 'noowner@example.test', 1, 'noowner@example.test', 1],
            1110 => [111, 'adminclear111@example.test', 0, null, 1],
            1120 => [112, 'cleanonly112@example.test', 0, null, 0],
            1130 => [null, 'orphan113@example.test', 1, 'orphan113@example.test', 1],
            1140 => [114, 'event114@example.test', 0, null, 0],
            1150 => [115, 'hist115@example.test', 0, null, 0],
            1160 => [116, 'nomatch116@example.test', 0, null, 0],
            1170 => [117, 'null117@example.test', 1, null, 0],
        ];
        foreach ($cars as $id => [$userId, $email, $bounced, $bouncedAddress, $suppressed]) {
            self::insert($pdo, 'cars', [
                'id'                    => $id,
                'ctime'                 => self::MTIME,
                'mtime'                 => self::MTIME,
                'model'                 => 'Elan',
                'series'                => 'S3',
                'variant'               => 'FHC',
                'year'                  => 1966,
                'type'                  => '36',
                'chassis'               => 'CH' . $id,
                'user_id'               => $userId,
                'email'                 => $email,
                'join_date'             => $userId === null ? null : self::joinDate($userId),
                'email_bounced'         => $bounced,
                'email_bounced_address' => $bouncedAddress,
                'email_suppressed'      => $suppressed,
            ]);
        }
    }

    private static function seedEvents(\PDO $pdo): void
    {
        // car id, address, event, occurred_at
        $events = [
            [1010, 'bounce.current@example.test', 'hard_bounce', '2026-02-01 10:00:00'],
            [1010, 'Bounce.Current@Example.TEST', 'hard_bounce', '2026-03-01 10:00:00'],
            [1010, 'bounce.current@example.test', 'soft_bounce', '2026-03-05 10:00:00'],
            [1020, 'old102@example.test', 'hard_bounce', '2026-03-02 10:00:00'],
            [1030, 'complaint103@example.test', 'spam', '2026-04-01 09:00:00'],
            [1040, 'both104@example.test', 'unsubscribed', '2026-04-15 07:00:00'],
            [1070, 'oldowner107@example.test', 'spam', '2026-04-02 09:00:00'],
            [1090, 'noprofile109@example.test', 'hard_bounce', '2026-07-01 06:00:00'],
            [1140, 'event114@example.test', 'hard_bounce', '2026-08-01 05:00:00'],
            [1140, 'event114@example.test', 'soft_bounce', '2026-08-02 05:00:00'],
        ];
        foreach ($events as $index => [$carId, $address, $event, $occurredAt]) {
            self::insert($pdo, 'er_email_events', [
                'car_id'           => $carId,
                'email'            => $address,
                'event'            => $event,
                'brevo_message_id' => 'msg-' . $index,
                'occurred_at'      => $occurredAt,
            ]);
        }
    }

    private static function seedHistory(\PDO $pdo): void
    {
        // car id, user id, operation, timestamp, address
        $rows = [
            [1040, 104, 'EMAIL SUPPRESSED', '2026-05-01 08:00:00', 'both104@example.test'],
            [1050, 105, 'EMAIL SUPPRESSED', '2026-02-10 12:00:00', 'unknown105@example.test'],
            [1050, 105, 'SUPPRESSION CLEARED BY OWNER', '2026-02-11 12:00:00', 'unknown105@example.test'],
            [1060, 106, 'EMAIL SUPPRESSED', '2026-06-01 11:00:00', 'caropt106@example.test'],
            [1070, 107, 'EMAIL SUPPRESSED', '2026-01-20 09:00:00', 'oldowner107@example.test'],
            [1110, 111, 'EMAIL SUPPRESSED', '2026-03-01 13:00:00', 'adminclear111@example.test'],
            [1110, 111, 'EMAIL SUPPRESSION CLEARED', '2026-03-02 13:00:00', 'adminclear111@example.test'],
            [1150, 115, 'EMAIL BOUNCED', '2026-09-01 04:00:00', 'hist115@example.test'],
        ];
        foreach ($rows as [$carId, $userId, $operation, $timestamp, $address]) {
            self::insert($pdo, 'cars_hist', [
                'operation' => $operation,
                'car_id'    => $carId,
                'model'     => 'Elan',
                'series'    => 'S3',
                'variant'   => 'FHC',
                'type'      => '36',
                'chassis'   => 'CH' . $carId,
                'user_id'   => $userId,
                'email'     => $address,
                'timestamp' => $timestamp,
            ]);
        }
    }

    /**
     * @param array<string, int|string|null> $values
     */
    private static function insert(\PDO $pdo, string $table, array $values): void
    {
        $columns = implode(', ', array_map(static fn (string $c): string => "`{$c}`", array_keys($values)));
        $marks = implode(', ', array_fill(0, count($values), '?'));
        $pdo->prepare("INSERT INTO `{$table}` ({$columns}) VALUES ({$marks})")->execute(array_values($values));
    }
}
