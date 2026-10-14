<?php

declare(strict_types=1);

/**
 * Pre-migration fixture for the #2358 sandbox tests.
 *
 * It writes one case for each rule of migration 20261013000000 into a sandbox
 * that stands at version 20261012000001. Every value is a literal, so a test
 * takes its expected values from here and not from the code under test.
 *
 * Event IDs are fixed (1 to 20). User IDs are 201 and up. Car ID = user ID * 10
 * (a second car of a user ends in 1).
 *
 * | Users | Case |
 * | ----- | ---- |
 * | 201 | owner of cars 2010, 2011, 2012. Address has spaces and capitals. |
 * | 202, 203 | car 2020 belongs to 202. Event 4 has the address of 203. |
 * | 204, 205 | both hold `shared@example.test` |
 * | 206 | car 2060. Event 5 has the shared address. |
 * | 207 | owner of cars 2070, 2071. Address `jose@example.test`. |
 * | 210 | the `noowner` account, car 2100 |
 * | 213 | none. Car 2130 has a NULL user_id. |
 * | 215 | car 2150. Event 17 has an empty address. |
 * | 216, 217 | both hold `twin@example.test`. Car 2160 belongs to 216. |
 *
 * | Event | Car | Case | Expected |
 * | ----- | --- | ---- | -------- |
 * | 1, 2, 3 | 2010, 2011, 2012 | same message, one owner | collapse into 1, at 09:00 |
 * | 4 | 2020 | address of another user | kept, user 203, by address |
 * | 5 | 2060 | address that two users hold | dropped |
 * | 6 | 2070 | accent variant `josé@` | dropped |
 * | 7, 8 | 2070 | exact address, and upper case with a space | kept, user 207, lower case |
 * | 9 | 2100 | owner is `noowner` | dropped |
 * | 10 | 2071 | address of `noowner` | dropped |
 * | 11 | 2130 | car with no user, unknown address | dropped |
 * | 12 | 9999 | car that does not exist, address of 207 | kept, user 207, by address |
 * | 13, 14 | 2070, 2071 | message ID with other case | collapse into 13, at 06:00 |
 * | 15 | 2010 | `sent` row | kept |
 * | 16 | 2070 | same message ID as event 1, other user | kept, not collapsed with 1 |
 * | 17 | 2150 | empty address | dropped |
 * | 18 | 2160 | owner's address, and a second user holds it too | kept, user 216, by owner |
 * | 19 | 2100 | car of `noowner`, address of 202 | kept, user 202, by address |
 * | 20 | 2100 | car of `noowner`, address that two users hold | dropped |
 */
final class EmailEventRekeyMigrationFixture
{
    /** Event IDs that the migration keeps, in ascending order. */
    public const KEPT_IDS = [1, 4, 7, 8, 12, 13, 15, 16, 18, 19];

    /** Step (b): event IDs that go to the owner of their car. */
    public const OWNER_MATCHED_IDS = [1, 2, 3, 7, 8, 13, 14, 15, 16, 18];

    /** Step (b): event IDs that go to the one user with the address. */
    public const ADDRESS_MATCHED_IDS = [4, 12, 19];

    /** Step (c): event IDs with no user, or with `noowner`. */
    public const DROPPED_IDS = [5, 6, 9, 10, 11, 17, 20];

    /** Step (d): the groups that collapse. */
    public const COLLAPSE_GROUPS = [
        ['kept_id' => 1, 'removed_ids' => [2, 3], 'occurred_at' => '2026-03-01 09:00:00'],
        ['kept_id' => 13, 'removed_ids' => [14], 'occurred_at' => '2026-03-02 06:00:00'],
    ];

    /** Kept event ID => the user that owns the row after the migration. */
    public const USER_AFTER = [
        1 => 201, 4 => 203, 7 => 207, 8 => 207, 12 => 207, 13 => 207, 15 => 201, 16 => 207, 18 => 216, 19 => 202,
    ];

    public const USER_IDS = [201, 202, 203, 204, 205, 206, 207, 210, 215, 216, 217];

    public const CAR_IDS = [2010, 2011, 2012, 2020, 2060, 2070, 2071, 2100, 2130, 2150, 2160];

    public const MTIME = '2026-01-01 00:00:00';

    public static function seed(\PDO $pdo): void
    {
        self::seedUsers($pdo);
        self::seedProfiles($pdo);
        self::seedCars($pdo);
        self::seedEvents($pdo);
    }

    /**
     * Remove every fixture row, so a test can seed again. Call it on the
     * pre-migration schema (after down()).
     */
    public static function clear(\PDO $pdo): void
    {
        $pdo->exec('DELETE FROM er_email_events');
        $pdo->exec('DELETE FROM cars WHERE id IN (' . implode(',', self::CAR_IDS) . ')');
        $pdo->exec('DELETE FROM cars_hist WHERE car_id IN (' . implode(',', self::CAR_IDS) . ')');
        $pdo->exec('DELETE FROM profiles WHERE user_id IN (' . implode(',', self::USER_IDS) . ')');
        $pdo->exec('DELETE FROM users WHERE id IN (' . implode(',', self::USER_IDS) . ')');
    }

