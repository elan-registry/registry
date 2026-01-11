# UserSpice Integration Guide

This document provides comprehensive guidance on integrating with UserSpice and
using existing integration patterns in the Lotus Elan Registry application.

## Official UserSpice Documentation

For additional UserSpice features and detailed framework documentation, see:

**UserSpice Knowledge Base**: <https://userspice.com/kb/>

Topics covered in official documentation:

- Core framework features and configuration
- Advanced authentication patterns
- Custom plugins and hooks
- Database schema and migrations
- User management and permissions
- Email templates and notifications

This guide focuses on **Elan Registry-specific integration patterns** and
**common usage examples** for our application.

---

## Page Security and Access Control

### SecurePage() Pattern

**CRITICAL**: All pages requiring authentication or permission checks MUST
include the `securePage()` check.

#### Standard Implementation

```php
<?php
require_once '../users/init.php';  // Initialize UserSpice

// REQUIRED: Security check - place immediately after init.php
if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Rest of page code here
?>
```

#### What securePage() Does

1. **Checks if user is logged in** - Redirects to login if not authenticated
2. **Checks page permissions** - Verifies user has required permission level
3. **Validates page registration** - Ensures page is registered in UserSpice admin
4. **Returns boolean** - `false` if access denied, `true` if access granted

#### Page Registration Requirements

**Step 1**: Register page in UserSpice Admin Panel

1. Navigate to: **Admin Dashboard → Page Management**
2. Add new page with full path (e.g., `app/cars/edit.php`)
3. Set permission levels:
   - `1` = Standard User
   - `2` = Administrator
   - Multiple levels can be assigned

**Step 2**: Update z_us_root.php if in new directory

If the page is in a new directory not yet included in UserSpice paths:

```php
// /z_us_root.php
$path = [
    '',
    'users/',
    'usersc/',
    'app/',
    'app/cars/',      // Add new directories here
    'app/admin/',
    'app/reports/',
    // ... other paths
];
```

#### Common Permission Levels

- **Level 1** - Standard registered users (all authenticated users)
- **Level 2** - Administrators (full access)
- **Level 3** - Custom permission level (if configured)

#### Permission Checks in Code

For additional permission checks within a page:

```php
// Check if current user has admin permissions
if (hasPerm([2], $user->data()->id)) {
    // Admin-only code
    echo '<a href="admin-function.php">Admin Panel</a>';
}

// Check for multiple permission levels
if (hasPerm([2, 3], $user->data()->id)) {
    // Admin or custom level
}

// Check for specific user
if ($user->data()->id == $ownerId) {
    // User is the owner of this resource
}
```

#### Example: Public vs Protected Pages

**Public Page** (no securePage required):

```php
<?php
require_once '../users/init.php';
// No securePage() - anyone can access
?>
<h1>Welcome to the Registry</h1>
```

**User-Only Page**:

```php
<?php
require_once '../users/init.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}
// Only authenticated users with permission level 1+ can access
?>
<h1>Your Cars</h1>
```

**Admin-Only Page**:

```php
<?php
require_once '../users/init.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Additional admin check
if (!hasPerm([2], $user->data()->id)) {
    Redirect::to('index.php');
}
// Only administrators can access
?>
<h1>Admin Dashboard</h1>
```

#### Best Practices

1. **Always use securePage()** on pages with sensitive data or operations
2. **Place immediately after init.php** - before any other logic
3. **Register pages before deploying** - unregistered pages will deny all access
4. **Use hasPerm() for granular control** - within pages for specific features
5. **Log permission denials** - for security auditing

```php
if (!hasPerm([2], $user->data()->id)) {
    logger($user->data()->id, 'SecurityError',
           'Unauthorized access attempt to admin function');
    Redirect::to('index.php');
}
```

#### Common Errors

**Error**: "This page has a security feature which has not yet been configured"

- **Cause**: Page not registered in UserSpice admin panel
- **Fix**: Add page in Admin Dashboard → Page Management

**Error**: Page redirects to login even when logged in

- **Cause**: User lacks required permission level
- **Fix**: Check user's permission level or adjust page permissions

**Error**: securePage() not working

- **Cause**: Path not in `$path` array in z_us_root.php
- **Fix**: Add directory to `$path` array

## Owner Data Management Patterns

**Use these patterns when working with owner/user data operations:**

### Geocoding System

**Automatic Location Geocoding:**

- Location updates automatically trigger Google Maps API geocoding
- Coordinates are automatically populated when city/state/country is provided
- Admin interface provides visual feedback for geocoding success/failure
- Failed geocoding preserves existing coordinates and shows clear error messages

```php
// ✅ Location updates with automatic geocoding
$owner = new ElanRegistryOwner($userId);
$owner->update([
    'id' => $userId,
    'city' => 'Portland',
    'state' => 'Oregon',
    'country' => 'United States',
    'csrf' => Token::generate()
]);
// Coordinates automatically populated via Google Maps API
```

**Geocoding Configuration:**

- API key stored in settings table as `elan_google_geo_key`
- Geocoding class: `usersc/classes/LocationGeocoder.php` (internal-only)
- Public API: `ElanRegistryOwner::geocodeAddress()` static method
- Coordinates rounded to 4 decimal places (~11 meter accuracy)
- Failed requests are logged for troubleshooting

### Admin Interface Structure

**Consolidated Management Interface:**

- **Location**: `app/admin/manage-consolidated.php`
- **Purpose**: Unified admin interface for all registry management tasks
- **Tabs Available**:
  - **Car/Owner Relationships**: Transfer requests and ownership management
  - **Manage Cars**: Car data quality issues and duplicate detection
  - **Owner Management**: Owner profiles with search and quality reports
  - **System Maintenance**: Database cleanup and maintenance tasks
  - **Settings**: Configuration management
  - **Account Cleanup**: User account management

