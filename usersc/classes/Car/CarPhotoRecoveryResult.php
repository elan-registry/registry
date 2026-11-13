<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * CarPhotoRecoveryResult - What one {@see CarPhotoRecovery::apply()},
 * {@see CarPhotoRecovery::ignore()} or {@see CarPhotoRecovery::undoIgnore()}
 * call did
 *
 * @package ElanRegistry\Car
 * @since v2.32.0
 * @see https://github.com/elan-registry/registry/issues/2644
 */
enum CarPhotoRecoveryResult: string
{
    /** apply() wrote `cars.image` and one log row. ignore() or undoIgnore() wrote one log row. */
    case Applied = 'applied';

    /** `cars.image` changed since the page loaded, or during the call. The call wrote nothing. */
    case Changed = 'changed';

    /** The selected actions change nothing, or the car has no row. The call wrote nothing. */
    case NothingToDo = 'nothing_to_do';

    /** The car folder or a photo file cannot be read. The call wrote nothing. */
    case Unreadable = 'unreadable';
}
