<?php

declare(strict_types=1);

namespace ElanRegistry\Migration;

use ElanRegistry\Exceptions\OwnerDatabaseException;
use PDO;
use PDOException;

/**
 * DeliverabilityMigrationQueries - The reads that decide what the #2101 migration copies
 *
 * Migration 20261012000000_move_deliverability_flags_to_profiles moves the
 * email deliverability flags from `cars` to `profiles`. The migration keeps
 * its reads in this class so that a test can call the same rules and compare
 * the decisions with the migrated rows.
 *
 * The class works on both schemas that it can meet:
 *
 * - Before the migration: `profiles.email_suppressed` holds the owner opt-out,
 *   and the new `profiles` columns do not exist. A missing column reads as 0
 *   or NULL, which is the value that phase 1 of the migration gives it.
 * - After phase 1: `profiles.email_opted_out` holds the opt-out.
 *
 * An owner with no `profiles` row reads as a row with every flag at 0,
 * because phase 1 inserts that row before phase 2 copies the flags.
 *
 * Address matching: every address goes through {@see self::normalizeAddress()},
 * and the compare is byte for byte in PHP. SQL does no address compare,
 * because the `users` and `cars` collations are case-insensitive and can
 * differ between installs, and MySQL `TRIM()` removes spaces only.
 *
 * The first call reads the database once and keeps the result. Make a new
 * instance after a write (for example after phase 1) to read the new state.
 *
 * The event names and `cars_hist` operation names are copies, not references
 * to `EmailEventApplier` or `EmailNoticeBuilder`. A migration must give the
 * same result when it runs again later, after those classes change (#2359).
 *
 * @package ElanRegistry\Migration
 * @see https://github.com/elan-registry/registry/issues/2101
 */
final class DeliverabilityMigrationQueries
{
    /** The GDPR reassignment account. Its cars never give a flag to a person. */
    public const NOOWNER_USERNAME = 'noowner';

    /** Brevo events that set the bounce flag at once. */
    private const HARD_BOUNCE_EVENTS = ['hard_bounce', 'blocked', 'invalid', 'invalid_email'];

    /** Three of these set the bounce flag (escalation). Used for a date only when no hard bounce exists. */
    private const SOFT_BOUNCE_EVENT = 'soft_bounce';

    /** Brevo events that set the car suppression flag: a complaint or an unsubscribe. */
    private const SUPPRESSION_EVENTS = ['spam', 'unsubscribed'];

    /** The `cars_hist` row of an owner opt-out (verify_car.php). */
    private const OPERATION_OPT_OUT = 'EMAIL SUPPRESSED';

    /** The `cars_hist` row of an admin Mark Bounced. */
    private const OPERATION_BOUNCED = 'EMAIL BOUNCED';

    /** The `cars_hist` rows that end an opt-out: owner Resume and admin Clear Suppression. */
    private const OPERATIONS_OPT_OUT_CLEAR = ['SUPPRESSION CLEARED BY OWNER', 'EMAIL SUPPRESSION CLEARED'];

    /** Upper limit of IDs in one IN() list. */
    private const ID_CHUNK = 1000;

    /**
     * The database state, read once. See {@see self::snapshot()}.
     *
     * @var array{
     *     cars: list<array{car_id: int, user_id: int, car_email: string, bounced: bool, bounced_address: string, suppressed: bool}>,
     *     profiles: array<int, array{opted_out: bool, opted_out_at_null: bool, bounced: bool, bounced_address: string, bounced_at_null: bool, blocked: bool, blocked_address: string}>,
     *     users: array<int, array{address: string, noowner: bool}>,
     *     history: list<array{id: int, car_id: int, user_id: int, operation: string, timestamp: string, address: string}>,
     *     events: list<array{car_id: int, address: string, event: string, occurred_at: string}>
     * }|null
     */
    private ?array $snapshot = null;

    /** @var list<array{user_id: int, join_date: ?string, address: string}>|null */
    private ?array $profilesToInsert = null;

    /** @var array{copies: list<array{user_id: int, address: string, bounced_at: ?string, car_ids: list<int>}>, dropped: list<array{user_id: int, car_id: int}>}|null */
    private ?array $bounces = null;

