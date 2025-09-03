# Database Schema Documentation

## Overview

**Database**: `unibrain_registry`  
**MySQL Version**: 8.0.39+  
**Character Set**: UTF-8/Latin1 (mixed)

### Core Components
- **User Management**: `users`, `profiles` tables with authentication and geographic data
- **Car Registry**: `cars`, `cars_hist` tables with comprehensive vehicle records and audit trails
- **Relationships**: `car_user` junction table enabling car sharing between users
- **Factory Data**: `elan_factory_info` reference table for Lotus Elan specifications
- **Views**: `usersview`, `users_carsview` for complex queries

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
| `user_id` | `int` | Owner user ID |
| `username` | `varchar(30)` | Owner username |
| `email`, `fname`, `lname` | `varchar(155)` | Owner contact information |
| `ctime`, `mtime` | `timestamp` | Creation and modification times |
| `model` | `varchar(30)` | Car model |
| `series` | `varchar(12)` | Car series (S1, S2, S3, S4, +2, Sprint) |
| `variant` | `varchar(15)` | Car variant |
| `type` | `char(3)` | Vehicle type code |
| `year` | `varchar(4)` | Manufacturing year |
| `chassis` | `varchar(15)` | Chassis number |
| `engine` | `varchar(15)` | Engine specification |
| `color` | `varchar(25)` | Vehicle color |
| `purchasedate`, `solddate` | `date` | Purchase and sale dates |
| `vericode` | `varchar(32)` | Verification code |
| `last_verified` | `timestamp` | Last verification date |
| `comments` | `text` | Additional notes |

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
| `id` | `int` | PRIMARY KEY |
| `userid` | `int` | User ID |
| `carid` | `int` | Car ID |
| `mtime` | `timestamp` | Relationship modification time |

#### `car_user_hist` - Relationship audit trail
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY |
| `operation` | `varchar(32)` | Operation type |
| `carid`, `userid` | `int` | Car and user IDs |
| `timestamp` | `timestamp` | Change timestamp |

### Factory Reference Data

#### `elan_factory_info` - Lotus Elan factory specifications
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY |
| `year`, `month` | `varchar(4)`, `varchar(2)` | Manufacturing date |
| `batch` | `varchar(4)` | Production batch |
| `type` | `varchar(2)` | Vehicle type |
| `serial`, `suffix` | `varchar(5)`, `varchar(1)` | Serial number and suffix |
| `engineletter`, `enginenumber` | `varchar(3)`, `varchar(10)` | Engine details |
| `gearbox` | `varchar(1)` | Gearbox type |
| `color` | `varchar(256)` | Factory color |
| `builddate` | `date` | Build/invoice date |

## Database Relationships

### Primary Relationships
- **Users ↔ Profiles**: One-to-one relationship (`users.id` → `profiles.user_id`)
- **Users ↔ Cars**: One-to-many direct ownership (`users.id` → `cars.user_id`)
- **Users ↔ Cars**: Many-to-many sharing via `car_user` junction table
- **Cars → History**: One-to-many audit trail (`cars.id` → `cars_hist.car_id`)

### Database Views

#### `usersview` - Combined user and profile data
```sql
SELECT users.id, email, fname, lname, username, join_date,
       last_login, logins, email_verified, permissions,
       city, state, country, lat, lon, website
FROM users JOIN profiles ON users.id = profiles.user_id
```

#### `users_carsview` - Comprehensive car ownership view
Complex view combining car, user, and profile information including:
- Cars linked through `car_user` junction table
- Direct car ownership relationships
- Orphaned cars (no user associations)
- Complete user and location details

## System Features

### Database Triggers
**Car Audit Triggers**:
- `cars_insert`: Logs new car registrations
- `cars_update`: Logs modifications (with bypass option via `@disable_triggers`)
- `cars_delete`: Logs car deletions

**Relationship Audit Triggers**:
- `car_user_insert/update/delete`: Maintains audit trail for sharing relationships

### Special System Accounts
- **`noowner` (ID: 83)**: Fallback owner for cars when users are deleted (GDPR compliance)
- **`admin` (ID: 1)**: Primary administrative account

**Note**: The `noowner` user is located dynamically by username, not hardcoded ID.

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

### Reference Tables

#### `country` - Country reference data
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY |
| `name` | `varchar(100)` | Country name |