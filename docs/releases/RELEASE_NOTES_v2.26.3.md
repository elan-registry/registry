# Elan Registry v2.26.3 Release Notes

**Release Date:** TBD
**Type:** Patch Release — User Deletion Hook & Stability Fixes

> **Note:** v2.26.2 was released but never deployed to production due to instability.
> v2.26.3 supersedes it and is the recommended upgrade from v2.26.1.

## Required Actions After Deployment

1. Run database migrations to drop the legacy `fk_cars_user_id` FK constraint if present:
   ```
   composer migrate
   ```
   *(No-op on environments that already dropped it.)*

## User-Facing Changes

None.

## Admin-Facing Changes

### Improvements

- **User Deletion — Owner Details Preserved** (TBD): Deleting an owner now correctly updates all denormalized car fields (name, email, city, etc.) to show the noowner placeholder, matching the behavior of the admin manual reassign tool.

## Issues Resolved

- TBD — User deletion hook: use Car::transfer() to update denormalized owner fields
- TBD — PDF viewer page: missing LogCategories import causes fatal error on first load
- TBD — Defensive migration: drop fk_cars_user_id FK if present (ON DELETE SET NULL race condition)
