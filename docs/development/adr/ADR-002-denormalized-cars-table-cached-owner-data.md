# ADR-002: Use a Denormalized Cars Table with Cached Owner Data

## Status

**Accepted** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

The Lotus Elan Registry is a read-heavy application. Car listings, geographic
maps, and car detail pages are accessed far more frequently than owner profiles
are updated. The primary owner data lives in the `users` table (name, email)
and `profiles` table (location, website), while the car data lives in the `cars`
table. A normalized design would require joining three tables for every query
that displays car information with owner context.

The application's primary read patterns include:

- **Car listings** -- DataTables server-side rendering with 50-500 rows per page,
  potentially filtered by location, make, or other criteria
- **Map markers** -- geographic coordinates and owner location/website for each car
- **Geographic statistics** -- car counts by country, state, or city
- **Car detail pages** -- full owner information (name, email, location) displayed
  alongside car specifications

Every car displays owner contact information prominently. The alternative to
denormalization would be to JOIN cars with users and profiles on every read,
but given the read-heavy nature of the application (car data is displayed far
more often than owner profiles are updated), the performance trade-off is
significant.

Additionally, the application has no ORM and no query builder. All SQL is
hand-written using the UserSpice `DB` class. Complex joins are verbose and
error-prone.

The application is a traditional server-rendered PHP application of moderate
size (approximately 50-60 pages), deployed on standard VPS hosting without
caching infrastructure (Redis/Memcached).

## Decision

Store 10 denormalized owner data columns directly in the `cars` table: `email`,
`fname`, `lname`, `join_date`, `city`, `state`, `country`, `lat`, `lon`, and
`website`. These columns duplicate data from the `users` (email, fname, lname,
join_date) and `profiles` (city, state, country, lat, lon, website) tables.

### Denormalized Columns

| Column in `cars` | Source Table | Source Column | Usage Context |
| --- | --- | --- | --- |
| `email` | `users` | `email` | Car owner contact display |
| `fname` | `users` | `fname` | Car owner contact display |
| `lname` | `users` | `lname` | Car owner contact display |
| `join_date` | `users` | `join_date` | Registry membership timeline |
| `city` | `profiles` | `city` | Geographic filtering, map display |
| `state` | `profiles` | `state` | Geographic filtering, map display |
| `country` | `profiles` | `country` | Geographic filtering, map display |
| `lat` | `profiles` | `lat` | Map markers, geographic stats |
| `lon` | `profiles` | `lon` | Map markers, geographic stats |
| `website` | `profiles` | `website` | Car owner contact display |

### Sync Mechanism

Denormalized columns are synchronized through four distinct paths:

#### 1. Ownership Transfer (Primary Sync)

When a car is transferred to a new owner via `CarAdministrationService::transfer()`:

```php
// All 10 columns are synchronized
// $targetUser is the flat object returned by getUserWithProfile()
$updateFields = [
    'user_id'   => $targetUser->id,
    'email'     => $targetUser->email ?? '',
    'fname'     => $targetUser->fname ?? '',
    'lname'     => $targetUser->lname ?? '',
    'join_date' => $targetUser->join_date ?? date(AppConstants::DATETIME_FORMAT),
    'city'      => $targetUser->city ?? '',
    'state'     => $targetUser->state ?? '',
    'country'   => $targetUser->country ?? '',
    'lat'       => $targetUser->lat ?? null,
    'lon'       => $targetUser->lon ?? null,
    'website'   => $targetUser->website ?? '',
];
// Executed via Car::update() callback (which calls CarRepository::update())
```

#### 2. Owner Profile Update (Partial Sync)

When an owner updates their location via the user settings page
(`usersc/user_settings.php`, lines 252-293), five location columns are
synchronized -- but **only when coordinates are available** (either from the
frontend LocationPicker or fallback geocoding). If validation passes but no
coordinates are obtained, no car sync occurs at all:

