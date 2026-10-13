<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use Closure;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use InvalidArgumentException;

/**
 * DeliverabilityFlagLog - Audit row for each change to an owner's profile email flag
 *
 * The owner profile is the system of record for email deliverability (#2101).
 * Each change to `email_opted_out`, `email_blocked` or `email_bounced` writes
 * one `logs` row with category `LogCategories::LOG_CATEGORY_EMAIL_BOUNCED`.
 * The `logs.metadata` JSON holds `owner_id` (int), `flag`, `old`, `new` and
 * `source`. `CarRepository::latestFlagClearAt()` and `recentFlagChanges()`
 * read that JSON, so the keys and value types here are a contract with them.
 * The `lognote` repeats the same values as text for the admin log viewer.
 *
 * One exception: `restoreArchivedAccount()` copies the archived opt-out into
 * the new profile. That restore writes no flag-change row.
 *
 * The row never holds an email address. That is why `record()` has no address
 * parameter.
 *
 * @package ElanRegistry\Car
 * @see https://github.com/elan-registry/registry/issues/2101
 */
final class DeliverabilityFlagLog
{
    public const FLAG_OPTED_OUT = 'email_opted_out';
    public const FLAG_BLOCKED = 'email_blocked';
    public const FLAG_BOUNCED = 'email_bounced';

    /** @var list<string> Every flag that `record()` and the log readers accept. */
    public const FLAGS = [
        self::FLAG_OPTED_OUT,
        self::FLAG_BLOCKED,
        self::FLAG_BOUNCED,
    ];

    public const SOURCE_OWNER = 'owner';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_RECONCILIATION = 'reconciliation';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_EMAIL_CHANGE = 'email_change';

    /** @var list<string> Every source that `record()` and the log readers accept. */
    public const SOURCES = [
        self::SOURCE_OWNER,
        self::SOURCE_ADMIN,
        self::SOURCE_WEBHOOK,
        self::SOURCE_RECONCILIATION,
        self::SOURCE_IMPORT,
        self::SOURCE_EMAIL_CHANGE,
    ];

    /** @var Closure(int, string, string, array<string, int|string>): int */
    private Closure $logger;

    /**
     * @param Closure|null $logger Receives (userId, logtype, lognote, metadata)
     *                             and returns the new `logs.id`. A return of 0
     *                             or less means that the insert failed.
     *                             Defaults to the UserSpice `logger()` helper.
     *                             Unit tests pass a recorder so that no database
     *                             is necessary.
     */
    // phpcs:disable PSR2.Methods.MethodDeclaration.MissingReturnType
    public function __construct(?Closure $logger = null)
    {
        // phpcs:enable PSR2.Methods.MethodDeclaration.MissingReturnType
        $this->logger = $logger ?? static function (int $userId, string $logtype, string $lognote, array $metadata): int {
            // logger() returns $db->lastId(). DB::query() sets it only on success, so 0 means a failed insert.
            return (int) logger($userId, $logtype, $lognote, $metadata);
        };
    }

    /**
     * Write one audit row for a change to an owner's profile email flag.
     *
     * Writes nothing when `$old === $new`, so a caller can pass the value that
     * a profile writer returned without a check of its own.
     *
     * @param int    $actingUserId User who made the change. Use 0 for a delivery
     *                             event, a cron job or an import.
     * @param int    $ownerId      `users.id` of the owner whose profile changed.
     * @param string $flag         One of {@see self::FLAGS}.
     * @param int    $old          Flag value before the change (0 or 1).
     * @param int    $new          Flag value after the change (0 or 1).
     * @param string $source       One of {@see self::SOURCES}.
     * @return bool True when a row was written, false when the value did not change.
     * @throws InvalidArgumentException When an argument is not a permitted value.
     * @throws CarDatabaseException     When the `logs` insert fails.
     */
    public function record(int $actingUserId, int $ownerId, string $flag, int $old, int $new, string $source): bool
    {
        if ($actingUserId < 0) {
            throw new InvalidArgumentException("Acting user ID must be 0 or more, got {$actingUserId}");
        }
        if ($ownerId <= 0) {
            throw new InvalidArgumentException("Owner ID must be more than 0, got {$ownerId}");
        }
        self::assertValidFlag($flag);
        self::assertValidSource($source);
        if (!in_array($old, [0, 1], true) || !in_array($new, [0, 1], true)) {
            throw new InvalidArgumentException("Flag values must be 0 or 1, got old={$old} new={$new}");
        }

        if ($old === $new) {
            return false;
        }

        $metadata = [
            'owner_id' => $ownerId,
            'flag' => $flag,
            'old' => $old,
            'new' => $new,
            'source' => $source,
        ];
        $lognote = "Owner {$ownerId} {$flag} changed from {$old} to {$new} (source: {$source})";

        $logId = ($this->logger)($actingUserId, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $lognote, $metadata);
        if ($logId <= 0) {
            throw new CarDatabaseException(
                "Flag-change audit row insert failed: owner={$ownerId} flag={$flag} source={$source}"
            );
        }

        return true;
    }

    /**
     * Check that a flag name is one that the audit row permits.
     *
     * @param string $flag Flag name to check.
     * @return void
     * @throws InvalidArgumentException When `$flag` is not in {@see self::FLAGS}.
     */
    public static function assertValidFlag(string $flag): void
    {
        if (!in_array($flag, self::FLAGS, true)) {
            throw new InvalidArgumentException("Unknown deliverability flag: {$flag}");
        }
    }

    /**
     * Check that a source name is one that the audit row permits.
     *
     * @param string $source Source name to check.
     * @return void
     * @throws InvalidArgumentException When `$source` is not in {@see self::SOURCES}.
     */
    public static function assertValidSource(string $source): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown deliverability flag source: {$source}");
        }
    }
}
