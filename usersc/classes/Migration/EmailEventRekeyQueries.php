<?php

declare(strict_types=1);

namespace ElanRegistry\Migration;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use PDO;
use PDOException;

/**
 * EmailEventRekeyQueries - The reads that decide what the #2358 migration writes
 *
 * Migration 20261013000000_key_er_email_events_by_user keys `er_email_events`
 * by user. The migration and the read-only report script
 * `29-Report-Email-Event-Rekey.php` both call this class, so the report shows
 * exactly what the migration does. The class only reads.
 *
 * The rules, steps (b) to (d) of the issue:
 *
 * - (b) A row goes to the owner of its car when the owner's `users.email`
 *   matches the row's `email`. Else it goes to the one user whose
 *   `users.email` matches. Else it gets no user.
 * - (c) A row with no user, or whose user is `noowner`, is dropped.
 * - (d) Rows with the same user, `brevo_message_id` and `event` collapse into
 *   one. The lowest `id` stays, with the earliest `occurred_at` of the group,
 *   so a `delivered` reset point does not move later.
 *
 * The three outcomes of (b) and (c) do not overlap: a row is matched by car
 * owner, matched by address, or dropped. A `noowner` match counts as dropped.
 *
 * Address matching: every address goes through {@see self::normalizeAddress()},
 * and the compare is byte for byte in PHP. SQL does no address compare,
 * because `er_email_events.email` uses `utf8mb4_unicode_ci`, which ignores
 * case and accents.
 *
 * The matching rule and the `noowner` name are frozen copies, not references
 * to `EmailAddress` or another runtime class. A migration must give the same
 * result when it runs again later, after those classes change.
 *
 * The first call reads the database once and keeps the result. Make a new
 * instance after a write to read the new state.
 *
 * @package ElanRegistry\Migration
 * @see https://github.com/elan-registry/registry/issues/2358
 */
final class EmailEventRekeyQueries
{
    /** The GDPR reassignment account. Its rows are dropped. */
    public const NOOWNER_USERNAME = 'noowner';

    /** A row matched by the owner of its car. */
    public const MATCHED_BY_OWNER = 'owner';

    /** A row matched by the one user with the same address. */
    public const MATCHED_BY_ADDRESS = 'address';

    /**
     * Each event row that keeps a user, keyed by event ID.
     *
     * @var array<int, array{user_id: int, email: string, matched_by: string}>|null
     */
    private ?array $assignments = null;

    /** @var list<int>|null */
    private ?array $droppedIds = null;

    /** @var list<array{kept_id: int, removed_ids: list<int>, occurred_at: string}>|null */
    private ?array $collapseGroups = null;

    /**
     * @param PDO|DatabaseInterface $connection A connection to the schema to read.
     *        The migration gives its Phinx PDO. The report script gives dbi().
     *        The class only reads.
     */
    public function __construct(private readonly PDO|DatabaseInterface $connection)
    {
    }

    /**
     * The matching rule for every address compare in #2358.
     *
     * Both sides of a compare go through this function, then the compare is
     * byte for byte. NULL becomes '', and '' matches no address.
     *
     * @param string|null $address A raw address from `users` or an event row
     * @return string The trimmed, lower-case address
     */
    public static function normalizeAddress(?string $address): string
    {
        return strtolower(trim((string) $address));
    }

    /**
     * Tell whether the table has the keyed-by-user schema of step (e).
     *
     * That is: `user_id` exists and is NOT NULL, and `car_id` allows NULL.
     * The migration runs its data phase only while this is false. The report
     * prints no counts while it is true.
     *
     * @return bool True when step (e) is done
     * @throws OwnerDatabaseException If the schema read fails
     */
    public function isRekeyed(): bool
    {
        $rows = $this->fetchRows(
            "SELECT COLUMN_NAME, IS_NULLABLE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'er_email_events'
                AND COLUMN_NAME IN ('user_id', 'car_id')"
        );

        $nullable = [];
        foreach ($rows as $row) {
            $nullable[(string) $row['COLUMN_NAME']] = (string) $row['IS_NULLABLE'] === 'YES';
        }

        return isset($nullable['user_id'], $nullable['car_id'])
            && !$nullable['user_id']
            && $nullable['car_id'];
    }

    /**
     * Step (b): each event row that keeps a user.
     *
     * Rows that step (c) drops are not here, also a row that matched `noowner`.
     *
     * @return array<int, array{user_id: int, email: string, matched_by: string}>
     *         Keyed by event ID, in ascending order. `email` is the normalized
     *         address that the migration writes. Never print it. `matched_by`
     *         is {@see self::MATCHED_BY_OWNER} or {@see self::MATCHED_BY_ADDRESS}.
     * @throws OwnerDatabaseException If a query fails
     */
    public function assignments(): array
    {
        $this->analyze();

        /** @var array<int, array{user_id: int, email: string, matched_by: string}> */
        return $this->assignments;
    }

