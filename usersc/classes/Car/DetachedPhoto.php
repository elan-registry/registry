<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarValidationException;

/**
 * DetachedPhoto - One detached photo of a {@see CarPhotoRecoveryRow} (#2644)
 *
 * A detached photo is a file in `userimages/{carId}/` that an earlier
 * `cars_hist` list held and the current `cars.image` does not.
 *
 * @package ElanRegistry\Car
 * @since v2.32.0
 * @see https://github.com/elan-registry/registry/issues/2644
 */
final readonly class DetachedPhoto
{
    /**
     * @param string      $name    The file name in the car directory
     * @param bool        $safe    The result of {@see CarImageProcessor::isSafeFilename()}. The apply does not attach a photo whose name is not safe.
     * @param DetachedHow $howLeft How the save that dropped the photo changed the list
     * @param string      $leftAt  The `cars_hist.timestamp` of that save
     * @throws CarValidationException If $name is empty
     */
    public function __construct(
        public string $name,
        public bool $safe,
        public DetachedHow $howLeft,
        public string $leftAt,
    ) {
        if ($name === '') {
            throw new CarValidationException('Car Photos: a detached photo needs a file name');
        }
    }
}
