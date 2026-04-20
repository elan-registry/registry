# Class Documentation

Complete reference for all custom classes in the Elan Registry application.

## Overview

All custom classes are located in `/usersc/classes/` (application classes) and
`/app/admin/includes/classes/` (admin-specific classes). Exception classes use
the `ElanRegistry\Exceptions` namespace and are located in
`/usersc/classes/Exceptions/`. Classes follow established design patterns with
consistent database integration, exception handling, and audit logging.

## Quick Class Selection Guide

Use this table to choose the right class for your task:

| Task | Use This Class | Why | Example |
| --- | --- | --- | --- |
| Load car by ID and get all data | Car | Direct database access, validation, history tracking | `$car = new Car(123)` |
| Display cars in a list view | CarView | Read-only, optimized for rendering, no mutations | `$view = new CarView()` → `$view->getAllCars()` |
| Update car data and create history | Car + update() | Automatic history via triggers, audit logging | `$car->update(['color' => 'Blue', ...])` |
| Access owner profile and user data | ElanRegistryOwner | User profile integration, custom user methods | `$owner = new ElanRegistryOwner($uid)` |
| Validate VIN/chassis format | ChassisValidator | Specialized validation for vehicle identifiers | `$validator->validate('26/0001')` |
| Create database backups | BackupManager | Backup/restore operations, database dumping | `$backup = new BackupManager(...)` |
| Convert markdown to HTML | MarkdownParser | Documentation rendering, safe HTML conversion | `$parser = new MarkdownParser(...)` |
| Get car images | CarImage | Image metadata and associations | `$images = CarImage::getByCarId($carId)` |
| Query car models by year/series | CarModel | Reference data for model filtering | `$models = (new CarModel())->getAvailableInYear(1970)` |

---

## Class Organization Patterns

### Namespaces

The Elan Registry uses namespaces to organize classes by their architectural role:

| Namespace | Purpose | Location | Examples |
| --- | --- | --- | --- |
| **(root)** | Entity classes (domain objects) | `/usersc/classes/` | Car, ElanRegistryOwner |
| `ElanRegistry\Exceptions` | Custom exception types | `/usersc/classes/Exceptions/` | CarNotFoundException, CarValidationException |
| `ElanRegistry\Reference` | **External reference data** | `/usersc/classes/ElanRegistry/Reference/` | CarModel, FactoryColor |

### Reference Data vs. Entity Classes

**Reference Data Classes** (`ElanRegistry\Reference`):

- Represent **external/canonical facts** about cars from Lotus (factory data, official colors, model specifications)
- **Read-only** - no create/update/delete operations
- Static query methods only
- Examples: CarModel (model types), FactoryColor (official colors), FactoryInfo (production specs)

**Entity Classes** (root namespace):

- Represent **registry records** (individual car registrations, owner profiles)
- **Full CRUD operations** - create, read, update, delete
- Instance methods and properties
- Examples: Car (individual registered car), ElanRegistryOwner (owner profile)

**Quick Decision Guide**:

- Does this represent data from an external authoritative source? → Reference class
- Does this represent a record in the registry database? → Entity class
- Does this need CRUD operations? → Entity class
- Is it lookup/metadata only? → Reference class

---

## Core Domain Classes

### Car

**Location**: `/usersc/classes/Car.php`

**Purpose**: Manages car records with full CRUD operations, history tracking,
and audit trails.

**Key Features**:

- Complete car lifecycle management (create, read, update, delete)
- Automatic history tracking via database triggers
- Image management integration
- Factory data association
- Owner relationship management
- Comprehensive validation and error handling

**Common Usage**:

```php
// Create new car
$car = new Car();
$carId = $car->create([
    'chassis' => '26/0001',
    'model_name' => 'S2',
    'body_style' => 'DHC',
    'body_color' => 'Red',
    'user_id' => $userId,
    'csrf' => Token::generate()
]);

// Load existing car
$car = new Car($carId);
$carData = $car->data();

// Update car
$car->update([
    'id' => $carId,
    'body_color' => 'Blue',
    'csrf' => Token::generate()
]);

// Delete car (soft delete with audit trail)
$car->delete($userId);
```

**Database Tables**:

- `cars` - Primary car data
- `cars_hist` - Audit trail (populated by triggers)
- `car_images` - Associated images
- `elan_factory_info` - Factory build information

