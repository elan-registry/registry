<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarDatabaseException;

/**
 * EmailEventMatcher - Find the user that one Brevo delivery event belongs to
 *
 * Test and production share one Brevo account, so an event can be for mail
 * that the other environment sent. An event is "ours" only when its message
 * ID is in this environment's `er_email_events` `sent` rows or in the
 * sendinblue plugin's `plg_sendinblue_messages` table. An address match
 * alone never makes an event ours.
 *
 * MATCHING RULE: both addresses go through {@see EmailAddress::normalize()},
 * then PHP compares the bytes. SQL only selects candidates, because the
 * column collations ignore accents and trailing spaces.
 *
 * The matcher never reads `cars.user_id` at event time, and never selects a
 * plugin row by address alone. It writes nothing.
 * {@see EmailEventApplier::apply()} applies the result.
 *
 * Not `final`: it is an injected collaborator of the webhook processor and
 * the two Brevo cron jobs, whose unit tests substitute it.
 *
 * @package ElanRegistry\Car
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2359
 */
class EmailEventMatcher
{
    /**
     * Longest address that the registry accepts, in bytes
     *
     * The limit is the width of the `varchar(155)` email columns (`users.email`
     * and `er_email_events.email`), so a longer address cannot be stored.
     */
    public const MAX_ADDRESS_BYTES = 155;

    /** Null until the first lookup that needs the plugin table. */
    private ?bool $pluginTableExists = null;

    public function __construct(private CarRepository $repo)
    {
    }

    /**
     * Match a delivery event to a user by its message ID
     *
     * First, the "not ours" check: when no `sent` row and no plugin row has
     * the ID, the result is {@see EmailEventMatch::notOurs()}. One plugin-row
     * lookup ({@see CarRepository::findSendinblueMessageById()}) serves both
     * this check and step (b). Then the steps of the matching rule
     * (EMAIL_SYSTEM.md, "The Matching Rule"):
     *
     * - (a) Exactly one `sent` row with the ID has the event address.
     *   The result has that row's user and car, and the row's
     *   `occurred_at` as the message time. More than one such row (two
     *   users) gives {@see EmailEventMatch::noUser()}.
     * - (b) The plugin row with the ID has the event address as its
     *   one whole `recipient_email` (a row with more than one recipient
     *   matches nothing), and exactly one user holds that address. The
     *   result has no car, and `accepted_at` as the message time.
     * - (c) None of the above: {@see EmailEventMatch::noUser()}.
     *
     * @param string $messageId Message ID from the event
     * @param string $address Recipient address from the event
     * @return EmailEventMatch
     * @throws CarDatabaseException If a query fails
     * @throws \InvalidArgumentException If the matched row has an empty time,
     *                                   or a user or car ID that is not more than 0
     */
    public function matchByMessageId(string $messageId, string $address): EmailEventMatch
    {
        $sentRows = $this->repo->findSentRowsByMessageId($messageId);
        $pluginRow = $this->pluginTableExists() ? $this->repo->findSendinblueMessageById($messageId) : null;
        if ($sentRows === [] && $pluginRow === null) {
            return EmailEventMatch::notOurs();
        }

        $normalized = EmailAddress::normalize($address);
        if ($normalized === '') {
            return EmailEventMatch::noUser();
        }

        $sameAddress = array_values(array_filter(
            $sentRows,
            static fn (object $row): bool => EmailAddress::normalize((string) $row->email) === $normalized
        ));
        if (count($sameAddress) > 1) {
            return EmailEventMatch::noUser();
        }
        if (count($sameAddress) === 1) {
            $row = $sameAddress[0];
            $carId = $row->car_id === null ? null : (int) $row->car_id;

            return EmailEventMatch::matched((int) $row->user_id, $carId, (string) $row->occurred_at);
        }

        if ($pluginRow !== null && EmailAddress::normalize((string) $pluginRow->recipient_email) === $normalized) {
            $userId = $this->matchUserByAddress($normalized);
            if ($userId !== null) {
                return EmailEventMatch::matched($userId, null, (string) $pluginRow->accepted_at);
            }
        }

        return EmailEventMatch::noUser();
    }

    /**
     * Tell whether an address is storable and well formed
     *
     * Callers check this before any match. The caller passes the address in
     * the form that it wants checked (trimmed or normalized).
     *
     * @param string $address Address to check
     */
    public static function isAcceptableAddress(string $address): bool
    {
        return strlen($address) <= self::MAX_ADDRESS_BYTES
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Name an address in a log line without the address itself
     *
     * @param string $address Address, normalized or not
     * @return string The first 8 hex characters of the sha256 of the normalized address
     */
    public static function addressLogHash(string $address): string
    {
        return substr(hash('sha256', EmailAddress::normalize($address)), 0, 8);
    }

    /**
     * Find the one user that holds an address, for the suppression import
     *
     * @param string $address Blocklisted address from Brevo
     * @return int|null The user ID when exactly one user holds the address
     *                  under the matching rule, else null
     * @throws CarDatabaseException If the query fails
     */
    public function matchUserByAddress(string $address): ?int
    {
        $userIds = $this->repo->findUserIdsByAddress($address);

        return count($userIds) === 1 ? $userIds[0] : null;
    }

    /**
     * Tell whether the plugin's message table exists, once per instance
     *
     * @throws CarDatabaseException If the query fails
     */
    private function pluginTableExists(): bool
    {
        // A site without the plugin has no table, and the lookup would then
        // fail on every event. The webhook cannot get here without the
        // plugin, because brevoNotReadyReason() returns 200 first. The
        // reconciliation job does not check it, so without this check the
        // job would skip every event.
        return $this->pluginTableExists ??= $this->repo->sendinblueMessagesTableExists();
    }
}