```php
// Sync fires only when geoResult contains lat/lon coordinates
if (!empty($geoResult) && isset($geoResult['lat']) && isset($geoResult['lon'])) {
    $userCarsQuery = $db->query("SELECT car_id AS id FROM car_user WHERE userid = ?", [$userId]);
    foreach ($userCarsQuery->results() as $car) {
        $db->update('cars', (int)$car->id, [
            'city'  => $city,
            'state' => $state,
            'country' => $country,
            'lat'   => $geoResult['lat'],
            'lon'   => $geoResult['lon'],
            'mtime' => date(AppConstants::DATETIME_FORMAT),
        ]);
    }
}
```

#### 3. Admin Location Sync (Targeted Sync)

Administrators can trigger location synchronization via the admin panel AJAX
endpoint (`app/admin/includes/process-owner-sync-location.php`):

```php
// Triggered via admin UI -- calls ElanRegistryOwner::syncLocationToCars()
// which updates all cars owned by the user via the car_user junction table
$owner->syncLocationToCars();
// Syncs: city, state, country, lat, lon, mtime
// Also writes LOCATION_SYNC audit record to cars_hist
```

#### 4. Bulk Repair (Archive)

Archived FIX scripts (`FIX/_ARCHIVE/20-Backfill-Location-Coordinates.php`,
`FIX/_ARCHIVE/04-Regeocode-Null-Coordinates.php`) were used for initial bulk
repair of location data. These are not part of the normal sync flow.

### Audit Trail

The `cars_hist` table mirrors the full `cars` schema, including all 10
denormalized columns. Three database triggers (`cars_insert`, `cars_update`,
`cars_delete`) automatically capture point-in-time snapshots on every change:

```sql
-- Simplified illustration. The actual triggers use explicit column lists.
-- The cars_update trigger includes a bypass guard for bulk operations:
CREATE TRIGGER cars_update
AFTER UPDATE ON cars
FOR EACH ROW
BEGIN
    IF @disable_triggers IS NULL THEN
        INSERT INTO cars_hist(operation, car_id, ...) VALUES ('UPDATE', OLD.id, ...);
    END IF;
END;
-- cars_insert and cars_delete do NOT check @disable_triggers; they always fire.
```

Application-level inserts are also made to `cars_hist` for significant
operations:

- **NEWOWNER** -- new car registered to an owner
- **MERGE** -- cars merged (rarely used)
- **LOCATION_SYNC** -- location fields synchronized via admin panel
- **GDPR_DELETE** -- cars reassigned to noowner system account when owner is deleted

The triggers bypass mechanism allows critical operations to skip audit logging:

```php
$db->query("SET @disable_triggers = 1");
// Perform operation without triggering audit insert
$db->query("SET @disable_triggers = NULL");
```

### Impact on Classes

The `Car` class and related services are designed to work directly with
denormalized data:

- `Car::find()` assembles the `$this->_owner` property directly from car
  columns, requiring zero secondary queries
- `Car::update()` includes all 10 denormalized columns in `$validCarFields`
- `CarDataTablesService` reads owner display data from the cars table directly
- `CarRepository::findAll()` queries only the `cars` table
- Geographic indexes on `city`, `state`, and `country` enable efficient
  filtering without secondary table access

## Consequences

### Positive

- **Single-table read queries.** Car listings, maps, and detail pages query only
  the `cars` table. No JOINs are required for any common read pattern. For a
  read-heavy application, this is a substantial performance advantage.
- **Efficient geographic filtering.** Geographic columns (`city`, `state`,
  `country`) are indexed directly on the cars table, enabling fast filtering by
  location without joining to the profiles table.
- **Complete point-in-time audit trail.** The `cars_hist` table captures the
  exact state of all car data (including owner information) at each point in
  time. Historical queries require no joins.
- **Simple query patterns.** All queries are straightforward single-table
  selects. There are no complex join predicates or NULL-handling cases.
  Hand-written SQL in a codebase with no ORM benefits from this simplicity.
- **No ORM dependency.** The application's hand-written SQL approach is naturally
  suited to denormalized data. Switching to an ORM could actually increase
  complexity.

### Negative

