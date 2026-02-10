# ElanRegistry Namespace

Custom classes for the Elan Registry application organized by architectural role.

## Directory Structure

```
ElanRegistry/
├── Exceptions/          # Custom exception types
│   ├── CarException.php
│   ├── CarNotFoundException.php
│   └── CarValidationException.php
└── Reference/           # External reference data classes
    ├── CarModel.php     # Car model definitions
    └── README.md        # This file
```

## Namespace Conventions

### ElanRegistry\Exceptions

**Purpose**: Custom exception types for domain-specific error handling.

**Pattern**: All exceptions extend a base exception class (e.g., CarException).

**Autoload**: Configured in `composer.json` → `autoload.psr-4`

**Usage**:
```php
use ElanRegistry\Exceptions\CarNotFoundException;

try {
    if (!$car) {
        throw new CarNotFoundException("Car ID {$id} not found");
    }
} catch (CarNotFoundException $e) {
    // Handle specific car not found error
}
```

---

### ElanRegistry\Reference

**Purpose**: External/canonical reference data from authoritative sources (Lotus factory data).

**Characteristics**:
- Read-only classes (no CRUD operations)
- Static query methods or simple instance methods
- Represent facts about cars from external sources, not registry records
- Used for filtering, validation, and reference lookups

**Examples**:
- `CarModel` - Model definitions, year ranges, series/variants (from cardefinition.js)
- `FactoryColor` (planned #298-1) - Official Lotus colors by series
- `FactoryInfo` (future) - Factory production specifications

**Autoload**: Configured in `composer.json` → `autoload.psr-4`

**Usage Pattern**:
```php
use ElanRegistry\Reference\CarModel;

$carModel = new CarModel();
$models = $carModel->getAvailableInYear(1970);
```

---

## Class Organization Patterns

### Reference Data vs. Entity Classes

**Reference Data Classes** (`ElanRegistry\Reference`):
- Represent **external/canonical facts** about cars from Lotus (factory data, official colors, model specifications)
- **Read-only** - no create/update/delete operations
- Static query methods or simple instance methods
- Example queries: Get models by year, get series by year, validate model combination
- Used by: Color normalization features, dynamic dropdowns, model-based filtering

**Entity Classes** (root namespace):
- Represent **registry records** (individual car registrations, owner profiles)
- **Full CRUD operations** - create, read, update, delete
- Instance methods and properties with state
- Example operations: Add car, update owner, delete car
- Used by: Car management pages, owner profiles, registry operations

**Quick Decision Guide**:
- Does this represent data from an external authoritative source? → Reference class
- Does this represent a record in the registry database? → Entity class
- Does this need CRUD operations? → Entity class
- Is it lookup/metadata only? → Reference class

---

## Adding New Classes

### Reference Data Class Checklist

When adding a new reference data class to `ElanRegistry\Reference`:

1. **Location**: `/usersc/classes/ElanRegistry/Reference/YourClass.php`
2. **Namespace**: `namespace ElanRegistry\Reference;`
3. **Autoload**: Already configured in composer.json (no changes needed)
4. **Documentation**: Add to `docs/development/CLASSES.md` → "Reference Data Classes" section
5. **Tests**: Create in `/tests/unit/Reference/YourClassTest.php`
6. **Database**: Document table in `docs/development/DATABASE.md`

### Example: Add FactoryColor Reference Class

```php
<?php
declare(strict_types=1);

namespace ElanRegistry\Reference;

use DB;

/**
 * FactoryColor - Reference Data for Lotus Factory Colors
 *
 * Provides read-only access to official Lotus factory colors by series.
 */
class FactoryColor
{
    private DB $db;

    public function __construct()
    {
        $this->db = DB::getInstance();
    }

    /**
     * Get all factory colors for a specific series
     */
    public function bySeriesNormalized(string $series): array
    {
        return $this->db->query(
            'SELECT * FROM factory_colors WHERE series_normalized = ? ORDER BY name',
            [$series]
        )->results();
    }

    // ... additional query methods
}
```

---

## See Also

- [CLASSES.md](../../../docs/development/CLASSES.md) - Complete class documentation
- [DATABASE.md](../../../docs/development/DATABASE.md) - Database schema
- [Issue #577](https://github.com/jimboone/elan-registry/issues/577) - car_models table creation
- [Issue #298-1](https://github.com/jimboone/elan-registry/issues/298-1) - Factory colors normalization (uses CarModel)
