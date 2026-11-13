<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarValidationException;

/**
 * CarPhotoRecoveryOutcome - What one {@see CarPhotoRecovery::apply()} call did (#2644)
 *
 * `ignoredNames` holds the ticked names that the call did not attach, because
 * the row does not list them as safe detached photos. `restoreUnavailable` is
 * true when the call was asked to restore the order, but the row has no
 * earlier order.
 *
 * The call sets these two values only after it checks the ticked names
 * against the row as the page saw it. That is, only for `Applied`, or for
 * `NothingToDo` after that check. For all other outcomes they are empty and
 * false:
 *
 * - `Changed` (also after a compare-and-set miss) and `Unreadable`: the page
 *   saw other data, so the names were judged against data that is gone.
 * - `NothingToDo` when the car has no row now (for example, a detached file
 *   was removed from disk after the page loaded): with no row to check
 *   against, a ticked name is not proven false.
 * - `NothingToDo` when no name is ticked and no restore is asked.
 *
 * @package ElanRegistry\Car
 * @since v2.32.0
 * @see https://github.com/elan-registry/registry/issues/2644
 */
final readonly class CarPhotoRecoveryOutcome
{
    /**
     * @param CarPhotoRecoveryResult $result             The result of the call
     * @param list<string>           $ignoredNames       The ticked names that the call did not attach
     * @param bool                   $restoreUnavailable True when a restore was asked and the row has no earlier order
     * @throws CarValidationException If $ignoredNames is not empty or $restoreUnavailable is true
     *                                for the result Changed or Unreadable
     */
    public function __construct(
        public CarPhotoRecoveryResult $result,
        public array $ignoredNames = [],
        public bool $restoreUnavailable = false,
    ) {
        $checked = $result === CarPhotoRecoveryResult::Applied || $result === CarPhotoRecoveryResult::NothingToDo;
        if (!$checked && ($ignoredNames !== [] || $restoreUnavailable)) {
            throw new CarValidationException(
                "Car Photos: the result {$result->name} cannot report ignored names or an unavailable restore"
            );
        }
    }
}
