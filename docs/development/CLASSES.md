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
| Create database backups | BackupManager | Backup/restore operations, database dumping | `$backup = new BackupManager(...)` |
| Decode car images | CarImageProcessor | Decodes the `cars.image` JSON array into usable entries | `$processor->decodeAndProcessImages($car->image, ...)` |
| Remove one image from a car | Car / CarImageProcessor | CAS-guarded single-filename removal; throws on concurrent modification | `$car->removeImage($filename)` |
| Remove multiple images from a car | Car / CarImageProcessor | CAS-guarded bulk removal; returns `['updated' => bool, 'casConflict' => bool]` instead of throwing, for callers (e.g. `mvTmpImages()`'s move-failure cleanup) that already have their own error-reporting path | `$car->removeImages($filenames)` |
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
- `CarDatabaseException` - Database operation failures (500)
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
- `updateLastVerified(int $carId, string $dateTime): bool` - Update a car's last-verified timestamp
- `updateVerificationSentAt(int $carId, string $dateTime): bool` - Update the timestamp at which a verification email was sent
- `updateEmailBounced(int $carId, bool $bounced, ?string $bouncedAddress = null): bool` -
  Set or clear a car's email-bounced flag, and the address it bounced against (#1887)
- `updateEmailSuppressed(int $carId, bool $suppressed): bool` - Set or clear a car's email-suppressed flag (#1887)
- `findByEmail(string $email): array` - Find cars whose `cars.email` matches a
  given address; used by the Brevo webhook receiver to map an inbound
  event's recipient back to the car(s) it belongs to (#1887)
- `insertEmailEvent(int $carId, string $email, string $event, ?string $reason, string $brevoMessageId, string $occurredAt): int` -
  Record one Brevo delivery-status event against a car (`er_email_events`),
  deduping on `(car_id, brevo_message_id, event)` via a hand-written
  `ON DUPLICATE KEY UPDATE` (deliberately not `DB::insert()`'s `$update`
  mode, which would also reassign identity columns on conflict); returns
  MySQL's `rowCount()` (1 insert, 2 changed duplicate, 0 unchanged
  duplicate) (#1887)
- `countSoftBouncesSinceLastDelivered(string $email): int` - Count distinct
  soft-bounce send cycles (`brevo_message_id` values) for an email since its
  most recent `delivered` event; the query the webhook receiver's
  soft-bounce escalation threshold is checked against (#1887)
- `deleteEmailEventsForCarIds(array $carIds): int` - Delete all
  `er_email_events` rows for a set of car ids; used by account-deletion and
  direct car-deletion cleanup, returns the number of rows deleted (0 for an
  empty array) (#1887)
- `deleteEmailEventsOlderThan(\DateTimeImmutable $cutoff): int` - Delete all
  `er_email_events` rows with `occurred_at` before the cutoff (retention
  pruning); used by nightly reconciliation job, returns the number of rows
  deleted (#1889)
- `clearBouncedForUser(int $userId, string $currentEmail): int` - Clear
  `email_bounced` and `email_bounced_address` on every car owned by a user
  whose recorded bounced address is no longer their current confirmed address
  (as determined by a successful email-verification code confirmation). Returns
  the number of rows affected by the UPDATE. Used by the `verifySuccess` hook
  to auto-clear stale bounces on confirmed email changes (#1890)
- `carIdsWithBouncedFlagButNoAddress(int $userId): array` - Query for car IDs
  owned by a user with `email_bounced=1` but no recorded bounced address —
  a pre-existing data-integrity anomaly. Returns an array of car IDs (empty if
  none). Used by the `verifySuccess` hook to detect and log the anomaly before
  clearing stale bounces (#1890)
- `transferEmailEvents(int $fromCarId, int $toCarId): bool` - Reassign
  `er_email_events` rows from one car to another; used by car merge, so the
  surviving car keeps the merged-away car's bounce/suppression history
  instead of losing it (#1887)
- `updateOwnerLastUpdated(int $carId, string $dateTime): bool` - Update the
  timestamp of the owner's last self-initiated edit; standalone primitive not
  currently called by `Car::update()` (which folds the same write into its
  single `updateCar()` call to avoid a duplicate `cars_hist` audit row — see
  `Car::update()`'s `$isOwnerInitiated` parameter)
- `freshnessSql(string $alias = 'cars'): string` - Static; returns a SQL
  boolean expression determining if a car is fresh (verified within 1 year via
  `last_verified` OR edited by owner within 1 year via `owner_last_updated`).
  The `$alias` parameter must match the table alias in the calling query (e.g.
  `'c'` for `cars AS c`) and is validated against `/^[A-Za-z_][A-Za-z0-9_]{0,63}$/`
  to prevent SQL injection. Compares against MySQL's `NOW()`.
- `stalenessSql(string $alias = 'cars'): string` - Static; returns
  `'NOT ' . freshnessSql($alias)` — the exact boolean negation of freshness.
- `isFresh(?string $lastVerified, string $ownerLastUpdated): bool` - **Not yet
  called from production code** (only the SQL form is wired in, via
  `findVerificationEligible()`); the send pipeline in v2.30.3 is the intended
  first caller, at which point this note is removed (issue #1970). PHP
  equivalent of `freshnessSql()` for in-code freshness checks, using PHP's clock
  where the SQL form uses MySQL's `NOW()`. Both clocks must resolve to the same
  timezone or the two forms can disagree at the one-year boundary from skew alone.
  Sharing a host does **not** guarantee this: `users/init.php` pins PHP to
  `America/Los_Angeles` on every web request, while MySQL follows its own
  `time_zone`, so agreement must be verified per environment. Validates **both**
  operands before comparing — deliberately not short-circuiting on a fresh
  `$ownerLastUpdated` — and throws `CarValidationException` if either is empty,
  malformed, or not a real calendar date (`2026-02-30` is rejected rather than
  rolled over to March 2), because a malformed value there is a programming
  error, not a data state.
- `findVerificationEligible(int $limit, int $offset): array` - Paginated
  query for cars eligible for a verification email: not sold, deliverable
  email, and stale — neither verified nor updated by its owner within the last
  year (see `stalenessSql()`). No longer falls back to `cars.mtime`.
- `updateSoldDate(int $carId, string $soldDate): bool` - Update a car's sold date
- `updateImage(int $carId, string $newJson, string $expectedJson): bool` - Compare-and-swap update of the image JSON column; returns `false` on concurrent modification
- `findByChassisKey(string $year, string $type, string $chassis): ?object` -
  Find a car by its composite chassis key (year, type, chassis); used by
  `chassis-availability.php` and `transfer-request.php` to check chassis
  uniqueness
- `findByVerificationCode(string $code): ?object` - Look up a car by verification code
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

**Used By**:

- Car class (composed data-access layer)
- `app/api/cars/chassis-availability.php`, `app/api/cars/transfer-request.php` (`findByChassisKey()`)
- User-deletion hook (`reassignCarsByUser()`, `deleteEmailEventsForCarIds()`)
- `CarAdministrationService` (`deleteEmailEventsForCarIds()` on car deletion, `transferEmailEvents()` on car merge)
- Email-verification hook (`clearBouncedForUser()`, `carIdsWithBouncedFlagButNoAddress()`) (#1890)
- Sitemap generation (`getAllForSitemap()`)
- `BrevoWebhookEventProcessor` (`findByEmail()`, `insertEmailEvent()`, `countSoftBouncesSinceLastDelivered()`) (#1887)
- `BrevoEventReconciliationJob` (`findByEmail()`, `insertEmailEvent()`, `countSoftBouncesSinceLastDelivered()`, `deleteEmailEventsOlderThan()`) (#1889)

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
(verification codes, verification timestamps, email-bounce tracking).
Constructor-injected with a `CarRepository`; validates input, delegates
persistence to the repository, and mutates the passed-in `$carData` object
on success.

**Key Features**:

- CSPRNG-backed verification code generation (`bin2hex(random_bytes(16))`,
  128 bits of entropy)
- Shared `persist()`/`updateBounced()` private helpers deduplicate the
  validate → repo call → log-on-failure → throw pattern across the six
  public methods that write to the repository (`generateVerificationCode()`
  is pure and makes no database call)
- Every repository-writing method throws `CarDatabaseException` on failure (repository
  returns `false`, or the repository call itself throws) rather than
  returning a falsy value — callers do not need to check a return value for
  failure

**Methods**:

- `setVerificationCode(object $carData, string $verificationCode): bool` - Persist a car's verification code (min. 8 characters)
- `generateVerificationCode(): string` - Generate a new verification code; pure function, no repository call
- `markVerified(object $carData): bool` - Record that a car has been verified (sets `last_verified` to now)
- `setVerificationSentAt(object $carData, string $dateTime): bool` - Record when a verification email was sent
- `setBounced(object $carData, string $bouncedAddress): bool` - Flag a car's owner email as bounced, recording the address the bounce was reported against
- `clearBounced(object $carData): bool` - Clear a car's bounced-email flag and the recorded bounced address (admin reversal)
- `setSuppressed(object $carData): bool` - Flag a car's owner email as suppressed (e.g. a Brevo `spam` complaint) — a distinct signal from a bounce (#1887)
- `clearSuppressed(object $carData): bool` - Clear a car's email-suppressed flag (admin reversal)
- `markSold(object $carData, ?string $soldDate): bool` - Record a car as sold (`null` defaults to today)

**Exceptions**:

- `CarDatabaseException` - Repository call failed or threw
- `CarValidationException` - Invalid input (e.g. a verification code under 8 characters, a malformed sold date)

**Used By**:

- Backend foundation for the car-owner verification system (issue #1155).
  `setBounced()`/`setSuppressed()` gained their first production caller in
  #1887: `BrevoWebhookEventProcessor` calls them when an inbound Brevo
  delivery-status webhook event reports a bounce or spam complaint. The
  verification-email-sending consumer for `setVerificationCode()`/
  `markVerified()`/`setVerificationSentAt()` still lands in a later
  verification-system milestone.

**See Also**:

- [ERROR_HANDLING.md](ERROR_HANDLING.md) - Exception patterns
- [DATABASE.md](DATABASE.md) - `cars.vericode`, `cars.last_verified`, `cars.owner_last_updated`,
  `cars.vericode_sent_at`, `cars.email_bounced`, `cars.email_bounced_address`, `cars.email_suppressed`, `cars.solddate`

---

### VerificationSettings

**Location**: `/usersc/classes/Car/VerificationSettings.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Site-wide feature switch and readiness probes for the car
verification system — kept separate from `CarVerificationManager` above:
this class owns one global config row plus two infrastructure probes
(Brevo, cron), while `CarVerificationManager` mutates per-car records. No
shared state or dependency exists between the two.

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
- `brevoReady(): bool` - True only if the Brevo API key is configured AND the plugin override file is active
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
  `er_verification_settings.unmatched_webhook_recipient_count` when an
  inbound Brevo webhook event's recipient matches no car; never throws
  (logs and returns `false` on a DB error, or if the `id = 1` settings row
  itself is missing) since the webhook's own response to Brevo must not
  hinge on this counter succeeding (#1887)

**Exceptions**:

- `VerificationConfigException` - Thrown by `setEnabled(true)` when `brevoReady()` is false

**Used By**:

- Admin "Verification System" tab (`app/admin/index.php`, `app/admin/includes/tab-verification.php`)
- Toggle endpoint (`app/api/admin/verification-toggle.php`)
- Brevo webhook receiver (`app/api/webhooks/brevo.php`) — `isEnabled()`/`brevoReady()` gates, and `incrementUnmatchedRecipientCounter()` (#1887)

**See Also**:

- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) - Full feature-switch design, the asymmetric gate, readiness-check semantics
- [DATABASE.md](DATABASE.md) - `er_verification_settings`, the `er_` table-prefix convention
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) - `LOG_CATEGORY_VERIFICATION_CONFIG_WARNING`, `LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED`

---

### BrevoWebhookEventProcessor

**Location**: `/usersc/classes/Car/BrevoWebhookEventProcessor.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Parsing and matching logic for inbound Brevo delivery-status
webhook events (#1887); no HTTP concerns (`http_response_code()`, `exit`) live
here — that separation is what makes this class unit-testable without the
subprocess dance the endpoint itself needs.

**Key Features**:

- Rejects malformed payloads (non-object JSON, a top-level list, missing
  `email`/`event`/`message-id`, or a non-list `tags`) before any DB call
- Filters to only events tagged `AppConstants::VERIFICATION_EMAIL_TAG`
- Matches the payload's `email` against every car sharing that address
  (`CarRepository::findByEmail()`), applying each car's transition
  independently
- A malformed/overflowing `ts_event`/`ts` falls back to receipt time
  (logged) rather than producing a `DATETIME` value MySQL would reject
- Delegates escalation rules to injected `EmailEventApplier`, shared with the
  reconciliation job (#1889), so both webhook and nightly backfill escalate
  identically
- The whole per-car write loop fails the entire request on the first write
  failure, even if earlier cars already succeeded — safe because every
  write is idempotent (`ON DUPLICATE KEY UPDATE` or a plain column update)

**Methods**:

- `process(mixed $decodedPayload): ProcessingResult` - Process one decoded
  webhook payload; the sole public entry point

**Constructor Dependencies**:

- `CarRepository $repo` - Recipient lookup via `findByEmail()`
- `EmailEventApplier $applier` - Event escalation logic

**Used By**:

- Brevo webhook receiver (`app/api/webhooks/brevo.php`)

**See Also**:

- `EmailEventApplier` — Escalation rules (extracted for both webhook and reconciliation job)
- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) - Full webhook receiver contract, escalation rules, HTTP status table
- [DATABASE.md](DATABASE.md) - `er_email_events`, `cars.email_bounced_address`, `cars.email_suppressed`
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) - `LOG_CATEGORY_EMAIL_WEBHOOK`

---

### EmailEventApplier

**Location**: `/usersc/classes/Car/EmailEventApplier.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Records one Brevo delivery-status event against one car and
applies whatever verification-flag escalation that event implies. Extracted
from `BrevoWebhookEventProcessor` (where it was private) because the nightly
reconciliation job (#1889) replays events the webhook never delivered, and
both paths must escalate identically.

**Key Features**:

- Payload-agnostic: takes already-parsed, already-validated scalars, so each
  caller (webhook or reconciliation job) keeps ownership of payload discovery
- Escalation rules owned here, shared with `BrevoWebhookEventProcessor`:
  - `hard_bounce` / `blocked` / `invalid` / `invalid_email` immediately flag
    as bounced (accepts both `invalid` and `invalid_email` to hedge against
    payload-contract uncertainty; Brevo may send either)
  - `soft_bounce` does not immediately change anything; after the event row is
    recorded, {@see CarRepository::countSoftBouncesSinceLastDelivered()} is
    consulted; at 3+ distinct send cycles (`brevo_message_id` values) since
    the email's last `delivered` event, escalates to bounced
  - `delivered` and `unique_opened` only record the event row; `delivered`
    implicitly resets the soft-bounce escalation window, so no separate
    "clear escalation" write exists; `unique_opened` never touches flags
  - `spam` flags as suppressed (distinct signal from a bounce)
  - Any other event is still recorded (Brevo may add/rename event types) but
    logged as unrecognized

**Constructor Dependencies**:

- `CarRepository $repo` - Event insertion and soft-bounce cycle counting
- `CarVerificationManager $verificationManager` - Flag writes (`setBounced()`, `setSuppressed()`)

**Methods**:

- `apply(int $carId, string $email, string $event, ?string $reason, string $messageId, string $occurredAt): void`
  — Record the event and apply escalation; throws `CarDatabaseException` on write failure

**Used By**:

- `BrevoWebhookEventProcessor::process()` — Per-car write loop
- `BrevoEventReconciliationJob::applyEvent()` — Backfill of missed events (#1889)

**See Also**:

- `BrevoWebhookEventProcessor` — Webhook parsing/matching (uses this for escalation)
- `BrevoEventReconciliationJob` — Nightly reconciliation job (uses this for identical escalation)
- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) - Full webhook receiver contract, escalation rules
- [DATABASE.md](DATABASE.md) - `er_email_events`, escalation state columns
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) - `LOG_CATEGORY_EMAIL_WEBHOOK`

---

### ProcessingResult (enum)

**Location**: `/usersc/classes/Car/ProcessingResult.php`

**Namespace**: `ElanRegistry\Car`

**Purpose**: Outcome of `BrevoWebhookEventProcessor::process()`. The webhook
endpoint maps each case 1:1 to an HTTP status (#1887).

**Cases**: `MATCHED_AND_RECORDED` (2xx), `NO_TAG_MATCH` (2xx, logged),
`NO_CAR_MATCH` (2xx, logged, unmatched counter incremented), `MALFORMED`
(4xx, logged), `WRITE_FAILURE` (5xx — the only retryable case)

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

**Abstract Methods (implemented by subclasses)**:

- `jobName(): string` — The `er_cron_job_runs.job_name` value for this job
- `guardIntervalHours(): int` — Minimum hours between claimed runs (≥1);
  passed to `CronJobGuard::claim()`
- `execute(): void` — The job's actual work; any `\Throwable` is caught and
  logged by `run()`

**Public Methods**:

- `run(): void` — Check enabled state, claim the guard, set time-limit
  backstop, execute with crash isolation; never throws
- `runNow(): void` — Execute immediately, bypassing enabled check and guard;
  for admin-triggered manual runs only; never throws

**Constructor**:

```php
public function __construct(protected readonly DatabaseInterface $db)
```

**Used By**:

- `BrevoEventReconciliationJob` (extends `AbstractCronJob`)

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

**See Also**:

- `AbstractCronJob` — Logs the three non-enabled cases distinctly
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_SKIPPED` (DISABLED), `LOG_CATEGORY_CRON_JOB_FAILURE` (MISSING/UNREADABLE)

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
- All failure modes (Brevo unconfigured, missing SDK, DB error, HTTP error)
  return empty array rather than throwing, so reconciliation poll failures do
  not abort the whole cron run
- Defensive reads for Brevo configuration (API key, plugin status), with
  distinct logging: `SKIPPED` for "not configured" (expected), `FAILURE` for
  "configured but unreachable" (infrastructure fault)
- Idempotent SDK autoloader with `require_once` and `class_exists()` short-circuit

**Constructor**:

```php
public function __construct(private readonly DatabaseInterface $db)
```

**Public Methods**:

- `fetchEvents(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate, int $limit, int $offset): array`
  — Fetch one page of delivery events for the date window; returns
  `\Brevo\Client\Model\GetEmailEventReportEvents[]`, or empty array on any
  failure (never throws)

**Private Methods**:

- `apiKey(): ?string` — Read Brevo API key from plugin settings; null if
  unconfigured or unreadable
- `loadSdk(): bool` — Load vendored SDK autoloader; idempotent, safe to call
  when already loaded

**Used By**:

- `BrevoEventReconciliationJob::backfillEvents()` — Polls Brevo for events to
  backfill

**See Also**:

- `BrevoEventReconciliationJob` — Nightly reconciliation job (#1889)
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_SKIPPED` (not configured), `LOG_CATEGORY_CRON_JOB_FAILURE` (connectivity/SDK issues)

---

### BrevoEventReconciliationJob

**Location**: `/usersc/classes/Cron/BrevoEventReconciliationJob.php`

**Namespace**: `ElanRegistry\Cron`

**Purpose**: Nightly backfill of Brevo delivery events the webhook (#1887)
never delivered, plus retention pruning of `er_email_events` (#1889). Closes
the lossy gap of the webhook by re-reading events from Brevo's statistics API
and applying them through the same `EmailEventApplier` the webhook uses, so
backfilled events escalate identically to live ones.

**Key Features**:

- **Bounded work per invocation**: Fetches exactly one page of events per run
  (never pagination loop), safe for in-process dispatch model
- **Self-healing backlog**: 48-hour fetch window (twice the ~24-hour claim
  interval) ensures every event is offered to at least two runs before aging
  out; idempotent writes on duplicate make re-applying safe
- **Newest-first ordering**: Events fetched newest-first, so freshest signal
  is never the part dropped if a page walks incomplete
- **Per-event skip on write failure**: Individual event write failures are
  logged and skipped (not aborting the page) because retry model already
  covers them and aborting would starve every event behind it
- **Separate retention prune**: 24-month retention cleanup wrapped in its own
  try/catch, so prune failures do not report as backfill failures

**Constructor**:

```php
public function __construct(
    DatabaseInterface $db,
    private readonly CarRepository $repo,
    private readonly EmailEventApplier $applier,
    private readonly BrevoEventReconciliationClient $client,
    ?\DateTimeImmutable $now = null,
)
```

The `$now` parameter is injectable for testing; defaults to wall-clock time.

**Configuration Constants**:

- `JOB_NAME = 'reconciliation'` — `er_cron_job_runs.job_name` value
- `GUARD_INTERVAL_HOURS = 20` — Claim interval (20h leaves slack to re-anchor within 48h window)
- `LOOKBACK_HOURS = 48` — Fetch window (doubled guard interval for overlap)
- `PAGE_SIZE = 1000` — Events per run (Brevo caps at 2500; 1000 is deliberate step below)
- `PAGE_OFFSET = 0` — Always zero (fetch newest first; nonzero would skip them)
- `RETENTION_MONTHS = 24` — Delete events older than this

**Methods**:

- `jobName(): string` — Returns `'reconciliation'`
- `guardIntervalHours(): int` — Returns `20`
- `execute(): void` — Backfill one page, then prune expired rows

**Private Methods**:

- `backfillEvents(): void` — Fetch one page and apply each tag-matching event
- `applyEvent(object $event): void` — Apply one Brevo statistics-API event to
  its cars (tag/email/event/message-id validation, `EmailEventApplier::apply()`)
- `pruneExpiredEvents(): void` — Delete `er_email_events` rows older than
  retention cutoff
- `resolveOccurredAt(mixed $rawDate, string $email, string $event): string` —
  Parse Brevo's UTC date string into a PHP-default-timezone DATETIME string,
  with logged fallback to "now" on parse failure or out-of-range value

**Used By**:

- Cron dispatch (`users/cron/cron.php`) via `AbstractCronJob::run()`

**See Also**:

- `AbstractCronJob` — Template-method base (crash isolation, enabled check, guard claim)
- `BrevoEventReconciliationClient` — Brevo statistics API polling
- `EmailEventApplier` — Escalation logic (shared with webhook)
- `CronJobGuard` — Atomic claim semantics
- [DATABASE.md](DATABASE.md) — `er_email_events`, `er_cron_job_runs` schema
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_CRON_JOB_FAILURE`, `LOG_CATEGORY_EMAIL_WEBHOOK`
- [DEPLOYMENT.md](DEPLOYMENT.md) — Cron job contract and timeout rationale

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
- Phase 2: form.php dynamic dropdowns (replacing cardefinition.js)

**See Also**:

- [Issue #577](https://github.com/elan-registry/registry/issues/577) - car_models table creation
- `/usersc/classes/ElanRegistry/README.md` - Namespace pattern documentation

## See Also

- [GitHub Wiki: Architecture Guide](https://github.com/elan-registry/registry/wiki/Architecture) - System architecture overview
- [DATABASE.md](DATABASE.md) - Database schema and relationships
- [GitHub Wiki: UserSpice Integration Guide](https://github.com/elan-registry/registry/wiki/Integration) - UserSpice integration patterns
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Code quality requirements
- [TESTING.md](../testing/TESTING.md) - Testing guidelines
