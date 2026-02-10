<!-- markdownlint-disable MD013 MD058 MD060 MD022 -->

# Database Schema Documentation

## Overview

**Database**: `unibrain_registry`  
**MySQL Version**: 8.0.39+  
**Character Set**: UTF-8/Latin1 (mixed)

### Core Components

- **User Management**: `users`, `profiles` tables with authentication and
  geographic data
- **Car Registry**: `cars`, `cars_hist` tables with comprehensive vehicle
  records and audit trails
- **Relationships**: `car_user`, `car_user_hist` junction tables with audit
  trails
- **Ownership Transfers**: `car_transfer_requests` table for self-service
  ownership transfer workflow
- **Factory Data**: `elan_factory_info` reference table for Lotus Elan
  specifications
- **System Tables**: `audit`, `country`, `fix_script_runs` for system operations
  and reference data

## Database Schema

### User Management

#### `users` - Primary user accounts
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `email` | `varchar(155)` | User email, NOT NULL, INDEX |
| `username` | `varchar(255)` | Display username |
| `password` | `varchar(255)` | Encrypted password |
| `fname`, `lname` | `varchar(255)` | First and last name |
| `permissions` | `int` | Permission level |
| `join_date` | `datetime` | Registration date |
| `last_login` | `datetime` | Last login timestamp |
| `email_verified` | `tinyint` | Email verification status |
| `active` | `int` | Account active status |
| `language` | `varchar(15)` | User language preference |

#### `profiles` - Extended user information
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY |
| `user_id` | `int` | Foreign key to `users.id` |
| `city`, `state`, `country` | `varchar(100)` | Location information |
| `lat`, `lon` | `float` | Geographic coordinates |
| `bio` | `text` | User biography |
| `website` | `varchar(100)` | Personal website |

### Car Registry

#### `cars` - Vehicle records

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int UNSIGNED` | PRIMARY KEY, AUTO_INCREMENT |
| `ctime`, `mtime` | `timestamp` | Creation and modification times |
| `vericode` | `varchar(32)` | Verification code |
| `last_verified` | `timestamp` | Last verification date |
| `ModifiedBy` | `varchar(30)` | User who last modified the record |
| `model` | `varchar(30)` | Car model (Elan) |
| `series` | `varchar(12)` | Car series (S1, S2, S3, S4, +2, Sprint) |
| `variant` | `varchar(15)` | Car variant |
| `year` | `varchar(4)` | Manufacturing year |
| `type` | `char(3)` | Vehicle type code |
| `chassis` | `varchar(15)` | Chassis number (INDEXED) |
| `color` | `varchar(25)` | Vehicle color |
| `engine` | `varchar(15)` | Engine specification |
| `purchasedate`, `solddate` | `date` | Purchase and sale dates |
| `comments` | `mediumtext` | Additional notes and history |
| `image` | `mediumtext` | Legacy image field (deprecated) |
| `user_id` | `int` | Primary owner user ID |
| `email`, `fname`, `lname` | `varchar(155)` | Owner contact info (synced) |
| `join_date` | `datetime` | Owner join date (synced) |
| `city`, `state`, `country` | `varchar(100)` | Owner location (synced, INDEXED) |
| `lat`, `lon` | `float` | Geographic coordinates (synced) |
| `website` | `varchar(100)` | Owner website (synced) |

**Note**: Owner-related fields (email, fname, lname, join_date, city, state,
country, lat, lon, website) are denormalized for performance and are
automatically synchronized from user profiles when user data changes.

#### `cars_hist` - Car audit trail
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY |
| `operation` | `varchar(32)` | Operation type (INSERT/UPDATE/DELETE) |
| `car_id` | `int UNSIGNED` | Original car ID |
| `timestamp` | `timestamp` | Change timestamp |
| *(All car columns)* | | Mirror of `cars` table structure |

#### `car_user` - Car sharing relationships

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `userid` | `int` | User ID (INDEXED) |
| `car_id` | `int` | Car ID (INDEXED) |
| `mtime` | `timestamp` | Relationship modification time |

**Note**: This junction table enables many-to-many relationships between users
and cars, allowing multiple users to be associated with a single car and users
to own multiple cars.

#### `car_user_hist` - Relationship audit trail

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `operation` | `varchar(32)` | Operation type (INSERT/UPDATE/DELETE) |
| `car_id` | `int UNSIGNED` | Car ID |
| `userid` | `int` | User ID |
| `timestamp` | `timestamp` | Change timestamp |

#### `car_transfer_requests` - Ownership transfer workflow

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int UNSIGNED` | PRIMARY KEY, AUTO_INCREMENT |
| `existing_car_id` | `int UNSIGNED` | Car being transferred (INDEXED) |
| `requested_by_user_id` | `int` | User requesting transfer (INDEXED) |
| `request_date` | `timestamp` | Transfer request date (INDEXED) |
| `status` | `enum` | pending, approved, denied, completed, expired (INDEXED) |
| `security_token` | `varchar(64)` | Unique security token (UNIQUE) |
| `expires_at` | `timestamp` | Token expiration time (INDEXED) |
| `admin_notes` | `text` | Administrative notes |
| `current_owner_response_date` | `timestamp` | Owner response timestamp |
| `completed_date` | `timestamp` | Transfer completion date |
| `denial_reason` | `text` | Reason for denial |
| `submitted_*` | various | Submitted car data fields (15 fields) |
| `created_by` | `int` | User who created request (INDEXED) |
| `modified_date` | `timestamp` | Last modification date |

