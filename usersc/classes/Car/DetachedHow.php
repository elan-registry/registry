<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * DetachedHow - How a detached photo left `cars.image` (#2644)
 *
 * {@see CarPhotoRecovery} classifies the save that dropped the photo: it
 * compares the `cars_hist` list that last held the photo with the next list.
 * The value is the text that the admin Car Photos tab shows.
 *
 * @package ElanRegistry\Car
 * @since v2.32.0
 * @see https://github.com/elan-registry/registry/issues/2644
 */
enum DetachedHow: string
{
    /** The next list had no photos. */
    case Removed = 'removed';

    /** The next list had photos, but none of the earlier ones. */
    case Replaced = 'replaced';

    /** The next list kept some of the earlier photos, but not this one. */
    case PartlyRemoved = 'partly removed';
}
