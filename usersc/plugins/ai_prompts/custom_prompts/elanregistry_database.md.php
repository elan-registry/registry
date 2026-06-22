<?php /* UserSpice AI Prompt — protected from HTTP access. Markdown content below. */ __halt_compiler(); ?>

# ElanRegistry — Database Context

Two authoritative sources together give the complete picture. Use both.

---

## DB Explainer: live schema authority

**DB Explainer** (installed at `usersc/plugins/db_explainer/`) exports the live
database schema — table names, column names, types, lengths, and the relationships
you've annotated in its UI.

Before starting any task that touches the database schema:

1. Open the DB Explainer admin page in your browser.
2. Select the ElanRegistry database and export as JSON.
3. Paste the JSON into the conversation before asking database-related questions.

The export is authoritative for column names and types. `docs/development/DATABASE.md`
does not automatically update when migrations run, so when the two disagree, the
DB Explainer export wins.

---

## DATABASE.md: relationships and business meaning

`docs/development/DATABASE.md` explains things the raw schema cannot:

- Which tables are related and why (business logic, not just FK constraints)
- Naming conventions and terminology (e.g., why `user_id` in `cars` maps to the owner)
- Audit trail design (how `cars_hist` is written by triggers, not application code)
- What columns mean in domain terms (e.g., what `body_style` values are valid)

Read the DB Explainer export for structure; read `DATABASE.md` for meaning.

---

## ElanRegistry tables (beyond generic UserSpice)

The shipped `where_to_look` prompt lists the core UserSpice tables (`users`, `groups`,
`permission_page_matches`, etc.). ElanRegistry adds:

| Table | Purpose |
|---|---|
| `cars` | Primary car registry records |
| `cars_hist` | Immutable audit trail — written by DB triggers, never by application code |
| `car_images` | Image metadata and file associations per car |
| `elan_factory_info` | Factory build data (chassis suffix lookup) |
| `car_transfers` | Ownership transfer requests and history |
| `profiles` | ElanRegistry owner profile data (extends UserSpice `users`) |
| `plg_db_explainer_*` | DB Explainer plugin tables (schema metadata, not registry data) |

---

## Key constraints and conventions

**`cars_hist` is trigger-written — never insert into it directly.**
Every `INSERT`/`UPDATE`/`DELETE` on `cars` fires a trigger that logs the change.
Application code never touches `cars_hist` directly.

**All writes use parameterized queries via `$db`.** Never concatenate user input into SQL.
The `Car` class handles all car mutations — use it rather than writing raw SQL for car records.

**MySQL 8.0+ is required.** The schema uses features (window functions, generated columns,
JSON functions) not available in MySQL 5.7.

**Integer columns return as strings in some PHP/MySQL configurations.** With
`declare(strict_types=1)`, always cast: `$id = (int)$row->id` or `$id = dbInt($row, 'id')`.

---

## Common query patterns

```php
// Load a car (prefer the Car class over raw SQL)
$car = new Car($carId);   // throws CarNotFoundException if missing

// Raw query when you need something the Car class doesn't expose
$row = $db->query(
    'SELECT c.*, p.fn, p.ln FROM cars c JOIN profiles p ON c.user_id = p.user_id WHERE c.id = ?',
    [$carId]
)->first();

// Listing cars (use CarView for display)
$cars = $db->query('SELECT * FROM cars WHERE user_id = ? ORDER BY id DESC', [$userId])->results();

// Always cast IDs from DB results
$carId  = (int)$row->id;
$userId = dbInt($row, 'user_id');   // throws if value is missing or non-numeric
```

---

## Migrations and schema changes

One-time schema migrations go in `app/admin/scripts/fix/` (prefixed with the date).
After running a migration, re-export from DB Explainer and update any affected
annotations so the export stays accurate.

See `docs/development/DATABASE.md` for the full schema narrative and
`docs/development/FIX_SCRIPTS.md` for migration script conventions.