    /** @var list<array{user_id: int, opted_out_at: string}>|null */
    private ?array $optOutRestores = null;

    /** @var array{copies: list<array{user_id: int, address: string, cause: ?string, blocked_at: ?string, car_ids: list<int>}>, dropped: list<array{user_id: int, car_id: int}>}|null */
    private ?array $blocks = null;

    /**
     * @param PDO $pdo A connection to the schema to read. The class only reads.
     */
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The matching rule for every address compare in #2101.
     *
     * Both sides of a compare go through this function, then the compare is
     * byte for byte. NULL becomes '', and '' matches no real address.
     *
     * @param string|null $address A raw address from `users`, `cars`, `profiles` or an event
     * @return string The trimmed, lower-case address
     */
    public static function normalizeAddress(?string $address): string
    {
        return strtolower(trim((string) $address));
    }

    /**
     * Tell whether `cars` still has the three flag columns.
     *
     * snapshot() reads the car flags only while they exist. When they do not,
     * the migration already ran.
     *
     * @return bool True when `cars.email_bounced`, `email_bounced_address` and `email_suppressed` all exist
     * @throws OwnerDatabaseException If the schema read fails
     */
    private function carFlagColumnsExist(): bool
    {
        return $this->columnExists('cars', 'email_bounced')
            && $this->columnExists('cars', 'email_bounced_address')
            && $this->columnExists('cars', 'email_suppressed');
    }

    /**
     * Find each user_id that has more than one `profiles` row.
     *
     * The migration adds UNIQUE (user_id), so it stops while this list is not
     * empty. An admin merges the rows first.
     *
     * @return list<int> User IDs, in ascending order
     * @throws OwnerDatabaseException If the query fails
     */
    public function duplicateProfileUserIds(): array
    {
        $rows = $this->fetchRows(
            'SELECT user_id FROM profiles GROUP BY user_id HAVING COUNT(*) > 1 ORDER BY user_id'
        );

        return array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
    }

    /**
     * Find each car owner that has no `profiles` row.
     *
     * Phase 1 inserts a row for each one, so phase 2 has a row to copy the
     * car flags to. `noowner`, a NULL `cars.user_id` and a `user_id` with no
     * `users` row get no row.
     *
     * @return list<array{user_id: int, join_date: ?string, address: string}>
     *         One entry per user, in ascending user ID order. `address` is the
     *         normalized `users.email`, for `email_address_seen`. Never print it.
     * @throws OwnerDatabaseException If the query fails
     */
    public function profilesToInsert(): array
    {
        if ($this->profilesToInsert !== null) {
            return $this->profilesToInsert;
        }

        $rows = $this->fetchRows(
            'SELECT DISTINCT u.id AS user_id, u.join_date, u.email
               FROM cars c
               JOIN users u ON u.id = c.user_id
               LEFT JOIN profiles p ON p.user_id = u.id
              WHERE p.id IS NULL
                AND u.username <> ?
              ORDER BY u.id',
            [self::NOOWNER_USERNAME]
        );

        $this->profilesToInsert = array_map(
            static fn (array $row): array => [
                'user_id'   => (int) $row['user_id'],
                'join_date' => $row['join_date'] === null ? null : (string) $row['join_date'],
                'address'   => self::normalizeAddress($row['email'] === null ? null : (string) $row['email']),
            ],
            $rows
        );

        return $this->profilesToInsert;
    }

    /**
     * Phase 2(a): the owners whose profile gets a bounce from a car.
     *
     * A car bounce copies when `cars.email_bounced = 1` and its
     * `email_bounced_address` matches the owner's current `users.email`. The
     * profile is written only when its bounce flag is 0, or is set for an
     * address that is not the owner's current address.
     *
     * @return list<array{user_id: int, address: string, bounced_at: ?string, car_ids: list<int>}>
     *         One entry per owner, in ascending user ID order. `address` is the
     *         normalized current address. Never print it. `bounced_at` is the
     *         latest matching bounce event or `EMAIL BOUNCED` history row, or NULL.
     * @throws OwnerDatabaseException If a query fails
     */
    public function bounceCopies(): array
    {
        return $this->analyzeBounces()['copies'];
    }

