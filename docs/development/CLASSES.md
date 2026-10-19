# Class Documentation

Complete reference for all custom classes in the Elan Registry application.

## Overview

All custom classes are located under `/usersc/classes/`, with admin-specific
classes in `/usersc/classes/admin/`. Exception classes use
the `ElanRegistry\Exceptions` namespace and are located in
`/usersc/classes/Exceptions/`. Classes follow established design patterns with
consistent database integration, exception handling, and audit logging.

## Quick Class Selection Guide

Use this table to choose the right class for your task:

| Task | Use This Class | Why | Example |
| --- | --- | --- | --- |
| Load car by ID and get all data | Car | Direct database access, validation, history tracking | `$car = new Car(123)` |
| Display cars in a list view | CarView | Read-only, optimized for rendering, no mutations | `$view = new CarView()` → `$view->getAllCars()` |
| Display owner name, quality badge, location | OwnerView | Static HTML generation, no DB, consolidated display logic | `ElanRegistry\OwnerView::displayName($owner)` |
| Update car data and create history | Car + update() | Automatic history via triggers, audit logging | `$car->update(['color' => 'Blue', ...])` |
| Access owner profile and user data | Owner | User profile integration, custom user methods | `$owner = new Owner($uid)` |
| Validate VIN/chassis format | ChassisValidator | Specialized validation for vehicle identifiers | `$validator->validate('26/0001')` |
| Direct DB queries on cars/history/factory data | CarRepository | Testable data-access layer, used by Car and API endpoints | `(new CarRepository($db))->findByChassisKey($year, $type, $chassis)` |
| Verification codes, verification timestamps, email-bounce tracking | CarVerificationManager | Business-logic layer over CarRepository; validates and throws rather than returning falsy on failure | `(new CarVerificationManager($repo))->generateVerificationCode()` |
| Create database backups | BackupManager | SQL dump creation, verification, and retention cleanup | `$backup = new BackupManager(...)` |
| Decode car images | CarImageProcessor | Decodes the `cars.image` JSON array into usable entries | `$processor->decodeAndProcessImages($car->image, ...)` |
| Remove one image from a car | Car / CarImageProcessor | CAS-guarded single-filename removal; throws on concurrent modification. Pass `true` when the owner removes it, to reset `owner_last_updated` | `$car->removeImage($filename, $isOwnerInitiated)` |
| Remove multiple images from a car | Car / CarImageProcessor | CAS-guarded bulk removal; returns `['updated' => bool, 'casConflict' => bool]` instead of throwing, for callers (e.g. `mvTmpImages()`'s move-failure cleanup) that already have their own error-reporting path | `$car->removeImages($filenames)` |
| Decide which status badges (Sold, Verified, New) a car shows | CarBadges | One definition of the badges and the rule that picks them, for the account page and the cars list | `CarBadges::forCar($car->data())` |
| Query car models by year/series | CarModel | Reference data for model filtering | `$models = (new CarModel())->getAvailableInYear(1970)` |

---

## Class Organization Patterns

### Namespaces

The Elan Registry uses namespaces to organize classes by their architectural role:

| Namespace | Purpose | Location | Examples |
| --- | --- | --- | --- |
| **(root)** | Entity classes (domain objects) | `/usersc/classes/` | Car, Owner |
| `ElanRegistry\Exceptions` | Custom exception types | `/usersc/classes/Exceptions/` | CarNotFoundException, CarValidationException |
| `ElanRegistry\Reference` | **External reference data** | `/usersc/classes/Reference/` | CarModel |

### Reference Data vs. Entity Classes

**Reference Data Classes** (`ElanRegistry\Reference`):

- Represent **external/canonical facts** about cars from Lotus (factory data, official colors, model specifications)
- **Read-only** - no create/update/delete operations
- Static query methods only
- Example: CarModel (model types, backed by the `car_models` table)

**Entity Classes** (root namespace):

- Represent **registry records** (individual car registrations, owner profiles)
- **Full CRUD operations** - create, read, update, delete
- Instance methods and properties
- Examples: Car (individual registered car), Owner (owner profile)

**Quick Decision Guide**:

- Does this represent data from an external authoritative source? → Reference class
- Does this represent a record in the registry database? → Entity class
- Does this need CRUD operations? → Entity class
- Is it lookup/metadata only? → Reference class

---

## Core Domain Classes

### Car

**Location**: `/usersc/classes/Car/Car.php`

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
// Create new car — CSRF is validated by the HTTP-layer caller (see save.php)
// before create()/update() are invoked, not inside the Car class itself.
$car = new Car();
$carId = $car->create([
    'chassis' => '26/0001',
    'model_name' => 'S2',
    'body_style' => 'DHC',
    'body_color' => 'Red',
    'user_id' => $userId,
]);

// Load existing car
$car = new Car($carId);
$carData = $car->data();

// Update car
$car->update([
    'id' => $carId,
    'body_color' => 'Blue',
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
- `CarDatabaseException` - Database operation failures (500). `sqlState()` returns the SQLSTATE from the `ERROR #<sqlstate>:` message prefix, or null
- `ExceptionLogSummary::of(\Throwable)` (not an exception) - a log-safe summary: class, code, SQLSTATE and `file:line`, never the message (#2359)
- `CarPermissionException` - Permission/auth denied (403) *(no production code throws this post-v2.28.0; retained for hierarchy completeness)*
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
| User lacks permission | CarPermissionException | Non-owner trying to edit someone else's car *(unthrown post-v2.28.0 — auth gates moved to callers; class retained for hierarchy)* |
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
use ElanRegistry\CarView;

// Render a single car image; $image is one decoded entry from cars.image
CarView::loadPicture(array $image, ?bool $thumbnail = null, bool $isPrimary = false): string

// Render the Bootstrap carousel for a car
CarView::displayCarousel(Car $car, ?int $instanceId = null): string

// Build the schema.org structured-data array for a car detail page
CarView::buildCarSchema(object $carData, string $currentUrl): array
```

These three static methods are the class's entire public surface.

**Design Notes**:

- Follows MVC pattern separation (view layer only)
- Uses constants to avoid magic numbers
- Static methods for stateless operations
- Integrates with Resize class for image processing

### OwnerView

**Location**: `/usersc/classes/OwnerView.php`

**Namespace**: `ElanRegistry`

**Purpose**: Static utility class for owner display and HTML generation. Consolidates
duplicated owner presentation logic (name, quality score, location, contact info) that
was previously scattered across 8+ template files.

**Key Features**:

- Owner name display with XSS escaping
- Quality score badge and progress bar (Bootstrap contextual classes)
- Location formatting (city, state, country)
- Contact info with website scheme validation (http/https only)
- Missing profile fields list with warning icons
- No database operations (view layer only)

**Common Usage**:

```php
use ElanRegistry\OwnerView;

// Display owner name
echo OwnerView::displayName($ownerData);          // "Jane Smith" (escaped)

// Quality score badge
echo OwnerView::displayQualityBadge($score);      // <span class="badge text-bg-success ...">

// Progress bar
echo OwnerView::displayQualityProgressBar($score);

// Location
echo OwnerView::displayLocation($ownerData);       // "Portland, Oregon, United States"

// Contact info (email mailto + validated website link)
echo OwnerView::displayContactInfo($ownerData);

// Missing fields list
$missing = $owner->validateProfileCompleteness();
echo OwnerView::displayMissingFields($missing);
```

**Design Notes**:

- Follows MVC pattern separation (view layer only, mirrors CarView pattern)
- Quality score thresholds: ≥80 → success, ≥60 → warning, <60 → danger
- All user data escaped with `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` at render time
- Website URLs validated via `parse_url` scheme check; only `http`/`https` rendered as links
- `qualityBadgeClass(float $score)` is public for direct use in templates

---

### Owner

**Location**: `/usersc/classes/Owner.php`

**Purpose**: Manages owner/user data with clean separation between UserSpice
authentication and ElanRegistry business logic.

**Key Features**:

- Owner profile management
- Owner-field sync to owned cars (`syncOwnerFieldsToCars()`: city, state,
  country, lat, lon, fname, lname, website, email)
- Profile quality scoring
- Owner search functionality
- Integration with UserSpice user system
- Combines `users` and `profiles` table data

**Common Usage**:

```php
// Load owner
$owner = new Owner($userId);
$ownerData = $owner->data();

// Update owner profile
$owner->update([
    'id' => $userId,
    'city' => 'Portland',
    'state' => 'Oregon',
    'country' => 'United States'
]);
// Note: Pass lat/lon explicitly; coordinates are not auto-populated server-side

// Get profile quality score
$score = $owner->getProfileQualityScore(); // Returns 0-100

// Search owners (admin function)
$results = (new Owner())->searchOwners('Portland');

// Update name, location, or website and sync the change to every owned car
$syncResult = $owner->updateProfileAndSync([
    'fname' => 'Jane',
    'lname' => 'Doe',
    'website' => 'https://example.com',
]);
```

**Database Tables**:

- `users` - UserSpice user authentication data
- `profiles` - Extended user profile information

**Integration**:

- Use `(new Owner($userId))->data()` to load combined user+profile data
- Used in admin consolidated management interface

**Exception Handling**:

All Owner exception classes are in the `ElanRegistry\Exceptions` namespace
and extend `OwnerException` (abstract, extends `ElanRegistryException`),
mirroring `CarException`'s pattern — `catch (OwnerException $e)` handles any
owner-domain error uniformly:

- `OwnerCreationException` — owner creation failures (500)
- `OwnerSearchException` — owner search failures (500)
- `OwnerUpdateException` — owner update failures (500)
- `OwnerValidationException` — invalid owner data (422)
- `OwnerDatabaseException` — database operation failures (500)

`find()`, `getCarsOwned()`, and `getOwnershipHistory()` throw
`OwnerDatabaseException` on a DB query failure. They still return `false`/
`[]` for a genuinely empty/not-found result — only a DB-layer error throws,
so callers can distinguish "not found" from "DB failed" without reading
logs. (`OwnerNotFoundException` was removed in v2.29.5 — nothing threw it
in production; `find()` returning `bool` covers the not-found case.)

`create()`/`update()` wrap their writes in `beginTransaction()`/`commit()`/
`rollback()` (an ownership-flag transaction guard mirroring
`CarRepository`'s pattern) and catch `\Throwable`, guaranteeing rollback on
any failure including a PHP `\Error`. Their post-write reload call is
wrapped in its own local `try/catch (OwnerDatabaseException $e)` — a reload
failure after a successful write is logged, not propagated.

`syncOwnerFieldsToCars()` returns an `OwnerSyncResult` value object with three
outcome buckets: `updated` (write succeeded), `failed` (history insert or UPDATE
failed at the DB level), and `skipped` (car no longer owned by this user at write
time — ownership changed between the initial car list and the write; not a failure,
expected behavior). `syncOwnerFieldsToCars()` lets `getCarsOwned()`'s exception
propagate uncaught by design — a DB failure should surface as a real exception, not
collapse into a result that silently reports nothing as updated or failed. It also
throws `OwnerDatabaseException` immediately if the `Owner` itself never loaded (no
user row for its ID) — this is a distinct precondition failure from "loaded but owns
zero cars," which still returns an empty, complete-success `OwnerSyncResult` (#1979).
`OwnerSyncResult::successMessage()`, currently called only by
`process-owner-sync-location.php`, words a skip-only outcome
(`updatedCount() === 0`) as "No cars were synchronized." rather than "...to 0
car(s).", which would otherwise read as a failure. `usersc/user_settings.php`
deliberately does not call it — it stays silent on a skip-only outcome since
a car the owner no longer owns isn't actionable for them; this is intentional
divergence, not drift. Callers with a real failure build their own message
from `failedCarsPhrase()` instead.

`updateProfileAndSync(array $fields): OwnerSyncResult` writes an owner's
name, location, website, and email, then pushes them onto the owner's cars.
Its caller is `usersc/user_settings.php`. It cannot clear the website to
empty, because `update()` drops empty values. `user_settings.php` handles
that one case with its own direct write, then calls
`syncOwnerFieldsToCars()`. `app/admin/includes/process-owner-update.php`
does not use this method yet. It calls `update()` and then
`syncOwnerFieldsToCars()` itself, with the same effect. Any new write to
these fields must also sync the cars, through this method or the same two
calls, or the cars keep stale copies.

It calls `update($fields)`, then `syncOwnerFieldsToCars()`, and returns that
call's `OwnerSyncResult`. The owner ID comes from the already-loaded `Owner`,
not from `$fields` — an `id` key in `$fields` is overwritten. It throws
`OwnerValidationException` or `OwnerUpdateException` for any failure before
the `users`/`profiles` write commits — nothing is written in that case. Once
that write commits, `syncOwnerFieldsToCars()` can still throw
`OwnerDatabaseException` or `CarDatabaseException`; the profile write already
committed in that case, so the caller must not re-raise it as a reason to
also fail the surrounding request. A result where `isCompleteSuccess()` is
`false` is not an exception — the caller reads the returned
`OwnerSyncResult` and decides how to report it.

`update()`'s post-commit reload failure is deliberately non-fatal there
(the write already succeeded, so a reload failure is logged, not thrown) —
but a stale `_data` left behind by that failure would otherwise feed
pre-update values into the sync, below. `updateProfileAndSync()` clears
`_data` before calling `update()` (not `_carsOwned`, which caches only which
cars the owner has, not their contact fields), so a failed reload leaves
`_data` null, and `syncOwnerFieldsToCars()`'s own "not loaded" guard turns
that into a thrown `OwnerDatabaseException` instead of silently syncing
stale values while reporting success. When `update()` fails before the
commit, nothing was written, so the method restores the old `_data` before
it throws. A caller can then still call `syncOwnerFieldsToCars()` on the
same `Owner`, as `user_settings.php` does for a website clear.

`ownerContactFields()` is the single definition of the nine denormalized
owner-contact columns (`fname`, `lname`, `email` from `users`; `city`,
`state`, `country`, `lat`, `lon`, `website` from `profiles`). It never
returns `mtime` or `owner_last_updated` — the latter drives verification-
email eligibility and must not be reset by a mechanical refresh. When the
owner failed to load, every value is `null`, so callers must check before
persisting. Consumed by `syncOwnerFieldsToCars()` (which adds its own
`mtime`) and by `OwnerContactRefresher`.

### OwnerContactRefresher

**Location**: `/usersc/classes/OwnerContactRefresher.php`

**Namespace**: `ElanRegistry`

**Purpose**: Refreshes a car's denormalized owner-contact columns from the
owner's current profile when the car is edited, so a stale car row stops
perpetuating outdated contact data (#1962). Exists as a class rather than
inline in `app/api/cars/save.php` because that file cannot be loaded by a
test — every path ends in `exit` via `ApiResponse::send()`, so logic living
there can only be hand-copied into tests, and a hand-copied test passes
even when the production code is deleted.

**Key Features**:

- Pure: returns a new array rather than mutating its argument
- No globals, no exit paths, no logging — callable from a unit test with no
  database
- All nine `ownerContactFields()` columns are refreshed, `website` included
  (#1963 made `website` owner-level and removed the per-car form field, so
  it no longer needs the carve-out this class previously had)
- Never writes `mtime` or `owner_last_updated`
- Returns the car details untouched when the owner failed to load, so a car
  with a dangling or null `user_id` keeps its existing contact data rather
  than being blanked with nulls
- A profile `website` that would fail `CarValidator`'s validation is skipped
  rather than merged, so an invalid legacy or #1961-bulk-promoted value
  can't reach `Car::update()`/`Car::create()` and block the edit or add-car
  flow with a `CarValidationException`

**Methods**:

- `refresh(array $cardetails, Owner $carOwner): array` — Merge the owner's
  current contact values over the car's details and return the result. The
  caller must pass the Owner built from the **car row's** `user_id`, never
  the logged-in session user: an admin or editor may be editing another
  member's car, and using the session user would write staff's contact
  details onto that member's record. This method cannot enforce that — by
  the time it is called both arguments are already chosen, and `Owner`
  carries no notion of who is logged in. Internally guarded, so calling it
  for an unloadable owner is safe.
- `hasLoadableOwner(Owner $carOwner): bool` — Whether the owner loaded.
  Split out so the endpoint can log the skip (it has the car ID and session
  user; this class has neither) without duplicating the null test. Calling
  `refresh()` without checking this first is safe, not a bug — the only
  consequence is that the skip goes unlogged.
- `hasValidWebsite(Owner $carOwner): bool` — Whether the owner's current
  profile website would survive `refresh()` unskipped. Mirrors
  `hasLoadableOwner()`'s split-out-for-logging pattern: `refresh()` silently
  skips an invalid website with no log line, so callers check this first if
  they want to log the skip. Returns `true` when the owner failed to load —
  `hasLoadableOwner()` already covers and logs that case.

**Persistence asymmetry** (easy to trip over): `syncOwnerFieldsToCars()`
writes its bundle through `CarRepository::updateCarForOwner()`, which
persists blanks — clearing your city there clears it on your cars. The edit
path routes through `Car::update()`, whose `array_filter` drops `''`/`null`
for any field not in `Car::CLEARABLE_FIELDS`, so blank owner values are
silently no-ops here — **except `website`**, the one field of the nine that
*is* in `CLEARABLE_FIELDS`, so it propagates a clear on both paths just like
the sync path does. This is a deliberate, user-confirmed decision (#1963),
not an oversight.

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
- `parseModel(string $model): array` - Static; splits a `series|variant|type` model string into its parts

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

### CarRepository

**Location**: `/usersc/classes/Car/CarRepository.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Database access layer for car operations. Extracted from `Car.php`
to provide a focused, testable data access layer wrapping the `cars`,
`cars_hist`, `elan_factory_info`, and `car_models` tables.

**Key Features**:

- CRUD operations for car records (`findById`, `insertCar`, `updateCar`, `deleteCar`)
- Row-locking lookup (`findByIdForUpdate`) for use inside an active transaction
- Optimistic-concurrency image update via compare-and-swap (`updateImage`)
- Chassis-based and verification-code-based car lookups
- Car history (`cars_hist`) read/write/transfer
- Factory serial-number lookup and suffix-code decoding
- Nested-transaction-safe `beginTransaction()`/`commit()`/`rollback()` (no-op when participating in an outer transaction already begun by the caller)

**Methods**:

- `findById(int $carId): ?object` - Look up a car by ID
- `findByIdForUpdate(int $carId): ?object` - Look up and row-lock a car (`SELECT ... FOR UPDATE`); must be called inside an active transaction
- `insertCar(array $fields): bool` - Insert a new car record
- `updateCar(int $carId, array $fields): bool` - Update an existing car record
- `deleteCar(int $carId): bool` - Delete a car by ID; throws `CarNotFoundException` if no row matched
- `reassignCarsByUser(int $fromUserId, ?int $toUserId): int` - Bulk-reassign all
  cars owned by one user to another (or clear ownership); used by the
  user-deletion hook
- `updateVerificationCode(int $carId, string $verificationCode): bool` - Update a car's verification code
- `updateLastVerified(int $carId, string $dateTime): bool` - **Deprecated** (#2107), no
  production callers — `CarVerificationManager::markVerified()` writes `last_verified` via
  `updateCar()` directly to set `owner_last_updated` atomically alongside it
- `updateVerificationSentAt(int $carId, string $dateTime): bool` - Update the timestamp at which a verification email was sent
- `findProfileEmailState(int $userId, bool $forUpdate = false): ?object` -
  Read the owner's deliverability state from `profiles` (#2101): `email_opted_out`,
  `email_opted_out_at`, `email_blocked`, `email_blocked_address`, `email_blocked_at`,
  `email_blocked_cause`, `email_bounced`, `email_bounced_address`, `email_bounced_at` and
  `email_bounce_cleared_at`, plus `user_email` (the owner's `users.email` from a `LEFT JOIN`, null when
  the `users` row is missing). Returns `null` when the owner has no `profiles` row. Treat that as
  "do not mail". With `$forUpdate` true it adds `FOR UPDATE OF p, u`, which locks the `profiles` row and
  then the `users` row, and the caller must be inside a transaction.
  It returns the stored values and does not apply the address-match rule. To decide whether a flag
  applies now, use `effectiveEmailFlags()`.
- `effectiveEmailFlags(int $userId): EffectiveEmailFlags` - Read the flags that apply to one owner now
  (#2356). One query joins `profiles` to `users`. A stored `email_blocked` or `email_bounced` applies
  when its `_address` is NULL, empty, or equal to `users.email`. The compare normalizes both sides with
  `EmailAddress::normalize()` (trim, then lower case). `email_opted_out`
  applies whatever the address. Returns `EffectiveEmailFlags::none()` when the owner has no `profiles`
  row or no `users` row. Throws `CarDatabaseException` if the query fails.
- `emailFlagActiveSql(string $flag): string` - Static. Returns the SQL predicate
  `p.<flag> = 1 AND (<address term>)` for `DeliverabilityFlagLog::FLAG_BLOCKED` or
  `DeliverabilityFlagLog::FLAG_BOUNCED`. The query must alias `profiles` as `p` and `users` as `u`. The
  address term is true when the stored address is NULL or empty, or when it equals `u.email`. Both
  sides use `LOWER(REGEXP_REPLACE(<column>, '^[[:space:]]+|[[:space:]]+$', ''))`, because MySQL
  `TRIM()` removes spaces only. The method compares `$flag` with `===` and builds the SQL from class
  constants only. For any other value, including `email_opted_out`, it throws `CarValidationException`.
  For the opt-out, use `p.email_opted_out = 1`. The PHP form of this rule is `effectiveEmailFlags()`.
  Queries on cars use it too: the send mails `users.email`, and `cars.email` is only a copy.
- `findProfileOptedOut(int $userId): ?int` -
  Read `profiles.email_opted_out` (0, 1, or null if no row). Renamed from
  `findProfileEmailSuppressed()` in #2101. It no longer uses `MAX()`, because `profiles.user_id` is unique.
  The locking variant is removed: Resume now locks the row through the profile writer.
- Profile writers (#2101). Each locks the owner's `profiles` row and `users` row, writes one row by
  `user_id`, and returns the old flag value (0 or 1), or `null` when the owner has no `profiles` row (nothing
  is written). `setProfileBlocked()` and `setProfileBounced()` return the old effective value (#2356): a
  flag stored for an old address does not apply, so they return 0, except that a repeat for the same
  address returns 1 with no write. The clear writers return the stored value. The caller passes the old
  value to `DeliverabilityFlagLog::record()`.
  - `setProfileOptedOut(int $userId, bool $optedOut): ?int` - Set or clear `email_opted_out` and
    `email_opted_out_at`. A repeat set keeps the first time.
  - `setProfileBlocked(int $userId, string $address, ?string $cause, string $blockedAt): ?int` - Set
    `email_blocked` with address, time and cause. `$cause` is one of `BLOCKED_CAUSES` or null. Writes
    when the flag is 0 or is set for another address. A repeat for the same address keeps the first
    time and cause.
  - `clearProfileBlocked(int $userId): ?int` - Clear `email_blocked` and its address, time and cause.
  - `setProfileBounced(int $userId, string $address, string $bouncedAt): ?int` - Set `email_bounced`
    with address and `email_bounced_at`. Same repeat rule as the block.
  - `clearProfileBounced(int $userId): ?int` - Clear `email_bounced` and its address and time, and set
    `email_bounce_cleared_at`.
  - The address is stored normalized (lower case). The setters throw `CarValidationException` for an
    empty address, an unknown cause or an invalid time, and `CarDatabaseException` on a query failure.
- `BLOCKED_CAUSE_SPAM`, `BLOCKED_CAUSE_UNSUBSCRIBED`, `BLOCKED_CAUSE_BLOCKLIST` and `BLOCKED_CAUSES` -
  Public constants for `profiles.email_blocked_cause`.
- `latestFlagClearAt(int $ownerId, string $flag, string $source): ?string` - Time of the latest
  `logs` row that cleared the flag (`new = 0`) for this owner and source. It reads the metadata
  `cleared_at` when the row has one (#2357), else `logdate`. Returns `null` when no row matches. It
  converts the BLOB `logs.metadata` (`JSON_EXTRACT(CONVERT(metadata USING utf8mb4), ...)`), matches
  `owner_id` as an integer, never reads `lognote`, and filters on `logtype` first to use
  `idx_logs_logtype_logdate`. Throws `InvalidArgumentException` for an unknown flag or source.
- `recentFlagChanges(int $ownerId, int $limit): array` - The owner's latest flag-change `logs` rows,
  newest first (`logdate DESC, id DESC`). Each item has `date`, `flag`, `old`, `new`, `source` and
  `acting_user_id`, and no address. `$limit` is clamped to 1 to 100. A row with malformed metadata is
  skipped. #2361 uses it.
- `restoreVerificationCodeState(int $carId, ?string $vericode, ?string $vericodeSentAt): bool` -
  Restore a car's pre-send vericode/vericode_sent_at after a failed send (#1884), distinct from
  `updateCar()` because it can observe a write that matched no row. Throws `CarDatabaseException` on query error.
- `incrementVerificationAttempts(int $carId): bool` -
  Increment `cars.verification_attempts` with rolling-year window reset via `verification_attempts_since` (#1884).
  Returns false if no row matched; throws `CarDatabaseException` on query error.
- `insertEmailEvent(int $carId, string $email, string $event, ?string $reason, string $brevoMessageId, string $occurredAt): int` -
  Record the `sent` event of one verification email (`er_email_events`).
  Only `CarVerificationSendService` calls it. The row carries the car, because
  it starts that car's send cycle. It is an `INSERT ... SELECT` from `cars`
  joined to `users`, so the row gets the car's owner as `user_id`. A car with
  no owner, an owner with no `users` row, or the `noowner` account writes no
  row and returns 0. The address is stored normalized. It dedups on
  `(user_id, brevo_message_id, event)` with a hand-written
  `ON DUPLICATE KEY UPDATE er_email_events.id = er_email_events.id`, so a
  duplicate changes nothing and the first write wins. It is deliberately not
  `DB::insert()`'s `$update` mode, which would also reassign identity columns
  on conflict. `$brevoMessageId` is the real Brevo message ID, or the local
  fallback ID (`local:` and the SHA-256 of the new vericode). Returns MySQL's
  `rowCount()`: 1 for a new row, 0 for a duplicate or when no row is written
  (#1887, #2358, #2359)
- `insertOwnerEmailEvent(int $userId, string $email, string $event, ?string $reason, string $messageId, string $occurredAt, ?int $carId = null): int` -
  Record a webhook, reconciliation or suppression-import event for an owner
  (#2358). `$carId` is the car of the matched verification `sent` row (a step
  (a) match). It is NULL when the event names no car. The upsert rule is the
  same as in `insertEmailEvent()`. A `$userId` less than 1, a user that does not exist, or
  the `noowner` account writes no row and returns 0. The method does not check
  the address or the time. The callers clamp the time
  (`resolveOccurredAt()`). `EmailEventApplier::apply()` calls it.
- `findUserIdsByAddress(string $address): array` - Distinct IDs of the users
  whose `users.email` equals the address under the matching rule (#2359). SQL
  selects candidates with the column collation. PHP keeps only a byte-equal
  match of the two `EmailAddress::normalize()` values. The `noowner` account is
  left out. An empty address returns `[]` with no query. `EmailEventMatcher`
  uses it.
- `findSentRowsByMessageId(string $messageId): array` - The verification
  `sent` rows that carry a message ID (#2359). Step (a) of the matcher uses it.
  The `brevo_message_id` index serves the query, and PHP keeps only a
  byte-equal ID.
- `findSendinblueMessageById(string $messageId): ?object` - The plugin's
  `plg_sendinblue_messages` row with this `message_id`, or null (#2359). It never
  selects a row by address. Step (b) of the matcher uses it. Call
  `sendinblueMessagesTableExists()` first.
- `lockEventGuardState(int $userId): ?array` - Lock the user's `profiles` row
  (`FOR UPDATE`), then the `users` row, and read `users.email`, `join_date`,
  `email_address_since` and `email_bounce_cleared_at` (#2359). Returns null when
  the user has no `users` row or is `noowner`. `has_profile` is false when
  there is no `profiles` row. Call it inside the caller's transaction.
  `EmailEventApplier::apply()` calls it first.
- `countSoftBouncesSinceLastDelivered(int $userId, string $email): int` - Count
  distinct soft-bounce send cycles (`brevo_message_id` values) for the owner and
  the address, since the latest of: the owner's most recent `delivered` event for
  the address, `profiles.email_bounce_cleared_at` and `profiles.email_address_since`.
  The address compare uses `COLLATE utf8mb4_bin`, so a former holder of the
  address or an accent variant does not count. The soft-bounce escalation
  threshold is checked against this query (#1887, #2101, #2358)
- `deleteEmailEventsForUser(int $userId): int` - Delete all `er_email_events`
  rows with this `user_id`, including rows with `car_id` NULL. The account-deletion
  privacy step uses it. A `$userId` less than 1 issues no query and returns 0 (#2358)
- `detachEmailEventsFromCar(int $carId): int` - Set `car_id` to NULL on the
  rows of a car, so the events stay with the owner. `CarAdministrationService::delete()`
  uses it. Returns the number of rows changed (#2358)
- `deleteEmailEventsOlderThan(\DateTimeImmutable $terminalCutoff, \DateTimeImmutable $shortCutoff): int` -
  Retention prune, used by the nightly reconciliation job. It deletes a
  terminal event (`EmailEventApplier::HARD_BOUNCE_EVENTS` and
  `SUPPRESSION_EVENTS`) older than `$terminalCutoff` (24 months). It deletes
  every other row older than `$shortCutoff` (90 days). It keeps each car's
  latest `sent` row whose `user_id` is the car's current owner. It sets that
  row's `email` to `''` only when the row is older than `$shortCutoff`. A
  previous owner's row on the car ages out after 90 days. A derived table
  avoids MySQL error 1093. Throws
  `\InvalidArgumentException` when `$terminalCutoff` is not before
  `$shortCutoff`. Returns the rows deleted plus the `sent` rows whose `email`
  was cleared. A second run with the same cutoffs changes no row (#1889, #2358)
- `lockProfileAndFindAddresses(int $userId): array` - First call of the
  account-deletion privacy step, inside its transaction. It runs
  `SELECT ... FROM profiles WHERE user_id = ? FOR UPDATE`. This is the lock that
  the event step of #2359 will take. Then no event row for the user can arrive
  between the read and the delete. Today an event write takes no profile lock,
  so this design applies after #2359 ships. A user with no profile row gets a
  gap lock.
  Returns the unique, non-empty, normalized addresses from five sources:
  `email_address_seen`, `email_bounced_address` and `email_blocked_address` of
  the profile, `DISTINCT email` of the user's event rows, and `cars.email` of
  the user's cars (#2358)
- `sendinblueMessagesTableExists(): bool` - Whether the sendinblue plugin's
  `plg_sendinblue_messages` table exists in the current schema. A site without
  the plugin has no such table (#2358)
- `deleteSendinblueMessagesForUser(int $userId, array $addresses): int` - Delete
  a user's `plg_sendinblue_messages` rows: the rows whose `message_id` is a
  `brevo_message_id` of the user's event rows, and the rows whose normalized
  `recipient_email` equals an address in `$addresses`. Call it before
  `deleteEmailEventsForUser()`, because the first delete reads the user's event
  rows. It skips an address that is another user's current `users.email`. It
  drops an empty, non-string or duplicate address, and issues no `IN ()` for an
  empty list. A `$userId` less than 1 issues no query (#2358)
- `clearFlagsForConfirmedEmailChange(int $userId): ?array` - Clear the bounce and the block when a
  confirmed email change gives a new address (#2356). Call it inside a transaction. It locks the
  `profiles` row and the `users` row (`SELECT ... FOR UPDATE OF p, u`), profiles first, the same order
  as `Owner::update()`. Then it makes one compare: the normalized `users.email`
  against `email_address_seen`. If they are equal, it writes nothing. If they differ, it clears
  `email_bounced` (with address and date, and sets `email_bounce_cleared_at`), clears `email_blocked`
  (with address, date and cause), and sets `email_address_since` to now and `email_address_seen` to the
  normalized address. It keeps `email_opted_out`. Returns `array{bounced: int, blocked: int,
  bouncedHadNoAddress: bool, blockedHadNoAddress: bool, changed: bool}` with the flag values from
  before the call, or `null` when the owner has no `profiles` row or no `users` row. Used by
  `CarVerificationManager::clearFlagsForConfirmedEmailChange()`.
- `transferEmailEvents(int $fromCarId, int $toCarId): bool` - Move
  `er_email_events` rows from one car to another by changing `car_id` only.
  Car merge uses it. The rows keep their `user_id`. A car transfer to a new
  owner does not call it and changes no event row (#1887, #2358)
- `freshnessSql(string $alias = 'cars'): string` - Static; returns a SQL
  boolean expression determining if a car is fresh (verified within 1 year via
  `last_verified` OR edited by owner within 1 year via `owner_last_updated`).
  The `$alias` parameter must match the table alias in the calling query (e.g.
  `'c'` for `cars AS c`) and is validated against `/^[A-Za-z_][A-Za-z0-9_]{0,63}$/`
  to prevent SQL injection. Compares against MySQL's `NOW()`.
- `stalenessSql(string $alias = 'cars'): string` - Static; returns
  `'NOT ' . freshnessSql($alias)` — the exact boolean negation of freshness.
  The statistics page's "Fresh (12 mo)" figure (`verified_cars` from
  `StatisticsDataService::getDataCompleteness()`) counts every car that is
  fresh by `freshnessSql()`. Sold cars are included, so the figure and the
  stale count add up to the total. The figure does not match the Verified
  badge, because `CarBadges::resolve()` hides Verified on a sold car. The
  label says "Fresh", not "Verified", for this reason. The response key stays
  `verified_cars` because it is the API contract.
- `isFresh(?string $lastVerified, string $ownerLastUpdated): bool` - PHP
  equivalent of `freshnessSql()` for in-code freshness checks, using PHP's clock
  where the SQL form uses MySQL's `NOW()`. First production caller is the admin
  user-view's "Verification & Email" card (`usersc/plugins/hooker/hooks/user_form_hook.php`,
  #1924), which renders per-car Verified/Unverified badges from it. Both clocks
  must resolve to the same timezone or the two forms can disagree at the
  one-year boundary from skew alone. Sharing a host does **not** guarantee
  this: `users/init.php` pins PHP to `America/Los_Angeles` on every web
  request, while MySQL follows its own `time_zone`, so agreement must be
  verified per environment. Validates **both** operands before comparing —
  deliberately not short-circuiting on a fresh `$ownerLastUpdated` — and throws
  `CarValidationException` if either is empty, malformed, or not a real
  calendar date (`2026-02-30` is rejected rather than rolled over to March 2),
  because a malformed value there is a programming error, not a data state.
  It returns `freshnessSource(...) !== null`, so it reads `freshnessCutoff()`
  one time and compares both parsed operands to that one value. The hook catches this per-car and renders an isolated "Unknown" badge for
  that row rather than failing the whole panel.
- `isWithinFreshnessWindow(string $timestamp, string $column): bool` -
  Decide if one datetime string is inside the 1-year freshness window. It
  compares the result of `parseTimestamp()` with `freshnessCutoff()`. Throws
  `CarValidationException` if $timestamp is empty, malformed, or not a real
  calendar date. The required `$column` parameter names the column in the
  exception message. Uses PHP's clock (see the timezone note on `isFresh()`).
- `freshnessSource(?string $lastVerified, string $ownerLastUpdated): ?array` -
  The rule of `isFresh()`, with the operand that makes the car fresh. Returns
  `['source' => 'confirmed', 'date' => <last_verified>]` when `last_verified`
  is inside the window, else `['source' => 'current', 'date' =>
  <owner_last_updated>]` when `owner_last_updated` is inside the window, else
  null. The dates are `parseTimestamp()` results. It reads `freshnessCutoff()`
  one time, so the fresh decision and the source use the same second. It
  parses both operands before it compares, and throws
  `CarValidationException` the same way as `isFresh()`.
  `CarBadges::verifiedStatus()` returns its result.
- `parseTimestamp(string $value, string $column): DateTimeImmutable` - The one
  parser for `last_verified` and `owner_last_updated`. Accepts `Y-m-d H:i:s`
  (a `T` separator is also accepted) and rejects an empty value, a relative
  value such as `'now'`, and a date that is not real (`2026-02-30`,
  `0000-00-00`) with `CarValidationException`. `isFresh()` and
  `isWithinFreshnessWindow()` use it. A caller that shows the date (for
  example `CarBadges::verifiedStatus()`) shows the returned object, so the
  shown date is the validated date.
- `freshnessCutoff(): int` - The one PHP definition of the window start: the
  Unix timestamp of `FRESHNESS_MONTHS` (12) months ago, from PHP's clock. Each
  call reads the clock again. `freshnessSource()` reads it one time for both
  of its comparisons. Throws `CarValidationException` if `strtotime()` returns
  false, because a cast to 0 would make every car fresh.
- `FRESHNESS_MONTHS` - Public constant, `12`. The length of the freshness
  window. `freshnessCutoff()` and the Verified badge tooltip
  (`CarBadges::verifiedTooltip()`) use it. `freshnessSql()` writes the same
  window as `INTERVAL 1 YEAR`; a unit test pins the two together.
- `QUEUE_STATUSES` - Public constant, `['all', 'eligible', 'pending',
  'bounced', 'suppressed', 'verified', 'sold']`. The statuses
  `findVerificationQueue()` accepts. Public so the admin dashboard can check
  a `?status=` URL value against the same list (#1896).
- `findVerificationStateByOwner(int $ownerId): array` - Per-car verification state for
  every car a user owns (`id`, `model`, `series`, `variant`, `year`, `email`,
  `owner_last_updated`, `last_verified`), ordered `model, year`. The rows hold car columns only. For
  the owner's deliverability flags, use `effectiveEmailFlags()` (#2356). Backs the admin
  user-view's "Verification & Email" card (#1924). PDO driver typing decides the type of a numeric
  column, so cast it at the call site.
- `findLatestEmailEventsByCarIds(array $carIds): array` - Latest
  `occurred_at DESC, id DESC` `er_email_events` row per car id in the car's
  send cycle, keyed by `(int) car_id`. A car with no row in its cycle is absent
  from the map. The cycle starts at the car's latest `sent` row (`car_id` = the
  car and `user_id` = the car's owner). It holds that row and every later
  non-`sent` row of the owner with the same `car_id`, or with the same
  `brevo_message_id` as that `sent` row (a row that the #2358 migration
  collapsed). Another later row with `car_id` NULL counts only when it is a
  hard bounce or a suppression event.
  A car with no `sent` row uses only the rows with its own `car_id`. Used alongside
  `findVerificationStateByOwner()` to back the per-car event detail (#1924,
  #2358). Returns `[]` with no query on an empty `$carIds` array. Throws
  `CarValidationException` for a car id that is not a positive int.
- `findVerificationEligible(int $limit, int $offset): array` - Paginated
  query for cars eligible for a verification email: not sold, an owner with a
  non-empty `users.email` (the address the send mails), and stale — neither verified nor updated by its owner within the last
  year (see `stalenessSql()`). No longer falls back to `cars.mtime`. Since #2101 it
  also excludes a car whose owner has `email_opted_out` set, or has an `email_blocked` or
  `email_bounced` that applies to the owner's current address
  (`emailFlagActiveSql()`, #2356),
  including a car registered after the flag was set (#2319), and a car whose owner has no
  `profiles` row. The query uses `LEFT JOIN profiles p ON p.user_id = cars.user_id`. The rows are
  `cars.*` only, with no profile fields.
- `countVerificationSummary(?int $windowDays): array` - Count cars in each
  verification dashboard state: `all`, `eligible`, `pending`, `bounced`,
  `suppressed`, `verified`, `sold`. Backs the admin dashboard's summary cards
  and filter-pill counts (#1896). `eligible`, `pending`, `bounced`, and
  `suppressed` are current state; `verified` and `sold` count distinct
  existing cars with a matching `cars_hist` row in the `$windowDays` window
  (`null` for all time). `all` counts distinct cars that are eligible,
  pending, bounced, or suppressed — it excludes verified and sold, since
  those are history, not a queue state. Bounced and suppressed can overlap,
  so `all` can be less than the sum of the four. `all` also counts the unsold cars of a live owner
  (not `noowner`) who has no `profiles` row, so the queue shows them as "No profile". `bounced` counts
  cars whose owner's `email_bounced` applies to the owner's current address
  (`emailFlagActiveSql()`). `pending` leaves out those cars. `suppressed` counts cars whose
  owner's `email_blocked` applies in the same way, or whose owner has `email_opted_out = 1`,
  until #2361 separates them. Throws
  `CarValidationException` if `$windowDays` is zero or negative, and
  `CarDatabaseException` on a query failure.
- `findVerificationQueue(string $status, ?int $windowDays, int $limit): array` -
  List the cars for one verification queue filter pill. `$status` must be
  one of `QUEUE_STATUSES`; each status uses the same definition as its count
  in `countVerificationSummary()`. `$windowDays` applies only to `verified`
  and `sold`. Row order depends on status: `pending` is longest-waiting
  first, `eligible` is oldest-verified first, `verified`/`sold` is most
  recent history row first, and `all`/`bounced`/`suppressed` is car id
  ascending. Each row carries the owner's state from `profiles`: `has_profile`,
  `email_opted_out`, `email_blocked_active`, `email_blocked_cause` and `email_bounced_active`. The
  `_active` columns are 1 only while the flag applies to the owner's current address
  (`emailFlagActiveSql()`). Throws
  `CarValidationException` for an unknown `$status` or an invalid `$windowDays`, and
  `CarDatabaseException` on a query failure.
- `findRecentVerificationActivity(?int $windowDays, int $limit = 20): array` -
  List the most recent `VERIFIED` and `VERIFIED SOLD` `cars_hist` rows,
  newest first. Backs the dashboard's Recent Activity panel (#1896). Reads
  `cars_hist` only, with no join to `cars` — a car merged or deleted after
  the event still appears, with the year/chassis/owner name values recorded
  at the time of the event. This is why Recent Activity can list a car that
  no longer exists, while `countVerificationSummary()`'s `verified`/`sold`
  counts (which join to `cars`) do not. Throws `CarValidationException` if
  `$windowDays` is zero or negative, and `CarDatabaseException` on a query
  failure.
- `findLatestEmailEventPerCarWithPrecedence(array $carIds): array` - Find the
  `er_email_events` row that sets each car's dashboard Status chip (#1896).
  Scopes to the car's current send cycle, as `findLatestEmailEventsByCarIds()`
  does (#2358): the car's latest `sent` row, every later non-`sent` row of
  the owner with the same `car_id` or the same `brevo_message_id` as that
  `sent` row, and a later terminal row with `car_id` NULL. A car with no `sent` row uses only
  the rows with its own `car_id`. Within the cycle, a terminal event
  (hard bounce or suppression) outranks any later non-terminal event, and
  otherwise the latest event wins. Keyed by car id; a car with no event in
  its cycle is absent from the result. Throws `CarValidationException` for a
  non-positive car id, and `CarDatabaseException` on a query failure.
- `updateSoldDate(int $carId, string $soldDate): bool` - **Deprecated** (#2107), no
  production callers — `CarVerificationManager::markSold()` writes `solddate` via
  `updateCar()` directly to set `owner_last_updated` atomically alongside it
- `updateImage(int $carId, string $newJson, ?string $expectedJson, ?string $ownerLastUpdated = null): bool` -
  Compare-and-swap update of the image JSON column; returns `false` on
  concurrent modification. A non-null `$ownerLastUpdated` also writes that
  column (see the method's PHPDoc and "What resets `owner_last_updated`" below)
- `findByChassisKey(string $year, string $type, string $chassis): ?object` -
  Find a car by its composite chassis key (year, type, chassis); used by
  `chassis-availability.php` and `transfer-request.php` to check chassis
  uniqueness
- `findByVerificationCode(string $code): ?object` -
  Look up a car by verification code. The plaintext code is hashed
  (HMAC-SHA256 via `hashVericode()`) before the database lookup; the hash
  stored in `cars.vericode` is never exposed to callers.
- `getAllForSitemap(): array` - Get all car IDs and modification times for sitemap generation
- `findByOwner(int $ownerId): array` - Find car IDs owned by a given user
- `getHistory(int $carId): array` - Get a car's history records, most recent first
- `insertHistory(array $fields): bool` - Insert a `cars_hist` record
- `transferHistory(int $fromCarId, int $toCarId): bool` - Reassign history records from one car to another
- `getFactoryInfo(string $chassis, int $suffixLength): ?object` - Look up factory info by full chassis serial, falling back to a suffix-length search
- `suffixToText(string $suffix): string` - Static; convert a factory suffix code to descriptive text
- `getFilterOptions(): array` - Distinct series/type/variant values from `car_models` for listing filter pills
- `beginTransaction()`/`commit()`/`rollback(): void` - Nested-transaction-safe wrappers; no-op when an outer transaction already owns the transaction
- `lastId(): int` - Last inserted ID
- `errorString(): string` - Last DB error message

**Exceptions**:

- `CarDatabaseException` - Query failure
- `CarNotFoundException` - `deleteCar()` when no row matched

**What resets `owner_last_updated`** (#1929):

`owner_last_updated` records the last *owner action*: something the car's
owner does to their own car that shows the record is being kept current.
`freshnessSql()`, `stalenessSql()` and `isFresh()` read it, so every
freshness badge, the statistics vector and the verification-eligibility
query depend on this list. "Owner" means the current user's ID equals the
car's `user_id` (`app/api/cars/save.php`). An admin or editor acting on
another owner's car is never an owner action.

| Action | Code path | Resets | Test |
| --- | --- | --- | --- |
| Owner edit, including image upload and reorder | `Car::update($fields, true)` | Yes | `CarEditOwnerColumnRefreshTest::testOwnerSelfEditSetsOwnerLastUpdatedButAdminEditDoesNot`, `CarImageOwnerFreshnessTest::testOwnerEditThatChangesImagesResetsOwnerLastUpdated` |
| Owner removes a photo | `Car::removeImage($file, true)` → `CarRepository::updateImage(..., $ownerLastUpdated)` | Yes | `CarImageOwnerFreshnessTest::testOwnerRemovalResetsOwnerLastUpdatedAndWritesOneHistoryRow` |
| Verify link | `CarVerificationManager::markVerified()` | Yes | `CarVerificationTest::testMarkVerifiedResetsOwnerLastUpdatedWithOneHistoryRow` |
| Sold link | `CarVerificationManager::markSold()` | Yes | `CarVerificationTest::testMarkSoldResetsOwnerLastUpdatedWithOneHistoryRow` |
| Car creation | column default (`NOT NULL`, `CURRENT_TIMESTAMP`), set by migration `20260905172137` | Set, not reset | Not an owner action, so no reset test. `OwnerSyncOwnerFieldsToCarsTest::testNullOwnerLastUpdatedNoLongerReachableAfterSchemaChange` checks that the column is `NOT NULL` |
| Opt-out link | `CarVerificationManager::setSuppressedForOwner()` | No: opting out confirms nothing | `CarVerificationManagerSuppressForOwnerTest::testOptOutDoesNotChangeOwnerLastUpdated` |
| Owner email or profile change, synced to cars | `Owner::syncOwnerFieldsToCars()` | No: it would mark every car fresh | `OwnerSyncOwnerFieldsToCarsTest::testOwnerLastUpdatedUnchangedAndCarStaysVerificationEligible` |
| Ownership transfer | `CarAdministrationService::transfer()` | No: a transfer is not a re-attestation (#1878) | `CarTransferTest::testTransferDoesNotChangeOwnerLastUpdated` |
| Admin or editor edits another owner's car | `Car::update($fields, false)` | No | `CarEditOwnerColumnRefreshTest::testOwnerSelfEditSetsOwnerLastUpdatedButAdminEditDoesNot` |
| Admin or editor removes a photo on another owner's car | `Car::removeImage($file, false)` | No | `CarImageOwnerFreshnessTest::testNonOwnerRemovalDoesNotResetOwnerLastUpdated` |
| Clean-up after a failed upload | `Car::removeImages()` | No | `CarImageOwnerFreshnessTest::testRemoveImagesCleanupDoesNotResetOwnerLastUpdated` |
| Car merge | `CarAdministrationService::merge()` | No | `CarMergeTest::testMergeDoesNotChangeOwnerLastUpdatedOnSurvivingCar` |
| Reconcile owner fields (maintenance script 26) | `app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php` | No | `ReconcileOwnerFieldsExecuteTest::testOwnerLastUpdatedNeverTouched` |

A new action that resets the date must write `owner_last_updated` in the
same UPDATE as its other columns (`Car::update()`'s `$isOwnerInitiated`, or
`updateImage()`'s `$ownerLastUpdated`), so the change makes one `cars_hist`
row. Add a row and a test here when you add one.

**Used By**:

- Car class (composed data-access layer)
- `app/api/cars/chassis-availability.php`, `app/api/cars/transfer-request.php` (`findByChassisKey()`)
- User-deletion hook (`reassignCarsByUser()`, and in the privacy step
  `lockProfileAndFindAddresses()`, `sendinblueMessagesTableExists()`,
  `deleteSendinblueMessagesForUser()`, `deleteEmailEventsForUser()`)
- `CarAdministrationService` (`detachEmailEventsFromCar()` on car deletion, `transferEmailEvents()` on car merge)
- `CarVerificationManager::clearFlagsForConfirmedEmailChange()` (the `verifySuccess` hook) (#2356)
- `EmailNoticeBuilder`, `CarVerificationSendService`, `BrevoSuppressionSyncJob`, `user_form_hook.php`, `user_settings.php`, and the Email on file row (`effectiveEmailFlags()`)
- Sitemap generation (`getAllForSitemap()`)
- `EmailEventMatcher` (`findSentRowsByMessageId()`, `findSendinblueMessageById()`, `findUserIdsByAddress()`) (#2359)
- `EmailEventApplier` (`lockEventGuardState()`, `insertOwnerEmailEvent()`, `countSoftBouncesSinceLastDelivered()`) (#2358, #2359)
- `CarVerificationSendService` (`insertEmailEvent()`)
- `BrevoEventReconciliationJob` (`deleteEmailEventsOlderThan()`) (#1889)
- `app/admin/includes/tab-verification.php` (`countVerificationSummary()`,
  `findVerificationQueue()`, `findRecentVerificationActivity()`,
  `findLatestEmailEventPerCarWithPrecedence()`, `QUEUE_STATUSES`) — the
  admin Verification dashboard (#1896)

**See Also**:

- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Exception patterns
- [DATABASE.md](DATABASE.md) - `cars`, `cars_hist`, `elan_factory_info` schema

---

### CarImageRelocator

**Location**: `/usersc/classes/Car/CarImageRelocator.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Filesystem operations for car image relocation during merge. Moves
all files (base filenames + resized variants) from a source car's image
directory to a target car's directory, handling collisions by renaming via
`CarImageProcessor::generateSecureFilename()`, and removes the emptied source
directory. Compensating inverse operation rolls back moves on merge failure.
Takes the image base directory as a constructor argument for unit-test
flexibility against temp directories.

**Key Features**:

- Move all image files (base + `-resized-{size}` variants) with collision renaming
- Path traversal guard via `UploadPathGuard::isWithinTarget()`
- Filename validation via `CarImageProcessor::isSafeFilename()`
- No-op on missing source directory (returns empty map)
- Self-compensating: a mid-flight failure moves everything back before re-throwing
- Preserves consistent base-filename mapping across all variants of a file
- Never overwrites an existing target file — on the forward path, base-filename
  collisions rename and variant collisions abort; on the compensation path,
  `restore()` reports the file as unrestored instead of aborting

**Methods**:

- `__construct(string $imageBaseDirectory)` — Absolute path to the `userimages/`
  root. Must be absolute: `ELAN_IMAGE_DIR` is the *relative* string
  `'userimages/'`, and passing it bare would silently defeat every path guard.
- `relocate(int $sourceCarId, int $targetCarId, array $sourceBaseFilenames): array`
  — Move all files from source to target directory, renaming on collision.
  Returns an old→new base-filename map for the caller to build the target's
  updated `cars.image` JSON; only base files that actually moved appear in it,
  so a filename listed in `cars.image` with no file on disk is omitted rather
  than carried onto the surviving car. No-op (returns empty map) if the source
  directory does not exist. On failure it restores everything it had already
  moved before re-throwing, so the caller's own `restore()` is then a no-op.
- `restore(int $sourceCarId, int $targetCarId, array $renameMap): array`
  — Compensating inverse; moves relocated files back under their original names,
  recreating the source directory if it is gone. Takes `relocate()`'s return
  value verbatim. Never throws (it runs on an error path where throwing would
  mask the original exception); returns the entries it could **not** move back,
  so the caller can log an incomplete rollback. Empty array means full recovery.
  If the source directory cannot be recreated, the target directory is missing,
  or either path fails the traversal guard, no files are moved at all and the
  entire map is returned as unrestored.

**Common Usage**:

```php
use ElanRegistry\Car\CarImageRelocator;

// Absolute path to the userimages/ root, not the bare constant
$relocator = new CarImageRelocator($abs_us_root . $us_url_root . ELAN_IMAGE_DIR);

$renameMap = [];
try {
    // ... DB work inside the open transaction ...

    $renameMap = $relocator->relocate($sourceId, $targetId, $sourceFilenames);

    // Target's existing entries first, then the source's post-rename names,
    // so the surviving car's primary (first) image is unchanged
    $targetNewImage = array_merge($targetExistingFilenames, array_values($renameMap));

    // ... write cars.image, insert audit row, commit ...
} catch (\Throwable $e) {
    // Compensate before rolling back. A non-empty return means the filesystem
    // could not be fully restored — log it, the operator must repair by hand.
    $unrestored = $relocator->restore($sourceId, $targetId, $renameMap);
    throw $e;
}
```

Initialize `$renameMap` **before** the `try` so the catch always has a value.
Do not compensate after a failed `commit()` — the DB state is then
indeterminate, and moving files back can corrupt a merge that actually
committed.

**Exceptions**:

- `ImageProcessingException` - Path traversal guard rejection or filesystem operation failure

**Used By**:

- `CarAdministrationService::merge()` — Moves images inside the merge transaction

**See Also**:

- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Exception patterns
- `CarImageProcessor` - Filename validation and secure generation
- [DATABASE.md](DATABASE.md) - `cars.image` JSON column

---

### CarVerificationManager

**Location**: `/usersc/classes/Car/CarVerificationManager.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Business-logic layer for the car-owner verification lifecycle
(verification codes, verification timestamps, owner email-deliverability flags).
Constructor-injected with a `CarRepository`; validates input, delegates
persistence to the repository, and mutates the passed-in `$carData` object
on success.

**Key Features**:

- CSPRNG-backed verification code generation (`bin2hex(random_bytes(16))`,
  128 bits of entropy)
- A shared `persist()` private helper deduplicates the
  validate → repo call → log-on-failure → throw pattern across the car
  methods that write to the repository (`generateVerificationCode()`
  is pure and makes no database call)
- The deliverability methods write the owner's `profiles` row and one
  `DeliverabilityFlagLog` row. They write nothing to `cars` and do not fan out (#2101)
- Every repository-writing method throws `CarDatabaseException` on failure (repository
  returns `false`, or the repository call itself throws) rather than
  returning a falsy value — callers do not need to check a return value for
  failure

**Methods**:

- `setVerificationCode(object $carData, string $verificationCode): bool` -
  Persist a car's verification code (min. 8 characters). The plaintext code
  is hashed (HMAC-SHA256 via `hashVericode()`) before storage in
  `cars.vericode`; the plaintext is never persisted, existing only in the
  immediate caller's scope to be composed into verification emails.
- `generateVerificationCode(): string` - Generate a new verification code; pure function, no repository call
- `markVerified(object $carData): bool` - Record that a car has been verified (sets `last_verified` to now)
- `setVerificationSentAt(object $carData, string $dateTime): bool` - Record when a verification email was sent
- `setBouncedFromEvent(int $ownerId, string $bouncedAddress, string $bouncedAt, string $source): bool` - Event entry
  point, keyed by the owner (#2358). Sets `email_bounced` on the owner's profile with the address and time.
  Skips with a log row (and returns false) for an owner with no `profiles` row. Returns true when a bounce now
  applies and none applied before. `$source` is one of `DeliverabilityFlagLog::SOURCES`.
  `EmailEventApplier::apply()` calls it.
- `setSuppressedFromEvent(int $ownerId, string $blockedAddress, ?string $cause, string $blockedAt, string $source): bool` -
  Event entry point, keyed by the owner (#2358). Sets `email_blocked` on the owner's profile with address, time and
  cause (a Brevo `spam` or `unsubscribed` event). Same skip rule and return value as `setBouncedFromEvent()`.
  `EmailEventApplier::apply()` calls it.
- `setSuppressedForOwner(int $ownerId): bool` -
  Owner opt-out (the link in the verification email). Sets `email_opted_out` and `email_opted_out_at`
  on the owner's profile, also when `email_blocked` or `email_bounced` is set. Source `owner`. Returns
  true when the flag changed from 0 to 1.
- `setBouncedForOwner(int $ownerId, string $bouncedAddress, int $actingUserId): bool` -
  Admin Mark Bounced. Sets `email_bounced`, the address and `email_bounced_at` (now) on the owner's
  profile. Source `admin`. Returns true when a bounce now applies and none applied before (#1884).
- `clearBouncedForOwner(int $ownerId, int $actingUserId): bool` -
  Admin Clear Bounced. Clears `email_bounced` and sets `email_bounce_cleared_at`. Source `admin`.
  Returns true when the flag changed from 1 to 0.
- `clearSuppressedForOwner(int $ownerId, int $actingUserId): bool` -
  Admin Clear Suppression. Until #2357, it clears `email_blocked` and `email_opted_out` on the
  profile. The bounce stays. Source `admin`. Writes one log row per flag that changed. Returns true when at
  least one flag changed.
- `clearSuppressedForOwnerByOwner(int $ownerId): bool` -
  Owner Resume via Account Settings. Clears `email_opted_out` and `email_opted_out_at` only. It leaves
  `email_blocked` and `email_bounced` unchanged, so a block still stops mail. Source `owner`. Returns true
  when the opt-out changed from 1 to 0. Used by `usersc/user_settings.php`.
- `clearFlagsForConfirmedEmailChange(int $actingUserId, int $userId): bool` -
  Clear the bounce and the block after a confirmed email change to a new address (#2356). Calls
  `CarRepository::clearFlagsForConfirmedEmailChange()`. It keeps the opt-out. Writes one audit row
  with source `email_change` for each flag that changed from 1 to 0. If a cleared flag had no
  recorded address, it logs the anomaly after the commit. The log row holds the user ID and the flag
  name, and no address. Runs its own transaction. Returns true when at least one flag changed. The
  `verifySuccess` hook (`sync_owner_email_on_verify.php`) is the only caller. The `email_act == 0`
  path in `usersc/user_settings.php` does not call it.
- The other owner methods run no transaction of their own. The caller opens one, so the profile write and the log row
  commit together. They throw `CarDatabaseException` when the owner has no `profiles` row (nothing is written).
  `getUserMessage()` is safe to show. The constructor takes an optional `DeliverabilityFlagLog` (default: a new one).
- `markSold(object $carData, ?string $soldDate): bool` - Record a car as sold (`null` defaults to today)

**Exceptions**:

- `CarDatabaseException` - Repository call failed or threw
- `CarValidationException` - Invalid input (e.g. a verification code under 8 characters, a malformed sold date)

**Used By**:

- Backend foundation for the car-owner verification system (issue #1155).
  Since #2358, `EmailEventApplier` calls `setBouncedFromEvent()` and
  `setSuppressedFromEvent()` when a Brevo delivery-status event reports a
  bounce or spam complaint. The
  verification-email-sending consumer for `setVerificationCode()`/
  `markVerified()`/`setVerificationSentAt()` still lands in a later
  verification-system milestone.

**See Also**:

- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Exception patterns
- [DATABASE.md](DATABASE.md) - `cars.vericode`, `cars.last_verified`, `cars.owner_last_updated`,
  `cars.vericode_sent_at`, `cars.solddate`, `profiles` deliverability columns

---

### DeliverabilityFlagLog

**Location**: `/usersc/classes/Car/DeliverabilityFlagLog.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Writes the audit row for each change to an owner's profile email
flag (#2101). `profiles` has no history table, so these `logs` rows are the
audit trail. The class is final.

**Key Features**:

- Constants for the flags (`FLAG_OPTED_OUT`, `FLAG_BLOCKED`, `FLAG_BOUNCED`, with
  the list `FLAGS`) and the sources (`SOURCE_OWNER`, `SOURCE_ADMIN`,
  `SOURCE_WEBHOOK`, `SOURCE_RECONCILIATION`, `SOURCE_IMPORT`,
  `SOURCE_EMAIL_CHANGE`, with the list `SOURCES`). `CarRepository::latestFlagClearAt()`
  and `recentFlagChanges()` validate against the same lists.
- The row has `logtype` `LogCategories::LOG_CATEGORY_EMAIL_BOUNCED`. The
  `logs.metadata` JSON holds `owner_id` (integer), `flag`, `old`, `new` and
  `source`. `lognote` repeats the values as text. The row never holds an email
  address, and `record()` has no address parameter.
- The constructor takes an optional `Closure` that receives
  `(userId, logtype, lognote, metadata)` and returns the new `logs.id`. It
  defaults to the UserSpice `logger()` helper. A unit test passes a recorder,
  so no database is needed.
- One exception to "each change writes a row": `restoreArchivedAccount()`
  copies the archived opt-out into the new profile and writes no row.

**Methods**:

- `record(int $actingUserId, int $ownerId, string $flag, int $old, int $new, string $source): bool` -
  Write one row. Returns false and writes nothing when `$old === $new`. Use
  `$actingUserId = 0` for a delivery event, a cron job or an import. Throws
  `InvalidArgumentException` for an unknown flag or source, a negative acting
  user ID, an owner ID below 1, or a value other than 0 or 1. Throws
  `CarDatabaseException` when the logger returns 0 or less (the insert failed).
- `assertValidFlag(string $flag): void` and `assertValidSource(string $source): void` -
  Static. Throw `InvalidArgumentException` for a value that is not in the list.

**Example**:

```php
$old = $repo->setProfileOptedOut($ownerId, true);   // old value, or null if no profiles row
if ($old !== null) {
    (new DeliverabilityFlagLog())->record(
        $ownerId,                                    // acting user (the owner)
        $ownerId,
        DeliverabilityFlagLog::FLAG_OPTED_OUT,
        $old,
        1,
        DeliverabilityFlagLog::SOURCE_OWNER
    );
}
```

**Used By**: `CarVerificationManager` (every owner and event method, including
`clearFlagsForConfirmedEmailChange()` with source `email_change`).

**See Also**:

- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#flag-change-audit-rows) - Row contents
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) - `LOG_CATEGORY_EMAIL_BOUNCED`

---

### CarBadges

**Location**: `/usersc/classes/Car/CarBadges.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: One definition of the status badges a car can show (#1900). The
account page hero and the cars list get badge keys from this class. The rule
that picks the badges is in `resolve()` only. `html()` is the only badge renderer. Pages
call it directly, and the cars list gets its output from `decorateRows()`.
The class is final, has a private constructor, and has only static methods.

The Vehicle Information card (`app/views/cars/_vehicle_info_card.php`) does
not call `forCar()` or `resolve()`. It draws two stamps: a Sold stamp in its
Sold row (from `isSold()` and `soldDate()`), and a Verified stamp in its
Verified row (from `verifiedStatus()`). The card is on the account page, the
car details page, and the public vericode landing page, so all three show
these stamps.

**Badges**:

| Key | Label | Shows when |
| --- | --- | --- |
| `new` | New | The car is new (cars list only) |
| `sold` | Sold | The car is sold |
| `verified` | Verified (with a check mark icon) | The car is fresh, not sold, and not new |

The display order is New, Sold, Verified. Each definition also has `tooltip`
and `tone` (the CSS tone, `er-badge--<tone>`). The `verified` tooltip is
"The owner confirmed, added, or updated this car's record in the last 12
months." The Verified row help button on the Vehicle Information card uses
the same sentence. The `new` tooltip gets its
numbers (90 days, 5 newest) from `CarShowcaseService::NEW_DAYS` and
`CarShowcaseService::NEW_FLOOR`, the same constants that
`getNewCarIds()` uses. The `verified` tooltip gets its number (12 months)
from `CarRepository::FRESHNESS_MONTHS`, the constant that
`CarRepository::freshnessCutoff()` uses.

**Methods**:

```php
public static function resolve(bool $sold, bool $fresh, bool $isNew): array
public static function forCar(object $car): array
public static function decorateRows(array $rows, array $newIds): array
public static function html(array $keys, string $style = 'flat'): string
public static function isSold(mixed $solddate): bool
public static function soldDate(mixed $solddate, int|string|null $carId = null): ?DateTimeImmutable
public static function verifiedStatus(object $car): ?array
public static function verifiedTooltip(): string
```

- `resolve()` is a pure function. It applies the "Shows when" rule in the
  table above and returns the badge keys in display order. It is marked
  `@internal`: pages call `forCar()` or `decorateRows()`. It stays public
  only because `CarBadgesTest` tests the rule directly.
- `verifiedTooltip()` returns the tooltip text of the Verified badge, not
  escaped. The Vehicle Information card uses it for the help button on its
  Verified row, so the badge and the row show the same text.
- `forCar()` takes a car record (for example `Car::data()`). Sold comes
  from `isSold()`. Fresh comes from `CarRepository::isFresh()` with
  `last_verified` and `owner_last_updated`. It never adds the `new` badge,
  because New is for the cars list only (`decorateRows()`).
- `decorateRows()` adds a `badges` key and a `badges_html` key to each cars
  DataTables row. `badges_html` is the flat badges from `html()` in a
  `<div class="er-badges ...">` row, or `''` when the car has no badges.
  `car-list.js` puts it after the Details link. The method reads
  `solddate`, the `is_fresh` column (from `CarRepository::freshnessSql()`),
  and `id`. A row without `is_fresh` is not fresh, and the method writes one
  log entry per call for those rows. It removes `is_fresh` from the row, so
  the API response does not expose it. Rows are objects (the
  `Database::results()` shape). Each row is cloned, so the input does not
  change. `$newIds` comes from
  `CarShowcaseService::getNewCarIds()`.
- `html()` returns one `<span class="er-badge er-badge--<tone>">` per known
  key, in the order of `$keys`. `$style` is `'flat'` (pill) or `'stamp'`
  (adds `er-badge--stamp`). Each span has a Bootstrap tooltip and
  `tabindex="0"`. The icon goes in an `aria-hidden="true"` span. Unknown keys
  give nothing. An empty result is `''`. There is no wrapper, so the caller
  owns the container. All values are escaped with `htmlspecialchars()`.
- `isSold()` is the sold rule for all pages: a `solddate` that is not null
  and not `''`. The save paths (`app/api/cars/save.php`, `CarValidator`)
  accept only a real `Y-m-d` date, so `isSold()` does not check the value
  again. `forCar()`, `decorateRows()`, and the vericode pages use it.
- `soldDate()` returns the sold date at midnight for display, or null when
  the car is not sold or the value is not a valid `Y-m-d` date. The account
  page, the car details page, and the vericode landing page use it for the
  Vehicle Information card. The vericode "already sold" notice also uses it.
  A sold car with a bad value (for example a zero date or `2024-02-30`) logs
  one entry to `LOG_CATEGORY_CAR_ERRORS`, with the car ID from `$carId` or
  `unknown`. `isSold()` is still true for that car, so the account hero and
  the cars list show Sold. The vericode landing page disables its sold button,
  and the "already sold" notice leaves out the date. The Vehicle Information
  card has no Sold row.
- `verifiedStatus()` returns the Verified row status for one car record. Returns
  an array with `source` and `date` keys when: the car is not sold, the car is
  fresh (verified or updated by its owner within the last 12 months), and the
  freshness dates are valid strings. Returns null otherwise (sold car, stale car,
  or missing/malformed dates). On malformed dates it logs one entry to the car
  errors category. It never reads `mtime`. `CarRepository::isFresh()` decides
  if the car is fresh, so the rule is not copied here. It returns the result
  of `CarRepository::freshnessSource()`, which reads the clock one time. For a
  fresh car, the source is `'confirmed'` when `last_verified` is not null and
  inside the freshness window, else `'current'`. The date is the
  `CarRepository::parseTimestamp()` result for the column of that source, so
  it is the validated date. The return shape is `array{source: 'confirmed'|'current', date: DateTimeImmutable}`.
  Used by the Vehicle Information card.

**Behavior on bad date data**: `forCar()` does not throw. When
`owner_last_updated` is missing or is not a string, `last_verified` is not
a string or null, or `CarRepository::isFresh()` throws
`CarValidationException`, the method writes one entry to the log
(`LogCategories::LOG_CATEGORY_CAR_ERRORS`) and treats the car as not fresh.
The Verified badge does not show. Sold still shows. `verifiedStatus()` does
the same and returns null. The log message names the method
(`CarBadges::forCar` or `CarBadges::verifiedStatus`), so the log shows which
page part has no Verified status.

**Usage**:

```php
use ElanRegistry\Car\CarBadges;

// Account page hero (in the template: <?= ... ?>)
echo CarBadges::html(CarBadges::forCar($carData), 'stamp');

// Cars list API (app/api/cars/list.php)
$response['data'] = CarBadges::decorateRows(
    $response['data'],
    (new CarShowcaseService())->getNewCarIds()
);
```

**How to add a badge**:

1. Add one entry to `CarBadges::BADGES`. Set `label`, `icon`, `tooltip`,
   and `tone`.
2. Add one line for the new key in `resolve()`, in display order, and a
   parameter if the rule needs new input.
3. Add a `--er-badge-<tone>` token and an `.er-badge--<tone>` rule in
   `usersc/templates/customizer.css`.
4. Add a case to `CarBadgesTest`, and add the badge to the Car status badges
   section of `app/admin/design-system.php`.

Do not add the new rules to a template or to `car-list.js`. `html()` draws
any key in `BADGES`, and the cars list JS needs no change.

**See Also**:

- [UI_STANDARDS.md](UI_STANDARDS.md#car-status-badges) - Tokens, classes, and accessibility rules

---

### EffectiveEmailFlags

**Location**: `/usersc/classes/Car/EffectiveEmailFlags.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Value object (`final readonly`) that holds the deliverability flags
that apply to one owner now (#2356). `CarRepository::effectiveEmailFlags()`
returns it.

**Key Features**:

- A stored `email_blocked` or `email_bounced` applies only while its `_address`
  matches the owner's current `users.email`. A flag with a NULL or empty
  `_address` applies (fail closed). `email_opted_out` applies whatever the
  address.
- Properties: `hasProfile`, `optedOut`, `optedOutAt`, `blocked`,
  `blockedAddress`, `blockedAt`, `blockedCause`, `bounced`, `bouncedAddress`,
  `bouncedAt` and `currentEmail`.
- The detail fields of a flag are set only when that flag applies. A reader
  cannot show an old address or date for a flag that does not apply.
- `currentEmail` is the raw `users.email`. A reader names it when an applied
  flag has a NULL or empty `_address`, because that flag applies to the
  current address. It is `null` when the owner has no profile.
- `EffectiveEmailFlags::none()` is the state of an owner with no `profiles`
  row. It has no flag. The caller must treat `hasProfile = false` as "do not
  mail".

**Used By**: `EmailNoticeBuilder`, `CarVerificationSendService::sendOne()`,
`BrevoSuppressionSyncJob`, `user_form_hook.php`, `usersc/user_settings.php`,
`app/owner/cars/details.php` and `app/views/cars/_vehicle_info_card.php`.

---

### EmailNoticeBuilder

**Location**: `/usersc/classes/Car/EmailNoticeBuilder.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Assembles the data for the account-page notice that tells an
owner that registry email is blocked, bounced or opted out (#1899, #2356). The
owner's `profiles` row is the system of record (#2101). Pure data assembler.
It returns a plain array and renders no HTML. The partial
`app/views/_email_paused_notice.php` escapes every field at render time.

**Key Features**:

- `buildForOwner(int $ownerId): ?array` - One owner-level state, or `null` when no
  flag applies. An owner with no car still gets the notice (#2319). The shape is
  `array{kind: 'blocked'|'bounced'|'opted_out', address: ?string, date: ?string,
  cause: ?string, alsoOptedOut: bool, optedOutDate: ?string, contentHash: string}`.
- Takes `CarRepository` by constructor injection, so a test can supply a mock. It
  calls only `CarRepository::effectiveEmailFlags()`. A flag stored for an old
  address does not show.
- `kind` constants: `KIND_BLOCKED`, `KIND_BOUNCED`, `KIND_OPTED_OUT`. One state wins.
  A block wins over a bounce. An opt-out is the `kind` only when no block or bounce
  applies. With a block or bounce, `alsoOptedOut` is true when the owner also opted
  out, and `optedOutDate` holds the opt-out date.
- `address` is the flagged address for `blocked` and `bounced`. If the flag has
  no stored address, it is the owner's current `users.email`. It is `null` for
  `opted_out`.
- `cause` is set only for `blocked`. It is one of the `CarRepository::BLOCKED_CAUSE_*`
  values. Any other stored value gives `null` (no cause, #2318).
- `date` and `optedOutDate` are `Y-m-d`. A NULL or invalid `_at` column gives
  `null`, and the partial omits the date.
- `contentHash` is a sha256 of the state. `account.php` uses it in the per-owner
  `sessionStorage` dismissal key, so a change to the state makes the notice
  reappear.
- The builder reads no `er_email_events` and no `cars_hist` rows. It has no
  per-car logic and no address list.

---

### VerificationEligibility

**Location**: `/usersc/classes/Car/VerificationEligibility.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Explains why an already-loaded car row is no longer due a
verification email (#1884). Extracted from app/admin/index.php's former
`eligibilitySkipReason()` function into its own class so the rule set is
directly unit-testable — constructing car-data objects and asserting on real
return values — instead of only via source-text inspection of an
unrequireable file.

**Key Features**:

- Encodes the SAME rules as `CarRepository::findVerificationEligible()`'s
  WHERE clause — not a second, independent definition of eligibility. If
  that SQL changes, this changes with it.
- Exists because the preview (GET) and the send (POST) are two separate
  requests, and a car can leave the eligible set in between (owner verifies
  or edits it, a bounce webhook fires, they opt out, or the car is marked
  sold). Re-running the query would show the row is gone from the result set
  but not WHY — this checks the row already loaded by `findById()`, with no
  second query, so the admin report can give a per-car reason.
- The owner-liveness clauses of the SQL (`INNER JOIN users`, the `noowner`
  exclusion), the owner profile clauses (no `profiles` row,
  `email_opted_out`, `email_blocked`, `email_bounced`) and the non-empty
  `users.email` clause are deliberately NOT
  duplicated here — they need a join this class has no row for. They stay
  enforced downstream in `CarVerificationSendService::sendOne()`.
- Fails closed on a malformed or MySQL zero-date `verification_attempts_since`
  by throwing rather than silently treating a corrupt timestamp as "outside
  the rolling window" (which would bypass the attempt cap).

**Methods**:

- `static skipReason(object $carData): ?string` - Checks, in priority order:
  sold, no owner on file, freshness (recently verified or updated), the 60-day cooldown, then the
  2-send-per-rolling-year attempt cap. It does not check `cars.email`: the send mails the owner's
  `users.email`, and `sendOne()` checks that (#2356). It no longer checks a bounce or a suppression
  flag (#2101): those are owner flags on `profiles`. Returns a short human-readable reason, or `null`
  if the car is still eligible.

**Exceptions**:

- `CarValidationException` - Thrown via `CarRepository::isFresh()` for a
  malformed `last_verified`/`owner_last_updated`, or directly for a
  malformed/zero-date `verification_attempts_since`

**Used By**:

- `VerificationBatchSender::processBatch()`, which re-checks eligibility for
  each car before calling `CarVerificationSendService::sendOne()`

**See Also**:

- [DATABASE.md](DATABASE.md) - `cars.solddate`, `cars.email`, `cars.user_id`,
  `cars.verification_attempts`, `cars.verification_attempts_since`

---

### VerificationBatchSender

**Location**: `/usersc/classes/Car/VerificationBatchSender.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Per-car loop body for the admin `verification_send_batch` POST
handler in `app/admin/index.php` (#1884, PR review gap-closing pass).
Extracted so the batch-loop failure containment and the
`SendResult::isUnrecorded()` -> report-bucket routing are directly
unit-testable — `app/admin/index.php` itself cannot be `require()`'d in a
unit test (needs the full UserSpice bootstrap).

**Key Features**:

- **Four independent try/catch guards, mirrored exactly from the original
  inline loop**: `findById()` throwing, `findById()` returning `null`,
  `VerificationEligibility::skipReason()` throwing, and `sendOne()`
  throwing. Each is scoped to one car so an uncaught fault never aborts the
  rest of the batch or silently drops subsequent cars from the report.
- **`isUnrecorded()` routed before the `STATUS_SENT` check**: an unrecorded
  send must land in the `unrecorded` bucket, never `sent` — reordering these
  checks was the exact defect commit 4224b511 already fixed once at a
  different layer.
- Not a general-purpose service — assumes the ids it receives already
  passed the admin authorization check and CSRF validation one layer up
  (`app/admin/index.php` filters non-positive ids before calling this
  class). Performs no authorization of its own.

**Methods**:

- `processBatch(array $carIds): array` - Given positive car ids, re-reads
  each via `CarRepository::findById()`, re-checks eligibility via
  `VerificationEligibility::skipReason()`, sends via
  `CarVerificationSendService::sendOne()`, and buckets the outcome. Returns
  `['sent' => array<object>, 'unrecorded' => array<array{car, reason}>,
  'skipped' => array<array{car, reason}>, 'failed' => array<array{car,
  reason}>]`.

**Constructor Dependencies**:

- `CarRepository $repo` - For `findById()` re-reads
- `CarVerificationSendService $sendSvc` - For `sendOne()`
- `int $currentUserId` - Passed through to every `logger()` call

**Used By**:

- Admin send tool's `verification_send_batch` case (Verification System tab
  in `app/admin/index.php`, #1884)

**See Also**:

- [VerificationEligibility](#verificationeligibility) - The eligibility
  re-check this class calls per car
- [CarVerificationSendService](#carverificationsendservice) - The send
  orchestration this class calls per car
- [SendResult](#sendresult) - The value object whose `isUnrecorded()` drives
  the bucket routing this class exists to cover

---

### VerificationSettings

**Location**: `/usersc/classes/Car/VerificationSettings.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Site-wide feature switch and readiness probes for the car
verification system — kept separate from `CarVerificationManager` above:
this class owns one global config row plus two infrastructure probes
(Brevo, cron), while `CarVerificationManager` writes per-car verification
fields and the owner's email flags on `profiles`. No shared state or
dependency exists between the two.

**Key Features**:

- Owns the single-row `er_verification_settings` table (`id = 1`)
- `brevoReady()` and `cronReady()` are computed live on every call, never
  cached
- **Asymmetric gate**: `setEnabled(true)` throws `VerificationConfigException`
  when `brevoReady()` is false; `setEnabled(false)` never throws, for any
  reason — an admin must always be able to disable verification mid-incident
- Every probe fails closed and logs rather than throwing, so a database or
  filesystem hiccup hides the feature instead of breaking the page
- Performs no permission checks itself — callers must run `hasPerm()` before
  calling `setEnabled()`

**Methods**:

- `isEnabled(): bool` - Whether verification is currently switched on
- `setEnabled(bool $enabled, int $actingUserId = 0): bool` - Turn verification on or off
- `brevoReady(): bool` - True only if `brevoNotReadyReason()` returns `null`:
  the plugin table is readable, the API key is saved, `plugins.ini.php` has
  `sendinblue = 1`, `override.php` is in place, `info.xml` shows plugin
  version 1.8.0 or later
- `brevoNotReadyReason(): ?string` - `null` when Brevo is ready. Otherwise a
  message that says why not. The admin Verification tab and the 422 response
  of the enable toggle show it
- `cronReady(): bool` - True if `lastCronRequestAt()` reports a timestamp
  within twice `CRON_TRANSPORT_INTERVAL_MINUTES` (`usersc/includes/config.php`;
  20 minutes today)
- `lastCronRequestAt(): ?DateTimeImmutable` - Timestamp of the most recent
  cron transport hit, read from `er_verification_settings.last_cron_request_at`
  (#1974; previously scanned the `logs` table)
- `recordCronRequest(): bool` - Record that the cron transport hit this
  environment; called by `users/cron/cron.php` on every non-denied hit,
  writing `er_verification_settings.last_cron_request_at`; never throws (logs
  and returns `false` on a DB error, or if the `id = 1` settings row is
  missing) (#1974)
- `incrementUnmatchedRecipientCounter(): bool` - Increment
  `er_verification_settings.unmatched_recipient_count` when a verification-tagged
  event of our mail matches no single user (#2359). Called by two subsystems:
  (1) the webhook receiver (`app/api/webhooks/brevo.php`'s `NO_USER_MATCH` branch,
  only when `BrevoWebhookEventProcessor::hasVerificationTag()` is true) and
  (2) `BrevoEventReconciliationJob::countUnmatched()`, which tests the event's tag.
  The suppression import does not call it.
  Never throws (logs and returns `false` on a DB error, or if the `id = 1` settings row
  is missing) since error paths must not break the caller's own response flow (#1887, #2085)
- `unmatchedRecipientCount(): ?int` - Read accessor for
  `er_verification_settings.unmatched_recipient_count`; returns `null` — never `0` —
  on a DB error, a missing `id = 1` row, or a non-numeric/negative stored value,
  logging the specific cause under `LOG_CATEGORY_VERIFICATION_CONFIG_WARNING`.
  Never throws. Callers must branch on `null`: a genuine zero and an unreadable
  counter must not render alike. The admin Verification tab shows a
  `text-bg-danger` "Unavailable" badge for the `null` case
  (`app/admin/includes/tab-verification.php`, #2085)
- `batchSize(): int` - Configured verification-email batch size
  (`er_verification_settings.batch_size`); fails closed to `5` on any read
  problem (#1884)
- `setBatchSize(int $size, int $actingUserId = 0): bool` - Set the batch
  size, clamped to `[1, 25]` regardless of the submitted value; never throws
  (logs and returns `false` on a DB error or unconfirmed write) (#1885)

**Exceptions**:

- `VerificationConfigException` - Thrown by `setEnabled(true)` when `brevoReady()` is false

**Used By**:

- Admin "Verification System" tab (`app/admin/index.php`, `app/admin/includes/tab-verification.php`)
- Toggle endpoint (`app/api/admin/verification-toggle.php`)
- Brevo webhook receiver (`app/api/webhooks/brevo.php`) — the
  `brevoNotReadyReason()` gate (it does not read the switch), and
  `incrementUnmatchedRecipientCounter()` (#1887)
- `SendVerificationBatchJob::refusesWhileSwitchOff()` — the `isEnabled()` gate,
  checked by `AbstractCronJob::run()` and `runNow()` before the job-level
  `enabled` flag. It is the only cron job that reads the switch (#2358). The
  reconciliation and suppression-sync jobs, and their `runNowWithSummary()`
  methods, run with the switch off. See
  `docs/development/DEPLOYMENT.md`'s cron-author contract for the full
  rationale.

**See Also**:

- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) - Full feature-switch design, the asymmetric gate, readiness-check semantics
- [DATABASE.md](DATABASE.md) - `er_verification_settings`, the `er_` table-prefix convention
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) - `LOG_CATEGORY_VERIFICATION_CONFIG_WARNING`, `LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED`

---

### SendResult

**Location**: `/usersc/classes/Car/SendResult.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Immutable value object describing the outcome of one car's
verification-email send attempt (#1884). Returned by
`CarVerificationSendService::sendOne()`.

**Key Features**:

- Named constructors (`sent()`, `failed()`, `sentUnrecorded()`) prevent
  inconsistent states (e.g. status "sent" with a non-null reason on a clean send)
- `sentUnrecorded()` is the deliberate exception: status stays `STATUS_SENT`
  (the email really was delivered and must never be retried), but `reason` is
  non-null so callers can render a distinct warning instead of silently
  folding a bookkeeping failure into an ordinary successful send
- `isUnrecorded(): bool` — true only for a `sentUnrecorded()` result; callers
  branch on this (not `reason !== null` directly) to route to a separate
  report bucket, keeping that rule defined once rather than re-derived at
  each call site
- No `skipped` status — skips are decided one layer up in the admin page,
  before `sendOne()` is called
- `reason` field is safe to render (already escaped at the point of render)

**Properties** (all `readonly`):

- `int $carId` - Car ID the send was attempted for
- `string $status` - `STATUS_SENT` or `STATUS_FAILED`
- `?string $reason` - Failure/warning reason (null only for a clean sent result)

**Factory Methods**:

- `static sent(int $carId): self` - Construct a clean success result
- `static failed(int $carId, string $reason): self` - Construct a failure result
- `static sentUnrecorded(int $carId, string $reason): self` - Construct a
  result for a genuinely delivered email whose bookkeeping (the
  `er_email_events` insert and/or `verification_attempts` increment) failed
  afterward

**Instance Methods**:

- `isUnrecorded(): bool` - True only for a `sentUnrecorded()` result

**Used By**:

- `CarVerificationSendService::sendOne()` (#1884)
- `VerificationBatchSender::processBatch()` (Verification System tab in
  `app/admin/index.php`) to build the result report

---

### CarVerificationSendService

**Location**: `/usersc/classes/Car/CarVerificationSendService.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: The single, shared verification-email send orchestration (#1884).
Owns the "pick eligible cars, rotate their codes, mail their owners, record
the outcome" operation. Both the manual admin tool and the future automated
cron job (#1885) call this service to prevent their eligibility rules and send
sequences from drifting.

**Key Features**:

- **Pure functions only**: every public method is a pure function of its
  arguments + current DB state. No `$_POST`/`$_SERVER`/session reads, no exit
  paths, no logging — anything specific to one caller's environment belongs
  in that caller
- **Single shared eligibility query**: `findEligible()` delegates verbatim to
  `CarRepository::findVerificationEligible()`, not re-implemented
- **Owner profile check in `sendOne()`**: `sendOne()` reads
  `CarRepository::effectiveEmailFlags()` and returns `SendResult::failed()`
  when the owner has no `profiles` row, has `email_opted_out` set, or has an
  `email_blocked` or `email_bounced` that applies to the current address. Then it
  fails the send when the owner's `users.email` is empty. The manual path checks one `cars`
  row that has no profile fields, and a flag can change between the batch
  preview and the send. Such a car shows under Failed, not Skipped.
- **Three separate scopes, never one transaction** in `sendOne()`: (A) rotate
  vericode+sent_at commit before network; (B) compose+send with no transaction;
  (C) record event+increment attempts in their own transaction on success.
  Prevents holding DB locks during slow Brevo calls and ensures failed sends
  don't consume the yearly attempt allowance.
- **Failed sends restore**: if `email()` returns false, the previous
  vericode/sent_at are atomically restored via one `updateCar()` call
- **Never report failure after real send**: if `email()` returned true, the
  owner has the message. Scope C is best-effort; on failure it logs the gap
  but still returns `SendResult::sent()` to prevent retry-triggered duplicates

**Methods**:

- `findEligible(int $limit, int $offset = 0): array` - List cars due for
  verification email; delegates to `CarRepository::findVerificationEligible()`
- `sendOne(object $carData): SendResult` - Send one car's verification email,
  return sent/failed

**Constructor Dependencies**:

- `CarRepository $repo` - For `findVerificationEligible()`, car writes, event insertion
- `CarVerificationManager $verifier` - For code generation and writes
- `CarVerificationEmailComposer $composer` - For email composition
- `?VerificationSettings $settings = null` - Optional 4th argument. For the
  `brevoReady()` check before each send. When `null`, the constructor uses
  `new VerificationSettings(dbi())`

**Mail tag**: `sendOne()` calls `email()` with
`['tags' => AppConstants::VERIFICATION_EMAIL_TAG]` (`car_verification`). It
returns a failed `SendResult` and leaves the vericode unrotated when
`brevoReady()` is false.

**Exceptions**:

- `CarDatabaseException` - From repository calls

**Used By**:

- `VerificationBatchSender::processBatch()` (Verification System tab in
  `app/admin/index.php`, #1884)
- Intended reuse by cron job (#1885)

**See Also**:

- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) — Admin send tool flow and shared-service design
- `app/admin/index.php` (Verification tab POST command handling) — How the admin tool uses this service

---

### SendPolicy

**Location**: `/usersc/classes/Car/SendPolicy.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: The one table of which owner flags stop which kind of mail
(#2360). Each send path asks it before it calls `email()`. The verification
send, owner contact and admin contact cannot drift apart. The mail table is in
[EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#send-policy-2360).

**Methods**:

- `__construct(CarRepository $repo)` - Reads the flags and the address matches
- `blockingFlags(MailKind $kind): array` - The flag names that stop this kind
  of mail. Public so a test can compare the table with the eligibility SQL
- `forUser(MailKind $kind, int $userId): SendDecision` - Decide for one owner.
  Reads `CarRepository::effectiveEmailFlags()`, so the address-match rule
  applies. The result holds only the flags that block this kind
- `forAddress(MailKind $kind, string $address): SendDecision` - Decide for an
  address that more than one user may hold. One blocking flag on any user stops
  the send. No user with the address gives `no_match`
- `verificationReason(SendDecision $decision): ?string` - The first refusal
  reason for the admin report, or `null` when allowed
- `adminMessage(SendDecision $decision): string` - The text for `usError()`.
  It starts with "Message not sent." and adds one sentence for each reason.
  It escapes the bounce date. It returns an empty string when allowed

**Exceptions**:

- `CarDatabaseException` - From the flag reads

**Used By**: `CarVerificationSendService`, `send-owner-email.php`,
`process-admin-contact.php`

---

### SendDecision

**Location**: `/usersc/classes/Car/SendDecision.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: The immutable result of one `SendPolicy` check (`final readonly`).

**Constructor**: `new SendDecision(array $flags, ?string $bouncedAt = null, int $matchCount = 1)`.
It throws `InvalidArgumentException` for a flag that is not a string in
`FLAG_ORDER`, and when `$matchCount` is negative or does not agree with
`no_match` (a count of 0 needs `no_match`, and `no_match` needs a count of 0). It removes duplicates and sorts the flags into `FLAG_ORDER`.

**Properties**:

- `bool $allowed` - Derived. True only when `$flags` is empty. You cannot pass it
- `list<string> $flags` - The flags that block this kind of mail
- `?string $bouncedAt` - The latest `email_bounced_at`. Kept only when
  `email_bounced` is in `$flags`, else `null`
- `int $matchCount` - The count of users checked: 1 from `forUser()`, the
  match count from `forAddress()` (0 for `no_match`). The admin log names it

**Constants**: `FLAG_NO_MATCH` (`no_match`), `FLAG_NO_PROFILE` (`no_profile`),
`FLAG_BOUNCED` (`email_bounced`), `FLAG_OPTED_OUT` (`email_opted_out`),
`FLAG_BLOCKED` (`email_blocked`), and `FLAG_ORDER` (the fixed order of these).
The three profile flag names are aliases of the `DeliverabilityFlagLog` constants.

**Methods**: `has(string $flag): bool` - True when the flag blocks the send.

---

### MailKind (enum)

**Location**: `/usersc/classes/Car/MailKind.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: The kinds of non-account mail that `SendPolicy` controls. A typo
in a caller is a fatal error and cannot let a send through.

| Case | Value | Sent by |
| --- | --- | --- |
| `Verification` | `verification` | The admin tool and the cron job |
| `OwnerContact` | `owner_contact` | `send-owner-email.php` |
| `AdminContact` | `admin_contact` | `process-admin-contact.php` |

---

### EmailEventMatcher

**Location**: `/usersc/classes/Car/EmailEventMatcher.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Finds the user that one Brevo delivery event belongs to (#2359).
It writes nothing. Test and production share one Brevo account, so an event is
"ours" only when its message ID is in this environment's `er_email_events`
`sent` rows or in `plg_sendinblue_messages`. An address match alone never makes
an event ours.

**Matching rule**: Both addresses go through `EmailAddress::normalize()` (lower
case and trim, nothing else). PHP then compares the bytes. SQL only selects
candidates, because the column collations ignore accents and trailing spaces.
The matcher never reads `cars.user_id` at event time, and never selects a
plugin row by address.

**Constructor**:

```php
public function __construct(private CarRepository $repo)
```

**Methods**:

- `matchByMessageId(string $messageId, string $address): EmailEventMatch` -
  1. Not ours (no `sent` row and no plugin row has the ID): `notOurs()`.
  2. Step (a): exactly one `sent` row with this ID has the event address.
     Result: that row's user and car, with the row's `occurred_at` as the
     message time. Two such rows give `noUser()`.
  3. Step (b): the plugin row with this ID has the event address as one whole
     `recipient_email`, and exactly one user holds the address. Result: that
     user, with no car and `accepted_at` as the message time. A row with more
     than one recipient matches nothing.
  4. Step (c): `noUser()`.

  Throws `CarDatabaseException` if a query fails.
- `matchUserByAddress(string $address): ?int` - For the suppression import.
  Returns the user ID when exactly one user holds the address under the
  matching rule. Returns null otherwise.

**Used By**:

- `BrevoWebhookEventProcessor`, `BrevoEventReconciliationJob`,
  `BrevoSuppressionSyncJob`

**See Also**:

- `EmailEventMatch` — The result type
- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#the-matching-rule) — The matching rule and its steps

---

### EmailEventMatch

**Location**: `/usersc/classes/Car/EmailEventMatch.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: `final` value object that `EmailEventMatcher` returns (#2359). It
has three kinds of result.

**Factories**:

- `notOurs()` - This environment did not send the message. The caller writes
  no row and sets no flag.
- `noUser()` - The message is ours, but no single user holds the event address.
  The caller writes no row and sets no flag.
- `matched(int $userId, ?int $carId, string $messageTime)` - The event belongs
  to one user. `$carId` is the car of the `sent` row, or null when the plugin
  row gave the match. `$messageTime` is when the message left this site. The
  date guards of `EmailEventApplier::apply()` compare against it. Throws
  `InvalidArgumentException` for an ID that is not more than 0, or an empty time.

**Methods**: `isNotOurs()`, `isNoUser()`, `isMatched()`, `userId()`, `carId()`,
`messageTime()`. The last three throw `LogicException` when the result is not
`matched`.

**See Also**:

- `EmailEventMatcher` — Producer
- `EmailEventApplier` — Consumer

---

### BrevoWebhookEventProcessor

**Location**: `/usersc/classes/Car/BrevoWebhookEventProcessor.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Parsing, matching and recording logic for inbound Brevo
delivery-status webhook events (#1887). It handles events of all registry mail
(#2359). No HTTP concerns (`http_response_code()`, `exit`) live here — that
separation is what makes this class unit-testable without the subprocess the
endpoint itself needs.

**Key Features**:

- Rejects a malformed payload before any database call: non-object JSON, a
  top-level list, a missing or empty `email`, `event` or `message-id`, a
  message ID longer than 255 bytes, and an address longer than 155 bytes or one
  that fails `FILTER_VALIDATE_EMAIL`
- Accepts only the eight events in `DELIVERY_EVENTS` (`delivered`,
  `soft_bounce`, `hard_bounce`, `blocked`, `invalid`, `invalid_email`, `spam`,
  `unsubscribed`). Any other event is `IGNORED`, and the log line names the
  event only (at most 32 characters). The webhook subscribes only to delivery
  events, so another name shows a Brevo change. There is no tag filter
- Finds the user with `EmailEventMatcher::matchByMessageId()`. A message that
  this environment did not send is `IGNORED`
- A missing or invalid `ts_event`/`ts`, or one more than 5 minutes after
  receipt, falls back to the receipt time. The log line names the user ID only
- Delegates the transaction, the guards and the flag rules to
  `EmailEventApplier::apply()` in `MODE_EVENT`, shared with the reconciliation
  job (#1889)
- Calls `sendinblueRecordEvent($messageId, $event, $occurredAtSeconds, $reason)`
  when the function exists. A `false` return or an exception is logged (no
  message) and does not change the result
- No log line holds an email address. No `catch` block calls `getMessage()`

**Methods**:

- `process(mixed $decodedPayload): ProcessingResult` - Process one decoded
  webhook payload. The only instance method that the endpoint calls
- `static hasVerificationTag(mixed $decodedPayload): bool` - True when `tags`
  is a list that holds `AppConstants::VERIFICATION_EMAIL_TAG`. The endpoint
  uses it to decide whether to increment the unmatched recipient counter. A
  `tags` value that is present but not a list is logged (its type only) as a
  payload contract change

**Constructor Dependencies**:

- `EmailEventMatcher $matcher` - Finds the user of the event
- `EmailEventApplier $applier` - Records the event and applies its flag

**Used By**:

- Brevo webhook receiver (`app/api/webhooks/brevo.php`)

**See Also**:

- `EmailEventMatcher`, `EmailEventApplier`
- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) - Full webhook receiver contract, escalation rules, HTTP status table
- [DATABASE.md](DATABASE.md) - `er_email_events`, `profiles` deliverability columns
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) - `LOG_CATEGORY_EMAIL_WEBHOOK`

---

### EmailEventApplier

**Location**: `/usersc/classes/Car/EmailEventApplier.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Records one matched Brevo delivery-status event and applies the
flag escalation that the event implies, in one transaction (#2359). The event
row belongs to the owner (#2358), and the flag is written to the owner's
profile (#2101). Extracted from `BrevoWebhookEventProcessor` because the
nightly reconciliation job (#1889) and the suppression import (#1923) need the
same rules.

**Key Features**:

- Payload-agnostic: takes an `EmailEventMatch` and already-validated scalars
- One transaction for each event. It locks the owner's `profiles` row first
  (`CarRepository::lockEventGuardState()`). Any failure rolls back the event
  row, the flag and the audit row, and rethrows a `CarDatabaseException`
- Date guards: the message time must not be before `join_date` or
  `email_address_since`. For a bounce event it must not be before
  `email_bounce_cleared_at` either. A failed guard writes nothing
- A duplicate event changes no column. Only a new row is "new"
- Cuts `reason` to 255 characters of valid UTF-8
- Escalation rules owned here:
  - `hard_bounce` / `blocked` / `invalid` / `invalid_email` immediately set
    the owner's profile `email_bounced`
  - `soft_bounce` sets nothing until 3 or more distinct send cycles
    (`brevo_message_id` values) since the email's last `delivered` event, the
    owner's last Clear Bounced and the owner's last address change. Then it
    escalates to bounced
  - `delivered` only records the event row, and implicitly resets the
    soft-bounce window. Any other event name (for example `unique_opened`)
    also only records the row. No caller passes another name: the webhook
    logs and drops an event that is not a delivery event
  - `spam` sets the owner's `email_blocked` with cause `spam`, and
    `unsubscribed` sets it with cause `unsubscribed`. The event must be later
    than the owner's latest admin Clear Block
- The flag changes only when the event address is the owner's current
  `users.email`
- `HARD_BOUNCE_EVENTS` and `SUPPRESSION_EVENTS` are `public const`, so
  read-only consumers (the admin user view, the chip query) share this
  vocabulary

**Constants**: `MODE_EVENT = 'event'` and `MODE_STATE = 'state'`.

- `MODE_EVENT` (webhook, reconciliation): the flag changes only when the event
  row is new.
- `MODE_STATE` (suppression import): Brevo's blocklist is a current state. The
  flag changes when it is clear, or when its stored address differs from the
  event address. The `join_date` and `email_address_since` guards do not
  apply. The bounce guard and the Clear Block bound compare `$occurredAt`
  (Brevo's `blockedAt`).

**Constructor Dependencies**:

- `CarRepository $repo` - Locked guard read, event insertion
  (`insertOwnerEmailEvent()`) and soft-bounce cycle counting
- `CarVerificationManager $verificationManager` - Flag writes
  (`setBouncedFromEvent()`, `setSuppressedFromEvent()`), which run inside the
  applier's transaction

**Methods**:

```php
public function apply(
    EmailEventMatch $match,
    string $address,
    string $event,
    ?string $reason,
    string $messageId,
    string $occurredAt,
    string $source,
    string $mode,
    ?string $blockedCause = null
): bool
```

- `$match` must be a matched result. `$source` is one of
  `DeliverabilityFlagLog::SOURCES` (`webhook`, `reconciliation`, `import`).
  `$mode` is `MODE_EVENT` or `MODE_STATE`. `$occurredAt` is the event time
  (`blockedAt` for the import).
- `$blockedCause` replaces the cause that the event name gives. It is allowed
  only for a suppression event in `MODE_STATE`, and must be one of
  `CarRepository::BLOCKED_CAUSES`. The import passes `blocklist` for Brevo's
  `adminBlocked`, while the event row stays `unsubscribed`.
- Returns true when the call wrote a new event row.
- Throws `InvalidArgumentException` for an unmatched result, an unknown source
  or mode, or a cause that is not allowed (nothing is written).
  Throws `CarValidationException` for an invalid datetime (nothing is written).
  Throws `CarDatabaseException` when a read or write fails (all writes roll back).

**Used By**:

- `BrevoWebhookEventProcessor::process()`
- `BrevoEventReconciliationJob::applyEvent()` — Backfill of missed events (#1889)
- `BrevoSuppressionSyncJob` — Suppression import (#1923)

**See Also**:

- `EmailEventMatcher`, `EmailEventMatch`
- `BrevoWebhookEventProcessor`, `BrevoEventReconciliationJob`
- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#event-recording-and-the-car-chip) - The transaction steps and the chip rule
- [DATABASE.md](DATABASE.md) - `er_email_events`, `profiles` deliverability columns
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) - `LOG_CATEGORY_EMAIL_WEBHOOK`

---

### ProcessingResult (enum)

**Location**: `/usersc/classes/Car/ProcessingResult.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Outcome of `BrevoWebhookEventProcessor::process()`. The webhook
endpoint maps each case 1:1 to an HTTP status (#1887).

**Cases**:

| Case | Meaning | Response |
| --- | --- | --- |
| `MATCHED_AND_RECORDED` | The event matched one user and the transaction committed | 2xx |
| `IGNORED` | Not a delivery event (for example `opened`), or a message that this environment did not send | 2xx, no row, no log |
| `NO_USER_MATCH` | The message is ours, but no single user holds the address | 2xx, logged. A verification-tagged event increments the unmatched counter |
| `MALFORMED` | The payload is not a JSON object, misses a required field, or has a bad address or a message ID over 255 bytes. Or the match or apply step cannot parse a stored value (for example a zero message time) | 4xx, logged |
| `WRITE_FAILURE` | A database read or write failed | 5xx. The only retryable case |

Since #2359, `NO_TAG_MATCH` and `NO_CAR_MATCH` do not exist.
`NO_CAR_MATCH` became `NO_USER_MATCH`.

**Used By**:

- Brevo webhook receiver (`app/api/webhooks/brevo.php`)

**See Also**:

- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) - HTTP status contract table

---

## Cron Jobs

### AbstractCronJob

**Location**: `/usersc/classes/Cron/AbstractCronJob.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: Template-method base class for all UserSpice cron jobs. Provides
three-layer mitigation against the subprocess-less, in-process dispatch model
of `users/cron/cron.php`: crash isolation, a `set_time_limit()` backstop, and
a job-owned enabled flag independent of UserSpice's own `crons.active`.

**Key Features**:

- **Crash isolation**: `execute()` runs inside `try/catch(\Throwable)` that
  logs and never rethrows, so one job's bug cannot cascade to the next job in
  cron.php's loop
- **Timeout backstop**: `set_time_limit()` sized from `CRON_TRANSPORT_INTERVAL_MINUTES`,
  applied only after a run has been claimed, so a hung job does not run
  indefinitely
- **Job-owned enabled flag**: `er_cron_job_runs.enabled` checked independently
  of UserSpice's `crons.active`; deliberate pause logged as skip
  (`LOG_CATEGORY_CRON_JOB_SKIPPED`), missing/unreadable row logged as failure
  (`LOG_CATEGORY_CRON_JOB_FAILURE`)
- **Manual run path**: `runNow()` bypasses enabled check and guard claim for
  admin-triggered immediate execution, retaining crash isolation only
- **Verification feature switch (opt-in)**: `refusesWhileSwitchOff()` returns
  `false` by default, so a job runs with the switch off. A job that sends
  email overrides it to return `!$settings->isEnabled()`. `run()` checks it
  before the enabled read and the guard claim, so a refused run does not stamp
  `last_run_at`. `runNow()` checks it first (#2358)

**Abstract Methods (implemented by subclasses)**:

- `jobName(): string` — The `er_cron_job_runs.job_name` value for this job
- `guardIntervalHours(): int` — Minimum hours between claimed runs (≥1);
  passed to `CronJobGuard::claim()`
- `execute(): void` — The job's actual work; any `\Throwable` is caught and
  logged by `run()`

**Protected Methods (override in a subclass)**:

- `refusesWhileSwitchOff(): bool` — Whether the job must not run now because
  the site-wide verification switch is off. The default returns `false`.
  Only `SendVerificationBatchJob` overrides it

**Public Methods**:

- `run(): void` — Check `refusesWhileSwitchOff()`, check enabled state, claim
  the guard, set time-limit backstop, execute with crash isolation; never throws
- `runNow(): void` — Check `refusesWhileSwitchOff()`, then execute immediately,
  bypassing enabled check and guard; for admin-triggered manual runs only;
  never throws

**Constructor**:

```php
public function __construct(protected readonly DatabaseInterface $db)
```

**Used By**:

- `BrevoEventReconciliationJob` (extends `AbstractCronJob`)
- `BrevoSuppressionSyncJob` (extends `AbstractCronJob`)
- `SendVerificationBatchJob` (extends `AbstractCronJob`; no `runNowWithSummary()`
  override — relies entirely on inherited `run()`/`runNow()`. It overrides
  `refusesWhileSwitchOff()`, so it is the only job that the switch stops)

**See Also**:

- `CronJobGuard` — Atomic claim semantics (`last_run_at` guard, `enabled` gate)
- `CronJobEnabledState` — Three-state resolution of enabled status
- [DEPLOYMENT.md](DEPLOYMENT.md) — "Timeout & Crash Isolation" section (full contract rationale)
- [DATABASE.md](DATABASE.md) — `er_cron_job_runs` schema
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_FAILURE`, `LOG_CATEGORY_CRON_JOB_SKIPPED`

---

### CronJobEnabledState (enum)

**Location**: `/usersc/classes/Cron/CronJobEnabledState.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: Outcome of `AbstractCronJob`'s `er_cron_job_runs` enabled-state
read. Distinguishes three reasons a job is "not enabled" that call for
opposite operator responses, rather than collapsing them into a single `false`
boolean.

**Cases**:

- `ENABLED` — Row exists and `enabled = 1`; the job may claim a run
- `DISABLED` — Row exists and `enabled = 0`; an operator deliberately paused
  it; logged as skip (not a failure)
- `MISSING` — No `er_cron_job_runs` row for this job name; never seeded or
  deleted; logged as failure (infrastructure issue)
- `UNREADABLE` — The read itself failed (connectivity, grants, missing table);
  logged as failure (infrastructure fault)

**Used By**:

- `AbstractCronJob::run()` — Enables distinct logging for pause vs. infrastructure fault
- `CronJobRunsReader::status()`/`badgeFor()` — Admin UI display (#2054)

**See Also**:

- `AbstractCronJob` — Logs the three non-enabled cases distinctly
- `CronJobRunsReader` — Display-side counterpart reading the same table for admin UI
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_SKIPPED` (DISABLED), `LOG_CATEGORY_CRON_JOB_FAILURE` (MISSING/UNREADABLE)

---

### CronJobRunsReader

**Location**: `/usersc/classes/Cron/CronJobRunsReader.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: Read-only, never-throws access to `er_cron_job_runs` for admin
UI display. `AbstractCronJob::enabledState()` reads the same table but is
scoped to the per-job cron *dispatch* context (private, no job-name
parameter, advances skip-logging bookkeeping). This is the display-side
counterpart: any job name, no side effects, returns both `enabled` state and
`last_run_at`. Same shape as `VerificationSettings` — the established
pattern for read-only, admin-facing status classes.

**Constructor**:

```php
public function __construct(private DatabaseInterface $db)
```

**Public Methods**:

- `status(string $jobName): array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable}`
  — Single-query read of a job's state, last-run timestamp, and last-failure
  timestamp. Prefer this over calling `state()`/`lastRunAt()` separately,
  which issues two queries and can report the two values as of different
  moments on an intermittent fault. `lastFailureAt` (#2148) joined this same
  read rather than getting a method of its own because it is only ever
  useful *compared against* `lastRunAt` (see `badgeFor()`) — reading the two
  at different moments could report a failure as current that a run since
  superseded.
- `state(string $jobName): CronJobEnabledState` — Thin wrapper over `status()`
- `lastRunAt(string $jobName): ?DateTimeImmutable` — Thin wrapper over `status()`;
  null for a missing/unreadable row, a job that has never run, or an
  unparseable stored value (including MySQL zero-dates)
- `badgeFor(CronJobEnabledState $state, ?DateTimeImmutable $lastRunAt, ?DateTimeImmutable $lastFailureAt = null):`
  `array{badgeClass: string, icon: string, text: string}`
  (static) — Maps a state/timestamp pair to display attributes; `MISSING`
  and `UNREADABLE` render identically ("Status unavailable"), `DISABLED`
  ("Paused") is visually distinct from both. Within `ENABLED`, a distinct
  danger "Last run failed" badge (#2148) renders when `$lastFailureAt` is at
  or after `$lastRunAt` — `last_run_at` is stamped *before* a claimed run's
  `execute()` runs, so on its own it cannot distinguish a run that finished
  from one that threw; comparing the two timestamps is what closes that gap.
  `>=`, not `>`: both columns are written by `NOW()` at one-second
  resolution, and a job that throws immediately after being claimed stamps
  both within the same second. `$lastFailureAt` defaults to `null` so
  callers written before this parameter existed keep their previous
  behavior.
- `lastOutcomeCounts(string $jobName): array{counts: array{sent: int, skipped: int, failed: int}|null, unreadable: bool}`
  (#1885) — Sent/skipped/failed tallies from a job's most recent *successful*
  run, kept separate from `status()` since these three columns are a
  dashboard-only readout with no bearing on the enable/pause decision.
  `counts` is `null` both when the job has genuinely never run (routine) and
  when the read itself failed (fault) — callers must check `unreadable` to
  tell the two apart, the same way `CronJobEnabledState::MISSING`/`UNREADABLE`
  are kept distinct from `DISABLED`. A row entirely missing for the job name
  is also `unreadable = true`, matching `status()`'s own `MISSING`
  resolution for that condition. Because these columns are written only at
  the end of a successful `execute()`, they stand as the *previous*
  successful run's numbers when the most recent claimed run failed — see
  `badgeFor()`'s failure case above, which is exactly when a caller should
  qualify this readout as stale rather than current.

**Used By**:

- `app/admin/includes/tab-verification.php` — Verification tab's "Last
  reconciliation run" row (#2054) and "Automatic Sending" panel (#1885)

**See Also**:

- `AbstractCronJob` — dispatch-context counterpart reading the same table
- `CronJobEnabledState` — the enum this class's `state()`/`status()` return
- `CronJobFailureLogReader` — Complementary read of the `LOG_CATEGORY_CRON_JOB_FAILURE`
  log category itself, for when the aggregate fault history (not just the
  latest claimed run per job) is what the page needs to show
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_FAILURE`
  (MISSING/UNREADABLE and unparseable `last_run_at`/`last_failure_at` values)

---

### CronJobFailureLogReader

**Location**: `/usersc/classes/Cron/CronJobFailureLogReader.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: Read-only, never-throws access to recent
`LOG_CATEGORY_CRON_JOB_FAILURE` log entries, for display (#2148).
`AbstractCronJob::run()`, `SendVerificationBatchJob`, `CronJobRunsReader`,
and `CronJobGuard::recordFailure()` all file entries under this category,
but until this class existed nothing in the admin UI read it back — several
of those log messages literally instruct the reader to "check the system
log for details," with nothing on the page pointing there. `CronJobRunsReader`
reports only the most recent claimed run *per job*; this reports the fault
channel as a whole, across every job, including faults from a row that
could not even be read (which `CronJobRunsReader` cannot represent) and from
the manual `runNow()` path (which never stamps a row at all — see
`AbstractCronJob::runNow()`'s own docblock).

**Constructor**:

```php
public function __construct(private DatabaseInterface $db)
```

**Public Methods**:

- `recentFailures(int $limit = 5): array{count: int|null, recent: list<array{loggedAt: string, message: string}>}`
  — Summarizes failures logged within `LOOKBACK_DAYS` (7 days — long enough
  to make a repeating nightly fault obvious by its count alone, short
  enough that a fault fixed last month has aged out). `count` is `null`,
  not `0`, when the summary could not be read — this is the fault channel
  itself, so "no failures" is exactly the reading that tells an operator to
  stop looking; rendering an unreadable count as a reassuring `0` would be
  the same failure mode `VerificationSettings::unmatchedRecipientCount()`
  avoids for the same reason. A genuine `0` (query succeeded, found
  nothing) is the only case `count` is actually zero.

**Used By**:

- `app/admin/includes/tab-verification.php` — Verification tab's "Cron job
  failures" summary row

**See Also**:

- `CronJobRunsReader` — Per-job status/badge counterpart reading `er_cron_job_runs`
- `AbstractCronJob` — Where every `LOG_CATEGORY_CRON_JOB_FAILURE` entry this
  class reads ultimately originates
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_FAILURE`

---

### CronRequestGate

**Location**: `/usersc/classes/Cron/CronRequestGate.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: The `cron_ip` allowlist check plus last-cron-request bookkeeping,
extracted from `users/cron/cron.php` (#1974, #2086) so the ordering the
original bug was about is unit-testable. `cron.php` remains the actual
transport entry point — it still owns request bootstrapping and the per-job
dispatch loop; this class owns exactly the two things #1974 found broken:
deny-and-die on a `cron_ip` mismatch, and recording a successful request —
strictly in that order, since a denied hit must never be recorded. Never
throws: depends on `VerificationSettings::recordCronRequest()`'s documented
never-throws contract, since `cron.php` calls this with no `try`/`catch`.

**Used By**:

- `users/cron/cron.php` — the sole call site, immediately before the
  per-job dispatch loop

**See Also**:

- `VerificationSettings::recordCronRequest()` — the never-throws write this
  class depends on
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_REQUEST`

---

### SendVerificationBatchJob

**Location**: `/usersc/classes/Cron/SendVerificationBatchJob.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: Automatic nightly send of one verification-email batch (#1885).
Puts the manual "Send Batch Now" admin tool's send path (#1882-#1884) on
UserSpice's cron transport so batches go out unattended. No send logic lives
here — `execute()` selects candidates via
`CarVerificationSendService::findEligible()` and hands their ids to
`VerificationBatchSender::processBatch()`, the exact class the manual admin
handler already calls, so the two paths can never drift apart.

**Key Features**:

- **The only job that the verification switch stops**: it overrides
  `refusesWhileSwitchOff()` to return `!$this->settings->isEnabled()` (#2358).
  With the switch off, `run()` and `runNow()` send nothing, and `run()` leaves
  `er_cron_job_runs.last_run_at` unchanged. The first `run()` after the switch
  goes on claims the run and sends
- **Bounded work per invocation**: one page of at most
  `VerificationSettings::batchSize()` cars (clamped to `[1, 25]`) per claimed
  run, never a loop until the eligible set empties — both for cron's
  in-process dispatch model and to enforce the deliberate slow send-volume
  ramp
- **Ships paused everywhere**: the seed migration sets
  `er_cron_job_runs.enabled = 0` in every environment (test and production
  alike), since no `APP_ENV`-style environment-detection convention exists
  in this codebase — an admin must explicitly resume it via the dashboard
  Pause/Resume control
- **`unrecorded` folded into `failed`**: `processBatch()` returns four
  outcome buckets, but only three columns exist to persist them in — a car
  whose email sent but whose bookkeeping write failed is deliberately
  counted as `failed` for the persisted/display counts (conservative: a
  false failure prompts investigation), while the routine completion log
  line still reports all four buckets distinctly
- **Manual "Send Batch Now" now respects Pause**: `app/admin/index.php`'s
  `verification_send_batch` handler checks
  `CronJobRunsReader::state(SendVerificationBatchJob::JOB_NAME)` before
  doing anything else, failing closed (blocking the send) on any state other
  than `ENABLED` — this is a different, narrower gate than
  `VerificationSettings::isEnabled()`'s site-wide kill switch, which that
  handler deliberately never checks (see that file's own docblock)

**Configuration Constants**:

- `JOB_NAME = 'send_verification_batch'` — `er_cron_job_runs.job_name` value
- `GUARD_INTERVAL_HOURS = 20` — **Public.** Claim interval (same 20-not-24
  rationale as `BrevoEventReconciliationJob`). Public so the admin
  Verification tab can show the next-eligible time from the same value the
  guard uses.

**Constructor**:

```php
public function __construct(
    DatabaseInterface $db,
    private readonly VerificationSettings $settings,
    private readonly CarVerificationSendService $sendSvc,
    private readonly VerificationBatchSender $sender,
)
```

**Methods**:

- `jobName(): string` — Returns `'send_verification_batch'`
- `guardIntervalHours(): int` — Returns `20`
- `refusesWhileSwitchOff(): bool` — Protected. Returns `true` while the
  verification switch is off. `AbstractCronJob::run()` and `runNow()` call it
  before any other step
- `execute(): void` — Read the configured batch size, ask
  `CarVerificationSendService::findEligible()` for that many candidates,
  hand ids to `VerificationBatchSender::processBatch()`, persist outcome
  counts, log a routine completion line under `LOG_CATEGORY_CAR_VERIFICATION`

**Private Methods**:

- `recordRunCounts(int $sent, int $skipped, int $failed): void` — Persist
  this run's outcome counts to `er_cron_job_runs`. Never rethrows — a
  failure to write three display integers must not make
  `AbstractCronJob::run()`'s catch-all report a successful batch as failed.
  Uses a confirmation `SELECT` (not `count() === 0`) to detect a genuinely
  missing row, since a plain `UPDATE` writing the same values two runs
  running legitimately reports zero rows changed under MySQL's
  changed-vs-matched `rowCount()` semantics

**Used By**:

- Cron dispatch (`users/cron/cron.php`) via `AbstractCronJob::run()`, through
  the thin shim `users/cron/send_verification_batch.php`
- `app/admin/index.php` — `verification_toggle_cron` (Pause/Resume) and
  `verification_send_batch` (pause-check) commands reference
  `SendVerificationBatchJob::JOB_NAME`

**See Also**:

- `AbstractCronJob` — Template-method base (crash isolation, enabled check, guard claim)
- `BrevoEventReconciliationJob` — Closest sibling; same base class and DI style
- `CarVerificationSendService`, `VerificationBatchSender` — Shared send path (#1884)
- `CronJobRunsReader::lastOutcomeCounts()` — Dashboard readout of the counts this job writes
- `CronJobGuard` — Atomic claim semantics
- [DATABASE.md](DATABASE.md) — `er_cron_job_runs` schema (`last_sent_count`/`last_skipped_count`/`last_failed_count`)
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_FAILURE`, `LOG_CATEGORY_CAR_VERIFICATION`

---

### BrevoDevOverride

**Location**: `/usersc/classes/Email/BrevoDevOverride.php`

**Namespace**: `ElanRegistry\Email`

**Purpose**: Routes the Brevo SDK to a local mock (mock-brevo) in the
development environment. It never changes the host in test or production.

**Methods**:

- `hostOverride(): ?string` - Returns `BREVO_API_HOST` when
  `US_ENVIRONMENT` is `development` and the value is not empty. Returns `null`
  in all other cases. Pure function
- `applyToSdk(string $autoloadPath): bool` - Loads the plugin's
  `vendor/autoload.php` and sets the override host on the Brevo SDK's shared
  default `Configuration`. The sendinblue plugin uses the same object, so its
  sends and delivery-status checks reach mock-brevo. Returns `true` when it set
  the host. Returns `false` when the override is inactive, the autoloader is
  not a readable file, or the SDK class is missing. Never throws. Logs
  `EmailError` when loading the SDK fails (#2184)

**Used By**:

- `usersc/includes/rate_limits_dev_override.php` (`applyToSdk()`, loaded by
  `usersc/includes/loader.php` on each request)
- `BrevoEventReconciliationClient`, `BrevoSuppressionSyncClient`
  (`hostOverride()`)

**See Also**:

- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#local-development) - Local mock setup

---

### BrevoEventReconciliationClient

**Location**: `/usersc/classes/Cron/BrevoEventReconciliationClient.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: Thin, read-only wrapper around the vendored Brevo SDK's
`TransactionalEmailsApi::getEmailEventReport()` endpoint (`GET /smtp/statistics/events`).
Used by the reconciliation job to poll Brevo for delivery events missed by the
webhook, with explicit HTTP timeouts preventing stalled connections from
holding cron runs open indefinitely.

**Key Features**:

- Explicit `timeout` and `connect_timeout` options (30s and 10s respectively)
  applied to Guzzle client, unlike the plugin's own `sendinblue()` function
  which leaves both at unlimited
- All failure modes (no API key, missing SDK, DB error, HTTP error)
  return `null` rather than throwing, so reconciliation poll failures do not
  abort the whole cron run; an empty array means the poll succeeded and Brevo
  genuinely returned zero events for the window — the two are distinguishable
  (#2061), unlike this method's original `array`-only return type (#1889)
- Defensive reads of the API key from `plg_sendinblue`, with distinct
  logging: `SKIPPED` for "no API key" (expected), `FAILURE` for
  "key may exist but the read or call failed" (infrastructure fault). The
  client checks only the key. It does not run the six-check readiness gate
  of `VerificationSettings::brevoReady()`
- Idempotent SDK autoloader with `require_once` and `class_exists()` short-circuit

**Constructor**:

```php
public function __construct(private readonly DatabaseInterface $db)
```

**Public Methods**:

- `fetchEvents(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate, int $limit, int $offset, string $eventType): ?array`
  — Fetch one page of delivery events of one type (Brevo's API name, for
  example `hardBounces` or `delivered`) for the date window (#2359). One type
  for each call, because a mixed page lets a high-volume type push a bounce
  out of the page. Returns
  `\Brevo\Client\Model\GetEmailEventReportEvents[]` on success (empty array if
  Brevo returned no events), or `null` if the poll itself failed (never
  throws)

**Private Methods**:

- `apiKey(): ?string` — Read Brevo API key from plugin settings; null if
  there is no API key or the row is unreadable
- `loadSdk(): bool` — Load vendored SDK autoloader; idempotent, safe to call
  when already loaded

**Used By**:

- `BrevoEventReconciliationJob::backfillEvents()` — Polls Brevo for events to
  backfill, one call for each event type

**See Also**:

- `BrevoEventReconciliationJob` — Nightly reconciliation job (#1889)
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_SKIPPED` (no API key), `LOG_CATEGORY_CRON_JOB_FAILURE` (connectivity/SDK issues)

---

### BrevoEventReconciliationJob

**Location**: `/usersc/classes/Cron/BrevoEventReconciliationJob.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: Nightly backfill of Brevo delivery events the webhook (#1887)
never delivered, plus retention pruning of `er_email_events` (#1889). Closes
the lossy gap of the webhook by re-reading events from Brevo's statistics API
and applying them through the same `EmailEventMatcher` and `EmailEventApplier`
the webhook uses, so backfilled events escalate identically to live ones. It
applies events of all registry mail (#2359). It passes the source
`reconciliation` and `MODE_EVENT` to the applier (#2101).

**Key Features**:

- **Bounded work per invocation**: Fetches exactly one page for each event
  type per run (never a pagination loop), safe for in-process dispatch model
- **One fetch for each event type** (#2359): `EVENT_TYPES` maps Brevo's API
  names (`hardBounces`, `softBounces`, `blocked`, `invalid`, `spam`,
  `unsubscribed`, `delivered`) to the webhook event names (`hard_bounce`,
  `soft_bounce`, `blocked`, `invalid_email`, `spam`, `unsubscribed`,
  `delivered`). The job records each event under the webhook name, so a
  backfilled row and a webhook row of one event are one row. 1000 `delivered`
  events do not hide a bounce
- **Self-healing backlog**: 48-hour fetch window (twice the ~24-hour claim
  interval) ensures every event is offered to at least two runs before aging
  out; idempotent writes on duplicate make re-applying safe
- **Newest-first ordering**: Events fetched newest-first, so freshest signal
  is never the part dropped if a page walks incomplete
- **Per-event skip on write failure**: Individual event write failures are
  logged and skipped (not aborting the page) because retry model already
  covers them and aborting would starve every event behind it
- **Separate retention prune**: a step of its own with two cutoffs (24 months
  for terminal events, 90 days for the rest, #2358). It has its own try/catch,
  so prune failures do not report as backfill failures. It runs in a `finally`
  block after the backfill, so a failed backfill does not stop it. A backfill
  failure still rethrows after the prune
- **Runs with the verification switch off**: the job does not override
  `AbstractCronJob::refusesWhileSwitchOff()`. `run()` reaches `execute()` and
  `runNowWithSummary()` runs its work with the switch off
- **One write for each event**: `applyEvent()` asks `EmailEventMatcher` for the
  user, then calls `EmailEventApplier::apply()` once. An event that is not ours
  is skipped silently. An address longer than 155 bytes or one that fails
  `FILTER_VALIDATE_EMAIL` is skipped and counted. A date more than 5 minutes
  after the run time is treated as missing
- **Unmatched counter**: `countUnmatched()` increments the unmatched recipient
  counter only for a verification-tagged event with no single user
- **No address in logs**: lines name the user ID or the address hash prefix.
  `catch` blocks log the `ExceptionLogSummary` of the exception, not its message

**Constructor**:

```php
public function __construct(
    DatabaseInterface $db,
    private readonly CarRepository $repo,
    private readonly EmailEventMatcher $matcher,
    private readonly EmailEventApplier $applier,
    private readonly BrevoEventReconciliationClient $client,
    ?\DateTimeImmutable $now = null,
)
```

The `$now` parameter is injectable for testing; defaults to wall-clock time.

**Configuration Constants**:

- `JOB_NAME = 'brevo_reconciliation'` — `er_cron_job_runs.job_name` value (renamed from `'reconciliation'` by #2129)
- `GUARD_INTERVAL_HOURS = 20` — Claim interval (20h leaves slack to re-anchor within 48h window)
- `LOOKBACK_HOURS = 48` — Fetch window (doubled guard interval for overlap)
- `PAGE_SIZE = 1000` — Events per run (Brevo caps at 2500; 1000 is deliberate step below)
- `PAGE_OFFSET = 0` — Always zero (fetch newest first; nonzero would skip them)
- `TERMINAL_RETENTION_MONTHS = 24` — The prune keeps a terminal event (hard
  bounce, block, spam report, unsubscribe) this long
- `SHORT_RETENTION_DAYS = 90` — The prune keeps every other row this long,
  except each car's latest `sent` row of its current owner. That row loses its
  address only when it is older than 90 days

**Methods**:

- `jobName(): string` — Returns `'brevo_reconciliation'`
- `guardIntervalHours(): int` — Returns `20`
- `execute(): void` — Backfill one page, prune expired rows in a `finally`
  block, then log a one-line summary (with a distinct suffix if the poll
  failed). The prune logs its count
- `runNowWithSummary(): ReconciliationSummary` — Sibling to the inherited
  `final runNow()` for the manual "run now" admin path (#2061). Not an
  override — `runNow()` stays `final`/void. Wraps the backfill and prune in
  try/catch, logs and **rethrows** on a genuinely unexpected failure (so a
  crash cannot render as a plausible all-zero summary), and returns a real
  `ReconciliationSummary` — with `pollFailed` set, not thrown — when only the
  Brevo poll itself failed

**Private Methods**:

- `backfillEvents(): ReconciliationSummary` — Fetch one page of each event type
  and apply each event, returning the run's counts
- `applyEvent(object $event, string $apiEventType, string $eventName, array &$counts, array &$eventTypeCounts): void`
  — Apply one Brevo statistics-API event (field and address validation,
  `EmailEventMatcher::matchByMessageId()`, `EmailEventApplier::apply()`),
  incrementing the passed-by-reference counters. Counts per event — see
  `ReconciliationSummary`
- `countUnmatched(mixed $rawTag, array &$counts): void` — Count an event of our
  mail that matched no single user, and increment the dashboard counter for a
  verification-tagged one
- `pruneExpiredEvents(): void` — Call `CarRepository::deleteEmailEventsOlderThan()`
  with the two cutoffs
- `resolveOccurredAt(mixed $rawDate, int $userId, string $eventName): string` —
  Parse Brevo's UTC date string into a PHP-default-timezone DATETIME string,
  with logged fallback to "now" on parse failure or out-of-range value

**Used By**:

- Cron dispatch (`users/cron/cron.php`) via `AbstractCronJob::run()`
- `app/admin/scripts/maintenance/27-Reconcile-Brevo-Events.php` — Manual "run
  now" admin script, via `runNowWithSummary()` (#2061)

**See Also**:

- `AbstractCronJob` — Template-method base (crash isolation, enabled check, guard claim)
- `BrevoEventReconciliationClient` — Brevo statistics API polling
- `ReconciliationSummary` — Return type of `runNowWithSummary()`
- `EmailEventApplier` — Escalation logic (shared with webhook)
- `CronJobGuard` — Atomic claim semantics
- [DATABASE.md](DATABASE.md) — `er_email_events`, `er_cron_job_runs` schema
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_FAILURE`, `LOG_CATEGORY_EMAIL_WEBHOOK`
- [DEPLOYMENT.md](DEPLOYMENT.md) — Cron job contract and timeout rationale

---

### ReconciliationSummary

**Location**: `/usersc/classes/Cron/ReconciliationSummary.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: `final readonly class` — outcome of one `BrevoEventReconciliationJob::runNowWithSummary()`
run (#2061). Lets the manual "run now" admin script render what a run did
inline, instead of requiring a trip to Admin → Logs. Mirrors
`SuppressionSyncSummary`'s role for the sibling suppression-sync job (#1923).

**Key Features**:

- Pure value object — no behavior, just readonly properties
- **Per-event counts** (#2359): the matcher gives one user or none for an
  event, so one event makes at most one write. `eventsExamined` is **not** the
  sum of the other counts. An event that is not ours, and an unmatched event
  without the verification tag, add to no count. `eventsExamined` is the only
  field answering "how many events did Brevo return." Script 27 labels
  `matchedCount` "owners matched"

**Constructor** (all params required except `pollFailed` and `counterFailureCount`):

```php
public function __construct(
    public int $matchedCount,
    public int $unmatchedCount,
    public int $skippedCount,
    public array $eventTypeCounts,   // array<string, int>, keyed on the webhook event name
    public int $eventsExamined,
    public int $invalidAddressCount, // address over 155 bytes or failing FILTER_VALIDATE_EMAIL
    public int $pagesFetched,        // one for each event type whose poll succeeded
    public bool $pollFailed = false, // true when at least one fetchEvents() call failed
    public int $counterFailureCount = 0, // unmatched events missing from the dashboard counter
)
```

**Used By**:

- `BrevoEventReconciliationJob::runNowWithSummary()` — Constructs and returns it
- `app/admin/scripts/maintenance/27-Reconcile-Brevo-Events.php` — Renders it inline

**See Also**:

- `BrevoEventReconciliationJob` — Producer
- `SuppressionSyncSummary` — Sibling DTO for the suppression-sync job. It counts per contact and has its own `invalidAddressCount`

---

### TransferEmailService

**Location**: `/usersc/classes/Transfer/TransferEmailService.php`

**Namespace**: `ElanRegistry\Transfer`

**Purpose**: Manages email notifications for car ownership transfer requests, approvals, and denials.
Extracted from procedural code to enable unit testing without live email or database dependencies
via injectable mailer and database connections.

**Key Features**:

- Transfer request notifications (to recipient)
- Admin alerts (to admins reviewing transfers)
- Approval/denial responses (to requester)
- Previous owner notifications (for post-approval transfers)
- Fully injectable dependencies for testing
- Email bodies rendered via PHP view partials in `app/views/email/_transfer_*.php`

**Constructor**:

```php
use ElanRegistry\Transfer\TransferEmailService;

$emailService = new TransferEmailService(
    $db,                                      // DB singleton
    'email',                                  // Callable mailer name
    $abs_us_root . $us_url_root              // Base path for template includes
);
```

**Parameters**:

| Parameter | Type | Description |
| --- | --- | --- |
| `$db` | `DB` | Database singleton from UserSpice |
| `$mailer` | `callable` | Email sender function — signature: `(string $to, string $subject, string $body): bool` |
| `$basePath` | `string` | Absolute path for template includes (`$abs_us_root . $us_url_root`) |

**Public Methods**:

- `sendRequest(int $transferRequestId): bool` — Notify the current car owner that a transfer has been requested
- `sendAdminAlert(int $transferRequestId): bool` — Alert admin(s) to review a pending transfer request
- `sendResponse(int $transferRequestId, bool $isApproved, string $adminNotes = '', ?int $previousOwnerId = null): bool`
  — Send approval or denial to requester and notify the previous/current owner
  (always sent; uses `$previousOwnerId` for approvals, car's current `user_id` for denials)

**Common Usage**:

```php
use ElanRegistry\Transfer\TransferEmailService;

$emailService = new TransferEmailService(
    dbi(),
    'email',
    $abs_us_root . $us_url_root
);

// When transfer request is created
$emailService->sendRequest($transferRequestId);
$emailService->sendAdminAlert($transferRequestId);

// When admin approves transfer
$emailService->sendResponse($transferRequestId, true, 'Approved', $previousOwnerId);

// When admin denies transfer
$emailService->sendResponse($transferRequestId, false, 'Documentation incomplete');
```

**Database Tables Accessed**:

- `car_transfer_requests` - Transfer request details
- `cars` - Car being transferred
- `profiles` - Owner/recipient profile data
- `users` - User emails

**Used By**:

- `app/api/cars/transfer-request.php` - After creating transfer request
- `app/admin/includes/process-transfer-approve.php` - When admin approves transfer
- `app/admin/includes/process-transfer-deny.php` - When admin denies transfer

**Testing**:

The injectable mailer and database dependencies enable unit testing without live email or database:

```php
// Unit test with anonymous class fakes (anonymous class satisfies object type hint)
$mockDb = new class {
    public function query(string $sql, array $params = []): object
    {
        return new class { public function count(): int { return 0; } };
    }
};
$mockMailer = fn($to, $subject, $body) => true;
$service = new TransferEmailService($mockDb, $mockMailer, '/fake/path/');
$this->assertFalse($service->sendRequest(999));
```

**See Also**:

- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Exception patterns for email failures

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

### EmailAddress

**Location**: `/usersc/classes/Car/EmailAddress.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: `final class` that holds the matching rule for owner email
addresses (#2358). Runtime code compares addresses from `users`, `cars`,
`profiles`, `er_email_events` and the sendinblue plugin with this rule.

**Methods**:

- `static normalize(?string $address): string` - Trim and lower-case. NULL
  becomes `''`, and `''` matches no real address. Both sides of a compare go
  through it, then the compare is byte for byte in PHP, or in SQL with
  `COLLATE utf8mb4_bin`. The column collations ignore case and accents, so a
  plain SQL `=` would match `jose@` and `josé@`.

**Used By**:

- `CarRepository` (event writes, flag reads, the privacy-step address list)
- `Owner`, `usersc/scripts/during_user_creation.php`,
  `app/admin/includes/account-cleanup-helpers.php`

**See Also**:

- `DeliverabilityMigrationQueries::normalizeAddress()` and
  `EmailEventRekeyQueries::normalizeAddress()` - Frozen copies for migrations.
  A migration must give the same result when it runs again after this rule
  changes, so it does not call `EmailAddress`.

---

### EmailEventRekeyQueries

**Location**: `/usersc/classes/Migration/EmailEventRekeyQueries.php`

**Namespace**: `ElanRegistry\Migration`

**Purpose**: The reads that decide what migration
`20261013000000_key_er_email_events_by_user` writes (#2358). The migration and
the read-only report script `29-Report-Email-Event-Rekey.php` call the same
methods, so the migration does what the report shows. The class only reads.

**Key Features**:

- Constructor takes a `PDO` (the migration) or a `DatabaseInterface` (the
  report script, `dbi()`).
- The first call reads the database once and keeps the result. Make a new
  instance after a write.
- Every address compare uses `normalizeAddress()` and compares byte for byte in
  PHP. SQL does no address compare, because `er_email_events.email` uses
  `utf8mb4_unicode_ci`.
- The matching rule and the `noowner` name are frozen copies, not references to
  runtime classes.
- The three outcomes of steps (b) and (c) do not overlap: a row is matched by
  car owner, matched by address, or dropped.

**Methods**:

- `static normalizeAddress(?string $address): string` - Trim and lower-case. The
  frozen copy of `EmailAddress::normalize()`.
- `isRekeyed(): bool` - True when `user_id` is NOT NULL and `car_id` allows
  NULL (step (e) is done). The migration skips its data phase then. The report
  prints "already migrated".
- `reportCounts(): array` - The five counts that the report script prints:
  `owner_matched`, `address_matched`, `dropped`, `collapsed_removed` and
  `collapse_groups`.
- `assignments(): array` - Step (b). Each row that keeps a user, keyed by event
  ID, with `user_id`, the normalized `email` and `matched_by`. Never print the
  `email`.
- `ownerMatchedIds(): array`, `addressMatchedIds(): array` - Step (b). The event
  IDs matched by the car owner, and by the one user with the same address.
- `droppedIds(): array` - Step (c). The event IDs with no user, or whose user is
  `noowner`.
- `collapseGroups(): array` - Step (d). Each group of rows with the same user,
  message ID and event: the kept ID (the lowest), the removed IDs and the
  earliest `occurred_at` of the group.

**Used By**:

- `database/migrations/20261013000000_key_er_email_events_by_user.php`
- `app/admin/scripts/maintenance/29-Report-Email-Event-Rekey.php`

**See Also**:

- [DATABASE.md](DATABASE.md) - `er_email_events`
- [DEPLOYMENT.md](DEPLOYMENT.md) - Deploy steps for this migration

---

### DeliverabilityMigrationQueries

**Location**: `/usersc/classes/Migration/DeliverabilityMigrationQueries.php`

**Namespace**: `ElanRegistry\Migration`

**Purpose**: The reads that decide what migration
`20261012000000_move_deliverability_flags_to_profiles` copies from `cars` to
`profiles` (#2101). The migration and its tests call the same methods, so a
test compares the decisions with the migrated rows. The migration can use the
class because `phinx.php` loads `vendor/autoload.php`.

**Key Features**:

- Constructor takes a `PDO`. It works before the migration (`profiles.email_suppressed`
  holds the opt-out) and after phase 1 (`profiles.email_opted_out`).
- The first call reads the database once and keeps the result. Make a new
  instance after a write.
- Every address compare uses `normalizeAddress()` and compares byte for byte in
  PHP. SQL does no address compare, because the `users` and `cars` collations
  are case-insensitive and can differ between installs.
- The event names and `cars_hist` operation names are copies, not references to
  `EmailEventApplier`, so the migration gives the same result when it runs
  again later.

**Methods**:

- `static normalizeAddress(?string $address): string` - Trim and lower-case. NULL
  becomes `''`, and `''` matches no real address. The rule for every address
  compare in #2101. It is a frozen copy since #2358. Runtime code
  (`CarRepository`, `Owner` and the hooks) uses `EmailAddress::normalize()`.
- `carFlagColumnsExist(): bool`, `duplicateProfileUserIds(): array`,
  `profilesToInsert(): array`, `bounceCopies(): array`, `droppedBounces(): array`,
  `optOutRestores(): array`, `blockCopies(): array`, `unknownCauseBlocks(): array`,
  `droppedSuppressions(): array`, `optedOutAtBackfill(): array` and
  `bouncedAtBackfill(): array` - Candidate rows and ID lists per migration case.
  Methods that name an address return it normalized. Never print it.

**See Also**:

- [DATABASE.md](DATABASE.md) - `profiles` deliverability columns
- [DEPLOYMENT.md](DEPLOYMENT.md) - Deploy steps for this migration

---

### BackupManager

**Location**: `/usersc/classes/admin/BackupManager.php`

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

**Namespace**: `ElanRegistry`

**Purpose**: Centralized branded HTML email template system. Instance-based
(constructor loads `$baseUrl`/`$logoUrl` via `getBaseUrl()`); callers compose
content with the `create*()` primitives below, then wrap it in `render()` for
the full branded document.

**Key Features**:

- Consistent branded header/footer, responsive layout (600px breakpoint)
- Composable content primitives: message boxes, detail rows (plain,
  highlighted, or trusted-HTML), free-text blocks, single or side-by-side
  action buttons
- Per-method escaping contract documented in the class's own docblock and in
  [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#emailtemplate-class) — some methods
  (`createMessageBox()`'s `$content`, `createRawDetailRow()`'s `$trustedHtml`)
  deliberately do NOT escape their input; see that contract before adding a
  new caller

**Methods**:

- `render(string $subject, string $subtitle, string $content, array $options = []): string` -
  Wrap composed `$content` in the full branded email document; escapes
  `$subject`/`$subtitle`, `$content` is trusted HTML
- `createMessageBox(string $title, string $content, string $style = 'default'): string` -
  Titled, bordered content box (styles: `default`, `message`, `alert`,
  `success`); escapes `$title` only, `$content` is raw HTML by design
- `createDetailRow(string $label, string $value, bool $highlighted = false): string` -
  Label/value row; escapes both always, regardless of `$highlighted`. When
  `$highlighted` is true, renders with a `#FFF9E0` background and `#B8860B`
  left border to flag a row needing attention
- `createRawDetailRow(string $label, string $trustedHtml): string` -
  Label/value row for embedding trusted HTML (an image, a link) as the
  value; escapes `$label` only — `$trustedHtml` is caller-trusted and NOT
  escaped
- `createMessageContent(string $text, bool $italic = false): string` -
  Free-text block with an accent border; escapes `$text`
- `createButton(string $text, string $url, string $style = 'primary'): string` -
  Single centered action button (styles: `primary`, `secondary`, `success`,
  `danger`); escapes both `$text` and `$url`
- `createButtonRow(array $buttons): string` - Two or more side-by-side action
  buttons, collapsing to stacked on narrow viewports; each entry is
  `['label' => string, 'url' => string, 'style' => string]` (`style`
  optional, defaults to `primary`); escapes `label`/`url`/`style` per entry
  and throws `\InvalidArgumentException` for fewer than two buttons or a
  malformed entry

**Common Usage**:

```php
$template = new EmailTemplate();

$content = $template->createMessageBox(
    'Transfer Details',
    $template->createDetailRow('Car', 'Elan 26/0001')
        . $template->createDetailRow('Engine Number', '', highlighted: true)
);
$content .= $template->createButtonRow([
    ['label' => 'Approve', 'url' => $approveUrl, 'style' => 'success'],
    ['label' => 'Decline', 'url' => $declineUrl, 'style' => 'danger'],
]);

$html = $template->render('Transfer Request', 'Action needed', $content);
// ... send $html via the project's email system, see EMAIL_SYSTEM.md
```

**See Also**:

- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md#emailtemplate-class) - Full per-method escaping contract and Brevo/sendinblue() sending pattern

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

### DocumentPortalTemplate

**Location**: `/usersc/classes/Documentation/DocumentPortalTemplate.php`

**Namespace**: `ElanRegistry\Documentation`

**Purpose**: Renders the reusable card grids, portal headers, breadcrumbs, and
nav footers used across documentation and application index pages.

**Common Usage**:

```php
use ElanRegistry\Documentation\DocumentPortalTemplate;

// Breadcrumb derived from a nav section
echo DocumentPortalTemplate::renderBreadcrumb('guides', $us_url_root, $title, 'fa-car');

// Card grid for an index page
echo DocumentPortalTemplate::renderDocumentCardGrid($cards);
```

> **Note**: Guide content is pre-rendered to static HTML and inlined as PHP
> heredocs in the individual guide pages under `docs/guides/`. To update guide
> content, edit the heredoc directly in the relevant PHP file.

### Naming Conventions

- **Classes**: PascalCase with descriptive business domain names
  - Examples: `Car`, `Owner`, `ChassisValidator`
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
    private DatabaseInterface $_db;
    private $_data;

    // Load existing record. The $db seam lets tests inject a double;
    // it defaults to the shared dbi() connection in production.
    public function __construct(?int $id = null, ?DatabaseInterface $db = null) {
        $this->_db = $db ?? dbi();
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
    // CSRF is validated by the caller (HTTP layer) before create() is called —
    // do not validate it inside domain-class methods (see Car, Owner; #1519)
    public function create(array $fields): int {
        // Validation
        // Database insert
        // Audit logging
        return $insertId;
    }

    // Update existing record
    // CSRF is validated by the caller (HTTP layer) before update() is called —
    // do not validate it inside domain-class methods (see Car, Owner; #1519)
    public function update(array $fields): bool {
        // Validation
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
$ownerData = (new Owner($userId))->data();
```

**UserSpice Classes**:

- `User` - Authentication and session management
- `Token` - CSRF token generation/validation
- `DB` - Database singleton

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
├── Related: Owner (via user_id)
└── Uses: ChassisValidator (for validation)

Owner
├── Uses: DB (singleton)
├── Related: Car (via user_id)
└── Uses: (new Owner($userId))->data() for combined user+profile

CarView
├── Uses: Resize (for image processing)
└── Used by: Car, various views

BackupManager
├── Uses: DB
└── Throws: BackupException

EmailTemplate
└── Used by: Transfer requests, notifications

DocumentPortalTemplate
└── Used by: docs/guides/index.php, docs/reference/*, app/owner/cars/index.php, app/owner/reports/statistics.php
```

## Reference Data Classes

Classes in the `ElanRegistry\Reference` namespace provide access to external/canonical data about Lotus Elan models, factory colors, and production specifications.

### CarModel

**Location**: `/usersc/classes/Reference/CarModel.php`

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
- The car form in `app/owner/cars/edit.php` uses dynamic model dropdowns.

**See Also**:

- [Issue #577](https://github.com/elan-registry/registry/issues/577) - car_models table creation
- `composer.json` - PSR-4 namespace mappings for application classes

## See Also

- [GitHub Wiki: Architecture Guide](https://github.com/elan-registry/registry/wiki/Architecture) - System architecture overview
- [DATABASE.md](DATABASE.md) - Database schema and relationships
- [GitHub Wiki: UserSpice Integration Guide](https://github.com/elan-registry/registry/wiki/Integration) - UserSpice integration patterns
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Code quality requirements
- [TESTING.md](../testing/TESTING.md) - Testing guidelines
