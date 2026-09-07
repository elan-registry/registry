<?php
declare(strict_types=1);

namespace ElanRegistry;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\OwnerCreationException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Exceptions\OwnerSearchException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;

/**
 * Manages owner profile data across the `users` and `profiles` tables,
 * isolating ElanRegistry business logic from UserSpice user management.
 * Provides CRUD operations, profile quality scoring, location synchronization,
 * ownership history, and owner search.
 *
 * @author Jim Boone
 */

class Owner
{
    /** Maps DB column → display label for simple profile completeness fields (lat/lon handled separately). */
    private const PROFILE_SIMPLE_FIELD_LABELS = [
        'fname'   => 'First Name',
        'lname'   => 'Last Name',
        'email'   => 'Email',
        'city'    => 'City',
        'state'   => 'State',
        'country' => 'Country',
    ];

    private DatabaseInterface $_db;
    private ?object $_data = null;
    private ?array $_carsOwned = null;
    private string $userTableName = 'users';
    private string $profileTableName = 'profiles';
    private bool $transactionOwner = false;

    /**
     * Instantiates the Owner object.
     *
     * @param int|null $id Optional User ID. If given, the owner information will be populated.
     * @param DatabaseInterface|null $db Optional database instance for testing. Defaults to the shared dbi() handle.
     */
    public function __construct(?int $id = null, ?DatabaseInterface $db = null)
    {
        $this->_db = $db ?? dbi();

        if ($id) {
            $this->find($id);
        }
    }

    /**
     * Find and load owner data by user ID
     *
     * Executes a users LEFT JOIN profiles query. When no profiles row exists,
     * string location fields (city, state, country, website) are normalised to ''
     * rather than null; lat and lon remain null as returned by the LEFT JOIN.
     *
     * Returns false when the user ID is invalid or genuinely not found. On a
     * DB error, throws OwnerDatabaseException instead of returning false, so
     * callers can distinguish "not found" from "DB failed" without reading
     * logs (#1505 PR B).
     *
     * @param int $userId The user ID to load
     * @return bool True if owner found and loaded; false if $userId <= 0 or not found
     * @throws OwnerDatabaseException If the query fails
     */
    public function find(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $q = $this->_db->query(
            "SELECT u.*, p.city, p.state, p.country, p.lat, p.lon, p.website
             FROM users u
             LEFT JOIN profiles p ON u.id = p.user_id
             WHERE u.id = ?",
            [$userId]
        );

        if ($this->_db->error()) {
            logger(0, \ElanRegistry\LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                "Owner::find DB error for userId={$userId}: " . $this->_db->errorString());
            throw new OwnerDatabaseException(
                "Owner::find failed for userId={$userId}: " . $this->_db->errorString()
            );
        }

        if ($q->count() > 0) {
            $ownerData = $q->first();
            $ownerData->city    = $ownerData->city    ?? '';
            $ownerData->state   = $ownerData->state   ?? '';
            $ownerData->country = $ownerData->country ?? '';
            $ownerData->website = $ownerData->website ?? '';
            $this->_data = $ownerData;
            return true;
        }

        return false;
    }

    /**
     * Get current owner data
     *
     * @return object|null Owner data object or null if not loaded
     */
    public function data(): ?object
    {
        return $this->_data;
    }

    /**
     * Begin a database transaction
     *
     * When participating in an outer transaction (begun by the caller before
     * this Owner instance), this method is a no-op. Mirrors
     * CarRepository::beginTransaction()'s ownership-flag pattern.
     *
     * @return void
     */
    public function beginTransaction(): void
    {
        if ($this->_db->inTransaction()) {
            return; // Participating in outer transaction — no-op
        }
        $this->_db->beginTransaction();
        $this->transactionOwner = true;
    }

    /**
     * Commit the current transaction
     *
     * When participating in an outer transaction (begun by the caller before
     * this Owner instance), this method is a no-op.
     *
     * @return void
     */
    public function commit(): void
    {
        if (!$this->transactionOwner) {
            return; // Outer transaction manages commit — no-op
        }
        $this->transactionOwner = false;
        if ($this->_db->inTransaction()) {
            $this->_db->commit();
        }
    }

    /**
     * Rollback the current transaction
     *
     * When participating in an outer transaction (begun by the caller before
     * this Owner instance), this method is a no-op.
     *
     * @return void
     */
    public function rollback(): void
    {
        if (!$this->transactionOwner) {
            return; // Outer transaction manages rollback — no-op
        }
        $this->transactionOwner = false;
        if ($this->_db->inTransaction()) {
            $this->_db->rollBack();
        }
    }