    /**
     * The car bounces that phase 2(a) drops, because the bounced address is
     * not the owner's current address (or no address was recorded).
     *
     * @return list<array{user_id: int, car_id: int}> In ascending car ID order
     * @throws OwnerDatabaseException If a query fails
     */
    public function droppedBounces(): array
    {
        return $this->analyzeBounces()['dropped'];
    }

    /**
     * Phase 2(b), first step: the owners whose opt-out comes back to the profile.
     *
     * An owner opt-out writes an `EMAIL SUPPRESSED` row to `cars_hist` for
     * each car. When the latest such row for the owner has no later Resume or
     * Clear Suppression row, the opt-out still stands, whatever the address.
     * This keeps a car-only opt-out (pre-#1883 data, or a failed profile write)
     * as the person's refusal. The row must carry the owner's own user ID, so
     * an opt-out never moves to the next owner of a transferred car.
     *
     * Only owners with `email_opted_out = 0` and a `profiles` row (now, or
     * after phase 1) are listed.
     *
     * @return list<array{user_id: int, opted_out_at: string}> In ascending user ID order
     * @throws OwnerDatabaseException If a query fails
     */
    public function optOutRestores(): array
    {
        if ($this->optOutRestores !== null) {
            return $this->optOutRestores;
        }

        $snapshot = $this->snapshot();
        $willHaveProfile = $this->willHaveProfile();

        // Rows come in (timestamp, id) order, so the last row seen per owner is the latest.
        $latestByOwner = [];
        foreach ($snapshot['history'] as $row) {
            if ($row['operation'] === self::OPERATION_OPT_OUT
                || in_array($row['operation'], self::OPERATIONS_OPT_OUT_CLEAR, true)
            ) {
                $latestByOwner[$row['user_id']] = $row;
            }
        }

        $restores = [];
        foreach ($latestByOwner as $userId => $row) {
            if ($row['operation'] !== self::OPERATION_OPT_OUT
                || !$this->isPersonOwner($userId)
                || !isset($willHaveProfile[$userId])
                || $this->profileState($userId)['opted_out']
            ) {
                continue;
            }
            $restores[$userId] = ['user_id' => $userId, 'opted_out_at' => $row['timestamp']];
        }
        ksort($restores);

        return $this->optOutRestores = array_values($restores);
    }

    /**
     * Phase 2(b), second step: the owners whose profile gets `email_blocked`.
     *
     * A suppressed car (`cars.email_suppressed = 1`) whose `cars.email`
     * matches the owner's current `users.email` blocks the owner when:
     *
     * 1. A `spam` or `unsubscribed` event exists for that address. The latest
     *    one gives the cause and the date. This also blocks an owner who
     *    complained and later opted out, so Resume cannot re-enable the mail.
     * 2. Or no such event exists, no such event exists for the car under
     *    another address, and the owner has no opt-out after the restore step.
     *    The cause is then NULL (unknown), and the date is the latest
     *    `EMAIL SUPPRESSED` row of the car, or NULL.
     *
     * `adminBlocked` and an unsubscribe both arrive as `unsubscribed`, so both
     * migrate with that cause. The profile is written only when its block
     * flag is 0, or is set for another address.
     *
     * @return list<array{user_id: int, address: string, cause: ?string, blocked_at: ?string, car_ids: list<int>}>
     *         One entry per owner, in ascending user ID order. `address` is the
     *         owner's normalized `users.email`, also when the cause is unknown.
     *         Never print it.
     * @throws OwnerDatabaseException If a query fails
     */
    public function blockCopies(): array
    {
        return $this->analyzeBlocks()['copies'];
    }

    /**
     * The owners that phase 2(b) blocks with no known cause.
     *
     * @return list<int> User IDs, in ascending order
     * @throws OwnerDatabaseException If a query fails
     */
    public function unknownCauseBlocks(): array
    {
        $ids = [];
        foreach ($this->blockCopies() as $copy) {
            if ($copy['cause'] === null) {
                $ids[] = $copy['user_id'];
            }
        }

        return $ids;
    }

