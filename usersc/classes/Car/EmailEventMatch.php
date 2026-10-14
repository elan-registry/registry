<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use InvalidArgumentException;
use LogicException;

/**
 * EmailEventMatch - The result of matching one Brevo delivery event to a user
 *
 * {@see EmailEventMatcher} returns one of three results:
 *
 * - {@see self::notOurs()}: this environment did not send the message. The
 *   caller writes no row and sets no flag.
 * - {@see self::noUser()}: the message is ours, but no single user holds the
 *   event address. The caller writes no row and sets no flag.
 * - {@see self::matched()}: the event belongs to one user. `carId` is the car
 *   of the verification `sent` row, or null when the plugin row gave the
 *   match. `messageTime` is when the message left this site. The date guards
 *   of {@see EmailEventApplier::apply()} compare against it.
 *
 * @package ElanRegistry\Car
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2359
 */
final class EmailEventMatch
{
    private const KIND_NOT_OURS = 'not_ours';
    private const KIND_NO_USER = 'no_user';
    private const KIND_MATCHED = 'matched';

    private function __construct(
        private readonly string $kind,
        private readonly ?int $userId = null,
        private readonly ?int $carId = null,
        private readonly ?string $messageTime = null,
    ) {
    }

    /**
     * Make the result for a message that this environment did not send
     *
     * @return self
     */
    public static function notOurs(): self
    {
        return new self(self::KIND_NOT_OURS);
    }

    /**
     * Make the result for our message when no single user holds the event address
     *
     * @return self
     */
    public static function noUser(): self
    {
        return new self(self::KIND_NO_USER);
    }

    /**
     * Make the result for an event that belongs to one user
     *
     * @param int $userId `users.id` of the user (more than 0)
     * @param int|null $carId Car of the verification `sent` row (more than 0), or null
     * @param string $messageTime When the message left this site, in AppConstants::DATETIME_FORMAT
     * @return self
     * @throws InvalidArgumentException If an ID is not more than 0 or the time is empty
     */
    public static function matched(int $userId, ?int $carId, string $messageTime): self
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException("EmailEventMatch: the user ID must be more than 0, got {$userId}");
        }
        if ($carId !== null && $carId <= 0) {
            throw new InvalidArgumentException("EmailEventMatch: the car ID must be more than 0 or null, got {$carId}");
        }
        if (trim($messageTime) === '') {
            throw new InvalidArgumentException('EmailEventMatch: the message time must not be empty');
        }

        return new self(self::KIND_MATCHED, $userId, $carId, $messageTime);
    }

    /**
     * Tell whether this environment did not send the message
     *
     * @return bool
     */
    public function isNotOurs(): bool
    {
        return $this->kind === self::KIND_NOT_OURS;
    }

    /**
     * Tell whether the message is ours but no single user holds the address
     *
     * @return bool
     */
    public function isNoUser(): bool
    {
        return $this->kind === self::KIND_NO_USER;
    }

    /**
     * Tell whether the event belongs to one user
     *
     * @return bool
     */
    public function isMatched(): bool
    {
        return $this->kind === self::KIND_MATCHED;
    }

    /**
     * Get the matched user's ID
     *
     * @return int
     * @throws LogicException If the result is not {@see self::matched()}
     */
    public function userId(): int
    {
        return $this->requireMatched()->userId ?? 0;
    }

    /**
     * Get the car of the verification `sent` row, or null
     *
     * @return int|null
     * @throws LogicException If the result is not {@see self::matched()}
     */
    public function carId(): ?int
    {
        return $this->requireMatched()->carId;
    }

    /**
     * Get when the message left this site
     *
     * @return string Datetime in AppConstants::DATETIME_FORMAT
     * @throws LogicException If the result is not {@see self::matched()}
     */
    public function messageTime(): string
    {
        return $this->requireMatched()->messageTime ?? '';
    }

    /**
     * @throws LogicException If the result is not {@see self::matched()}
     */
    private function requireMatched(): self
    {
        if ($this->kind !== self::KIND_MATCHED) {
            throw new LogicException("EmailEventMatch: a {$this->kind} result has no user, car or message time");
        }

        return $this;
    }
}