**Note**: This table implements the self-service ownership transfer workflow,
storing both the transfer request metadata and a snapshot of all submitted car
data for verification and potential updates.

### Factory Reference Data

#### `elan_factory_info` - Lotus Elan factory specifications

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `year`, `month` | `varchar(4)`, `varchar(2)` | Manufacturing date |
| `batch` | `varchar(4)` | Production batch |
| `type` | `varchar(2)` | Vehicle type code |
| `serial`, `suffix` | `varchar(5)`, `varchar(1)` | Serial number and suffix |
| `engineletter` | `varchar(3)` | Engine letter code |
| `enginenumber` | `varchar(10)` | Engine number |
| `gearbox` | `varchar(1)` | Gearbox type code |
| `color` | `varchar(256)` | Factory original color |
| `builddate` | `date` | Build/invoice date |
| `note` | `mediumtext` | Additional notes and documentation |

#### `car_models` - Lotus Elan model definitions and year ranges

**Purpose**: Reference table for Lotus Elan model types extracted from cardefinition.js

**Source**: Extracted from `/app/assets/js/cardefinition.js` MENU array

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `year_available_from` | `int` | First production year (1963-1974) |
| `year_available_to` | `int` | Last production year (1963-1974) |
| `display_name` | `varchar(100)` | Full display name from cardefinition.js |
| `human_readable_short` | `varchar(50)` | Short name without parenthetical |
| `series` | `varchar(15)` | Series identifier (S1, S2, S3, S4, Sprint, +2, etc.) |
| `variant` | `varchar(20)` | Body style (Roadster, FHC, DHC, Federal, Race) |
| `type_code` | `char(3)` | Lotus type code (26, 36, 45, 50, 26R) |
| `model_value` | `varchar(50)` | Composite key "series\|variant\|type" (UNIQUE) |
| `series_normalized` | `varchar(15)` | GENERATED: Normalized series (strips SE/S/E/Race) |

**Indexes**:
- `unique_model_combo` (series, variant, type_code) - Enforce model uniqueness
- `idx_year_range` (year_available_from, year_available_to) - Year range filtering
- `idx_series_normalized` (series_normalized) - Filtering by normalized series
- `idx_type_code` (type_code) - Filtering by Lotus type code

**Populated By**: FIX script `FIX/26-Load-Car-Models.php`

**Accessed Via**: `ElanRegistry\Reference\CarModel` class

**Example Records**:
```
id=1, years=1963-1964, series="S1", variant="Roadster", type_code="26", model_value="S1|Roadster|26"
id=5, years=1971-1974, series="S4", variant="FHC", type_code="36", model_value="S4|FHC|36"
```

**Used By**:
- Issue #298-1: Factory Colors normalization (series filtering)
- Issue #298-3: Series normalization (model-based filtering)
- Issue #298-4: Color suggestion API (model-based color filtering)
- Phase 2: Dynamic model dropdowns (replacing hardcoded cardefinition.js)

### System Tables

#### `audit` - UserSpice audit logging

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `user` | `int` | User ID who performed action |
| `page` | `varchar(255)` | Page or action performed |
| `timestamp` | `timestamp` | Action timestamp |
| `ip` | `varchar(255)` | IP address of user |
| `viewed` | `int(1)` | View status flag |

#### `country` - Country reference data

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `name` | `varchar(100)` | Country name |

#### `fix_script_runs` - Database maintenance tracking

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `script_name` | `varchar(255)` | Name of FIX script executed |
| `run_date` | `timestamp` | Execution timestamp |

## Database Relationships

### Primary Relationships

- **Users ↔ Profiles**: One-to-one relationship
  (`users.id` → `profiles.user_id`)
- **Users ↔ Cars**: One-to-many direct ownership
  (`users.id` → `cars.user_id`)
- **Users ↔ Cars**: Many-to-many sharing via `car_user` junction table
- **Cars → History**: One-to-many audit trail (`cars.id` → `cars_hist.car_id`)

### Data Access Patterns

**Note**: This database no longer uses views. All data access is performed through direct queries or the application layer using the `getUserWithProfile()` function for combined user and profile data.

## System Features

### Database Triggers

**Car Audit Triggers** (implemented):

- `cars_insert`: Automatically logs new car registrations to `cars_hist` table
- `cars_update`: Logs car modifications to `cars_hist` table with bypass via
  `@disable_triggers` variable
- `cars_delete`: Logs car deletions to `cars_hist` table

**Trigger Details**:

- All triggers capture complete car record snapshots including owner data
- Triggers updated 2025-09-12 to use current schema (no deprecated columns)
- Each trigger records operation type (INSERT/UPDATE/DELETE) and timestamp

**Note**: Currently only `cars` table has triggers. The `car_user` relationship
changes are logged through application-level logging to `car_user_hist` table,
not database triggers.

### Special System Accounts

- **`noowner` (ID: 83)**: Fallback owner for cars when users are deleted
  (GDPR compliance)
- **`admin` (ID: 1)**: Primary administrative account

**Note**: The `noowner` user is located dynamically by username, not hardcoded
ID.

### User Deletion & GDPR Compliance

**Cleanup Process** (`/usersc/scripts/after_user_deletion.php`):

1. Remove orphaned `profiles` records
2. Remove user's `car_user` relationships
3. Transfer car ownership to `noowner` user (preserves registry data)
4. All changes automatically logged via database triggers

**Maintenance Utilities** (`/FIX/02-Cleanup-Orphaned-Profiles.php`):

- Cleanup orphaned profiles and relationships
- Reassign ownerless cars to `noowner`
- Real-time progress reporting