    /**
     * The car suppressions that phase 2(b) drops, because the suppression
     * belongs to an address that is not the owner's current address.
     *
     * That is a car whose `cars.email` does not match the owner's
     * `users.email`, or a car with a complaint or unsubscribe event only
     * under another address.
     *
     * @return list<array{user_id: int, car_id: int}> In ascending car ID order
     * @throws OwnerDatabaseException If a query fails
     */
    public function droppedSuppressions(): array
    {
        return $this->analyzeBlocks()['dropped'];
    }

    /**
     * Phase 1 backfill source for `profiles.email_opted_out_at`.
     *
     * For each profile with the opt-out flag set and no opt-out date: the
     * latest owner `EMAIL SUPPRESSED` history row of that owner. A profile
     * with no such row is absent, and its date stays NULL.
     *
     * @return array<int, string> Date keyed by user ID
     * @throws OwnerDatabaseException If a query fails
     */
    public function optedOutAtBackfill(): array
    {
        $snapshot = $this->snapshot();

        $latestOptOut = [];
        foreach ($snapshot['history'] as $row) {
            if ($row['operation'] === self::OPERATION_OPT_OUT) {
                $latestOptOut[$row['user_id']] = $row['timestamp'];
            }
        }

        $dates = [];
        foreach ($snapshot['profiles'] as $userId => $profile) {
            if ($profile['opted_out'] && $profile['opted_out_at_null'] && isset($latestOptOut[$userId])) {
                $dates[$userId] = $latestOptOut[$userId];
            }
        }
        ksort($dates);

        return $dates;
    }

    /**
     * Phase 1 backfill source for `profiles.email_bounced_at`.
     *
     * For each profile with `email_bounced = 1` and no bounce date: the latest
     * bounce event or `EMAIL BOUNCED` history row that matches the profile's
     * `email_bounced_address`. A profile with no match is absent, and its
     * date stays NULL.
     *
     * @return array<int, string> Date keyed by user ID
     * @throws OwnerDatabaseException If a query fails
     */
    public function bouncedAtBackfill(): array
    {
        $dates = [];
        foreach ($this->snapshot()['profiles'] as $userId => $profile) {
            if (!$profile['bounced'] || !$profile['bounced_at_null']) {
                continue;
            }
            $date = $this->latestBounceAt($userId, $profile['bounced_address']);
            if ($date !== null) {
                $dates[$userId] = $date;
            }
        }
        ksort($dates);

        return $dates;
    }

    /**
     * Compute the bounce copies and the dropped bounces once.
     *
     * @return array{copies: list<array{user_id: int, address: string, bounced_at: ?string, car_ids: list<int>}>, dropped: list<array{user_id: int, car_id: int}>}
     * @throws OwnerDatabaseException If a query fails
     */
    private function analyzeBounces(): array
    {
        if ($this->bounces !== null) {
            return $this->bounces;
        }

        $snapshot = $this->snapshot();
        $carIdsByOwner = [];
        $dropped = [];

        foreach ($snapshot['cars'] as $car) {
            if (!$car['bounced'] || !$this->isPersonOwner($car['user_id'])) {
                continue;
            }
            $current = $snapshot['users'][$car['user_id']]['address'];
            if ($current === '' || $car['bounced_address'] !== $current) {
                $dropped[] = ['user_id' => $car['user_id'], 'car_id' => $car['car_id']];
                continue;
            }
            $carIdsByOwner[$car['user_id']][] = $car['car_id'];
        }

        $copies = [];
        foreach ($carIdsByOwner as $userId => $carIds) {
            $current = $snapshot['users'][$userId]['address'];
            $profile = $this->profileState($userId);
            if ($profile['bounced'] && $profile['bounced_address'] === $current) {
                continue; // The profile already holds this bounce.
            }
            $copies[$userId] = [
                'user_id'    => $userId,
                'address'    => $current,
                'bounced_at' => $this->latestBounceAt($userId, $current),
                'car_ids'    => $carIds,
            ];
        }
        ksort($copies);

        return $this->bounces = ['copies' => array_values($copies), 'dropped' => $dropped];
    }

