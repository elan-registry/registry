<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarValidationException;

/**
 * CarPhotoRecoveryRow - One car in the admin Car Photos tab
 *
 * {@see CarPhotoRecovery::findRows()} makes one row for each car that has
 * detached photos ({@see DetachedPhoto}), an order to restore, or both.
 *
 * `earlierOrder` is the reverse of `currentNames` when a `cars_hist` list
 * held that reverse order, else null. `earlierOrderAt` is the time of the
 * latest such list.
 *
 * `unreadable` lists the photo names that the server cannot read, or
 * `{carId}/` when it cannot read the car folder. When it is not empty, the
 * row has no detached photos and no earlier order, so it offers no action.
 *
 * @package ElanRegistry\Car
 * @since v2.32.0
 * @see https://github.com/elan-registry/registry/issues/2644
 */
final readonly class CarPhotoRecoveryRow
{
    /**
     * @param int                 $carId          The car ID
     * @param string|null         $rawImage       The raw `cars.image` value, for the compare-and-set write
     * @param list<string>        $currentNames   The names in `cars.image`, in stored order
     * @param list<DetachedPhoto> $detached       The detached photos, in the order the apply attaches them
     * @param list<string>|null   $earlierOrder   The order to restore, or null
     * @param string|null         $earlierOrderAt The `cars_hist` time of that order, or null
     * @param list<string>        $unreadable     The names that cannot be read, or `{carId}/` for the folder
     * @throws CarValidationException If $carId is not positive, if $unreadable is set with detached
     *                                photos or an earlier order, or if only one of $earlierOrder and
     *                                $earlierOrderAt is set
     */
    public function __construct(
        public int $carId,
        public ?string $rawImage,
        public array $currentNames,
        public array $detached,
        public ?array $earlierOrder,
        public ?string $earlierOrderAt,
        public array $unreadable = [],
    ) {
        if ($carId <= 0) {
            throw new CarValidationException("Car Photos: a row needs a positive car ID, not {$carId}");
        }
        if ($unreadable !== [] && ($detached !== [] || $earlierOrder !== null)) {
            throw new CarValidationException("Car Photos: the unreadable row of car {$carId} cannot offer an action");
        }
        if (($earlierOrder === null) !== ($earlierOrderAt === null)) {
            throw new CarValidationException("Car Photos: the row of car {$carId} needs both the earlier order and its time, or neither");
        }
    }

    /**
     * Get the names of the detached photos that the apply can attach.
     *
     * @return list<string> The safe detached names, in attach order
     */
    public function attachableNames(): array
    {
        $names = [];
        foreach ($this->detached as $photo) {
            if ($photo->safe) {
                $names[] = $photo->name;
            }
        }
        return $names;
    }

    /**
     * Get the names of all detached photos, safe or not.
     *
     * @return list<string> The detached names, in attach order
     */
    public function detachedNames(): array
    {
        return array_map(static fn(DetachedPhoto $photo): string => $photo->name, $this->detached);
    }
}
