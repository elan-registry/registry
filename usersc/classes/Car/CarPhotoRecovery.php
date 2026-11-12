<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\LogCategories;

/**
 * CarPhotoRecovery - Find and reattach detached car photos (#2644)
 *
 * A detached photo is a file in `userimages/{carId}/` that an earlier
 * `cars_hist` list held and the current `cars.image` does not. A file that no
 * `cars_hist` list held is an orphan (#2643). This class does not show it.
 *
 * History semantics: the `cars_update` and `cars_delete` triggers write the
 * OLD row, and `cars_insert` writes the NEW row. So each `cars_hist` row is a
 * state the car had, and the row of an update holds the list from before that
 * save, with the time of that save. The rows are read in `id` order, because
 * `id` is the insert order and the stable tie-break for rows written in the
 * same second. A row with a NULL `image` is not a state. A save made with
 * `@disable_triggers` set leaves no history row, so `leftAt` can be earlier
 * than the real drop.
 *
 * The admin tab calls findRows() on a GET, and apply(), ignore() or
 * undoIgnore() on a POST. apply() finds the row again on the server and
 * attaches a ticked name only when the row lists it as a safe detached photo,
 * so a form cannot add a file name. The tab sends the versionToken() of each
 * row's `cars.image`, and the three writes do nothing when the value changed
 * since the page loaded.
 *
 * Ignore marks a row as reviewed with no change. It writes one `logs` row
 * (CarActions, note prefix `Car Photos:`) with the token and the detached
 * names. isIgnored() is true while the newest decision for the car is that
 * ignore, the row still has the same token and detached names, and the row
 * is readable. No table holds it: when an admin clears the logs, the rows
 * show again.
 *
 * An order restore makes the `cars_update` trigger write the reversed list
 * to `cars_hist`, so the restored list is again the reverse of a history
 * list. The apply log row holds the token of the list it wrote
 * (`token_after`). While `cars.image` has that token, findRows() offers no
 * restore for the car. A later save changes the token, and the check runs
 * again.
 *
 * A car folder or photo file that the server cannot read does not stop the
 * scan. That car gets a row with `unreadable` set and no actions, and apply()
 * does not write it. A database failure still throws.
 *
 * @package ElanRegistry\Car
 * @since v2.32.0
 * @see https://github.com/elan-registry/registry/issues/2644
 */