**Quality Badge System:**

- Dynamic badges show issue counts on relevant tabs
- Owner Management tab shows owner-specific quality issues
- Manage Cars tab shows car-specific quality issues
- Badges update in real-time based on database state

**Owner Management Features:**

- Advanced search with UNION-based prioritization (exact matches first)
- Real-time geocoding feedback with visual indicators
- Profile quality scoring and completion tracking
- Bulk location synchronization to owned cars

### Owner Profile Access

```php
// ✅ PREFERRED: Use existing custom function for complete owner data
$owner = getUserWithProfile($userId);
if ($owner) {
    echo "Owner: {$owner->fname} {$owner->lname}";
    echo "Location: {$owner->city}, {$owner->state}, {$owner->country}";
    echo "Coordinates: {$owner->lat}, {$owner->lon}";
}

// ✅ ACCEPTABLE: When you need additional owner context
class ElanRegistryOwner {
    public static function getOwnerProfile(int $userId): ?object {
        return getUserWithProfile($userId);
    }

    public function getCarsOwned(): array {
        return $this->_db->query("SELECT * FROM cars WHERE user_id = ?", [$this->_data->id])->results();
    }
}
```

### Location Updates with Geocoding

```php
// ✅ CORRECT: Use ElanRegistryOwner geocoding API
public function updateLocation(array $locationData): bool {
    // Use static geocoding method
    $geoResult = ElanRegistryOwner::geocodeAddress(
        $locationData['city'],
        $locationData['state'],
        $locationData['country']
    );

    // Update profile with geocoded coordinates
    if (!empty($geoResult)) {
        $updateFields = array_merge($locationData, $geoResult);
        return $this->_db->update('profiles', $this->_profileId, $updateFields);
    }

    return false;
}
```

### Owner Search and Management Interface

```php
// ✅ CORRECT: Admin search functionality
public function searchOwners(string $searchTerm): array {
    $searchTerm = '%' . $searchTerm . '%';

    return $this->_db->query(
        "SELECT u.id, u.fname, u.lname, u.email,
                p.city, p.state, p.country
         FROM users u
         LEFT JOIN profiles p ON u.id = p.user_id
         WHERE u.fname LIKE ? OR u.lname LIKE ? OR u.email LIKE ?
            OR p.city LIKE ? OR p.state LIKE ?
         ORDER BY u.lname, u.fname",
        [$searchTerm, $searchTerm, $searchTerm,
         $searchTerm, $searchTerm]
    )->results();
}
```

### Data Quality Integration

```php
// ✅ CORRECT: Profile completeness scoring
public function getProfileQualityScore(): float {
    $owner = $this->data();
    $totalFields = 7;
    $completedFields = 0;

    if (!empty($owner->fname))
        $completedFields++;
    if (!empty($owner->lname))
        $completedFields++;
    if (!empty($owner->email))
        $completedFields++;
    if (!empty($owner->city))
        $completedFields++;
    if (!empty($owner->state))
        $completedFields++;
    if (!empty($owner->country))
        $completedFields++;
    if (!empty($owner->lat) && !empty($owner->lon))
        $completedFields++;

    return round(($completedFields / $totalFields) * 100, 1);
}
```

## ElanRegistry Terminology Standards

**CRITICAL:** Consistent terminology is essential for code clarity and user experience.

### User vs Owner Terminology

- **Users**: Authentication and session management context (UserSpice framework)
  - Use in UserSpice integration code
  - Database table references (`users` table)
  - Session management and permissions
  - Authentication workflows

- **Owners**: Car registry business domain context (ElanRegistry terminology)
  - Use in UI elements and user-facing content
  - Business logic and domain operations
  - Car ownership and registry functionality
  - Admin interfaces referring to registry participants

### Code Implementation Guidelines

```php
// ✅ CORRECT: UserSpice context
$user = new User();
if ($user->isLoggedIn()) {
    // UserSpice authentication logic
}

// ✅ CORRECT: ElanRegistry context
$owner = new ElanRegistryOwner($userId);
$ownerProfile = $owner->getOwnerProfile();
echo "Owner: " . $owner->data()->fname . " " . $owner->data()->lname;

// ✅ CORRECT: Database operations use UserSpice table names
$userQuery = $db->query("SELECT * FROM users WHERE id = ?", [$userId]);
$profileQuery = $db->query(
    "SELECT * FROM profiles WHERE user_id = ?",
    [$userId]
);

// ✅ CORRECT: UI elements use owner terminology
echo "<h3>Owner Information</h3>";
echo "<button>Contact Owner</button>";
echo "<span>Owner Location: {$owner->city}, {$owner->state}</span>";
```

### Integration Patterns

**Use existing `getUserWithProfile()` function for combined data access:**

```php
// ✅ CORRECT: Leverage existing custom function
$ownerData = getUserWithProfile($userId);
if ($ownerData) {
    echo "Owner: {$ownerData->fname} {$ownerData->lname}";
    echo "Location: {$ownerData->city}, ";
    echo "{$ownerData->state}";
}
```

**Follow established Car class patterns for new domain classes:**

```php
// ✅ CORRECT: ElanRegistryOwner class follows Car class patterns
class ElanRegistryOwner {
    private $_db;
    private $_data;

    public function __construct(?int $id = null) {
        $this->_db = DB::getInstance();
        if ($id) {
            $this->find($id);
        }
    }

    public function create(array $fields): bool {
        // Validation, sanitization, audit logging
        // Follow Car class exception handling patterns
    }
}
```
