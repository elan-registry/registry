# UserSpice Integration Guide

This document provides comprehensive guidance on integrating with UserSpice and using existing integration patterns in the Lotus Elan Registry application.

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
- Geocoding script: `app/views/_geolocate.php`
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
// ✅ CORRECT: Integrate with existing geocoding system
public function updateLocation(array $locationData): bool {
    // Set required variables for geocoding
    $city = $locationData['city'];
    $state = $locationData['state'];
    $country = $locationData['country'];

    // Include geocoding system
    include($abs_us_root . $us_url_root . 'app/views/_geolocate.php');

    // Update profile with geocoded coordinates
    if (!empty($fields)) {
        $updateFields = array_merge($locationData, $fields);
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
