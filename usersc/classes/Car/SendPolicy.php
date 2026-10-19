<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTimeImmutable;
use ElanRegistry\AppConstants;

/**
 * SendPolicy - The one table of which owner flags stop which kind of mail
 *
 * Each send path asks this class before it calls `email()`, so the rules
 * live in one place and cannot drift apart between the verification send,
 * owner contact and admin contact:
 *
 * | Flag              | Verification | OwnerContact | AdminContact |
 * |-------------------|--------------|--------------|--------------|
 * | `no_profile`      | blocks       | blocks       | blocks       |
 * | `email_bounced`   | blocks       | blocks       | blocks       |
 * | `email_opted_out` | blocks       | blocks       | blocks       |
 * | `email_blocked`   | blocks       | blocks       | sent         |
 *
 * An admin can still write to an owner whose only flag is `email_blocked`,
 * because an admin block stops registry mail, not a direct message from an
 * admin. A bounce stops all kinds, because the address does not accept mail.
 *
 * The flag reads come from {@see CarRepository::effectiveEmailFlags()}, so
 * a block or bounce applies only while its address matches the owner's
 * current email.
 *
 * @package ElanRegistry\Car
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2360
 */
final class SendPolicy
{
    /**
     * @param CarRepository $repo Reads the owner flags and the address matches
     */
    public function __construct(private CarRepository $repo)
    {
    }

    /**
     * The flags that stop a kind of mail, in {@see SendDecision::FLAG_ORDER}
     *
     * Public so that a test can compare this table with the eligibility SQL
     * and not with a copy of it.
     *
     * @param MailKind $kind The kind of mail
     * @return list<string> Flag names from {@see SendDecision::FLAG_ORDER}
     */
    public function blockingFlags(MailKind $kind): array
    {
        $all = [
            SendDecision::FLAG_NO_PROFILE,
            SendDecision::FLAG_BOUNCED,
            SendDecision::FLAG_OPTED_OUT,
            SendDecision::FLAG_BLOCKED,
        ];

        return match ($kind) {
            MailKind::Verification, MailKind::OwnerContact => $all,
            MailKind::AdminContact => array_values(array_diff($all, [SendDecision::FLAG_BLOCKED])),
        };
    }

    /**
     * Decide whether one owner can get a kind of mail now
     *
     * @param MailKind $kind   The kind of mail
     * @param int      $userId Owner user ID
     * @return SendDecision Holds only the flags that block this kind
     * @throws \ElanRegistry\Exceptions\CarDatabaseException If the flag read fails
     */
    public function forUser(MailKind $kind, int $userId): SendDecision
    {
        $emailFlags = $this->repo->effectiveEmailFlags($userId);

        $present = [];
        if (!$emailFlags->hasProfile) {
            $present[] = SendDecision::FLAG_NO_PROFILE;
        }
        if ($emailFlags->bounced) {
            $present[] = SendDecision::FLAG_BOUNCED;
        }
        if ($emailFlags->optedOut) {
            $present[] = SendDecision::FLAG_OPTED_OUT;
        }
        if ($emailFlags->blocked) {
            $present[] = SendDecision::FLAG_BLOCKED;
        }

        return new SendDecision(
            array_values(array_intersect($present, $this->blockingFlags($kind))),
            $emailFlags->bouncedAt
        );
    }

    /**
     * Decide whether mail to an address can go now
     *
     * For an admin message that names an address that more than one user
     * holds. The mail reaches every user with that address, so one blocking
     * flag on any of them stops it. No user with the address also stops it
     * (`no_match`), because nobody can be checked.
     *
     * @param MailKind $kind    The kind of mail
     * @param string   $address The address the mail goes to
     * @return SendDecision Merged flags of all matching users, and the latest bounce date
     * @throws \ElanRegistry\Exceptions\CarDatabaseException If a read fails
     */
    public function forAddress(MailKind $kind, string $address): SendDecision
    {
        $userIds = $this->repo->findUserIdsByAddress($address);
        if ($userIds === []) {
            return new SendDecision([SendDecision::FLAG_NO_MATCH], null, 0);
        }

        $flags     = [];
        $bouncedAt = null;
        foreach ($userIds as $userId) {
            $decision = $this->forUser($kind, $userId);
            $flags    = array_merge($flags, $decision->flags);
            // DATETIME strings in one format sort as text, so a string compare finds the latest.
            if ($decision->bouncedAt !== null && ($bouncedAt === null || $decision->bouncedAt > $bouncedAt)) {
                $bouncedAt = $decision->bouncedAt;
            }
        }

        return new SendDecision($flags, $bouncedAt, count($userIds));
    }

    /**
     * The reason a verification send is refused, for the admin report
     *
     * The strings and their priority must stay stable: the admin batch
     * report and the log lines show them.
     *
     * @param SendDecision $decision Result of {@see self::forUser()}
     * @return string|null The first reason, or null when the send is allowed
     */
    public function verificationReason(SendDecision $decision): ?string
    {
        return match (true) {
            $decision->allowed                               => null,
            $decision->has(SendDecision::FLAG_NO_PROFILE)    => 'Owner has no profile.',
            $decision->has(SendDecision::FLAG_OPTED_OUT)     => 'Owner has opted out of verification emails.',
            $decision->has(SendDecision::FLAG_BLOCKED)       => 'Owner email is blocked.',
            $decision->has(SendDecision::FLAG_BOUNCED)       => 'Owner email bounced.',
            // Only no_match is left, which forUser() never sets. Refuse with a reason.
            default                                          => 'No owner has this address now.',
        };
    }

    /**
     * The message that tells an admin why a contact message was not sent
     *
     * The text is for `usError()`. The bounce date is validated as `Y-m-d`
     * before it goes into the text.
     *
     * Use this method for AdminContact decisions. For AdminContact,
     * `email_blocked` never blocks, so it has no sentence. A decision whose
     * only flag is `email_blocked` gets only "Message not sent.".
     *
     * @param SendDecision $decision Result of {@see self::forUser()} or {@see self::forAddress()}
     * @return string "Message not sent." and one sentence for each reason.
     *                An empty string when the send is allowed.
     */
    public function adminMessage(SendDecision $decision): string
    {
        if ($decision->allowed) {
            return '';
        }

        $sentences = ['Message not sent.'];
        if ($decision->has(SendDecision::FLAG_NO_MATCH)) {
            $sentences[] = 'No owner has this address now.';
        }
        if ($decision->has(SendDecision::FLAG_NO_PROFILE)) {
            $sentences[] = 'This owner has no profile.';
        }
        if ($decision->has(SendDecision::FLAG_BOUNCED)) {
            $bounceDate  = self::toDate($decision->bouncedAt);
            $sentences[] = $bounceDate === null
                ? "This owner's address is invalid."
                : "This owner's address is invalid. It bounced on "
                    . htmlspecialchars($bounceDate, ENT_QUOTES, 'UTF-8') . '.';
        }
        if ($decision->has(SendDecision::FLAG_OPTED_OUT)) {
            $sentences[] = 'The owner opted out.';
        }

        return implode(' ', $sentences);
    }

    /**
     * Format a DATETIME column value as `Y-m-d`
     *
     * The same rule as {@see EmailNoticeBuilder}: null for null, malformed
     * or zero-date values.
     */
    private static function toDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . AppConstants::DATETIME_FORMAT, $value);
        if ($parsed === false || $parsed->format(AppConstants::DATETIME_FORMAT) !== $value) {
            return null;
        }

        return $parsed->format('Y-m-d');
    }
}