    /**
     * Step (b): the event IDs that go to the owner of their car.
     *
     * @return list<int> In ascending order
     * @throws OwnerDatabaseException If a query fails
     */
    public function ownerMatchedIds(): array
    {
        return $this->idsMatchedBy(self::MATCHED_BY_OWNER);
    }

    /**
     * Step (b): the event IDs that go to the one user with the same address.
     *
     * @return list<int> In ascending order
     * @throws OwnerDatabaseException If a query fails
     */
    public function addressMatchedIds(): array
    {
        return $this->idsMatchedBy(self::MATCHED_BY_ADDRESS);
    }

    /**
     * Step (c): the event IDs with no user, or whose user is `noowner`.
     *
     * @return list<int> In ascending order
     * @throws OwnerDatabaseException If a query fails
     */
    public function droppedIds(): array
    {
        $this->analyze();

        /** @var list<int> */
        return $this->droppedIds;
    }

    /**
     * Step (d): the groups of rows that collapse into one row.
     *
     * Only groups with two or more rows are listed. The group key is the user,
     * `brevo_message_id` and `event`. Both strings compare with ASCII case
     * folded and trailing spaces removed, because the new UNIQUE key compares
     * them with `utf8mb4_unicode_ci`. A byte-exact key could leave two rows
     * that the key calls equal, and the key would then fail to build.
     *
     * @return list<array{kept_id: int, removed_ids: list<int>, occurred_at: string}>
     *         In ascending kept ID order. `kept_id` is the lowest ID of the
     *         group, `removed_ids` the others in ascending order, and
     *         `occurred_at` the earliest `occurred_at` of the group.
     * @throws OwnerDatabaseException If a query fails
     */
    public function collapseGroups(): array
    {
        $this->analyze();

        /** @var list<array{kept_id: int, removed_ids: list<int>, occurred_at: string}> */
        return $this->collapseGroups;
    }

    /**
     * The counts that the report script prints, one for each outcome.
     *
     * @return array{owner_matched: int, address_matched: int, dropped: int, collapsed_removed: int, collapse_groups: int}
     *         `collapsed_removed` is the number of rows that step (d) deletes.
     *         `collapse_groups` is the number of groups they come from.
     * @throws OwnerDatabaseException If a query fails
     */
    public function reportCounts(): array
    {
        $groups = $this->collapseGroups();
        $removed = 0;
        foreach ($groups as $group) {
            $removed += count($group['removed_ids']);
        }

        return [
            'owner_matched'     => count($this->ownerMatchedIds()),
            'address_matched'   => count($this->addressMatchedIds()),
            'dropped'           => count($this->droppedIds()),
            'collapsed_removed' => $removed,
            'collapse_groups'   => count($groups),
        ];
    }