**Constants**:

- `CHASSIS_SUFFIX_LENGTH` - Length of chassis suffix for factory lookup
- `DATETIME_FORMAT` - Standard datetime format (`Y-m-d G:i:s`)
- `SQL_START_TRANSACTION`, `SQL_COMMIT`, `SQL_ROLLBACK` - Transaction SQL
- `OPERATION_DELETE`, `OPERATION_MERGE` - History operation names

**Exception Handling** (all extend `CarException`):

All exception classes are in the `ElanRegistry\Exceptions` namespace:

- `CarNotFoundException` - Car ID not found (404)
- `CarValidationException` - Invalid car data (422)
- `CarDatabaseException` - Database operation failures (500)
- `CarPermissionException` - Permission/auth denied (403)
- `CarCreationException` - Car creation failures (500)
- `CarDeletionException` - Car deletion failures (500)
- `CarMergeException` - Car merge failures (500)
- `CarTransferException` - Ownership transfer failures (500)

**When to Use Which Exception**:

| Situation | Exception Class | Example |
| --- | --- | --- |
| Car ID not found in database | CarNotFoundException | User tries to edit car that was deleted |
| Validation of user input fails | CarValidationException | Invalid chassis format, missing required field |
| Database query/operation fails | CarDatabaseException | INSERT fails, UPDATE fails, deadlock |
| User lacks permission | CarPermissionException | Non-owner trying to edit someone else's car |
| Car creation fails | CarCreationException | Cannot create car due to validation or database issue |
| Car deletion fails | CarDeletionException | Cannot delete car, foreign key constraint |
| Car merge operation fails | CarMergeException | Cannot merge duplicate cars |
| Car transfer fails | CarTransferException | Cannot transfer ownership |

**Usage**:

```php
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;

try {
    $car = new Car($carId);
} catch (CarNotFoundException $e) {
    // Handle not found - return 404
    return response()->notFound('Car not found');
} catch (CarValidationException $e) {
    // Handle validation error - return 422
    return response()->error('Invalid car data: ' . $e->getMessage());
}
```

### CarView

**Location**: `/usersc/classes/CarView.php`

**Purpose**: Static utility class for car display, image processing, and HTML
generation.

**Key Features**:

- Responsive image loading with size optimization
- Bootstrap carousel generation
- Car detail display formatting
- Thumbnail generation
- Image path resolution
- No database operations (view layer only)

**Common Usage**:

```php
// Display car image
CarView::loadCarPic($imageData, true); // true = thumbnail

// Generate image carousel
$carouselId = rand(1000, 9999);
CarView::generateCarousel($images, $carouselId);

// Display car specifications
CarView::displayCarSpecs($carData);
```

**Design Notes**:

- Follows MVC pattern separation (view layer only)
- Uses constants to avoid magic numbers
- Static methods for stateless operations
- Integrates with Resize class for image processing

### ElanRegistryOwner

**Location**: `/usersc/classes/ElanRegistryOwner.php`

**Purpose**: Manages owner/user data with clean separation between UserSpice
authentication and ElanRegistry business logic.

**Key Features**:

- Owner profile management
- Location data with geocoding integration
- Profile quality scoring
- Owner search functionality
- Integration with UserSpice user system
- Combines `users` and `profiles` table data

**Common Usage**:

```php
// Load owner
$owner = new ElanRegistryOwner($userId);
$ownerData = $owner->data();

// Update owner profile
$owner->update([
    'id' => $userId,
    'city' => 'Portland',
    'state' => 'Oregon',
    'country' => 'United States',
    'csrf' => Token::generate()
]);
// Note: Coordinates auto-populated via geocoding

// Get profile quality score
$score = $owner->getProfileQualityScore(); // Returns 0-100

// Search owners (admin function)
$results = ElanRegistryOwner::searchOwners('Portland');
```

**Database Tables**:

- `users` - UserSpice user authentication data
- `profiles` - Extended user profile information

**Integration**:

- Works with `getUserWithProfile($userId)` custom function
- Provides `geocodeAddress()` static method for location geocoding
- Used in admin consolidated management interface

### LocationGeocoder

> **INTERNAL USE ONLY** — Do not use directly.

