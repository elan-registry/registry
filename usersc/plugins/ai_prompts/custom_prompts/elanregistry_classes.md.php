<?php /* UserSpice AI Prompt — protected from HTTP access. Markdown content below. */ __halt_compiler(); ?>

# ElanRegistry — Custom Classes

ElanRegistry adds its own class layer on top of UserSpice. These classes live in
`usersc/classes/` (PSR-4 autoloaded under the `ElanRegistry\` namespace) and handle
the car registry domain. The UserSpice framework classes (`DB`, `Input`, `Token`, etc.)
remain unchanged — these classes extend and wrap them.

---

## Quick selection guide

| Task | Class | Location |
|---|---|---|
| Load/create/update/delete a registered car | `Car` | `usersc/classes/Car.php` |
| Display car images, specs, carousels (no DB writes) | `CarView` | `usersc/classes/CarView.php` |
| Load/update an owner's registry profile | `ElanRegistryOwner` | `usersc/classes/ElanRegistryOwner.php` |
| Return a JSON response from an AJAX endpoint | `ApiResponse` | `usersc/classes/ApiResponse.php` |
| Log an action or error to the audit trail | `logger()` + `LogCategories` | `usersc/classes/LogCategories.php` |
| Validate a chassis/VIN format | `ChassisValidator` | `usersc/classes/ChassisValidator.php` |
| Read POST data without HTML-encoding (for DB storage) | `ElanRegistry\Input` | `usersc/classes/Input.php` |
| Look up factory reference data (models, colors) | `ElanRegistry\Reference\*` | `usersc/classes/ElanRegistry/Reference/` |

---

## Namespace layout

```
ElanRegistry\             → usersc/classes/          (entity classes — full CRUD)
ElanRegistry\Exceptions\  → usersc/classes/Exceptions/ (typed exceptions)
ElanRegistry\Reference\   → usersc/classes/ElanRegistry/Reference/ (read-only reference data)
```

Entity classes (`Car`, `ElanRegistryOwner`) represent registry records with full CRUD.
Reference classes (`CarModel`, `FactoryColor`, `FactoryInfo`) represent external authoritative
data from Lotus — read-only, static methods only, no insert/update/delete.

---

## Car

Full car lifecycle: create, read, update, delete, history tracking, image management.
History is written automatically by database triggers — never manually.

```php
// Load
$car = new Car($carId);  // throws CarNotFoundException if not found
$data = $car->data();    // stdObject with all car columns

// Create
$car = new Car();
$carId = $car->create([
    'chassis'    => '26/0001',
    'model_name' => 'S2',
    'body_style' => 'DHC',
    'body_color' => 'Red',
    'user_id'    => $userId,
    'csrf'       => Token::generate(),
]);

// Update
$car->update([
    'id'         => $carId,
    'body_color' => 'Blue',
    'csrf'       => Token::generate(),
]);

// Delete (soft delete with audit trail)
$car->delete($userId);
```

**Key database tables:** `cars` (primary), `cars_hist` (trigger-written audit trail),
`car_images`, `elan_factory_info`.

**Exception hierarchy** (all extend `CarException` in `ElanRegistry\Exceptions\`):

| Exception | HTTP | When |
|---|---|---|
| `CarNotFoundException` | 404 | ID not found |
| `CarValidationException` | 422 | Invalid field data |
| `CarDatabaseException` | 500 | DB operation failure |
| `CarPermissionException` | 403 | User lacks permission |
| `CarCreationException` | 500 | Create failed |
| `CarDeletionException` | 500 | Delete failed |
| `CarMergeException` | 500 | Merge failed |
| `CarTransferException` | 500 | Ownership transfer failed |

---

## ElanRegistryOwner

Combines `users` and `profiles` table data. Separates UserSpice authentication from
ElanRegistry business logic.

```php
$owner = new ElanRegistryOwner($userId);
$data  = $owner->data();   // merged user + profile data

$owner->update([
    'id'      => $userId,
    'city'    => 'Portland',
    'state'   => 'Oregon',
    'country' => 'United States',
    'csrf'    => Token::generate(),
]);
```

**Terminology:** "owners" in the UI and business logic; "users" only in authentication/session
context. Never mix these labels.

Use `getUserWithProfile($userId)` (UserSpice helper) when you only need combined data
without mutation — it's lighter than instantiating `ElanRegistryOwner`.

---

## ApiResponse

Standardized JSON responses for all AJAX endpoints (Pattern A). Always use this instead
of `json_encode()` directly — it sets correct HTTP status codes and integrates logging.

```php
// Simple success
ApiResponse::success('Saved.')->send();

// Success with data payload
ApiResponse::success('Car loaded.')
    ->withData('car', $car->data())
    ->withData('images', $images)
    ->send();

// Error with logging (log executes when send() is called)
ApiResponse::serverError('Unexpected error.')
    ->withLogging($userId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $e->getMessage())
    ->send();

// Validation failure
ApiResponse::validationError(['chassis' => 'Invalid format.'])->send();
```

**Factory methods:** `success()` (200), `error()` (400), `validationError()` (422),
`unauthorized()` (401), `forbidden()` (403), `notFound()` (404), `serverError()` (500).

The response shape is always `{success: bool, message: string, ...extraData}`.
The frontend `ElanRegistryAPI` client (available globally via `footer.php`) handles
these responses and surfaces errors automatically.

See `docs/development/ERROR_HANDLING.md` for complete usage.

---

## LogCategories

Constants for the `logger()` function's category argument. Always use these — never
pass a raw string.

```php
// Log an event
logger($userId, LogCategories::LOG_CATEGORY_CAR_UPDATE, 'Color changed to Blue');
logger($userId, LogCategories::LOG_CATEGORY_CAR_DELETION, 'Car 26/0001 deleted');
logger(0,       LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $e->getMessage());

// Categories follow LOG_CATEGORY_{DOMAIN}_{ACTION} naming.
// Run this to see all available categories:
// grep "const LOG_CATEGORY" usersc/classes/LogCategories.php
```

See `docs/development/LOG_CATEGORIES.md` for the full reference.

---

## ElanRegistry\Input

Thin wrapper around UserSpice's `Input` that returns raw POST/GET values without
HTML-encoding. Use this for all values going to the database.

```php
use ElanRegistry\Input;

$color   = Input::raw('color');    // raw POST value — safe to store
$carId   = Input::raw('car_id');   // cast to int before use: (int)Input::raw('car_id')
```

Never use `\Input::get()` for values destined for the database — see `elanregistry_overrides`
for the double-encoding explanation.

---

## CarView (display only)

Static utility class for rendering car images, carousels, and spec tables.
No database writes — view layer only.

```php
CarView::loadCarPic($imageData, true);      // true = thumbnail
CarView::generateCarousel($images, rand(1000, 9999));
CarView::displayCarSpecs($carData);
```

---

## ChassisValidator

Validates Lotus Elan chassis and VIN formats specific to this registry.

```php
$validator = new ChassisValidator();
$result    = $validator->validate('26/0001');
if (!$result->isValid()) {
    throw new CarValidationException($result->getError());
}
```