    /**
     * Compute the block copies and the dropped suppressions once.
     *
     * @return array{copies: list<array{user_id: int, address: string, cause: ?string, blocked_at: ?string, car_ids: list<int>}>, dropped: list<array{user_id: int, car_id: int}>}
     * @throws OwnerDatabaseException If a query fails
     */
    private function analyzeBlocks(): array
    {
        if ($this->blocks !== null) {
            return $this->blocks;
        }

        $snapshot = $this->snapshot();

        $restored = [];
        foreach ($this->optOutRestores() as $restore) {
            $restored[$restore['user_id']] = true;
        }

        // Latest complaint or unsubscribe per address, and the addresses each car has events for.
        $latestEventByAddress = [];
        $eventAddressesByCar = [];
        foreach ($snapshot['events'] as $event) {
            if (!in_array($event['event'], self::SUPPRESSION_EVENTS, true)) {
                continue;
            }
            $latestEventByAddress[$event['address']] = $event;
            $eventAddressesByCar[$event['car_id']][$event['address']] = true;
        }

        // Latest owner opt-out row per car, for the date of an unknown-cause block.
        $latestOptOutByCarOwner = [];
        foreach ($snapshot['history'] as $row) {
            if ($row['operation'] === self::OPERATION_OPT_OUT) {
                $latestOptOutByCarOwner[$row['car_id'] . ':' . $row['user_id']] = $row['timestamp'];
            }
        }

        $byOwner = [];
        $dropped = [];
        foreach ($snapshot['cars'] as $car) {
            if (!$car['suppressed'] || !$this->isPersonOwner($car['user_id'])) {
                continue;
            }
            $userId = $car['user_id'];
            $current = $snapshot['users'][$userId]['address'];
            if ($current === '' || $car['car_email'] !== $current) {
                $dropped[] = ['user_id' => $userId, 'car_id' => $car['car_id']];
                continue;
            }

            if (isset($latestEventByAddress[$current])) {
                $byOwner[$userId]['car_ids'][] = $car['car_id'];
                continue;
            }

            if (isset($eventAddressesByCar[$car['car_id']])) {
                // The car has a complaint, but only under an earlier address.
                $dropped[] = ['user_id' => $userId, 'car_id' => $car['car_id']];
                continue;
            }

            if ($this->profileState($userId)['opted_out'] || isset($restored[$userId])) {
                continue; // The owner's own opt-out explains the suppression.
            }

            $byOwner[$userId]['car_ids'][] = $car['car_id'];
            $optOutAt = $latestOptOutByCarOwner[$car['car_id'] . ':' . $userId] ?? null;
            $byOwner[$userId]['unknown_at'] = self::later($byOwner[$userId]['unknown_at'] ?? null, $optOutAt);
        }

        $copies = [];
        foreach ($byOwner as $userId => $found) {
            $current = $snapshot['users'][$userId]['address'];
            $profile = $this->profileState($userId);
            if ($profile['blocked'] && $profile['blocked_address'] === $current) {
                continue; // The profile already holds this block.
            }
            $event = $latestEventByAddress[$current] ?? null;
            $copies[$userId] = [
                'user_id'    => $userId,
                'address'    => $current,
                'cause'      => $event === null ? null : $event['event'],
                'blocked_at' => $event === null ? ($found['unknown_at'] ?? null) : $event['occurred_at'],
                'car_ids'    => $found['car_ids'],
            ];
        }
        ksort($copies);

        return $this->blocks = ['copies' => array_values($copies), 'dropped' => $dropped];
    }