**Location**: `/usersc/classes/LocationGeocoder.php`

**Purpose**: Internal implementation class for geocoding. This class is an
implementation detail of ElanRegistryOwner and should NEVER be instantiated
directly.

**Why this restriction**: LocationGeocoder is encapsulated within
ElanRegistryOwner to provide a clean API and allow future implementation
changes (caching, provider switching) without affecting calling code.

**Runtime Protection**: Attempting to instantiate LocationGeocoder directly
will throw a `GeocodingException` with a clear error message directing you
to use `ElanRegistryOwner::geocodeAddress()` instead.

**❌ WRONG - DO NOT DO THIS:**

```php
// NEVER instantiate LocationGeocoder directly - will throw exception!
$geocoder = new LocationGeocoder($apiKey);  // ❌ Runtime error!
```

**✅ CORRECT - Use Public API:**

```php
// Forward geocoding (address → coordinates)
$result = ElanRegistryOwner::geocodeAddress('Portland', 'Oregon', 'United States');
if (!empty($result)) {
    $lat = $result['lat'];  // 45.5152
    $lon = $result['lon'];  // -122.6784
}

// For reverse geocoding, use LocationGeocoder methods via ElanRegistryOwner
// (Future enhancement for Issue #245)
```

**Internal Methods** (via ElanRegistryOwner only):