    private static function seedUsers(\PDO $pdo): void
    {
        $emails = [
            201 => '  Three.Cars@Example.test',
            202 => 'own202@example.test',
            203 => 'addr203@example.test',
            204 => 'shared@example.test',
            205 => 'shared@example.test',
            206 => 'own206@example.test',
            207 => 'jose@example.test',
            210 => 'noowner@example.test',
            215 => 'own215@example.test',
            216 => 'twin@example.test',
            217 => 'Twin@Example.test',
        ];
        foreach ($emails as $id => $email) {
            self::insert($pdo, 'users', [
                'id'          => $id,
                'permissions' => 1,
                'email'       => $email,
                'username'    => $id === 210 ? 'noowner' : 'user' . $id,
                'created'     => '2024-01-01 00:00:00',
                'join_date'   => '2024-02-01 00:00:00',
            ]);
        }
    }

    private static function seedProfiles(\PDO $pdo): void
    {
        // The migration must not write a flag. These rows prove it.
        foreach ([201, 207] as $id) {
            self::insert($pdo, 'profiles', [
                'user_id' => $id,
                'bio'     => '',
                'city'    => '',
                'state'   => '',
                'country' => '',
            ]);
        }
    }

    private static function seedCars(\PDO $pdo): void
    {
        // car id => user id
        $cars = [
            2010 => 201, 2011 => 201, 2012 => 201, 2020 => 202, 2060 => 206,
            2070 => 207, 2071 => 207, 2100 => 210, 2130 => null, 2150 => 215, 2160 => 216,
        ];
        foreach ($cars as $id => $userId) {
            self::insert($pdo, 'cars', [
                'id'        => $id,
                'ctime'     => self::MTIME,
                'mtime'     => self::MTIME,
                'model'     => 'Elan',
                'series'    => 'S3',
                'variant'   => 'FHC',
                'year'      => 1966,
                'type'      => '36',
                'chassis'   => 'CH' . $id,
                'user_id'   => $userId,
                'email'     => 'car' . $id . '@example.test',
                'join_date' => $userId === null ? null : '2024-02-01 00:00:00',
            ]);
        }
    }

    private static function seedEvents(\PDO $pdo): void
    {
        // id, car id, address, event, message id, occurred_at
        $events = [
            [1, 2010, 'three.cars@example.test', 'delivered', 'msg-A', '2026-03-01 10:00:00'],
            [2, 2011, 'Three.Cars@Example.TEST', 'delivered', 'msg-A', '2026-03-01 09:00:00'],
            [3, 2012, ' three.cars@example.test ', 'delivered', 'msg-A', '2026-03-01 11:00:00'],
            [4, 2020, 'addr203@example.test', 'hard_bounce', 'msg-4', '2026-03-03 10:00:00'],
            [5, 2060, 'shared@example.test', 'hard_bounce', 'msg-5', '2026-03-04 10:00:00'],
            [6, 2070, 'josé@example.test', 'soft_bounce', 'msg-6', '2026-03-05 10:00:00'],
            [7, 2070, 'jose@example.test', 'soft_bounce', 'msg-7', '2026-03-06 10:00:00'],
            [8, 2070, 'JOSE@Example.TEST ', 'soft_bounce', 'msg-8', '2026-03-07 10:00:00'],
            [9, 2100, 'noowner@example.test', 'hard_bounce', 'msg-9', '2026-03-08 10:00:00'],
            [10, 2071, 'noowner@example.test', 'hard_bounce', 'msg-10', '2026-03-09 10:00:00'],
            [11, 2130, 'nobody@example.test', 'hard_bounce', 'msg-11', '2026-03-10 10:00:00'],
            [12, 9999, 'jose@example.test', 'delivered', 'msg-12', '2026-03-11 10:00:00'],
            [13, 2070, 'jose@example.test', 'delivered', 'MSG-Z', '2026-03-02 07:00:00'],
            [14, 2071, 'jose@example.test', 'delivered', 'msg-z', '2026-03-02 06:00:00'],
            [15, 2010, 'three.cars@example.test', 'sent', 'local:abc', '2026-03-01 08:00:00'],
            [16, 2070, 'jose@example.test', 'delivered', 'msg-A', '2026-03-12 12:00:00'],
            [17, 2150, '', 'hard_bounce', 'msg-17', '2026-03-13 10:00:00'],
            [18, 2160, 'twin@example.test', 'delivered', 'msg-18', '2026-03-14 10:00:00'],
            [19, 2100, 'own202@example.test', 'hard_bounce', 'msg-19', '2026-03-15 10:00:00'],
            [20, 2100, 'shared@example.test', 'hard_bounce', 'msg-20', '2026-03-16 10:00:00'],
        ];
        foreach ($events as [$id, $carId, $address, $event, $messageId, $occurredAt]) {
            self::insert($pdo, 'er_email_events', [
                'id'               => $id,
                'car_id'           => $carId,
                'email'            => $address,
                'event'            => $event,
                'brevo_message_id' => $messageId,
                'occurred_at'      => $occurredAt,
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