    /**
     * The latest bounce date for an owner's address.
     *
     * A hard-bounce event or an `EMAIL BOUNCED` row of the owner wins. A soft
     * bounce gives the date only when neither exists, because three soft
     * bounces set the flag and the last one is the closest known time.
     *
     * @param int    $userId  The owner
     * @param string $address A normalized address
     * @return string|null The date, or NULL when nothing matches
     * @throws OwnerDatabaseException If a query fails
     */
    private function latestBounceAt(int $userId, string $address): ?string
    {
        if ($address === '') {
            return null;
        }

        $snapshot = $this->snapshot();
        $hard = null;
        $soft = null;
        foreach ($snapshot['events'] as $event) {
            if ($event['address'] !== $address) {
                continue;
            }
            if (in_array($event['event'], self::HARD_BOUNCE_EVENTS, true)) {
                $hard = self::later($hard, $event['occurred_at']);
            } elseif ($event['event'] === self::SOFT_BOUNCE_EVENT) {
                $soft = self::later($soft, $event['occurred_at']);
            }
        }
        foreach ($snapshot['history'] as $row) {
            if ($row['operation'] === self::OPERATION_BOUNCED
                && $row['user_id'] === $userId
                && $row['address'] === $address
            ) {
                $hard = self::later($hard, $row['timestamp']);
            }
        }

        return $hard ?? $soft;
    }

    /**
     * The later of two 'Y-m-d H:i:s' dates. NULL loses.
     */
    private static function later(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    /**
     * Tell whether a user ID is a real person: a `users` row that is not `noowner`.
     *
     * @throws OwnerDatabaseException If a query fails
     */
    private function isPersonOwner(int $userId): bool
    {
        $user = $this->snapshot()['users'][$userId] ?? null;

        return $user !== null && !$user['noowner'];
    }

    /**
     * The users that have a `profiles` row now or after phase 1.
     *
     * @return array<int, true>
     * @throws OwnerDatabaseException If a query fails
     */
    private function willHaveProfile(): array
    {
        $ids = [];
        foreach (array_keys($this->snapshot()['profiles']) as $userId) {
            $ids[$userId] = true;
        }
        foreach ($this->profilesToInsert() as $row) {
            $ids[$row['user_id']] = true;
        }

        return $ids;
    }

    /**
     * The flags of an owner's profile. No row reads as every flag at 0, the
     * state of the row that phase 1 inserts.
     *
     * @return array{opted_out: bool, opted_out_at_null: bool, bounced: bool, bounced_address: string, bounced_at_null: bool, blocked: bool, blocked_address: string}
     * @throws OwnerDatabaseException If a query fails
     */
    private function profileState(int $userId): array
    {
        return $this->snapshot()['profiles'][$userId] ?? [
            'opted_out'         => false,
            'opted_out_at_null' => true,
            'bounced'           => false,
            'bounced_address'   => '',
            'bounced_at_null'   => true,
            'blocked'           => false,
            'blocked_address'   => '',
        ];
    }

    /**
     * Read every row the rules need, once.
     *
     * Each read is a small, bounded table scan: flagged cars, the profile
     * flag columns, the `cars_hist` rows of four operations, and the
     * deliverability events. The `users` rows come by primary key.
     *
     * @return array{
     *     cars: list<array{car_id: int, user_id: int, car_email: string, bounced: bool, bounced_address: string, suppressed: bool}>,
     *     profiles: array<int, array{opted_out: bool, opted_out_at_null: bool, bounced: bool, bounced_address: string, bounced_at_null: bool, blocked: bool, blocked_address: string}>,
     *     users: array<int, array{address: string, noowner: bool}>,
     *     history: list<array{id: int, car_id: int, user_id: int, operation: string, timestamp: string, address: string}>,
     *     events: list<array{car_id: int, address: string, event: string, occurred_at: string}>
     * }
     * @throws OwnerDatabaseException If a query fails, or `profiles` has no opt-out column
     */
    private function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $cars = [];
        if ($this->carFlagColumnsExist()) {
            // A NULL user_id has no owner to copy to, so SQL leaves it out.
            $rows = $this->fetchRows(
                'SELECT id, user_id, email, email_bounced, email_bounced_address, email_suppressed
                   FROM cars
                  WHERE user_id IS NOT NULL
                    AND (email_bounced = 1 OR email_suppressed = 1)
                  ORDER BY id'
            );
            foreach ($rows as $row) {
                $cars[] = [
                    'car_id'          => (int) $row['id'],
                    'user_id'         => (int) $row['user_id'],
                    'car_email'       => self::normalizeAddress(self::stringOrNull($row['email'])),
                    'bounced'         => (int) $row['email_bounced'] === 1,
                    'bounced_address' => self::normalizeAddress(self::stringOrNull($row['email_bounced_address'])),
                    'suppressed'      => (int) $row['email_suppressed'] === 1,
                ];
            }
        }

        $profiles = $this->readProfiles();

        $history = [];
        $operations = array_merge([self::OPERATION_OPT_OUT, self::OPERATION_BOUNCED], self::OPERATIONS_OPT_OUT_CLEAR);
        $rows = $this->fetchRows(
            'SELECT id, car_id, user_id, operation, `timestamp`, email
               FROM cars_hist
              WHERE operation IN (' . self::placeholders(count($operations)) . ')
                AND user_id IS NOT NULL
              ORDER BY `timestamp`, id',
            $operations
        );
        foreach ($rows as $row) {
            $history[] = [
                'id'        => (int) $row['id'],
                'car_id'    => (int) $row['car_id'],
                'user_id'   => (int) $row['user_id'],
                'operation' => (string) $row['operation'],
                'timestamp' => (string) $row['timestamp'],
                'address'   => self::normalizeAddress(self::stringOrNull($row['email'])),
            ];
        }

        $events = [];
        $eventNames = array_merge(self::HARD_BOUNCE_EVENTS, [self::SOFT_BOUNCE_EVENT], self::SUPPRESSION_EVENTS);
        $rows = $this->fetchRows(
            'SELECT car_id, email, event, occurred_at
               FROM er_email_events
              WHERE event IN (' . self::placeholders(count($eventNames)) . ')
              ORDER BY occurred_at, id',
            $eventNames
        );
        foreach ($rows as $row) {
            $events[] = [
                'car_id'      => (int) $row['car_id'],
                'address'     => self::normalizeAddress(self::stringOrNull($row['email'])),
                'event'       => (string) $row['event'],
                'occurred_at' => (string) $row['occurred_at'],
            ];
        }

        $userIds = array_merge(
            array_column($cars, 'user_id'),
            array_column($history, 'user_id')
        );

        $this->snapshot = [
            'cars'     => $cars,
            'profiles' => $profiles,
            'users'    => $this->readUsers($userIds),
            'history'  => $history,
            'events'   => $events,
        ];

        return $this->snapshot;
    }