    /**
     * @return list<int>
     * @throws OwnerDatabaseException If a query fails
     */
    private function idsMatchedBy(string $matchedBy): array
    {
        $ids = [];
        foreach ($this->assignments() as $id => $assignment) {
            if ($assignment['matched_by'] === $matchedBy) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Read the rows once and compute steps (b) to (d).
     *
     * @throws OwnerDatabaseException If a query fails
     */
    private function analyze(): void
    {
        if ($this->assignments !== null) {
            return;
        }

        $users = $this->readUsers();
        $carOwners = $this->readCarOwners();

        // Address => the IDs of the users that hold it, to find a single match.
        $usersByAddress = [];
        foreach ($users as $userId => $user) {
            if ($user['address'] !== '') {
                $usersByAddress[$user['address']][] = $userId;
            }
        }

        $assignments = [];
        $dropped = [];
        $groups = [];
        foreach ($this->readEvents() as $event) {
            $address = $event['address'];
            $userId = null;
            $matchedBy = null;

            $ownerId = $event['car_id'] === null ? null : ($carOwners[$event['car_id']] ?? null);
            if ($address !== '' && $ownerId !== null && ($users[$ownerId]['address'] ?? '') === $address) {
                $userId = $ownerId;
                $matchedBy = self::MATCHED_BY_OWNER;
            } elseif ($address !== '' && count($usersByAddress[$address] ?? []) === 1) {
                $userId = $usersByAddress[$address][0];
                $matchedBy = self::MATCHED_BY_ADDRESS;
            }

            if ($userId === null || $matchedBy === null || $users[$userId]['noowner']) {
                $dropped[] = $event['id'];
                continue;
            }

            $assignments[$event['id']] = ['user_id' => $userId, 'email' => $address, 'matched_by' => $matchedBy];

            $key = $userId . "\0" . self::keyPart($event['brevo_message_id']) . "\0" . self::keyPart($event['event']);
            $groups[$key][] = ['id' => $event['id'], 'occurred_at' => $event['occurred_at']];
        }

        $collapse = [];
        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            // Events come in ascending ID order, so the first row has the lowest ID.
            $earliest = $rows[0]['occurred_at'];
            foreach ($rows as $row) {
                if (strcmp($row['occurred_at'], $earliest) < 0) {
                    $earliest = $row['occurred_at'];
                }
            }
            $collapse[] = [
                'kept_id'     => $rows[0]['id'],
                'removed_ids' => array_column(array_slice($rows, 1), 'id'),
                'occurred_at' => $earliest,
            ];
        }
        usort($collapse, static fn (array $a, array $b): int => $a['kept_id'] <=> $b['kept_id']);

        $this->assignments = $assignments;
        $this->droppedIds = $dropped;
        $this->collapseGroups = $collapse;
    }

    /**
     * One part of the collapse key: ASCII case folded, trailing spaces removed.
     *
     * This copies what `utf8mb4_unicode_ci` (PAD SPACE) calls equal for the
     * ASCII values that Brevo message IDs and event names use.
     */
    private static function keyPart(string $value): string
    {
        return strtolower(rtrim($value, ' '));
    }

    /**
     * Every event row, in ascending ID order.
     *
     * @return list<array{id: int, car_id: ?int, address: string, event: string, brevo_message_id: string, occurred_at: string}>
     * @throws OwnerDatabaseException If the query fails
     */
    private function readEvents(): array
    {
        $rows = $this->fetchRows(
            'SELECT id, car_id, email, event, brevo_message_id, occurred_at
               FROM er_email_events
              ORDER BY id'
        );

        return array_map(
            static fn (array $row): array => [
                'id'               => (int) $row['id'],
                'car_id'           => $row['car_id'] === null ? null : (int) $row['car_id'],
                'address'          => self::normalizeAddress($row['email'] === null ? null : (string) $row['email']),
                'event'            => (string) $row['event'],
                'brevo_message_id' => (string) $row['brevo_message_id'],
                'occurred_at'      => (string) $row['occurred_at'],
            ],
            $rows
        );
    }

    /**
     * The owner of each car that has one.
     *
     * @return array<int, int> User ID keyed by car ID
     * @throws OwnerDatabaseException If the query fails
     */
    private function readCarOwners(): array
    {
        $rows = $this->fetchRows('SELECT id, user_id FROM cars WHERE user_id IS NOT NULL');
        $owners = [];
        foreach ($rows as $row) {
            $owners[(int) $row['id']] = (int) $row['user_id'];
        }

        return $owners;
    }

    /**
     * Every user, with the normalized address and the `noowner` mark.
     *
     * @return array<int, array{address: string, noowner: bool}> Keyed by user ID
     * @throws OwnerDatabaseException If the query fails
     */
    private function readUsers(): array
    {
        $rows = $this->fetchRows('SELECT id, email, username FROM users');
        $users = [];
        foreach ($rows as $row) {
            $users[(int) $row['id']] = [
                'address' => self::normalizeAddress($row['email'] === null ? null : (string) $row['email']),
                'noowner' => (string) $row['username'] === self::NOOWNER_USERNAME,
            ];
        }

        return $users;
    }

    /**
     * Run one SELECT that has no bound values.
     *
     * @return list<array<string, mixed>>
     * @throws OwnerDatabaseException If the query fails, on either connection
     *                                type. A PDOException is wrapped as the
     *                                previous exception. The message holds no
     *                                bound value, so no address.
     */
    private function fetchRows(string $sql): array
    {
        try {
            if ($this->connection instanceof DatabaseInterface) {
                $db = $this->connection->query($sql);
                if ($db->error()) {
                    throw new OwnerDatabaseException('EmailEventRekeyQueries: query failed: ' . $db->errorString());
                }

                return array_values($db->results(true));
            }

            $statement = $this->connection->prepare($sql);
            if ($statement === false || !$statement->execute()) {
                // A failed execute() records its error on the statement, not on the connection.
                $errorInfo = $statement === false ? $this->connection->errorInfo() : $statement->errorInfo();
                throw new OwnerDatabaseException(
                    'EmailEventRekeyQueries: query failed: ' . implode(' ', array_map('strval', $errorInfo))
                );
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $rows;
        } catch (PDOException $e) {
            throw new OwnerDatabaseException('EmailEventRekeyQueries: query failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