    /**
     * Create a new owner (user + profile)
     *
     * @param array $fields Key value pairs for owner data
     * @return bool True if owner is created
     * @throws OwnerCreationException If validation fails or database operation fails
     */
    public function create(array $fields = []): bool
    {
        if (empty($fields)) {
            throw OwnerCreationException::withUserMessage(
                'No data provided for owner creation',
                'No data provided for owner creation.'
            );
        }

        // Validate required fields for both user and profile
        $this->validateRequiredFields($fields, ['fname', 'lname', 'email']);

        // Validate and sanitize all fields
        $fields = $this->validateAndSanitizeFields($fields);

        // Start transaction for user + profile creation
        $this->beginTransaction();

        try {
            // Split fields between user and profile tables
            $userFields = $this->extractUserFields($fields);
            $profileFields = $this->extractProfileFields($fields);

            // Create user record
            $userFields['join_date'] = date(AppConstants::DATETIME_FORMAT);
            $userFields['vericode'] = hashVericode(randomString(15));

            if (!$this->_db->insert($this->userTableName, $userFields)) {
                throw OwnerCreationException::withUserMessage(
                    'Database error during user creation: ' . $this->_db->errorString(),
                    'Failed to create owner account. Please try again.'
                );
            }

            $userId = $this->_db->lastId();

            // Create profile record
            $profileFields['user_id'] = $userId;
            $profileFields['ctime'] = date(AppConstants::DATETIME_FORMAT);

            if (!$this->_db->insert($this->profileTableName, $profileFields)) {
                throw OwnerCreationException::withUserMessage(
                    'Database error during profile creation: ' . $this->_db->errorString(),
                    'Failed to create owner profile. Please try again.'
                );
            }

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Owner creation transaction failed: ' . $e->getMessage());
            throw $e;
        }

        // Post-commit: reload and log outside the transaction so that a failure
        // here does not trigger ROLLBACK or log a false "transaction failed" message.
        // A reload failure after a successful write is a lower-severity failure
        // than the write itself failing, so it's caught and logged rather than
        // propagated — matching Car::find()'s treatment of its own subordinate
        // lookups (#1505 PR A).
        try {
            $this->find($userId);
        } catch (OwnerDatabaseException $e) {
            logger($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                "Owner::create() post-commit reload failed for userId={$userId}: " . $e->getMessage());
        }
        logger($userId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, "Owner created: {$userFields['fname']} {$userFields['lname']} ({$userFields['email']})");

        return true;
    }