- **Identity and website sync gap.** The `fname`, `lname`, `email`, `join_date`,
  and `website` columns only synchronize during ownership transfer. If an owner
  changes their name, email address, or website URL, the `cars` table will not
  be updated automatically. The divergence persists until either: (a) another
  owner change occurs, or (b) an administrator manually runs the location sync
  (which does not fix identity or website fields). This is a known and documented
  limitation.
- **Storage overhead.** Ten columns are duplicated per car row. For 500+ cars,
  this is roughly 40-80 KB of additional storage (negligible in 2024). However,
  the `cars_hist` table doubles this overhead for audit trail purposes.
- **Multiple sync code paths.** Four distinct sync mechanisms must remain
  synchronized as the application evolves:
  1. Ownership transfer (all fields)
  2. Profile update (location only)
  3. Admin location sync (location only)
  4. GDPR user deletion (fields set to defaults)

  Changes to either the source schema (`users`, `profiles`) or the business
  rules for synchronization must be reflected in all four code paths.
- **Overstated "automatic" sync in documentation.** Existing documentation
  refers to owner data as "automatically" synchronized, which is misleading.
  Synchronization is conditional (only certain fields, only in specific
  operations) and requires explicit code in multiple places. This can lead
  developers to incorrectly assume data is in sync when it may not be.
- **Schema change friction.** Adding, renaming, or removing fields in the
  `users` or `profiles` tables requires updates to the sync code in at least
  four places, plus updates to the `cars_hist` schema.

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| Identity field divergence becomes a support issue | Medium | Low | Document limitation; add UI warnings when fields diverge; consider quarterly full sync |
| Developer adds user field without updating sync code | Medium | Medium | Centralized sync path list; PR checklist item; test validating sync alignment |
| Location data becomes stale due to sync gaps | Low | Low | Admin dashboard for stale data; regular audit queries; monitor sync frequency |
| Schema migration requires updating multiple sync paths | Low | Medium | Document all sync paths in DATABASE.md; consider SyncManager utility class |

## Alternatives Considered

### Normalized Design with Joins

Store owner data only in the `users` and `profiles` tables. Query with JOINs
for every read operation.

```sql
SELECT c.id, c.vin, u.fname, u.lname, u.email, p.city, p.state, p.country
FROM cars c
JOIN users u ON c.user_id = u.id
LEFT JOIN profiles p ON u.id = p.user_id
WHERE c.deleted = 0;
```

**Rejected because:**

- Every car listing, map render, and detail page would require a three-table
  JOIN. For a read-heavy application with 500+ cars per listing (pagination
  notwithstanding), this is a measurable performance cost.
- Geographic filtering (e.g., "cars in Germany") would require joining to the
  profiles table, negating the benefit of indexes on the cars table alone.
- Hand-written SQL without an ORM makes complex joins verbose and harder to
  maintain. The lack of a query builder means each JOIN is written out
  explicitly with all predicates and NULL handling.

### Database Views (Virtual Denormalization)

Create a view that joins cars, users, and profiles, and query the view instead
of the cars table.

```sql
CREATE VIEW cars_with_owners AS
SELECT c.*, u.fname, u.lname, u.email, u.join_date,
       p.city, p.state, p.country, p.lat, p.lon, p.website
FROM cars c
JOIN users u ON c.user_id = u.id
LEFT JOIN profiles p ON u.id = p.user_id;
```

**Rejected because:**

- The application previously used views but removed them (views are mentioned in
  historical discussions as a past approach). This suggests performance or
  maintenance issues with views in the MySQL/PHP environment.
- Views do not avoid the JOIN cost; the database still executes the JOIN on
  every query. They simply make it implicit and harder to optimize.
- Views add a layer of indirection that makes query plans harder to debug
  without a DBA-level understanding of the codebase.

### Materialized Views (Scheduled Refresh)

Create a separate table that is refreshed on a schedule (e.g., hourly or
nightly).

```sql
-- Scheduled job (cron)
REPLACE INTO cars_materialized
SELECT c.*, u.fname, u.lname, u.email, u.join_date,
       p.city, p.state, p.country, p.lat, p.lon, p.website
FROM cars c
JOIN users u ON c.user_id = u.id
LEFT JOIN profiles p ON u.id = p.user_id;
```