final class CarPhotoRecovery
{
    /** Extensions a photo file can have, lower case. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

    /** The image types that exif_imagetype() must report for a photo. */
    private const IMAGE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF];

    /** Size of the resized copy that the tab shows as a thumbnail. */
    private const THUMBNAIL_SIZE = 100;

    /** The `action` metadata value of an Ignore log row. */
    private const ACTION_IGNORE = 'car_photos_ignore';

    /** The `action` metadata value of an Undo ignore log row. */
    private const ACTION_UNIGNORE = 'car_photos_unignore';

    /** The `action` metadata value of an apply log row. */
    private const ACTION_APPLY = 'car_photos_apply';

    /** The `logs.lognote` prefix of every row that this class writes, and of the decisions that it reads. */
    private const NOTE_PREFIX = 'Car Photos:';

    private readonly string $imageRoot;

    private readonly CarRepository $repository;

    /**
     * The Car Photos decisions read from `logs`, or null before the first
     * read and after a write. `ignore` holds the newest Ignore or Undo ignore
     * decision by car ID. `restored` holds, by car ID, the versionToken() of
     * the list that the newest order restore wrote.
     *
     * @var array{ignore: array<int, array{action: string, token: string, detached: list<string>, userId: int, at: string}>, restored: array<int, string>}|null
     */
    private ?array $decisionLog = null;

    /**
     * @param DatabaseInterface  $db         Database connection
     * @param string|null        $imageRoot  Absolute path of `userimages/`. Null uses the application's real directory.
     * @param CarRepository|null $repository Repository for the compare-and-set write. Null makes one on $db.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        ?string $imageRoot = null,
        ?CarRepository $repository = null,
    ) {
        if ($imageRoot === null) {
            global $abs_us_root, $us_url_root;
            $imageRoot = $abs_us_root . $us_url_root . ELAN_IMAGE_DIR;
        }
        $this->imageRoot  = rtrim($imageRoot, '/') . '/';
        $this->repository = $repository ?? new CarRepository($db);
    }

    /**
     * Find the cars that have detached photos or an order to restore.
     *
     * @param list<int>|null $carIds The cars to check, or null for every car with history. An empty list returns no rows.
     * @return list<CarPhotoRecoveryRow> One row for each car with something to show. The rows with an
     *         earlier order come first, then the other rows, each group in car ID order.
     *         A car whose folder or a photo file cannot be read has a row with `unreadable` set.
     * @throws ImageProcessingException If the image root is not a readable directory
     * @throws CarValidationException   If $carIds holds a value that is not a positive int
     * @throws CarDatabaseException     If a query fails or returns a row with an unexpected shape
     */
    public function findRows(?array $carIds = null): array
    {
        // A wrong root would show every car as having no photo folder, and
        // the tab would say that no car has detached photos.
        if (!is_dir($this->imageRoot) || !is_readable($this->imageRoot)) {
            throw new ImageProcessingException("Car Photos: the image root {$this->imageRoot} is not a readable directory");
        }
        if ($carIds !== null) {
            foreach ($carIds as $carId) {
                if (!is_int($carId) || $carId <= 0) {
                    throw new CarValidationException('Car Photos: each car ID must be a positive int');
                }
            }
            if ($carIds === []) {
                return [];
            }
        }

        $history  = $this->loadHistory($carIds);
        $restored = $this->decisionLog()['restored'];
        $rows     = [];
        foreach ($this->loadCurrent($carIds) as $carId => $rawImage) {
            $row = $this->buildRow($carId, $rawImage, $history[$carId] ?? [], $restored[$carId] ?? null);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        // The rows with an order to restore come first: the admin checks
        // those thumbnails side by side.
        usort($rows, static fn(CarPhotoRecoveryRow $a, CarPhotoRecoveryRow $b): int =>
            [$a->earlierOrder === null, $a->carId] <=> [$b->earlierOrder === null, $b->carId]);
        return $rows;
    }

    /**
     * Restore the earlier order and/or reattach ticked detached photos of one car.
     *
     * The call finds the row again on the server. It attaches a ticked name
     * only when the row lists it as a safe detached photo, and puts it in
     * $ignoredNames otherwise. When the `cars.image` value that the call reads
     * now does not match $seenToken, the car changed since the page loaded,
     * and the call writes nothing. This is also true when the car has no row
     * now. The order restore runs first, then the ticked photos go after the
     * existing ones, in their stored order. The write is one compare-and-set
     * against the `cars.image` value that the call read, so the `cars_update`
     * trigger writes one history row.
     *
     * @param int          $carId         The car to change
     * @param array<mixed> $reattachNames The ticked photo names. Each entry must be a string.
     * @param bool         $restoreOrder  True to restore the earlier order
     * @param int          $userId        The admin or editor, for the log row
     * @param string       $seenToken     The versionToken() of `cars.image` when the page loaded
     * @param-out list<string> $ignoredNames
     * @param array<mixed>|null $ignoredNames Out: the ticked names that the call did not attach because the
     *                                        row does not list them as safe detached photos. The call fills it
     *                                        only after it checks the names against a row. Empty when the
     *                                        result is Changed (also after a compare-and-set miss) or
     *                                        Unreadable, because the page saw other data. Also empty for
     *                                        NothingToDo when the car has no row now (for example, a detached
     *                                        file was removed from disk after the page loaded): with no row
     *                                        to check against, a ticked name is not proven false.
     * @return CarPhotoRecoveryResult Applied, Changed (token mismatch, car gone or compare-and-set miss),
     *                                Unreadable or NothingToDo (also when no name is ticked and $restoreOrder is false)
     * @throws CarValidationException   If $carId is not positive, or an entry of $reattachNames is not a string
     * @throws CarDatabaseException     If a query fails
     * @throws ImageProcessingException If the image root is not readable, or the list cannot be encoded
     */
    public function apply(
        int $carId,
        array $reattachNames,
        bool $restoreOrder,
        int $userId,
        string $seenToken,
        ?array &$ignoredNames = null,
    ): CarPhotoRecoveryResult {
        $ignoredNames = [];
        if ($carId <= 0) {
            throw new CarValidationException('Car Photos: the car ID must be positive');
        }
        $ticked = [];
        foreach ($reattachNames as $name) {
            if (!is_string($name)) {
                throw new CarValidationException("Car Photos: each photo name for car {$carId} must be a string");
            }
            $ticked[] = $name;
        }
        $ticked = array_values(array_unique($ticked));
        if ($ticked === [] && !$restoreOrder) {
            return CarPhotoRecoveryResult::NothingToDo;
        }

        $row = $this->rowAsSeen($carId, $seenToken);
        if ($row instanceof CarPhotoRecoveryResult) {
            return $row;
        }

        $newNames      = $row->currentNames;
        $orderRestored = false;
        if ($restoreOrder && $row->earlierOrder !== null) {
            $newNames      = $row->earlierOrder;
            $orderRestored = true;
        }
        $attachable   = $row->attachableNames();
        $attached     = array_values(array_intersect($attachable, $ticked));
        $ignoredNames = array_values(array_diff($ticked, $attachable));
        $newNames     = array_merge($newNames, $attached);

        if (!$orderRestored && $attached === []) {
            return CarPhotoRecoveryResult::NothingToDo;
        }

        $newJson = (new CarImageProcessor($this->repository))->encodeImages($newNames);
        if (!$this->repository->updateImage($carId, $newJson, $row->rawImage)) {
            // Another save won the race, so the names were judged against
            // data that is gone.
            $ignoredNames = [];
            return CarPhotoRecoveryResult::Changed;
        }

        logger(
            $userId,
            LogCategories::LOG_CATEGORY_CAR_ACTIONS,
            sprintf(
                self::NOTE_PREFIX . ' car %d, reattached %d photo(s), order restored: %s',
                $carId,
                count($attached),
                $orderRestored ? 'yes' : 'no'
            ),
            [
                'action'         => self::ACTION_APPLY,
                'car_id'         => $carId,
                'reattached'     => $attached,
                'order_restored' => $orderRestored,
                'token_after'    => self::versionToken($newJson),
            ]
        );
        $this->decisionLog = null;

        return CarPhotoRecoveryResult::Applied;
    }

    /**
     * Mark the row of one car as reviewed with no change.
     *
     * The call writes one `logs` row and does not change `cars.image`. The
     * tab hides the row while isIgnored() is true for it: any change to
     * `cars.image` or to the detached names shows the row again.
     *
     * @param int    $carId     The car
     * @param int    $userId    The admin or editor, for the log row
     * @param string $seenToken The versionToken() of `cars.image` when the page loaded
     * @return CarPhotoRecoveryResult Applied (log row written), Changed (token mismatch or car gone),
     *                                Unreadable, or NothingToDo (no row, or the row is already ignored)
     * @throws CarValidationException   If $carId is not positive
     * @throws CarDatabaseException     If a query fails
     * @throws ImageProcessingException If the image root is not readable
     */
    public function ignore(int $carId, int $userId, string $seenToken): CarPhotoRecoveryResult
    {
        return $this->decide($carId, $userId, $seenToken, self::ACTION_IGNORE);
    }

    /**
     * Show the row of one car again after ignore().
     *
     * The call writes one `logs` row that is newer than the ignore.
     *
     * @param int    $carId     The car
     * @param int    $userId    The admin or editor, for the log row
     * @param string $seenToken The versionToken() of `cars.image` when the page loaded
     * @return CarPhotoRecoveryResult Applied (log row written), Changed (token mismatch or car gone),
     *                                Unreadable, or NothingToDo (no row, or the row is not ignored)
     * @throws CarValidationException   If $carId is not positive
     * @throws CarDatabaseException     If a query fails
     * @throws ImageProcessingException If the image root is not readable
     */
    public function undoIgnore(int $carId, int $userId, string $seenToken): CarPhotoRecoveryResult
    {
        return $this->decide($carId, $userId, $seenToken, self::ACTION_UNIGNORE);
    }

    /**
     * Tell if the newest Ignore of a car still applies to its row.
     *
     * @param CarPhotoRecoveryRow $row A row from findRows()
     * @return bool True when the newest decision is an ignore, with the token and the detached names of $row,
     *              and $row is readable
     * @throws CarDatabaseException If the `logs` query fails
     */
    public function isIgnored(CarPhotoRecoveryRow $row): bool
    {
        return $this->ignoredBy($row) !== null;
    }

    /**
     * Get who ignored a row, and when.
     *
     * @param CarPhotoRecoveryRow $row A row from findRows()
     * @return array{userId: int, at: string}|null The user ID and `logs.logdate` of the ignore, or null when isIgnored() is false
     * @throws CarDatabaseException If the `logs` query fails
     */
    public function ignoredBy(CarPhotoRecoveryRow $row): ?array
    {
        // An unreadable row has no detached names to compare, so an old
        // ignore must not hide the row and its read failure.
        if ($row->unreadable !== []) {
            return null;
        }
        $decision = $this->decisionLog()['ignore'][$row->carId] ?? null;
        if ($decision === null || $decision['action'] !== self::ACTION_IGNORE
            || !hash_equals($decision['token'], self::versionToken($row->rawImage))) {
            return null;
        }
        $names = $row->detachedNames();
        sort($names);
        return $decision['detached'] === $names ? ['userId' => $decision['userId'], 'at' => $decision['at']] : null;
    }

    /**
     * Get the version token of a raw `cars.image` value.
     *
     * The tab puts the token in the form, and apply() compares it with the
     * value it reads, to find a change since the page loaded. NULL and ''
     * give different tokens.
     *
     * @param string|null $rawImage The raw `cars.image` value
     * @return string 64 lowercase hex characters
     */
    public static function versionToken(?string $rawImage): string
    {
        return hash('sha256', $rawImage === null ? 'null' : 'string:' . $rawImage);
    }

    /**
     * Tell if a value has the format of a versionToken() result.
     *
     * @param string $value The value to check, for example a token from the form
     * @return bool True when $value is 64 lowercase hex characters
     */
    public static function isValidToken(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /**
     * Get the name of the file to show as a thumbnail for a photo.
     *
     * @param int    $carId    The car ID
     * @param string $filename A photo name from a CarPhotoRecoveryRow
     * @return string The `-resized-100` copy when it exists, else $filename
     */
    public function thumbnailName(int $carId, string $filename): string
    {
        $parts     = pathinfo($filename);
        $thumbnail = $parts['filename'] . '-resized-' . self::THUMBNAIL_SIZE . '.' . ($parts['extension'] ?? '');
        if (CarImageProcessor::isSafeFilename($thumbnail) && is_file($this->carDirectory($carId) . $thumbnail)) {
            return $thumbnail;
        }
        return $filename;
    }

    /**
     * Find the row of one car again, and check that the page saw its current value.
     *
     * @return CarPhotoRecoveryRow|CarPhotoRecoveryResult The row; else Changed (malformed or mismatched
     *         token, or the car is gone), NothingToDo (no row, and the token matches) or Unreadable
     * @throws CarValidationException   If $carId is not positive
     * @throws CarDatabaseException     If a query fails
     * @throws ImageProcessingException If the image root is not readable
     */
    private function rowAsSeen(int $carId, string $seenToken): CarPhotoRecoveryRow|CarPhotoRecoveryResult
    {
        // A malformed token cannot prove that the page saw the current list.
        if (!self::isValidToken($seenToken)) {
            return CarPhotoRecoveryResult::Changed;
        }
        $rows = $this->findRows([$carId]);
        if ($rows === []) {
            // No row now. Another session can have changed `cars.image` so
            // that nothing is left to show, and the result must say so.
            $current = $this->loadCurrent([$carId]);
            if (!array_key_exists($carId, $current)) {
                return CarPhotoRecoveryResult::Changed;
            }
            return hash_equals(self::versionToken($current[$carId]), $seenToken)
                ? CarPhotoRecoveryResult::NothingToDo
                : CarPhotoRecoveryResult::Changed;
        }
        $row = $rows[0];
        if (!hash_equals(self::versionToken($row->rawImage), $seenToken)) {
            return CarPhotoRecoveryResult::Changed;
        }
        if ($row->unreadable !== []) {
            return CarPhotoRecoveryResult::Unreadable;
        }
        return $row;
    }

    /**
     * Write an Ignore or Undo ignore decision for one car, when it changes the ignore state.
     *
     * @param string $action ACTION_IGNORE or ACTION_UNIGNORE
     * @return CarPhotoRecoveryResult Applied (log row written), Changed, Unreadable, or NothingToDo
     *                                (no row, or the row already has the state that $action sets)
     * @throws CarValidationException   If $carId is not positive
     * @throws CarDatabaseException     If a query fails
     * @throws ImageProcessingException If the image root is not readable
     */
    private function decide(int $carId, int $userId, string $seenToken, string $action): CarPhotoRecoveryResult
    {
        $row = $this->rowAsSeen($carId, $seenToken);
        if ($row instanceof CarPhotoRecoveryResult) {
            return $row;
        }
        if ($this->isIgnored($row) === ($action === self::ACTION_IGNORE)) {
            return CarPhotoRecoveryResult::NothingToDo;
        }
        $this->logDecision($row, $userId, $action);
        return CarPhotoRecoveryResult::Applied;
    }

    /**
     * Write one Ignore or Undo ignore log row for a row.
     *
     * @param string $action ACTION_IGNORE or ACTION_UNIGNORE
     */
    private function logDecision(CarPhotoRecoveryRow $row, int $userId, string $action): void
    {
        $names = $row->detachedNames();
        logger(
            $userId,
            LogCategories::LOG_CATEGORY_CAR_ACTIONS,
            $action === self::ACTION_IGNORE
                ? sprintf(self::NOTE_PREFIX . ' car %d ignored with %d detached photo(s)', $row->carId, count($names))
                : sprintf(self::NOTE_PREFIX . ' car %d, ignore undone', $row->carId),
            [
                'action'   => $action,
                'car_id'   => $row->carId,
                'token'    => self::versionToken($row->rawImage),
                'detached' => $names,
            ]
        );
        $this->decisionLog = null;
    }

    /**
     * Get the Car Photos decisions, read from `logs` once until the next write.
     *
     * @return array{ignore: array<int, array{action: string, token: string, detached: list<string>, userId: int, at: string}>, restored: array<int, string>}
     *         `ignore`: the newest Ignore or Undo ignore decision by car ID, with `detached` sorted.
     *         `restored`: the versionToken() of the list that the newest order restore wrote, by car ID.
     * @throws CarDatabaseException If the `logs` query fails
     */
    private function decisionLog(): array
    {
        return $this->decisionLog ??= $this->loadDecisions();
    }

    /**
     * Read the Car Photos decisions of each car from `logs`.
     *
     * The decisions are `Car Photos:` rows of the CarActions category whose
     * metadata has an `action` key. A later row wins. A row whose metadata
     * does not decode, or lacks a key, is skipped: the car then shows.
     * An apply row counts only when it restored the order.
     *
     * @return array{ignore: array<int, array{action: string, token: string, detached: list<string>, userId: int, at: string}>, restored: array<int, string>}
     * @throws CarDatabaseException If the query fails
     */
    private function loadDecisions(): array
    {
        $this->db->query(
            "SELECT id, user_id, logdate, metadata FROM logs
             WHERE logtype = ? AND lognote LIKE ?
             ORDER BY id",
            [LogCategories::LOG_CATEGORY_CAR_ACTIONS, self::NOTE_PREFIX . '%']
        );
        if ($this->db->error()) {
            throw new CarDatabaseException('Car Photos: the logs query failed: ' . $this->db->errorString());
        }

        $decisions = [];
        $restored  = [];
        foreach ($this->db->results(true) as $logRow) {
            $logRow   = (array) $logRow;
            $metadata = is_string($logRow['metadata'] ?? null) ? json_decode($logRow['metadata'], true) : null;
            if (!is_array($metadata)) {
                continue;
            }
            $action   = $metadata['action'] ?? null;
            $carId    = $metadata['car_id'] ?? null;
            if ($action === self::ACTION_APPLY) {
                $tokenAfter = $metadata['token_after'] ?? null;
                if (is_int($carId) && $carId > 0 && ($metadata['order_restored'] ?? null) === true && is_string($tokenAfter)) {
                    $restored[$carId] = $tokenAfter;
                }
                continue;
            }
            $token    = $metadata['token'] ?? null;
            $detached = $metadata['detached'] ?? null;
            if (!in_array($action, [self::ACTION_IGNORE, self::ACTION_UNIGNORE], true)
                || !is_int($carId) || $carId <= 0 || !is_string($token)
                || !is_array($detached) || !array_is_list($detached)
                || array_filter($detached, static fn(mixed $name): bool => !is_string($name)) !== []) {
                continue;
            }
            /** @var list<string> $detached */
            sort($detached);
            $userId = $logRow['user_id'] ?? null;
            $at     = $logRow['logdate'] ?? null;
            $decisions[$carId] = [
                'action'   => $action,
                'token'    => $token,
                'detached' => $detached,
                'userId'   => is_int($userId) ? $userId : (is_string($userId) && ctype_digit($userId) ? (int) $userId : 0),
                'at'       => is_string($at) ? $at : '',
            ];
        }
        return ['ignore' => $decisions, 'restored' => $restored];
    }

    /**
     * Read the raw `cars.image` value of each car to check.
     *
     * @param list<int>|null $carIds The cars, or null for each car with a non-NULL history list
     * @return array<int, string|null> Raw `cars.image` by car ID, in car ID order
     * @throws CarDatabaseException If the query fails or a row has an unexpected shape
     */
    private function loadCurrent(?array $carIds): array
    {
        if ($carIds === null) {
            $this->db->query(
                'SELECT c.id, c.image FROM cars c
                 WHERE EXISTS (SELECT 1 FROM cars_hist h WHERE h.car_id = c.id AND h.image IS NOT NULL)
                 ORDER BY c.id'
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($carIds), '?'));
            $this->db->query("SELECT id, image FROM cars WHERE id IN ({$placeholders}) ORDER BY id", $carIds);
        }
        if ($this->db->error()) {
            throw new CarDatabaseException('Car Photos: the cars query failed: ' . $this->db->errorString());
        }

        $current = [];
        foreach ($this->db->results(true) as $row) {
            $row   = (array) $row;
            $carId = $this->intColumn($row, 'id');
            $current[$carId] = $this->imageColumn($row, $carId);
        }
        return $current;
    }

    /**
     * Read the non-NULL `cars_hist` lists of the cars to check.
     *
     * @param list<int>|null $carIds The cars, or null for all cars
     * @return array<int, list<array{id: int, names: list<string>, time: string}>> States by car ID, in `id` order
     * @throws CarDatabaseException If the query fails or a row has an unexpected shape
     */
    private function loadHistory(?array $carIds): array
    {
        if ($carIds === null) {
            $this->db->query(
                'SELECT id, car_id, image, timestamp FROM cars_hist
                 WHERE image IS NOT NULL
                 ORDER BY car_id, id'
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($carIds), '?'));
            $this->db->query(
                "SELECT id, car_id, image, timestamp FROM cars_hist
                 WHERE image IS NOT NULL AND car_id IN ({$placeholders})
                 ORDER BY car_id, id",
                $carIds
            );
        }
        if ($this->db->error()) {
            throw new CarDatabaseException('Car Photos: the cars_hist query failed: ' . $this->db->errorString());
        }

        $history = [];
        // Most saves do not change the photos, so many rows hold the same list.
        $decoded = [];
        foreach ($this->db->results(true) as $row) {
            $row   = (array) $row;
            $carId = $this->intColumn($row, 'car_id');
            $image = $this->imageColumn($row, $carId);
            $time  = $row['timestamp'] ?? null;
            if ($image === null) {
                continue;
            }
            if (!is_string($time)) {
                throw new CarDatabaseException("Car Photos: cars_hist row for car {$carId} has no timestamp");
            }
            $history[$carId][] = [
                'id'    => $this->intColumn($row, 'id'),
                'names' => $decoded[$image] ??= CarImageProcessor::storedImageFilenames($image),
                'time'  => $time,
            ];
        }
        return $history;
    }

    /**
     * Build the row for one car, or null when the car has nothing to show.
     *
     * @param int         $carId    The car ID
     * @param string|null $rawImage The raw `cars.image` value
     * @param list<array{id: int, names: list<string>, time: string}> $states The history states, in `id` order
     * @param string|null $restoredToken The token of the list that the newest order restore wrote, or null
     */
    private function buildRow(int $carId, ?string $rawImage, array $states, ?string $restoredToken): ?CarPhotoRecoveryRow
    {
        if ($states === []) {
            return null;
        }
        $currentNames = CarImageProcessor::storedImageFilenames($rawImage);
        $unreadable   = [];
        $detached     = $this->findDetached($carId, $currentNames, $states, $unreadable);
        if ($unreadable !== []) {
            // Without the bytes of every file, the hash and type checks are
            // not complete, so the row offers no action.
            return new CarPhotoRecoveryRow($carId, $rawImage, $currentNames, [], null, null, $unreadable);
        }

        $earlierOrder   = null;
        $earlierOrderAt = null;
        $reversed       = array_reverse($currentNames);
        // The restore made the trigger write the reversed list to cars_hist,
        // so the current list is the reverse of a history list again. While
        // cars.image is the list that the restore wrote, offer no restore.
        $justRestored = $restoredToken !== null && hash_equals($restoredToken, self::versionToken($rawImage));
        if (!$justRestored && count($currentNames) >= 2 && $reversed !== $currentNames) {
            foreach (array_reverse($states) as $state) {
                if ($state['names'] === $reversed) {
                    $earlierOrder   = $reversed;
                    $earlierOrderAt = $state['time'];
                    break;
                }
            }
        }

        if ($detached === [] && $earlierOrder === null) {
            return null;
        }
        return new CarPhotoRecoveryRow($carId, $rawImage, $currentNames, $detached, $earlierOrder, $earlierOrderAt);
    }

    /**
     * Find the detached photos of one car.
     *
     * A file is a candidate when it is not in the current list, is not a
     * resized copy, is a real JPEG, PNG or GIF, and a history state held it.
     * A candidate with the same bytes as an attached photo is dropped. Of
     * candidates with the same bytes, one stays: a safe name first, then the
     * one that a later state held, then the lower name.
     *
     * @param int          $carId        The car ID
     * @param list<string> $currentNames The names in `cars.image`
     * @param list<array{id: int, names: list<string>, time: string}> $states The history states, in `id` order
     * @param list<string> $unreadable   Out: the names that cannot be read, or `{carId}/` for the folder
     * @return list<DetachedPhoto> In drop order, then in the order of the list that dropped them. Not complete when $unreadable is set.
     */
    private function findDetached(int $carId, array $currentNames, array $states, array &$unreadable): array
    {
        $directory = $this->carDirectory($carId);
        if (!is_dir($directory)) {
            return [];
        }
        // is_readable() first, so a folder without read access gives no
        // warning. The false check stays for a read that fails after it.
        $entries = is_readable($directory) ? scandir($directory) : false;
        if ($entries === false) {
            $unreadable[] = $carId . '/';
            return [];
        }

        /** @var array<string, list<array{name: string, safe: bool, stateIndex: int, position: int}>> $byHash */
        $byHash = [];
        foreach ($entries as $name) {
            if (in_array($name, $currentNames, true) || CarImageProcessor::isResizedVariant($name)) {
                continue;
            }
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
                continue;
            }
            $path = $directory . $name;
            if (!is_file($path)) {
                continue;
            }
            // History first, so an orphan that the server cannot read does
            // not block its car.
            $stateIndex = $this->lastStateIndexWith($name, $states);
            if ($stateIndex === null) {
                continue;
            }
            if (!is_readable($path)) {
                $unreadable[] = $name;
                continue;
            }
            // @-suppressed like decodeAndProcessImages(): a file that is too
            // short to have a header gives a notice, and false is handled.
            if (!in_array(@exif_imagetype($path), self::IMAGE_TYPES, true)) {
                continue;
            }
            $hash = $this->hashFile($path);
            if ($hash === null) {
                $unreadable[] = $name;
                continue;
            }
            $position = array_search($name, $states[$stateIndex]['names'], true);
            $byHash[$hash][] = [
                'name'       => $name,
                'safe'       => CarImageProcessor::isSafeFilename($name),
                'stateIndex' => $stateIndex,
                'position'   => is_int($position) ? $position : 0,
            ];
        }

        if ($byHash !== []) {
            foreach ($currentNames as $name) {
                $path = $directory . $name;
                if (!CarImageProcessor::isSafeFilename($name) || !is_file($path)) {
                    continue;
                }
                $hash = $this->hashFile($path);
                if ($hash === null) {
                    $unreadable[] = $name;
                    continue;
                }
                unset($byHash[$hash]);
            }
        }
        if ($unreadable !== []) {
            return [];
        }

        $kept = [];
        foreach ($byHash as $group) {
            usort($group, static fn(array $a, array $b): int =>
                [$b['safe'], $b['stateIndex'], $a['name']] <=> [$a['safe'], $a['stateIndex'], $b['name']]);
            $kept[] = $group[0];
        }
        usort($kept, static fn(array $a, array $b): int =>
            [$a['stateIndex'], $a['position'], $a['name']] <=> [$b['stateIndex'], $b['position'], $b['name']]);

        $detached = [];
        foreach ($kept as $candidate) {
            $index    = $candidate['stateIndex'];
            $left     = $states[$index]['names'];
            $next     = $states[$index + 1]['names'] ?? $currentNames;
            $detached[] = new DetachedPhoto(
                $candidate['name'],
                $candidate['safe'],
                $this->classify($left, $next),
                $states[$index]['time'],
            );
        }
        return $detached;
    }

    /**
     * Find the last history state that holds a name.
     *
     * With OLD rows from the update trigger, that row records the save that
     * dropped the name, and its timestamp is the time of that save.
     *
     * @param list<array{id: int, names: list<string>, time: string}> $states The history states, in `id` order
     * @return int|null The index in $states, or null when no state holds the name (an orphan)
     */
    private function lastStateIndexWith(string $name, array $states): ?int
    {
        for ($i = count($states) - 1; $i >= 0; $i--) {
            if (in_array($name, $states[$i]['names'], true)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Classify the save that changed list $left to list $next.
     *
     * @param list<string> $left The list that held the photo
     * @param list<string> $next The next list
     */
    private function classify(array $left, array $next): DetachedHow
    {
        if ($next === []) {
            return DetachedHow::Removed;
        }
        if (array_intersect($left, $next) === []) {
            return DetachedHow::Replaced;
        }
        return DetachedHow::PartlyRemoved;
    }

    /**
     * Get the SHA-256 hash of a file.
     *
     * @return string|null The hash, or null when the file cannot be read
     */
    private function hashFile(string $path): ?string
    {
        // is_readable() first, so a file without read access gives no warning.
        $hash = is_readable($path) ? hash_file('sha256', $path) : false;
        return $hash === false ? null : $hash;
    }

    /** Absolute path of a car's photo directory, with a trailing slash. */
    private function carDirectory(int $carId): string
    {
        return $this->imageRoot . $carId . '/';
    }

    /**
     * Read a positive int column from a database row.
     *
     * @param array<mixed> $row The row
     * @throws CarDatabaseException If the value is not a positive integer
     */
    private function intColumn(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        throw new CarDatabaseException("Car Photos: a row has no valid '{$column}' value");
    }

    /**
     * Read the `image` column from a database row.
     *
     * @param array<mixed> $row The row
     * @throws CarDatabaseException If the value is not a string or NULL
     */
    private function imageColumn(array $row, int $carId): ?string
    {
        $value = $row['image'] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new CarDatabaseException("Car Photos: the image value of car {$carId} is not a string");
        }
        return $value;
    }
}