    /**
     * Update existing owner information
     *
     * @param array $fields Owner data to update
     * @return bool True if update succeeds
     * @throws OwnerValidationException If validation fails
     * @throws OwnerUpdateException If database operation fails
     */
    public function update(array $fields = []): bool
    {
        if (empty($fields) || !isset($fields['id'])) {
            logger($fields['id'] ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Owner update failed: No data or ID provided');
            throw OwnerValidationException::withUserMessage(
                'No data or ID provided for owner update',
                'Unable to process update. Please try again.'
            );
        }

        if (!is_numeric($fields['id']) || $fields['id'] <= 0) {
            throw OwnerValidationException::withUserMessage(
                'Invalid owner ID provided for update',
                'Unable to identify the owner record. Please try again.'
            );
        }

        $userId = (int)$fields['id'];

        // Validate and sanitize fields
        $fieldsToValidate = $fields;
        unset($fieldsToValidate['id']);
        if (!empty($fieldsToValidate)) {
            $validatedFields = $this->validateAndSanitizeFields($fieldsToValidate, false);
        } else {
            throw OwnerValidationException::withUserMessage(
                'No fields provided for update',
                'No changes were submitted. Please enter values to update.'
            );
        }

        // Start transaction for user + profile updates
        $this->beginTransaction();

        try {
            // Split fields between user and profile tables
            $userFields = $this->extractUserFields($validatedFields);
            $profileFields = $this->extractProfileFields($validatedFields);

            // Update user fields if any
            if (!empty($userFields)) {
                // Note: users table doesn't have mtime field (UserSpice standard)
                if (!$this->_db->update($this->userTableName, $userId, $userFields)) {
                    throw OwnerUpdateException::withUserMessage(
                        'Database error during user update: ' . $this->_db->errorString(),
                        'Failed to update owner account. Please try again.'
                    );
                }
            }

            // Update profile fields if any
            if (!empty($profileFields)) {
                // Note: profiles table doesn't have mtime field (UserSpice standard)

                // UserSpice DB::update() uses array for custom WHERE: ['column' => 'value']
                $updateResult = $this->_db->update($this->profileTableName, ['user_id' => $userId], $profileFields);

                if (!$updateResult) {
                    throw OwnerUpdateException::withUserMessage(
                        'Database error during profile update: ' . $this->_db->errorString(),
                        'Failed to update owner profile. Please try again.'
                    );
                }
            }

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            logger($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Owner update transaction failed: ' . $e->getMessage());
            throw $e;
        }

        // Post-commit: reload and log outside the transaction so that a failure
        // here does not trigger ROLLBACK or log a false "transaction failed" message.
        // A reload failure after a successful write is a lower-severity failure
        // than the write itself failing, so it's caught and logged rather than
        // propagated — matching Car::find()'s treatment of its own subordinate
        // lookups (#1505 PR A).
        try {
            $this->find($userId);
        } catch (OwnerDatabaseException $e) {
            logger($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                "Owner::update() post-commit reload failed for userId={$userId}: " . $e->getMessage());
        }
        $fieldsUpdated = array_merge(array_keys($userFields), array_keys($profileFields));
        logger($userId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, "Owner updated - fields: " . implode(', ', $fieldsUpdated));

        return true;
    }

    /**
     * Get all cars owned by this owner
     *
     * @return array Array of car objects owned by this owner
     * @throws OwnerDatabaseException If the query fails
     */
    public function getCarsOwned(): array
    {
        if (!$this->_data) {
            return [];
        }

        if ($this->_carsOwned !== null) {
            return $this->_carsOwned;
        }

        $carsQuery = $this->_db->query(
            "SELECT c.* FROM cars c WHERE c.user_id = ? ORDER BY c.model, c.year",
            [$this->_data->id]
        );

        if ($this->_db->error()) {
            logger((int)$this->_data->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                "getCarsOwned DB error for userId={$this->_data->id}: " . $this->_db->errorString());
            throw new OwnerDatabaseException(
                "Owner::getCarsOwned failed for userId={$this->_data->id}: " . $this->_db->errorString()
            );
        }

        $this->_carsOwned = $carsQuery->count() > 0 ? $carsQuery->results() : [];
        return $this->_carsOwned;
    }

    /**
     * Get ownership history for this owner
     *
     * @return array Array of ownership history records
     * @throws OwnerDatabaseException If the query fails
     */
    public function getOwnershipHistory(): array
    {
        if (!$this->_data) {
            return [];
        }

        $historyQuery = $this->_db->query(
            "SELECT ch.*, c.chassis, c.model, c.year
             FROM cars_hist ch
             LEFT JOIN cars c ON ch.car_id = c.id
             WHERE ch.user_id = ?
             ORDER BY ch.ctime DESC",
            [$this->_data->id]
        );

        if ($this->_db->error()) {
            logger((int)$this->_data->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                "getOwnershipHistory DB error for userId={$this->_data->id}: " . $this->_db->errorString());
            throw new OwnerDatabaseException(
                "Owner::getOwnershipHistory failed for userId={$this->_data->id}: " . $this->_db->errorString()
            );
        }

        return $historyQuery->count() > 0 ? $historyQuery->results() : [];
    }

    /**
     * Get profile completeness score
     *
     * @return float Profile completeness percentage (0-100)
     */
    public function getProfileQualityScore(): float
    {
        if (!$this->_data) {
            return 0.0;
        }

        return self::qualityScoreFromRow($this->_data);
    }

    /**
     * Calculate quality score from a plain query result row.
     *
     * Accepts a raw DB row object so batch loops can score many owners without
     * constructing a full Owner for each one.
     *
     * @param object $row DB row — must include all PROFILE_SIMPLE_FIELD_LABELS columns
     *                    (fname, lname, email, city, state, country) plus lat and lon.
     *                    Missing properties are treated as empty (score 0 for that field).
     *                    (1 point each for simple fields; lat+lon together count as 1 combined point, 7 points total)
     * @return float Score 0–100
     */
    public static function qualityScoreFromRow(object $row): float
    {
        $completed = 0;
        foreach (array_keys(self::PROFILE_SIMPLE_FIELD_LABELS) as $field) {
            if (!empty($row->$field)) {
                $completed++;
            }
        }
        // Explicit check — !empty() treats 0.0 as absent from the score; ?? '' handles unset/null properties
        if (($row->lat ?? '') !== '' && ($row->lon ?? '') !== '') {
            $completed++;
        }
        return round(($completed / 7) * 100, 1);
    }

    /**
     * Return a Bootstrap contextual color class for a quality score.
     *
     * @param float $score Quality score 0–100
     * @return string 'success', 'warning', or 'danger'
     */
    public static function getQualityBadgeClass(float $score): string
    {
        if ($score >= 80) {
            return 'success';
        }
        if ($score >= 60) {
            return 'warning';
        }
        return 'danger';
    }

    /**
     * Validate profile completeness and return missing fields
     *
     * @return array<string> Human-readable labels for missing profile fields (e.g. 'First Name', 'Location Coordinates')
     */
    public function validateProfileCompleteness(): array
    {
        $missingFields = [];

        if (!$this->_data) {
            return ['Owner data not loaded'];
        }

        foreach (self::PROFILE_SIMPLE_FIELD_LABELS as $field => $label) {
            if (empty($this->_data->$field)) {
                $missingFields[] = $label;
            }
        }
        // Explicit check — !empty() treats 0.0 as empty, falsely reporting equator/prime-meridian coordinates as missing
        if ($this->_data->lat === null || $this->_data->lat === '' ||
            $this->_data->lon === null || $this->_data->lon === '') {
            $missingFields[] = 'Location Coordinates';
        }

        return $missingFields;
    }

    /**
     * Search owners by various criteria
     *
     * @param string $searchTerm Search term. Single-word: matches name, email, and location. Multi-word: matches name and city/state only (email excluded from UNION path).
     * @param int $limit Maximum number of results (default 50)
     * @return array Array of owner search results
     */
    public function searchOwners(string $searchTerm, int $limit = 50): array
    {
        $searchTerm = trim($searchTerm);
        if (empty($searchTerm)) {
            return [];
        }

        // Split into words; for multi-word input, also strip commas from each token.
        // Single-word input bypasses comma-stripping to preserve the original term exactly.
        $searchWords = array_values(array_filter(explode(' ', strtolower($searchTerm))));

        if (count($searchWords) > 1) {
            $searchWords = array_values(array_filter(array_map(
                static fn(string $word) => trim($word, ', '),
                $searchWords
            )));
        }

        if (count($searchWords) === 0) {
            return [];
        }

        if (count($searchWords) < 2) {
            // Single-word search: matches name, email, and location
            $searchPattern = '%' . $searchWords[0] . '%';
            $sql = "SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon
                    FROM users u
                    LEFT JOIN profiles p ON u.id = p.user_id
                    WHERE LOWER(u.fname) LIKE ? OR LOWER(u.lname) LIKE ? OR LOWER(u.email) LIKE ?
                       OR LOWER(p.city) LIKE ? OR LOWER(p.state) LIKE ? OR LOWER(p.country) LIKE ?
                    ORDER BY u.lname, u.fname
                    LIMIT " . (int)$limit;

            $params = [$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern];

        } else {
            // Multi-word search: UNION-based query to prioritize exact matches
            // ($searchWords are already lowercased from strtolower($searchTerm) above)
            $word1 = $searchWords[0];
            $word2 = $searchWords[1];

            $sql = "
            (SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon, 1 as priority
             FROM users u LEFT JOIN profiles p ON u.id = p.user_id
             WHERE (LOWER(u.fname) = ? AND LOWER(u.lname) = ?) OR (LOWER(u.fname) = ? AND LOWER(u.lname) = ?))
            UNION
            (SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon, 2 as priority
             FROM users u LEFT JOIN profiles p ON u.id = p.user_id
             WHERE ((LOWER(u.fname) = ? OR LOWER(u.lname) = ?) AND (LOWER(p.city) = ? OR LOWER(p.state) = ?))
                OR ((LOWER(u.fname) = ? OR LOWER(u.lname) = ?) AND (LOWER(p.city) = ? OR LOWER(p.state) = ?)))
            UNION
            (SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon, 3 as priority
             FROM users u LEFT JOIN profiles p ON u.id = p.user_id
             WHERE (LOWER(p.city) = ? AND LOWER(p.state) = ?) OR (LOWER(p.city) = ? AND LOWER(p.state) = ?))
            ORDER BY priority, lname, fname
            LIMIT " . (int)$limit;

            $params = [
                // First UNION: exact name matches
                $word1, $word2,  // fname=word1 AND lname=word2
                $word2, $word1,  // fname=word2 AND lname=word1
                // Second UNION: name + location matches
                $word1, $word1, $word2, $word2,  // (fname=word1 OR lname=word1) AND (city=word2 OR state=word2)
                $word2, $word2, $word1, $word1,  // (fname=word2 OR lname=word2) AND (city=word1 OR state=word1)
                // Third UNION: location pairs
                $word1, $word2,  // city=word1 AND state=word2
                $word2, $word1   // city=word2 AND state=word1
            ];
        }

        $searchQuery = $this->_db->query($sql, $params);
        if ($this->_db->error()) {
            throw OwnerSearchException::withUserMessage(
                'Owner search DB query failed: ' . $this->_db->errorString(),
                'Search failed. Please try again.'
            );
        }
        return $searchQuery->count() > 0 ? $searchQuery->results() : [];
    }

    /**
     * The single definition of the nine denormalized owner-contact columns.
     *
     * fname, lname, email come from `users`; city, state, country, lat, lon,
     * website come from `profiles` (both loaded via {@see Owner::find()}'s
     * users+profiles LEFT JOIN). This is the canonical column list for any
     * caller that copies the owner's contact data onto a car — currently
     * {@see Owner::syncOwnerFieldsToCars()}, which adds its own `mtime` on
     * top. Never returns `mtime` or `owner_last_updated`: neither is an
     * owner-contact value, and `owner_last_updated` in particular must never
     * be written by a mechanical refresh (see the comment in
     * `syncOwnerFieldsToCars()` for why).
     *
     * If `$this->_data` is null (the owner failed to load, e.g. a
     * freshly-constructed `Owner` for a deleted or invalid user ID), every
     * value in the returned array is null — callers that write these values
     * to a car must check for that themselves before treating the result as
     * ready to persist.
     *
     * @return array<string, mixed> The nine owner-contact fields, keyed by
     *         column name.
     */
    public function ownerContactFields(): array
    {
        return [
            'fname'   => $this->_data->fname ?? null,
            'lname'   => $this->_data->lname ?? null,
            'email'   => $this->_data->email ?? null,
            'city'    => $this->_data->city ?? null,
            'state'   => $this->_data->state ?? null,
            'country' => $this->_data->country ?? null,
            'lat'     => $this->_data->lat ?? null,
            'lon'     => $this->_data->lon ?? null,
            'website' => $this->_data->website ?? null,
        ];
    }

    /**
     * Sync the owner's contact fields to every car they own.
     *
     * Copies the nine denormalized owner-contact columns — fname, lname, email
     * from `users` and city, state, country, lat, lon, website from `profiles` —
     * onto each car in `getCarsOwned()`, plus an explicit `mtime`.
     *
     * Each car is written inside its own transaction, so one car's failure rolls
     * back only that car and the loop continues. Per-car outcomes are collected
     * into the returned result rather than thrown; only a failure to read the
     * car list propagates as an exception.
     *
     * A car whose ownership changed between the initial car list snapshot and
     * the write is reported as skipped, not failed — the previous owner's data
     * was correctly not written, and this is expected behavior rather than an
     * error.
     *
     * @return OwnerSyncResult Per-car outcome: the car IDs synchronized, those
     *         skipped because ownership changed mid-sync (not a failure), and
     *         those that were rolled back due to a real error
     * @throws OwnerDatabaseException If this Owner failed to load (no user row
     *         for this ID), since there is no owner data to sync onto any car;
     *         if getCarsOwned() fails to query — a DB failure here must surface
     *         as a real exception, not silently collapse into "0 cars synced"
     *         (#1505 PR B); if an ownership lookup fails mid-loop, which is an
     *         infrastructure failure rather than a per-car outcome; or if this
     *         method is called while an outer transaction is already open,
     *         which would make every per-car rollback a silent no-op
     * @throws CarDatabaseException If a per-car UPDATE fails at the DB level —
     *         propagated rather than recorded as a per-car failure, for the same
     *         reason as above
     */
    public function syncOwnerFieldsToCars(): OwnerSyncResult
    {
        if (!$this->_data) {
            throw new OwnerDatabaseException(
                'Owner::syncOwnerFieldsToCars() called on an Owner that failed to load '
                . '(no user row for this ID) — cannot sync fields that were never read.'
            );
        }

        $ownedCars = $this->getCarsOwned();

        if (empty($ownedCars)) {
            return new OwnerSyncResult();
        }

        if ($this->_db->inTransaction()) {
            throw new OwnerDatabaseException(
                'Owner::syncOwnerFieldsToCars() cannot run inside an outer transaction: '
                . "CarRepository's nesting-aware helpers would make every per-car "
                . 'rollback a silent no-op, committing car rows without their audit rows.'
            );
        }

        // `cars.mtime` is `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE
        // CURRENT_TIMESTAMP`, so MySQL bumps it on any UPDATE that changes a value —
        // dropping `mtime` from the bundle below would NOT stop that. What this method
        // must never write is `owner_last_updated`: a profile sync is not the owner
        // confirming their car's data, and that omission is the entire mechanism
        // keeping a synced car eligible for verification. The verification design
        // therefore excludes `mtime` from the freshness test, which reads
        // `last_verified` and `owner_last_updated` only. If you are reading this
        // because you noticed
        // `mtime` moving on every sync: that is expected — do NOT "fix" it by adding
        // `mtime` back into a staleness calculation.
        $syncTime = date(AppConstants::DATETIME_FORMAT);
        $ownerFields = $this->ownerContactFields();
        $ownerFields['mtime'] = $syncTime;

        // CarRepository::updateCarForOwner() writes this bundle via raw SQL with
        // no validation, unlike Car::update()/Car::create() (the path
        // OwnerContactRefresher::refresh() feeds). Writing an invalid website
        // through here would not fail now, but would plant a value that blocks
        // every future Car::update()/create() call for this owner's cars — an
        // edit having nothing to do with the website field would still fail
        // CarValidator on save. Drop the field rather than the whole sync;
        // matches OwnerContactRefresher::refresh()'s "skip the field, not the
        // car" behavior for the same input.
        if (isset($ownerFields['website']) && !OwnerContactRefresher::isValidWebsite($ownerFields['website'])) {
            unset($ownerFields['website']);
        }

        $ownerId = (int) $this->_data->id;
        $repo = new CarRepository($this->_db);
        $updated = [];
        $failed = [];
        $skipped = [];

        foreach ($ownedCars as $car) {
            $carId = (int) $car->id;

            // WARNING: nothing between beginTransaction() and the matching
            // commit()/rollback() may call logger(). logger() writes to the InnoDB
            // `logs` table through the same global $db connection this transaction
            // runs on, so its row is destroyed by the rollback — erasing the
            // diagnostic exactly when something has gone wrong. Log only after the
            // transaction has closed (as the handlers below do), and rely on the
            // exception message as the durable record inside it.
            $repo->beginTransaction();

            try {
                $rowsChanged = $repo->updateCarForOwner($carId, $ownerId, $ownerFields);

                if ($rowsChanged === 0) {
                    // 0 is ambiguous: PDO reports rows *changed*, not matched, so a
                    // rewrite of identical values is indistinguishable from "no row
                    // matched". Disambiguate with an ownership check.
                    if (!$this->carBelongsToOwner($carId, $ownerId)) {
                        // The car left this owner between the getCarsOwned() snapshot
                        // and this write. Do not write the previous owner's data anywhere.
                        $repo->rollback();
                        $skipped[] = $carId;
                        logger($ownerId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS,
                            "syncOwnerFieldsToCars: car ID {$carId} is no longer owned by user {$ownerId}; skipped");
                        continue;
                    }

                    // The row matched and already held these values. Success, and no
                    // application history row: a sync that changed nothing is not a
                    // business event worth recording as OWNER_SYNC.
                    //
                    // This does NOT mean cars_hist gains nothing. The cars_update
                    // trigger is AFTER UPDATE ... FOR EACH ROW, gated only by
                    // @disable_triggers, and MySQL fires it for every row MATCHED —
                    // not every row changed. A no-op UPDATE therefore still writes one
                    // trigger row with operation='UPDATE' (verified against the live
                    // trigger during #1873). Anything counting sync activity must
                    // filter on operation='OWNER_SYNC' rather than counting all new
                    // rows for the car.
                    $repo->commit();
                    $updated[] = $carId;
                    continue;
                }

                // Populate the car's identity columns from the row already in hand.
                // cars_hist declares model/series/variant/type/chassis NOT NULL with no
                // default, so under STRICT_TRANS_TABLES omitting them fails the insert
                // outright; matches CarAdministrationService's history-insert pattern.
                $historyFields = $ownerFields;
                $historyFields['car_id']       = $carId;
                $historyFields['user_id']      = $ownerId;
                $historyFields['operation']    = 'OWNER_SYNC';
                $historyFields['comments']     = "Car owner contact details synchronized with owner profile update.";
                $historyFields['ctime']        = $syncTime;
                $historyFields['model']        = $car->model ?? '';
                $historyFields['series']       = $car->series ?? '';
                $historyFields['variant']      = $car->variant ?? '';
                // `cars_hist.year` is smallint unsigned NULL: under STRICT_TRANS_TABLES
                // '' is rejected ("Incorrect integer value") while NULL inserts cleanly,
                // and `cars.year` is itself nullable.
                $historyFields['year']         = $car->year ?? null;
                $historyFields['type']         = $car->type ?? '';
                $historyFields['chassis']      = $car->chassis ?? '';
                $historyFields['color']        = $car->color ?? '';
                $historyFields['engine']       = $car->engine ?? '';
                $historyFields['purchasedate'] = $car->purchasedate ?? null;

                if (!$repo->insertHistory($historyFields)) {
                    // Roll back the UPDATE too — a car row must not move without its
                    // audit entry.
                    $errorString = $repo->errorString();
                    $repo->rollback();
                    $failed[] = $carId;
                    logger($ownerId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS,
                        "syncOwnerFieldsToCars: failed to insert history record for car ID {$carId}, update rolled back: " . $errorString);
                    continue;
                }

                $repo->commit();
                $updated[] = $carId;
            } catch (OwnerDatabaseException | CarDatabaseException $e) {
                // A failed ownership lookup or a DB-level UPDATE error is an
                // infrastructure failure, not a per-car outcome — it must not be
                // reported as "this car could not be updated". Propagate.
                //
                // Guard the rollback itself: on a dropped connection ROLLBACK fails
                // too, and an unguarded call would let PHP discard the original $e
                // (it does not chain an exception thrown from inside a catch),
                // replacing "the update failed because X" with "server has gone
                // away" at exactly the moment an operator needs the real cause.
                // Matches the pattern in database/migrations/
                // 20260905172137_convert_car_timestamps_to_datetime.php.
                try {
                    $repo->rollback();
                } catch (\Throwable $rollbackFailure) {
                    throw new OwnerDatabaseException(
                        "syncOwnerFieldsToCars: rollback failed at car ID {$carId} while handling: "
                        . $e->getMessage() . ' (rollback error: ' . $rollbackFailure->getMessage() . ')',
                        0,
                        $e
                    );
                }
                // Logged here, after the rollback, because propagating discards the
                // OwnerSyncResult: the caller receives an exception and cannot report
                // which cars already committed. Cars listed below are durable — each
                // commits independently — and the sync is idempotent, so a retry
                // re-syncs them harmlessly via the no-op branch. Any log row written
                // inside the transaction would have been rolled back with it, so this
                // line is the only durable record of the partial state.
                logger($ownerId, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                    "syncOwnerFieldsToCars: aborted at car ID {$carId} for owner {$ownerId} after "
                    . count($updated) . ' car(s) already committed (IDs: ' . implode(', ', $updated)
                    . '); ' . count($failed) . ' recorded failed. ' . $e->getMessage());
                throw $e;
            } catch (\Throwable $e) {
                // One bad car must not abandon the rest of the sync.
                //
                // Guard the rollback itself, same reasoning as above: if it also
                // throws, still bucket this car as failed rather than letting the
                // rollback failure propagate and silently drop the car from every
                // bucket (updated/failed/skipped) with no record of what happened.
                try {
                    $repo->rollback();
                } catch (\Throwable $rollbackFailure) {
                    logger($ownerId, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                        "syncOwnerFieldsToCars: rollback failed at car ID {$carId} while handling: "
                        . $e->getMessage() . ' (rollback error: ' . $rollbackFailure->getMessage() . ')');
                }
                $failed[] = $carId;
                logger($ownerId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS,
                    "syncOwnerFieldsToCars: sync failed for car ID {$carId}: " . $e->getMessage());
            }
        }

        $result = new OwnerSyncResult($updated, $failed, $skipped);

        if ($result->updatedCount() > 0) {
            logger($ownerId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS,
                "Owner fields synchronized to {$result->updatedCount()} car(s)");
        }

        return $result;
    }

    /**
     * Check whether a car is currently owned by a given user.
     *
     * Used to disambiguate a zero-row UPDATE from CarRepository::updateCarForOwner():
     * a row present means the car simply had nothing to change, no row means it
     * left this owner.
     *
     * Deliberately does no logging of its own: it runs inside the caller's per-car
     * transaction, and logger() writes through the same connection, so any row it
     * inserted would be destroyed by the rollback that follows. The thrown
     * exception carries the full error string and is logged by the call sites'
     * handlers once the transaction has closed.
     *
     * syncOwnerFieldsToCars()'s entire skipped-vs-failed split rests on this
     * method throwing rather than returning false when the query itself fails —
     * that is what lets a genuine DB error surface as a real exception instead
     * of being silently absorbed into "skipped" as if it were an ownership
     * change (#1954). Never weaken this to catch its own errors and return
     * false; that would make a real failure invisible everywhere skips are
     * treated as non-errors, including places that never surface skips to the
     * user (e.g. usersc/user_settings.php).
     *
     * @param int $carId  Car to check
     * @param int $userId Owner the car must currently belong to
     * @return bool True if the car exists and belongs to this user
     * @throws OwnerDatabaseException If the query fails — the caller must not
     *         treat a failed lookup as proof the car changed hands
     */
    private function carBelongsToOwner(int $carId, int $userId): bool
    {
        $q = $this->_db->query(
            'SELECT id FROM cars WHERE id = ? AND user_id = ?',
            [$carId, $userId]
        );

        if ($this->_db->error()) {
            throw new OwnerDatabaseException(
                "Owner::carBelongsToOwner failed for carId={$carId} userId={$userId}: "
                . $this->_db->errorString()
            );
        }

        return $q->count() > 0;
    }

    /**
     * Validate required fields
     *
     * @param array $fields Fields to check
     * @param array $requiredFields List of required field names
     * @return void
     * @throws OwnerValidationException If required fields are missing
     */
    private function validateRequiredFields(array $fields, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($fields[$field]) || trim((string)$fields[$field]) === '') {
                throw OwnerValidationException::withUserMessage(
                    "Required field '{$field}' is missing or empty",
                    "Required field '{$field}' is missing or empty."
                );
            }
        }
    }