    /**
     * Read the flag columns of every profile, keyed by user ID.
     *
     * Before phase 1, a column that phase 1 adds reads as its default (0 or
     * NULL). Two rows for one user (only possible before the UNIQUE key)
     * merge: a flag set on either row counts.
     *
     * @return array<int, array{opted_out: bool, opted_out_at_null: bool, bounced: bool, bounced_address: string, bounced_at_null: bool, blocked: bool, blocked_address: string}>
     * @throws OwnerDatabaseException If a query fails, or `profiles` has no opt-out column
     */
    private function readProfiles(): array
    {
        if ($this->columnExists('profiles', 'email_opted_out')) {
            $optedOut = 'email_opted_out';
        } elseif ($this->columnExists('profiles', 'email_suppressed')) {
            $optedOut = 'email_suppressed';
        } else {
            throw new OwnerDatabaseException(
                'DeliverabilityMigrationQueries: profiles has neither email_opted_out nor email_suppressed.'
            );
        }

        $optedOutAt = $this->columnExists('profiles', 'email_opted_out_at') ? 'email_opted_out_at' : 'NULL';
        $bouncedAt = $this->columnExists('profiles', 'email_bounced_at') ? 'email_bounced_at' : 'NULL';
        $hasBlocked = $this->columnExists('profiles', 'email_blocked');
        $blocked = $hasBlocked ? 'email_blocked' : '0';
        $blockedAddress = $hasBlocked ? 'email_blocked_address' : 'NULL';

        // The column names above are fixed literals, never input.
        $rows = $this->fetchRows(
            "SELECT user_id,
                    {$optedOut} AS opted_out,
                    {$optedOutAt} AS opted_out_at,
                    email_bounced,
                    email_bounced_address,
                    {$bouncedAt} AS bounced_at,
                    {$blocked} AS blocked,
                    {$blockedAddress} AS blocked_address
               FROM profiles
              ORDER BY id"
        );

        $profiles = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $state = [
                'opted_out'         => (int) $row['opted_out'] === 1,
                'opted_out_at_null' => $row['opted_out_at'] === null,
                'bounced'           => (int) $row['email_bounced'] === 1,
                'bounced_address'   => self::normalizeAddress(self::stringOrNull($row['email_bounced_address'])),
                'bounced_at_null'   => $row['bounced_at'] === null,
                'blocked'           => (int) $row['blocked'] === 1,
                'blocked_address'   => self::normalizeAddress(self::stringOrNull($row['blocked_address'])),
            ];
            $previous = $profiles[$userId] ?? null;
            $profiles[$userId] = $previous === null ? $state : self::mergeProfiles($previous, $state);
        }