**Rejected because:**

- MySQL 8.0+ does not natively support materialized views. This would require a
  custom cron job to manage refresh.
- Introduces staleness windows: owner data would be out-of-date until the next
  refresh. For a relatively small, community-driven application where timeliness
  matters, this is problematic.
- Adds operational complexity (cron management, failure handling, monitoring)
  with no performance advantage over static denormalization.
- The application has no Redis/Memcached infrastructure, so there's no existing
  cache management pattern to leverage.

### Application-Level Caching (Redis/Memcached)

Cache the result of car + owner JOINs in Redis or Memcached, invalidating the
cache when cars or user profiles are updated.

```php
$cacheKey = "car:$carId:with_owner";
$cached = $redis->get($cacheKey);
if ($cached === null) {
    $car = $db->query("SELECT ... FROM cars c JOIN users u ...");
    $redis->setex($cacheKey, 3600, json_encode($car));
} else {
    $car = json_decode($cached, true);
}
```

**Rejected because:**

- Introduces an external infrastructure dependency (Redis or Memcached) that is
  not currently part of the hosting environment. The application is designed to
  run on standard PHP hosting.
- Adds operational overhead: setup, monitoring, failover, and memory management
  of the cache layer.
- Cache invalidation complexity: every car update, ownership transfer, and
  profile update must invalidate relevant cache keys. The current approach has
  four sync code paths; adding cache management would require updates in all
  four plus additional cache invalidation logic.
- For a read-heavy but not ultra-high-traffic application (hundreds of cars,
  not millions), the benefit does not justify the operational cost.

### Hybrid Approach (Selective Denormalization)

Denormalize only the most-queried fields (e.g., owner name and location) but
leave less-frequently accessed fields (email, website) in a separate table,
queried only when needed.

```php
// Cars table has: fname, lname, city, state, country, lat, lon
// For email and website, query users/profiles separately
$car = $db->select('cars', ['id' => $carId])[0];
if (displaying_email($context)) {
    $user = $db->select('users', ['id' => $car['user_id']])[0];
    $car['email'] = $user['email'];
}
```

**Rejected because:**

- Email is displayed on every car detail page and car owner listings. It is not
  less-frequently accessed; it is a core data element in most views.
- Complicates the Car class and query patterns by making some fields always
  available and others conditionally available.
- Does not address the geographic filtering use case, which requires lat/lon
  and other location fields to be readily available for JOINs or index lookups.
- The performance benefit is minimal: a selective approach saves 2-3 columns of
  storage per car but requires conditional logic that adds code complexity.

## References

- **Schema Definition**: [database/1-schema.sql](../../database/1-schema.sql)
  (cars table lines 62-92, cars_hist lines 97-128, triggers lines 335-398)
- **Car Class**: [usersc/classes/Car.php](../../usersc/classes/Car.php)
- **Car Administration**: [usersc/classes/Car/CarAdministrationService.php](../../usersc/classes/Car/CarAdministrationService.php)
  (transfer method lines 125-230)
- **Owner Class**: [usersc/classes/Owner.php](../../usersc/classes/Owner.php)
  (syncLocationToCars method lines 531-572)
- **User Settings Page**: [usersc/user_settings.php](../../usersc/user_settings.php)
  (location update and conditional car sync, lines 162-293)
- **Admin Location Sync**: [app/admin/includes/process-owner-sync-location.php](../../app/admin/includes/process-owner-sync-location.php)
- **User Deletion Handler**: [usersc/scripts/after_user_deletion.php](../../usersc/scripts/after_user_deletion.php)
- **Database Documentation**: [docs/development/DATABASE.md](../development/DATABASE.md)
- **FIX Scripts (Archive)**: `FIX/_ARCHIVE/20-Backfill-Location-Coordinates.php`,
  `FIX/_ARCHIVE/04-Regeocode-Null-Coordinates.php`
- **ADR-001: Authentication Framework**: [ADR-001-userspice-authentication-framework.md](ADR-001-userspice-authentication-framework.md)