    /**
     * Validate and sanitize owner fields
     *
     * @param array $fields Fields to validate and sanitize
     * @param bool $requireAll Whether all validations are required (create) or optional (update)
     * @return array Validated and sanitized fields
     * @throws OwnerValidationException If validation fails
     */
    private function validateAndSanitizeFields(array $fields, bool $requireAll = true): array
    {
        $validatedFields = [];

        foreach ($fields as $key => $value) {
            switch ($key) {
                case 'fname':
                case 'lname':
                    if (!empty($value)) {
                        $validatedFields[$key] = InputSanitizer::normalize($value, 25);
                        if ($validatedFields[$key] === '') {
                            throw OwnerValidationException::withUserMessage(
                                "{$key} must be at least 1 character long",
                                'Name field must be at least 1 character long.'
                            );
                        }
                    } elseif ($requireAll) {
                        throw OwnerValidationException::withUserMessage(
                            "{$key} is required",
                            'A required name field is missing.'
                        );
                    }
                    break;

                case 'email':
                    if (!empty($value)) {
                        $email = filter_var(trim($value), FILTER_VALIDATE_EMAIL);
                        if ($email === false) {
                            throw OwnerValidationException::withUserMessage(
                                'Invalid email format',
                                'Invalid email format.'
                            );
                        }
                        $validatedFields[$key] = $email;
                    } elseif ($requireAll) {
                        throw OwnerValidationException::withUserMessage('Email is required', 'Email is required.');
                    }
                    break;

                case 'city':
                case 'state':
                case 'country':
                    if (!empty($value)) {
                        $validatedFields[$key] = InputSanitizer::normalize($value, 100);
                    }
                    break;

                case 'website':
                    if (!empty($value)) {
                        $trimmed = trim($value);
                        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
                            throw OwnerValidationException::withUserMessage(
                                'Website URL must start with http:// or https:// (e.g. https://example.com)',
                                'Website URL must start with http:// or https:// (e.g. https://example.com)'
                            );
                        }
                        $urlScheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
                        if (!in_array($urlScheme, ['http', 'https'], true)) {
                            throw OwnerValidationException::withUserMessage(
                                'Website URL must use http:// or https:// — other protocols are not allowed',
                                'Website URL must use http:// or https:// — other protocols are not allowed'
                            );
                        }
                        $validatedFields[$key] = $trimmed;
                    }
                    break;

                case 'password':
                    if (!empty($value)) {
                        // Basic password validation - UserSpice handles detailed requirements
                        if (strlen($value) < 6) {
                            throw OwnerValidationException::withUserMessage(
                                'Password must be at least 6 characters long',
                                'Password must be at least 6 characters long.'
                            );
                        }
                        $validatedFields[$key] = password_hash($value, PASSWORD_BCRYPT, ['cost' => 12]);
                    }
                    break;

                case 'lat':
                    // Explicit check — !empty() treats 0.0 as empty, silently dropping equator coordinates
                    if ($value !== null && $value !== '') {
                        if (!is_numeric($value) || abs((float) $value) > 90) {
                            throw OwnerValidationException::withUserMessage(
                                "Invalid lat coordinate value",
                                "Invalid coordinate value."
                            );
                        }
                        $validatedFields[$key] = (float) $value;
                    }
                    break;

                case 'lon':
                    // Explicit check — !empty() treats 0.0 as empty, silently dropping prime-meridian coordinates
                    if ($value !== null && $value !== '') {
                        if (!is_numeric($value) || abs((float) $value) > 180) {
                            throw OwnerValidationException::withUserMessage(
                                "Invalid lon coordinate value",
                                "Invalid coordinate value."
                            );
                        }
                        $validatedFields[$key] = (float) $value;
                    }
                    break;

                default:
                    // Unknown fields are silently dropped — security control to prevent
                    // privilege-escalating columns (active, permissions) from reaching the DB.
                    // To support a new field, add an explicit validated case above.
                    break;
            }
        }

        return $validatedFields;
    }

    /**
     * Extract user table fields from input array.
     *
     * Privilege-controlling columns (active, permissions) are intentionally
     * absent — they must never be writable via the general Owner::update() path.
     *
     * @param array $fields Input fields array
     * @return array Fields that belong to users table
     */
    private function extractUserFields(array $fields): array
    {
        $userFieldNames = ['fname', 'lname', 'email', 'password'];
        return array_intersect_key($fields, array_flip($userFieldNames));
    }

    /**
     * Extract profile table fields from input array
     *
     * @param array $fields Input fields array
     * @return array Fields that belong to profiles table
     */
    private function extractProfileFields(array $fields): array
    {
        $profileFieldNames = ['city', 'state', 'country', 'lat', 'lon', 'website'];
        return array_intersect_key($fields, array_flip($profileFieldNames));
    }

}
