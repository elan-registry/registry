<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use AppConstants;
use CarErrorMessages;
use DB;
use ElanRegistry\Exceptions\CarCreationException;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarPermissionException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ImageProcessingException;
use Exception;
use LogCategories;
use Token;

/**
 * Car is a facade class for managing Car data
 *
 * Delegates to focused service classes for validation, image processing,
 * database operations, verification, administration, and DataTables.
 * Maintains full backward compatibility with existing callers.
 *
 * @author Jim Boone
 * @version 2.15.0
 * @access public
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class Car
{
    private const CHASSIS_SUFFIX_LENGTH = 5;

    private DB $_db;
    /** @var mixed */
    private $_data;
    private ?array $_history = null;
    private ?array $_images = null;
    private ?object $_factory = null;
    /** @var array<string, mixed>|object|null */
    private $_owner;
    private string $tableName = 'cars';
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
     * @return void
     */
    public function __construct(?int $id = null)
    {
        $this->_db = DB::getInstance();

        if (function_exists('getSettings')) {
            $settings = getSettings();
        } else {
            $settingsQuery = $this->_db->query('SELECT * FROM settings WHERE id = ?', [1]);
            $settings = $settingsQuery->count() > 0 ? $settingsQuery->first() : null;
        }

        if ($id && $settings) {
            $this->imageDir = $settings->elan_image_dir . $id . '/';
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
            $this->imageProcessor = new CarImageProcessor();
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
            $this->verificationManager = new CarVerificationManager();
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
            $this->dataTablesService = new CarDataTablesService();
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
        $settings = getSettings();

        if (empty($fields)) {
            throw new CarCreationException('No data provided for car creation');
        }

        // CSRF Protection
        if (!isset($fields['token']) || !Token::check($fields['token'])) {
            throw new CarCreationException('Invalid CSRF token provided');
        }
        unset($fields['token']);

        $this->getValidator()->validateRequiredFields($fields, ['chassis', 'model', 'year']);
        $fields = $this->getValidator()->validateAndSanitizeFields($fields);

        $fields['ctime'] = date(AppConstants::DATETIME_FORMAT);
        if (!empty($fields['images'])) {
            try {
                $fields['image'] = $this->getImageProcessor()->encodeImages($fields['images']);
                unset($fields['images']);
            } catch (Exception $e) {
                logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Image encoding error during create: " . $e->getMessage());
                throw new ImageProcessingException('Error processing car images: ' . $e->getMessage());
            }
        }

        $repo = $this->getRepository();
        if (!$repo->insert($this->tableName, $fields)) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Car creation failed: ' . $repo->errorString());
            throw new CarCreationException('Database error during car creation: ' . $repo->errorString());
        }

        $id = $repo->lastId();
        $this->find($id);
        $this->imageDir = $settings->elan_image_dir . $id . '/';
        $ownerId = (int) $this->data()->user_id;

        if (!$repo->insertCarUser($ownerId, $id)) {
            logger($ownerId, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Car ID $id created but owner assignment (car_user) failed for user ID $ownerId. DB error: " . $repo->errorString());
            throw new CarCreationException('Car record was created but owner assignment failed. Please try again or contact the administrator.');
        }

        logger($ownerId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Car ID $id created and assigned to owner (user ID: $ownerId)");
        return true;
    }

    /**
     * Update an existing car record
     *
     * @param array $fields Car data to update
     * @return bool True if update succeeds
     * @throws Exception If validation fails or database operation fails
     */
    public function update(array $fields = []): bool
    {
        if (empty($fields) || !isset($fields['id'])) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car update failed: No data or ID provided');
            throw new CarValidationException('No data or ID provided for car update');
        }

        // CSRF Protection
        if (!isset($fields['token']) || !Token::check($fields['token'])) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car update failed: Invalid CSRF token');
            throw new CarValidationException('Invalid CSRF token provided');
        }
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
            } catch (Exception $e) {
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

        $filteredFields = array_filter($filteredFields, function ($value) {
            return $value !== '' && $value !== null;
        });

        $repo = $this->getRepository();
        $updateResult = $repo->update($this->tableName, $carId, $filteredFields);

        if (!$updateResult) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Car update failed: query returned false');
            throw new CarDatabaseException('Database update failed - check logs for details');
        }

        $this->find($carId);

        return true;
    }

    /**
     * Find car by ID or return all cars
     *
     * @param int|null $carID Car ID to find
     * @return bool True if found, false otherwise
     */
    public function find(?int $carID = null): bool
    {
        global $us_url_root;
        global $abs_us_root;

        if (is_null($carID)) {
            return $this->findAll();
        }

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

        // Get history
        $this->_history = $repo->getHistory($carID);

        // Get factory info
        $this->_factory = null;
        if (!is_null($this->_data->chassis) && !empty($this->_data->chassis)) {
            $factoryData = $repo->getFactoryInfo($this->_data->chassis, self::CHASSIS_SUFFIX_LENGTH);
            if ($factoryData !== null) {
                if (!is_null($factoryData->suffix) && $factoryData->suffix !== "") {
                    $factoryData->suffix = $factoryData->suffix .
                        " (" . FactoryDataFormatter::suffixToText($factoryData->suffix) . ")";
                }
                $this->_factory = $factoryData;
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

    /**
     * Find all cars
     *
     * @return bool Always returns true
     */
    public function findAll(): bool
    {
        $this->_data = $this->getRepository()->findAll();
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
     * @return mixed Car data object or array
     */
    public function data(): mixed
    {
        return $this->_data;
    }

    /**
     * Get car history
     *
     * @return array|null Car history array or null
     */
    public function history(): ?array
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
     * @return array|object Owner information
     */
    public function owner(): array|object
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
     * @return bool True if image was removed successfully, false otherwise
     * @throws Exception If validation fails or database operation fails
     */
    public function removeImage(string $filename): bool
    {
        if (!$this->exists()) {
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found'));
        }

        $result = $this->getImageProcessor()->removeImage($this->_data, $filename, $this->_db);

        if ($result) {
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
     * @param string $reason Reason for deletion (for audit trail)
     * @param string $token CSRF token (required)
     * @return bool True if deletion was successful
     * @throws Exception If validation fails or database operation fails
     */
    public function delete(string $reason, string $token): bool
    {
        global $user;

        if (!Token::check($token)) {
            throw new CarDeletionException(CarErrorMessages::getMessage('csrf_token_invalid', 'admin', ['operation' => 'car deletion']));
        }

        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_delete', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_DELETION, $technicalMsg);
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found_delete'));
        }

        if (!isset($user) || !$user->isLoggedIn()) {
            throw new CarDeletionException(CarErrorMessages::getMessage('user_auth_required', 'admin', ['operation' => 'car deletion']));
        }

        $this->getAdministrationService()->delete(
            $this->_data,
            $reason,
            currentUserId(),
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
     * @return bool True if transfer was successful
     * @throws Exception If validation fails or database operation fails
     */
    public function transfer(int $newUserId, string $reason = 'Administrative transfer', string $operationType = 'NEWOWNER'): bool
    {
        global $user;

        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_transfer', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found_transfer'));
        }

        if (!isset($user) || !$user->isLoggedIn()) {
            throw new CarPermissionException(CarErrorMessages::getMessage('user_auth_required', 'admin', ['operation' => 'car transfer']));
        }

        $self = $this;

        $result = $this->getAdministrationService()->transfer(
            $this->_data,
            $newUserId,
            $reason,
            $operationType,
            currentUserId(),
            $this->getRepository(),
            function (array $fields) use ($self): bool {
                return $self->update($fields);
            },
            function (int $id) use ($self): object {
                $self->find($id);
                return $self->data();
            }
        );

        return $result;
    }

    /**
     * Merge another car's history into this car and delete the old car
     *
     * @param int $oldCarId The car ID to merge into this car (will be deleted)
     * @param string $reason Reason for merge (for audit trail)
     * @return bool True if merge was successful
     * @throws Exception If validation fails or database operation fails
     */
    public function merge(int $oldCarId, string $reason = 'Administrative merge'): bool
    {
        global $user;

        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_merge', ['id' => 'target']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found_merge'));
        }

        if (!isset($user) || !$user->isLoggedIn()) {
            throw new CarPermissionException(CarErrorMessages::getMessage('user_auth_required', 'admin', ['operation' => 'car merge']));
        }

        $result = $this->getAdministrationService()->merge(
            $this->_data,
            $oldCarId,
            $reason,
            currentUserId(),
            $this->getRepository()
        );

        // Clear cached history
        $this->_history = null;

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
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_verification', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found_verification'));
        }

        return $this->getVerificationManager()->setVerificationCode($this->_data, $verificationCode, $this->_db);
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
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_verify', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found_verify'));
        }

        return $this->getVerificationManager()->markVerified($this->_data, $this->_db);
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
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_sold', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, $technicalMsg);
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found_sold'));
        }

        return $this->getVerificationManager()->markSold($this->_data, $soldDate, $this->_db);
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
            $db = DB::getInstance();
            $repo = new CarRepository($db);
            $carData = $repo->findByVerificationCode($verificationCode);

            if ($carData !== null) {
                $car = new Car((int) $carData->id);
                return $car->exists() ? $car : null;
            }
            return null;
        } catch (Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('unexpected_error', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('unexpected_error'));
        }
    }

    /**
     * Find all cars owned by a specific user
     *
     * @param int $ownerID User ID of the car owner
     * @return array Array of Car objects owned by the user
     * @throws CarValidationException If owner ID is invalid
     */
    public static function findByOwner(int $ownerID): array
    {
        if ($ownerID <= 0) {
            throw new CarValidationException('Invalid owner ID provided');
        }

        $db = DB::getInstance();
        $repo = new CarRepository($db);
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
        return $this->getDataTablesService()->getDataTablesData($request, $table, $this->_db);
    }
}

// Backward compatibility: allow existing code to use bare 'Car' class name
if (!\class_exists(\Car::class, false)) {
    \class_alias(Car::class, \Car::class);
}
