<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use Exception;
use ElanRegistry\AppConstants;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarConcurrentModificationException;
use ElanRegistry\Exceptions\CarCreationException;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\LogCategories;

/**
 * Car is a facade class for managing Car data
 *
 * Delegates to focused service classes for validation, image processing,
 * database operations, verification, administration, and DataTables.
 * Administration methods (delete, transfer, merge) require an explicit
 * $actingUserId parameter (added v2.28.0) — callers are responsible for
 * ensuring the acting user is authenticated before invoking those methods.
 * All current callers enforce this via securePage()/requireAdminAjax()
 * before resolving $actingUserId from currentUserId() or $user->data()->id.
 *
 * @author Jim Boone
 * @version 2.15.0
 * @access public
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class Car
{
    private const CHASSIS_SUFFIX_LENGTH = 5;

    /**
     * Fields allowed to carry an explicit null through update() to clear
     * the column in the database. All other fields keep the default
     * strip-empty behavior.
     */
    private const CLEARABLE_FIELDS = [
        'color', 'engine', 'purchasedate', 'solddate', 'website', 'comments',
    ];

    private DatabaseInterface $_db;
    private ?object $_data = null;
    private array $_history = [];
    private ?array $_images = null;
    private ?object $_factory = null;
    private ?array $_owner = null;
    private string $imageDir = '';

    // Lazy-initialized service instances
    private ?CarValidator $validator = null;
    private ?CarImageProcessor $imageProcessor = null;
    private ?CarRepository $repository = null;
    private ?CarVerificationManager $verificationManager = null;
    private ?CarAdministrationService $administrationService = null;
    private ?CarDataTablesService $dataTablesService = null;

    /**
     * Instantiates the Car object.
     *
     * @param int|null $id Optional Car ID. If given, the information for Car will be populated.
     * @param DatabaseInterface|null $db Optional database instance for testing. Defaults to the shared dbi() handle.
     * @return void
     */
    public function __construct(?int $id = null, ?DatabaseInterface $db = null)
    {
        $this->_db = $db ?? dbi();

        if ($id) {
            $this->imageDir = ELAN_IMAGE_DIR . $id . '/';
            $this->find($id);
        }
    }

    // ============================================================
    // SERVICE ACCESSORS (lazy initialization)
    // ============================================================

    private function getValidator(): CarValidator
    {
        if ($this->validator === null) {
            $this->validator = new CarValidator();
        }
        return $this->validator;
    }

    private function getImageProcessor(): CarImageProcessor
    {
        if ($this->imageProcessor === null) {
            $this->imageProcessor = new CarImageProcessor($this->getRepository());
        }
        return $this->imageProcessor;
    }

    private function getRepository(): CarRepository
    {
        if ($this->repository === null) {
            $this->repository = new CarRepository($this->_db);
        }
        return $this->repository;
    }

    private function getVerificationManager(): CarVerificationManager
    {
        if ($this->verificationManager === null) {
            $this->verificationManager = new CarVerificationManager($this->getRepository());
        }
        return $this->verificationManager;
    }

    private function getAdministrationService(): CarAdministrationService
    {
        if ($this->administrationService === null) {
            $this->administrationService = new CarAdministrationService();
        }
        return $this->administrationService;
    }

    private function getDataTablesService(): CarDataTablesService
    {
        if ($this->dataTablesService === null) {
            $this->dataTablesService = new CarDataTablesService($this->_db);
        }
        return $this->dataTablesService;
    }

    // ============================================================
    // CRUD OPERATIONS
    // ============================================================

    /**
     * Creates a Car in the Database
     *
     * @param array $fields Key value pairs for car data
     * @return bool True if car is created
     * @throws Exception If validation fails or database operation fails
     */
    public function create(array $fields = []): bool
    {
        if (empty($fields)) {
            throw new CarCreationException('No data provided for car creation');
        }

        // CSRF is validated by the caller (HTTP layer, save.php) before
        // create() is called — see #1519. Strip a stray token key rather
        // than let it flow into CarValidator/insertCar() unchecked.
        unset($fields['token']);

        $this->getValidator()->validateRequiredFields($fields, ['chassis', 'model', 'year']);
        $fields = $this->getValidator()->validateAndSanitizeFields($fields);

        $fields['ctime'] = date(AppConstants::DATETIME_FORMAT);
        if (!empty($fields['images'])) {
            try {
                $fields['image'] = $this->getImageProcessor()->encodeImages($fields['images']);
                unset($fields['images']);
            } catch (\Throwable $e) {
                logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Image encoding error during create: " . $e->getMessage());
                throw new ImageProcessingException('Error processing car images: ' . $e->getMessage());
            }
        }

        $repo = $this->getRepository();
        if (!$repo->insertCar($fields)) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Car creation failed: ' . $repo->errorString());
            throw new CarCreationException('Database error during car creation: ' . $repo->errorString());
        }

        $id = $repo->lastId();
        if (!$this->find($id)) {
            throw new CarCreationException("Car ID {$id} not found after insert");
        }
        $this->imageDir = ELAN_IMAGE_DIR . $id . '/';
        $ownerId = (int) $this->data()->user_id;

        logger($ownerId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Car ID $id created and assigned to owner (user ID: $ownerId)");
        return true;
    }

    /**
     * Update an existing car record
     *
     * @param array $fields Car data to update
     * @param bool $isOwnerInitiated True when the update originates from an owner-facing
     *                               edit flow, which additionally refreshes the car's
     *                               owner_last_updated freshness timestamp. Defaults to
     *                               false so admin and internal callers are unaffected.
     * @return bool True if update succeeds
     * @throws Exception If validation fails or database operation fails
     */
    public function update(array $fields = [], bool $isOwnerInitiated = false): bool
    {
        if (empty($fields) || !isset($fields['id'])) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car update failed: No data or ID provided');
            throw new CarValidationException('No data or ID provided for car update');
        }

        // CSRF is validated by the caller (HTTP layer, save.php) before
        // update() is called — see #1519. Strip a stray token key rather
        // than let it flow into CarValidator/persistence unchecked.
        unset($fields['token']);

        if (!is_numeric($fields['id']) || $fields['id'] <= 0) {
            throw new CarValidationException('Invalid car ID provided for update');
        }

        // Validate and sanitize fields (excluding id)
        $fieldsToValidate = $fields;
        unset($fieldsToValidate['id']);
        if (!empty($fieldsToValidate)) {
            $validatedFields = $this->getValidator()->validateAndSanitizeFields($fieldsToValidate, false);
            $fields = array_merge(['id' => $fields['id']], $validatedFields);
        }

        $fields['mtime'] = date(AppConstants::DATETIME_FORMAT);
        if (!empty($fields['images'])) {
            try {
                $fields['image'] = $this->getImageProcessor()->encodeImages($fields['images']);
                unset($fields['images']);
            } catch (\Throwable $e) {
                logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Image encoding error during update: " . $e->getMessage());
                throw new ImageProcessingException('Error processing car images: ' . $e->getMessage());
            }
        }

        // Filter to valid car fields
        $validCarFields = [
            'id', 'user_id', 'year', 'model', 'series', 'variant', 'type',
            'chassis', 'chassis_override', 'color', 'engine', 'purchasedate', 'solddate',
            'website', 'comments', 'image', 'mtime',
            'email', 'fname', 'lname', 'join_date', 'city', 'state', 'country', 'lat', 'lon'
        ];
        $filteredFields = array_intersect_key($fields, array_flip($validCarFields));

        $carId = (int) $filteredFields['id'];
        unset($filteredFields['id']);

        $filteredFields = array_filter(
            $filteredFields,
            fn($value, $key) => in_array($key, self::CLEARABLE_FIELDS, true)
                || ($value !== '' && $value !== null),
            ARRAY_FILTER_USE_BOTH
        );

        // Fold into the single updateCar() call below rather than a separate
        // update, so a genuine owner edit produces exactly one UPDATE and one
        // cars_hist audit row instead of two.
        if ($isOwnerInitiated) {
            $filteredFields['owner_last_updated'] = date(AppConstants::DATETIME_FORMAT);
        }

        $repo = $this->getRepository();
        $updateResult = $repo->updateCar($carId, $filteredFields);

        if (!$updateResult) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Car update failed: query returned false');
            throw new CarDatabaseException('Database update failed - check logs for details');
        }

        if (!$this->find($carId)) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                "Car ID {$carId} updated successfully but reload via find() failed — in-memory state may be stale");
        }

        return true;
    }

    /**
     * Find car by ID
     *
     * @param int $carID Car ID to find
     * @return bool True if found, false otherwise
     * @throws CarDatabaseException If the underlying lookup query fails (propagated
     *                              from CarRepository::findById(); a car that simply
     *                              does not exist returns false rather than throwing)
     */
    public function find(int $carID): bool
    {
        global $us_url_root;
        global $abs_us_root;

        $repo = $this->getRepository();
        $data = $repo->findById($carID);

        if ($data === null) {
            return false;
        }

        $this->_data = $data;

        // Process images
        $this->_images = $this->getImageProcessor()->decodeAndProcessImages(
            $this->_data->image,
            $this->imageDir,
            $us_url_root ?? '',
            $abs_us_root ?? ''
        );

        // Get history — getHistory() now throws CarDatabaseException on a DB
        // failure (previously silently returned []). find() itself must not
        // start throwing for this subordinate lookup, so catch, log, and
        // continue with an empty history rather than fail the whole find().
        try {
            $this->_history = $repo->getHistory($carID);
        } catch (CarDatabaseException $e) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                "Car::find() getHistory failed for car {$carID}: " . $e->getMessage());
            $this->_history = [];
        }

        // Get factory info — same rationale as getHistory() above:
        // getFactoryInfo() now throws on DB failure (previously returned null).
        $this->_factory = null;
        if (!empty($this->_data->chassis)) {
            try {
                $factoryData = $repo->getFactoryInfo($this->_data->chassis, self::CHASSIS_SUFFIX_LENGTH);
                if ($factoryData !== null) {
                    if (!empty($factoryData->suffix)) {
                        $factoryData->suffix = $factoryData->suffix .
                            " (" . CarRepository::suffixToText($factoryData->suffix) . ")";
                    }
                    $this->_factory = $factoryData;
                }
            } catch (CarDatabaseException $e) {
                logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                    "Car::find() getFactoryInfo failed for car {$carID}: " . $e->getMessage());
            }
        }

        // Get owner info
        $this->_owner = [
            'user_id'   => $this->_data->user_id,
            'email'     => $this->_data->email,
            'fname'     => $this->_data->fname,
            'lname'     => $this->_data->lname,
            'join_date' => $this->_data->join_date,
            'city'      => $this->_data->city,
            'state'     => $this->_data->state,
            'country'   => $this->_data->country,
            'lat'       => $this->_data->lat,
            'lon'       => $this->_data->lon
        ];

        return true;
    }

    // ============================================================
    // DATA ACCESSORS
    // ============================================================

    /**
     * Check if car data exists
     *
     * @return bool True if car data exists
     */
    public function exists(): bool
    {
        return !empty($this->_data);
    }

    /**
     * Get car data
     *
     * @return ?object Car data object, or null if not loaded
     */
    public function data(): ?object
    {
        return $this->_data;
    }

    /**
     * Get car history
     *
     * @return array<object> Car history records (empty if not yet loaded or no records exist)
     */
    public function history(): array
    {
        return $this->_history;
    }

    /**
     * Get factory information for this car
     *
     * @return object|null Factory data object or null
     */
    public function factory(): ?object
    {
        return $this->_factory;
    }

    /**
     * Get car images
     *
     * @return array Array of image information
     */
    public function images(): array
    {
        return $this->_images ?? [];
    }

    /**
     * Get car owner information
     *
     * @return ?array Owner info array, or null if not loaded
     */
    public function owner(): ?array
    {
        return $this->_owner;
    }

    // ============================================================
    // IMAGE OPERATIONS
    // ============================================================

    /**
     * Remove an image from the car's image list
     *
     * @param string $filename Image filename to remove
     * @param bool $isOwnerInitiated True when the car's owner removes the photo. See CarImageProcessor::removeImage().
     * @return bool True if image was removed successfully, false otherwise
     * @throws CarNotFoundException If the car does not exist
     * @throws ImageProcessingException If the filename is empty or JSON encoding fails
     * @throws CarConcurrentModificationException If the image list changed concurrently
     * @throws CarDatabaseException If the database update query fails
     */
    public function removeImage(string $filename, bool $isOwnerInitiated = false): bool
    {
        if (!$this->exists()) {
            throw new CarNotFoundException('The requested car could not be found or may have already been removed.');
        }

        $result = $this->getImageProcessor()->removeImage($this->_data, $filename, $isOwnerInitiated);

        if ($result) {
            // Clear cached images to force reload
            $this->_images = null;
        }

        return $result;
    }

    /**
     * Remove multiple images from the car's image list in a single write.
     *
     * Unlike removeImage(), this does not throw on a CAS conflict — see
     * CarImageProcessor::removeImages() for details.
     *
     * @param array<int, string> $filenames Image filenames to remove
     * @return array{updated: bool, casConflict: bool} Result of the removal attempt
     * @throws CarNotFoundException If the car does not exist
     * @throws ImageProcessingException If encoding fails
     * @throws CarDatabaseException If database update fails
     */
    public function removeImages(array $filenames): array
    {
        if (!$this->exists()) {
            throw new CarNotFoundException('The requested car could not be found or may have already been removed.');
        }

        $result = $this->getImageProcessor()->removeImages($this->_data, $filenames);

        if ($result['updated']) {
            // Clear cached images to force reload
            $this->_images = null;
        }

        return $result;
    }

    // ============================================================
    // ADMINISTRATION OPERATIONS
    // ============================================================

    /**
     * Delete the car and all associated records
     *
     * This method does not validate CSRF itself — the caller MUST do so
     * before invoking delete() (see #1519, #1829; today's only caller,
     * admin/index.php, validates it unconditionally before reaching this
     * call).
     *
     * @param string $reason Reason for deletion (for audit trail)
     * @param int $actingUserId ID of the admin performing the action — caller MUST verify
     *                         the user is authenticated and authorized before invoking
     * @return bool True if deletion was successful
     * @throws Exception If validation fails or database operation fails
     */
    public function delete(string $reason, int $actingUserId): bool
    {
        if (!$this->exists()) {
            logger($actingUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, 'Car not found - cannot delete car ID: unknown');
            throw new CarNotFoundException('The car could not be found or may have already been removed.');
        }

        $this->getAdministrationService()->delete(
            $this->_data,
            $reason,
            $actingUserId,
            $this->getRepository()
        );

        // Clear local data since car no longer exists
        $this->_data = null;
        $this->_images = null;
        $this->_factory = null;
        $this->_owner = null;

        return true;
    }

    /**
     * Transfer car ownership to a different user
     *
     * @param int $newUserId The user ID to transfer ownership to
     * @param string $reason Reason for transfer (for audit trail)
     * @param string $operationType Operation type for history
     * @param int $actingUserId ID of the admin performing the action — caller MUST verify
     *                         the user is authenticated and authorized before invoking
     * @return true Always returns true; throws on any failure.
     * @throws CarNotFoundException If the car does not exist
     * @throws CarValidationException If the target user does not exist
     * @throws CarDatabaseException If a database operation fails
     * @throws OwnerDatabaseException If the target-user lookup itself fails
     *         due to a DB error, before the transaction begins — see
     *         CarAdministrationService::transfer()'s docblock
     */
    public function transfer(int $newUserId, string $reason, string $operationType, int $actingUserId): true
    {
        if (!$this->exists()) {
            logger($actingUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER, 'Car not found - cannot transfer car ID: unknown');
            throw new CarNotFoundException('The car could not be found for ownership transfer.');
        }

        return $this->getAdministrationService()->transfer(
            $this->_data,
            $newUserId,
            $reason,
            $operationType,
            $actingUserId,
            $this->getRepository(),
            $this->_db
        );
    }

    /**
     * Merge another car's history into this car and delete the old car
     *
     * @param int $oldCarId The car ID to merge into this car (will be deleted)
     * @param string $reason Reason for merge (for audit trail)
     * @param int $actingUserId ID of the admin performing the action — caller MUST verify
     *                         the user is authenticated and authorized before invoking
     * @return bool True if merge was successful
     * @throws Exception If validation fails or database operation fails
     */
    public function merge(int $oldCarId, string $reason, int $actingUserId): bool
    {
        if (!$this->exists()) {
            logger($actingUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Target car not found - cannot merge car ID: target');
            throw new CarNotFoundException('The car could not be found for merging.');
        }

        $result = $this->getAdministrationService()->merge(
            $this->_data,
            $oldCarId,
            $reason,
            $actingUserId,
            $this->getRepository()
        );

        // Clear cached history
        $this->_history = [];

        return $result;
    }

    // ============================================================
    // VERIFICATION OPERATIONS
    // ============================================================

    /**
     * Set verification code for the car
     *
     * @param string $verificationCode The verification code to set
     * @return bool True if verification code was set successfully
     * @throws Exception If validation fails or database operation fails
     */
    public function setVerificationCode(string $verificationCode): bool
    {
        if (!$this->exists()) {
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Car not found - cannot set verification code for ID: unknown');
            throw new CarNotFoundException('The car could not be found for verification.');
        }

        return $this->getVerificationManager()->setVerificationCode($this->_data, $verificationCode);
    }

    /**
     * Mark car as verified
     *
     * @return bool True if car was marked as verified successfully
     * @throws Exception If validation fails or database operation fails
     */
    public function markVerified(): bool
    {
        if (!$this->exists()) {
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Car not found - cannot mark as verified for ID: unknown');
            throw new CarNotFoundException('The car could not be found for verification.');
        }

        return $this->getVerificationManager()->markVerified($this->_data);
    }

    /**
     * Mark car as sold
     *
     * @param string|null $soldDate Optional sold date (defaults to current date)
     * @return bool True if car was marked as sold successfully
     * @throws Exception If validation fails or database operation fails
     */
    public function markSold(?string $soldDate = null): bool
    {
        if (!$this->exists()) {
            logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, 'Car not found - cannot mark as sold for ID: unknown');
            throw new CarNotFoundException('The car could not be found to mark as sold.');
        }

        return $this->getVerificationManager()->markSold($this->_data, $soldDate);
    }

    /**
     * Find a car by its verification code
     *
     * @param string $verificationCode The verification code to search for
     * @return Car|null Car object if found, null if not found
     * @throws Exception If database operation fails
     */
    public static function findByVerificationCode(string $verificationCode): ?Car
    {
        if (empty($verificationCode)) {
            return null;
        }

        try {
            $repo = new CarRepository(dbi());
            $carData = $repo->findByVerificationCode($verificationCode);

            if ($carData !== null) {
                $car = new Car((int) $carData->id);
                return $car->exists() ? $car : null;
            }
            return null;
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Unexpected error: ' . $e->getMessage());
            throw new CarDatabaseException('An unexpected error occurred. Please try again or contact support.');
        }
    }

    /**
     * Find all cars owned by a specific user
     *
     * @param int $ownerID User ID of the car owner
     * @return array Array of Car objects owned by the user
     * @throws CarValidationException If owner ID is invalid
     * @throws CarDatabaseException If the database query fails
     */
    public static function findByOwner(int $ownerID): array
    {
        if ($ownerID <= 0) {
            throw new CarValidationException('Invalid owner ID provided');
        }

        $repo = new CarRepository(dbi());
        $carResults = $repo->findByOwner($ownerID);
        $cars = [];

        foreach ($carResults as $key => $car) {
            $cars[$key] = new Car((int) $car->id);
        }

        return $cars;
    }

    // ============================================================
    // DATATABLES
    // ============================================================

    /**
     * Secure DataTables server-side processing for cars and factory tables
     *
     * @param array $request DataTables request parameters
     * @param string $table Table type ('cars' or 'factory')
     * @return array DataTables response array
     */
    public function getDataTablesData(array $request, string $table = 'cars'): array
    {
        return $this->getDataTablesService()->getDataTablesData($request, $table);
    }
}

// Backward compatibility: allow existing code to use bare 'Car' class name
if (!\class_exists('Car', false)) {
    \class_alias(\ElanRegistry\Car\Car::class, 'Car');
}