- `geocode(string $city, string $state, string $country): ?array` - Forward geocoding
- `reverseGeocode(float $lat, float $lon): ?array` - Reverse geocoding (Issue #245)

**Error Handling:**

- Throws `GeocodingException` if instantiated outside ElanRegistryOwner (architectural enforcement)
- Returns `null` on all error conditions (API failures, network issues, invalid data)
- Logs all errors via UserSpice logger with 'Geocode' category
- Validates input parameters and coordinates

**Features:**

- Configurable timeout (default: 10 seconds)
- Configurable coordinate precision (default: 4 decimal places ≈ 11m accuracy)
- cURL with file_get_contents fallback
- Comprehensive error logging
- SSL verification and proper HTTP headers

**Integration:**

- **Internal use only** - instantiated only by `ElanRegistryOwner::geocodeAddress()`
- Not used directly by application code
- Pure implementation detail hidden behind ElanRegistryOwner API

**Public API:**

- `ElanRegistryOwner::geocodeAddress($city, $state, $country)` - Use this method for all geocoding needs

### CarValidator

**Location**: `/usersc/classes/Car/CarValidator.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Provides focused, testable validation and sanitization logic for all car data fields. Extracted from Car class to enable independent testing and reuse.

**Key Features**:

- Field-by-field validation with type coercion
- Automatic input sanitization (HTML stripping, trimming, truncation)
- Model format and existence validation via CarModel
- Date format validation (YYYY-MM-DD)
- Email and URL validation
- Coordinate validation (latitude/longitude)
- Flexible required/optional field handling
- Consistent error reporting via CarValidationException

**Common Usage**:

```php
use ElanRegistry\Car\CarValidator;

$validator = new CarValidator();

// Validate all required fields (create mode)
try {
    $clean = $validator->validateAndSanitizeFields([
        'chassis' => '26/0001',
        'model' => 'S4|FHC|36',
        'year' => '1970',
        'color' => 'Red',
        'email' => 'owner@example.com'
    ], true); // requireAll = true

    // All fields validated and sanitized
} catch (CarValidationException $e) {
    echo "Validation failed: " . $e->getMessage();
}

// Validate optional fields (update mode)
$clean = $validator->validateAndSanitizeFields([
    'color' => 'Blue'  // Only updating color
], false); // requireAll = false
```

**Validation Rules**:

| Field | Rule | Example |
| --- | --- | --- |
| `chassis` | 3-50 chars, sanitized | `26/0001` |
| `model` | Format: `series\|variant\|type`, must exist in car_models | `S4\|FHC\|36` |
| `year` | 1963-1974 inclusive | `1970` |
| `email` | Valid email format | `user@example.com` |
| `website` | Valid URL format | `https://example.com` |
| `purchasedate` / `solddate` | YYYY-MM-DD format | `2023-06-15` |
| `lat` / `lon` | -180 to +180 range | `51.5` |
| `color`, `engine`, `series`, `variant`, `type` | 1-100 chars, sanitized | `Red` |
| `comments` | 1-5000 chars, sanitized | `Well maintained` |

**Model Validation** (Phase 2):

As of Phase 2, model validation includes both format and existence checks:

```php
// Format validation: "series|variant|type"
if (format is invalid) throw CarValidationException('Invalid model format...');

// Existence validation: Check car_models table
if (!CarModel::exists($series, $variant, $type)) {
    throw CarValidationException("Invalid model combination: ...");
}
```

**Methods**:

- `validateAndSanitizeFields(array $fields, bool $requireAll): array` - Main validation method
- `validateRequiredFields(array $fields, array $required): void` - Check required fields are present
- `sanitizeString(string $input, int $maxLength): string` - HTML strip and truncate

**Exceptions**:

- `CarValidationException` - Validation failure (422 Bad Request)

**Used By**:

- Car class (create/update operations)
- edit.php AJAX endpoint
- Integration tests for car operations

**See Also**:

- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Exception patterns
- CarModel reference class for model validation

---

### ChassisValidator

**Location**: `/usersc/classes/ChassisValidator.php`

**Purpose**: Validates Lotus Elan chassis numbers for all production and race
car formats (1963-1974).

**Key Features**:

- Comprehensive format validation for all Elan models
- Support for historical race car formats
- Detailed error messages for invalid formats
- Format type detection
- Prefix and suffix validation

**Common Usage**:

```php
// Validate chassis number
$validator = new ChassisValidator();
$result = $validator->validate('26/0001');

if ($result['valid']) {
    echo "Valid: " . $result['chassis'];
    echo "Format: " . $result['format_type'];
} else {
    echo "Invalid: " . $result['error_reason'];
}
```

**Supported Formats**:

- Series 1/2/3/4 production cars
- Sprint models
- Plus 2 models
- Historical race cars (special formats)

**Validation Results**:

```php
[
    'valid' => true/false,
    'chassis' => 'normalized chassis number',
    'error_reason' => 'error message if invalid',
    'format_type' => 'detected format type'
]
```

## Support Classes

### BackupManager

**Location**: `/app/admin/includes/classes/BackupManager.php`

Database backup management with retention policies, schema operation integration, and environment-aware cleanup. Throws `BackupException` on failures.

**See [BACKUP_SYSTEM.md](BACKUP_SYSTEM.md)** for complete API reference, usage examples, and retention policies.

### Resize

**Location**: `/usersc/classes/Resize.php`

**Purpose**: Image processing with EXIF orientation correction and metadata
removal for privacy.

**Key Features**:

- Automatic EXIF orientation correction
- Privacy-preserving metadata removal
- Configurable resize dimensions
- Maintains aspect ratio
- Multiple output format support (JPEG, PNG, GIF)
- Quality control for JPEG output

**Common Usage**:

```php
// Resize image
$resize = new Resize($imagePath);
$resize->resizeImage(800, 600, 'auto'); // width, height, crop type
$resize->saveImage($outputPath, 85); // quality 85

// Create thumbnail
$resize = new Resize($imagePath);
$resize->resizeImage(300, 300, 'crop');
$resize->saveImage($thumbnailPath);
```

**Crop Types**:

- `auto` - Maintains aspect ratio
- `crop` - Crops to exact dimensions
- `exact` - Forces exact dimensions

**Privacy Note**:

- Automatically strips EXIF metadata (GPS, camera info, etc.)
- Preserves only essential image data

### EmailTemplate

**Location**: `/usersc/classes/EmailTemplate.php`

**Purpose**: Centralized email template system with branded HTML formatting.

**Key Features**:

- Consistent branded email design
- Responsive HTML email layout
- Header/footer management
- Action button generation
- Multi-section content support
- Registry branding integration

**Common Usage**:

```php
// Create email template
$template = new EmailTemplate();

// Build email with sections
$html = $template->buildEmail(
    'Transfer Request',
    [
        [
            'title' => 'Transfer Details',
            'content' => 'Car #26/0001 has been transferred...'
        ],
        [
            'title' => 'Next Steps',
            'content' => 'Please review and approve...'
        ]
    ],
    [
        'text' => 'View Transfer Request',
        'url' => 'https://elanregistry.org/app/transfers/view.php?id=123'
    ]
);

// Send email
// ... use with PHPMailer or other email system
```

**Email Structure**:

- Branded header with logo
- Multiple content sections with titles
- Optional action button
- Footer with registry information
- Responsive design for mobile devices

### registrySendEmail()

**Location**: `/usersc/includes/custom_functions.php`

**Purpose**: Registry-specific email sender that sets the To: display name on
both transport paths (Brevo and PHPMailer/SMTP). The UserSpice base `email()`
function does not expose recipient name to `addAddress()`, which raises spam
scores. Use this wrapper instead of `email()` when sending registry emails that
need a named recipient.

**Signature**:

```php
function registrySendEmail(
    string $to,
    string $toName,
    string $subject,
    string $body,
    array $opts = []
): mixed
```

**Parameters**:

| Parameter | Description |
| --------- | ----------- |
| `$to` | Recipient email address |
| `$toName` | Recipient display name (used in `To:` header) |
| `$subject` | Email subject line |
| `$body` | HTML email body |
| `$opts` | Optional: `['reply' => '...']` or `['replyTo' => '...']` |

**Returns**: `true` on success; error string (Brevo) or `false` (PHPMailer) on failure.

**Example**:

```php
$body = email_body('_email_contact_owner.php', $options);
$result = registrySendEmail(
    $ownerEmail,
    $ownerName,
    '[ELANREGISTRY] You have a message',
    $body,
    ['replyTo' => $senderEmail]
);
if ($result !== true) {
    // log failure
}
```

**Transport Paths**:

- **Brevo** (when `sendinblue()` exists): delegates to `sendinblue($to, $subject, $body, $toName, $opts)` — the 4th argument is the display name
- **PHPMailer/SMTP**: constructs the message directly with `$mail->addAddress($to, $toName)`

> **Note**: A known upstream bug in the Brevo plugin's `override.php` passes `""` as `$to_name`
> to the Brevo API. See issue #601 and the TODO comment in `custom_functions.php` for what to
> review when #601 is resolved.

### MarkdownParser

**Location**: `/usersc/classes/MarkdownParser.php`

**Namespace**: `ElanRegistry\Documentation`

**Purpose**: Markdown to HTML converter for documentation rendering.

**Key Features**:

- Lightweight markdown parsing
- Security-focused HTML generation
- XSS protection
- Support for headers, lists, code blocks, links, emphasis
- Table of contents generation
- Anchor link creation

**Common Usage**:

```php
use ElanRegistry\Documentation\MarkdownParser;

// Parse markdown to HTML
$parser = new MarkdownParser();
$html = $parser->parse($markdownContent);

// Used by documentation viewer
// See /docs/view.php for integration example
```

**Supported Markdown**:

- Headers (H1-H6)
- Bold and italic text
- Links and images
- Code blocks (fenced and indented)
- Ordered and unordered lists
- Blockquotes
- Horizontal rules

**Security**:

- All output is HTML-escaped by default
- Safe handling of user-generated content
- Prevents XSS attacks

### DocumentConfig

**Location**: `/usersc/classes/DocumentConfig.php`

**Namespace**: `ElanRegistry\Documentation`

**Purpose**: Document metadata and access control configuration for the unified
documentation system.

**Key Features**:

- Document categorization
- Access control rules
- Metadata management
- Breadcrumb configuration
- Public vs admin document separation

**Common Usage**:

```php
use ElanRegistry\Documentation\DocumentConfig;

// Get document metadata
$config = new DocumentConfig();
$metadata = $config->getDocumentMetadata('CAR_TRANSFER_USER_GUIDE');

// Check access permissions
$isPublic = $config->isPublicDocument('CAR_TRANSFER_FAQ');

// Get category information
$category = $config->getCategory('user-guides');
```

**Document Categories**:

- `user-guides` - End-user documentation (public)
- `admin-guides` - Administrator documentation (admin only)
- `faq` - Frequently asked questions (public)
- `technical` - Technical documentation (admin only)

**Access Control**:

- Documents in `/docs/faq/` - Public access
- Documents in `/docs/faq/admin/` - Admin only
- Document viewer enforces access rules

## Design Patterns

### Database Integration Pattern

All domain classes use the UserSpice database singleton:

```php
class MyClass {
    private $_db;

    public function __construct() {
        $this->_db = DB::getInstance();
    }

    public function query() {
        return $this->_db->query(
            "SELECT * FROM table WHERE id = ?",
            [$id]
        )->results();
    }
}
```

### Exception Handling Pattern

Custom exceptions in `/usersc/classes/Exceptions/` with `ElanRegistry\Exceptions` namespace:

```php
// Import exception at top of file
use ElanRegistry\Exceptions\MyCustomException;

// Use in class
try {
    if (!$valid) {
        throw new MyCustomException('Invalid data');
    }
} catch (MyCustomException $e) {
    logger($userId, 'ErrorCategory', $e->getMessage());
    throw $e;
}
```

### Audit Logging Pattern

All significant operations use the UserSpice logger:

```php
logger(
    $user->data()->id ?? 0,
    'Category',
    'Descriptive message: ' . $details
);
```

**Common Categories**:

- `SystemError` - System-level failures
- `ValidationError` - Input validation failures
- `DatabaseError` - Database operation failures
- `CarErrors` - Car-related errors
- `CarActions` - Car-related user operations
- `DatabaseMaintenance` - Maintenance operations

### Naming Conventions

- **Classes**: PascalCase with descriptive business domain names
  - Examples: `Car`, `ElanRegistryOwner`, `ChassisValidator`
- **Methods**: camelCase with verb-first naming
  - Examples: `getData()`, `updateRecord()`, `validateInput()`
- **Private properties**: Underscore prefix
  - Examples: `$_db`, `$_data`, `$_userId`
- **Constants**: UPPER_SNAKE_CASE
  - Examples: `THUMBNAIL_SIZE`, `MAX_UPLOAD_SIZE`

### CRUD Operation Pattern

Standard pattern for data management classes:

```php
class MyDomainClass {
    private $_db;
    private $_data;

    // Load existing record
    public function __construct(?int $id = null) {
        $this->_db = DB::getInstance();
        if ($id) {
            $this->find($id);
        }
    }

    // Find by ID
    public function find(int $id): bool {
        $data = $this->_db->query("SELECT * FROM table WHERE id = ?", [$id]);
        if ($data->count()) {
            $this->_data = $data->first();
            return true;
        }
        return false;
    }

    // Create new record
    public function create(array $fields): int {
        // Validation
        // CSRF check
        // Database insert
        // Audit logging
        return $insertId;
    }

    // Update existing record
    public function update(array $fields): bool {
        // Validation
        // CSRF check
        // Database update
        // Audit logging
        return true;
    }

    // Get data
    public function data(): ?object {
        return $this->_data ?? null;
    }
}
```

## Integration Patterns

### UserSpice Integration

**Custom Functions**: `/usersc/includes/custom_functions.php`

```php
// Combined user + profile data
$owner = getUserWithProfile($userId);
```

**UserSpice Classes**:

- `User` - Authentication and session management
- `Token` - CSRF token generation/validation
- `DB` - Database singleton

### Geocoding Integration (Deprecated)

**NOTE**: As of v2.11.0, geocoding is handled by the `LocationGeocoder` class. See LocationGeocoder section above.

**Legacy Information** (for reference only):
The previous implementation used a procedural include pattern via `/app/views/_geolocate.php`, which has been removed in favor of the OOP approach.

**Current Usage**:

```php
// Use ElanRegistryOwner static method for all geocoding
$result = ElanRegistryOwner::geocodeAddress($city, $state, $country);

// Check for successful geocoding
if (!empty($result)) {
    $lat = $result['lat'];
    $lon = $result['lon'];
}
```

### Message Handling

**Modern Session-Based Messages**:

```php
// Set messages
if (!empty($errors)) {
    foreach ($errors as $error) {
        usError($error);
    }
}

if (!empty($successes)) {
    foreach ($successes as $success) {
        usSuccess($success);
    }
}

// Display messages (in view)
sessionValMessages($errors, $successes, null);
```

## Class Relationships

```text
Car
├── Uses: DB (singleton)
├── Uses: CarView (for display)
├── Uses: Resize (for images)
├── Related: ElanRegistryOwner (via user_id)
└── Uses: ChassisValidator (for validation)

ElanRegistryOwner
├── Uses: DB (singleton)
├── Related: Car (via user_id)
└── Integrates: getUserWithProfile()

CarView
├── Uses: Resize (for image processing)
└── Used by: Car, various views

BackupManager
├── Uses: DB
└── Throws: BackupException

EmailTemplate
└── Used by: Transfer requests, notifications

MarkdownParser
└── Used by: DocumentConfig, docs/view.php

DocumentConfig
├── Uses: MarkdownParser
└── Used by: docs/view.php
```

## Testing

All classes should have corresponding PHPUnit tests in `/tests/`:

- `/tests/Unit/` - Unit tests for individual methods
- `/tests/Integration/` - Integration tests with database
- `/tests/Regression/` - Issue-specific regression tests

**Run tests**:

```bash
composer test:unit         # Unit tests only
composer test:integration  # Integration tests
composer test:quick        # Fast subset (<30s)
```

## Reference Data Classes

Classes in the `ElanRegistry\Reference` namespace provide access to external/canonical data about Lotus Elan models, factory colors, and production specifications.

### CarModel

**Location**: `/usersc/classes/ElanRegistry/Reference/CarModel.php`

**Namespace**: `ElanRegistry\Reference`

**Purpose**: Query car model reference data from `car_models` table. Provides access to model definitions, year ranges, series/variant combinations.

**Key Features**:

- Query models by production year
- Filter by series (S1, S2, S3, S4, Sprint, +2)
- Validate model combinations
- Get year availability ranges
- Support for color filtering (via series_normalized)

**Common Usage**:

```php
use ElanRegistry\Reference\CarModel;

$carModel = new CarModel();

// Get all models available in 1970
$models = $carModel->getAvailableInYear(1970);

// Get all S4 models (across all years)
$s4Models = $carModel->getBySeries('S4');

// Get model by pipe-delimited value
$model = $carModel->byValue('S4|FHC|36');
if ($model) {
    echo $model->human_readable_short; // "Coupe S4"
}

// Get unique series in 1973
$series = $carModel->getSeriesInYear(1973); // ["S4", "Sprint", "+2S/130"]

// Validate model exists
if ($carModel->exists('S4', 'FHC', '36')) {
    // Valid model combination
}
```

**Methods**:

- `getAvailableInYear(int $year): array<object>` - Models for specific year
- `getBySeries(string $series): array<object>` - All models with series
- `byValue(string $modelValue): ?object` - Get by "series|variant|type"
- `getSeriesInYear(int $year): array<string>` - Unique series in year
- `groupByYear(): array<int, array<object>>` - Models grouped by year
- `getAll(): array<object>` - All models (admin/reference)
- `exists(string $series, string $variant, string $typeCode): bool` - Validate combination

**Database Table**: `car_models`

**Used By**:

- Issue #298-1: Factory Colors migration (series filtering)
- Issue #298-4: Color suggestion API (model-based color filtering)
- Issue #298-7: Bulk cleanup script (model validation)
- Phase 2: edit.php dynamic dropdowns (replacing cardefinition.js)

**See Also**:

- [Issue #577](https://github.com/jimboone/elan-registry/issues/577) - car_models table creation
- `/usersc/classes/ElanRegistry/README.md` - Namespace pattern documentation

## Best Practices

1. **Always use type declarations** - PHP 8+ strict typing required
2. **Include `declare(strict_types=1);`** - New files must use strict mode
3. **CSRF protection** - All state-changing operations require CSRF validation
4. **Audit logging** - Log all significant operations with appropriate category
5. **Exception handling** - Use custom exceptions for domain errors
6. **Input validation** - Validate and sanitize all user inputs
7. **Prepared statements** - Always use parameterized queries
8. **Consistent naming** - Follow established naming conventions
9. **PHPDoc blocks** - Complete documentation for all public methods
10. **Test coverage** - Write tests for all new classes and methods

## See Also

- [GitHub Wiki: Architecture Guide](https://github.com/jimboone/elan-registry/wiki/Architecture) - System architecture overview
- [DATABASE.md](DATABASE.md) - Database schema and relationships
- [GitHub Wiki: UserSpice Integration Guide](https://github.com/jimboone/elan-registry/wiki/Integration) - UserSpice integration patterns
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Code quality requirements
- [TESTING.md](../testing/TESTING.md) - Testing guidelines