        return $profiles;
    }

    /**
     * Merge two `profiles` rows of one user. A set flag wins, with its address.
     *
     * @param array{opted_out: bool, opted_out_at_null: bool, bounced: bool, bounced_address: string, bounced_at_null: bool, blocked: bool, blocked_address: string} $a
     * @param array{opted_out: bool, opted_out_at_null: bool, bounced: bool, bounced_address: string, bounced_at_null: bool, blocked: bool, blocked_address: string} $b
     * @return array{opted_out: bool, opted_out_at_null: bool, bounced: bool, bounced_address: string, bounced_at_null: bool, blocked: bool, blocked_address: string}
     */
    private static function mergeProfiles(array $a, array $b): array
    {
        return [
            'opted_out'         => $a['opted_out'] || $b['opted_out'],
            'opted_out_at_null' => $a['opted_out_at_null'] && $b['opted_out_at_null'],
            'bounced'           => $a['bounced'] || $b['bounced'],
            'bounced_address'   => $a['bounced'] ? $a['bounced_address'] : $b['bounced_address'],
            'bounced_at_null'   => $a['bounced_at_null'] && $b['bounced_at_null'],
            'blocked'           => $a['blocked'] || $b['blocked'],
            'blocked_address'   => $a['blocked'] ? $a['blocked_address'] : $b['blocked_address'],
        ];
    }

    /**
     * Read the `users` rows of the given IDs, by primary key.
     *
     * @param list<int> $userIds May hold duplicates
     * @return array<int, array{address: string, noowner: bool}> Keyed by user ID
     * @throws OwnerDatabaseException If a query fails
     */
    private function readUsers(array $userIds): array
    {
        $users = [];
        foreach (array_chunk(array_values(array_unique($userIds)), self::ID_CHUNK) as $chunk) {
            $rows = $this->fetchRows(
                'SELECT id, email, username FROM users WHERE id IN (' . self::placeholders(count($chunk)) . ')',
                $chunk
            );
            foreach ($rows as $row) {
                $users[(int) $row['id']] = [
                    'address' => self::normalizeAddress(self::stringOrNull($row['email'])),
                    'noowner' => (string) $row['username'] === self::NOOWNER_USERNAME,
                ];
            }
        }

        return $users;
    }

    /**
     * Tell whether a column exists in the connected schema.
     *
     * @throws OwnerDatabaseException If the query fails
     */
    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->fetchRows(
            'SELECT COUNT(*) AS n
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return (int) ($rows[0]['n'] ?? 0) > 0;
    }

    /**
     * Run one prepared SELECT.
     *
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     * @throws OwnerDatabaseException If the query fails. The message holds no bound value, so no address.
     */
    private function fetchRows(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if ($statement === false || !$statement->execute($params)) {
                // A failed execute() records its error on the statement, not on the connection.
                $errorInfo = $statement === false ? $this->pdo->errorInfo() : $statement->errorInfo();
                throw new OwnerDatabaseException(
                    'DeliverabilityMigrationQueries: query failed: ' . implode(' ', array_map('strval', $errorInfo))
                );
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $rows;
        } catch (PDOException $e) {
            throw new OwnerDatabaseException('DeliverabilityMigrationQueries: query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
