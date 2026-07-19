# Elan Registry v2.26.3 Release Notes

**Release Date:** July 19, 2026
**Type:** Patch Release — User Deletion Hook & Stability Fixes

> **Note:** v2.26.2 was released but never deployed to production due to
> instability discovered during testing. v2.26.3 supersedes it and is the
> recommended upgrade from v2.26.1.

## Required Actions After Deployment

1. Run database migrations:
   ```
   composer migrate
   ```
   Drops the `fk_cars_user_id` FK constraint if present. No-op on environments
   where the constraint does not exist (including production).

## User-Facing Changes

None.

## Admin-Facing Changes

### Improvements

- **User Deletion — Owner Details Preserved**: Deleting an owner now correctly
  updates all denormalized car fields (name, email, city, state, country, etc.)
  to show the noowner placeholder, matching the behavior of the admin manual
  reassign tool. Previously only `user_id` was updated, leaving stale owner
  details visible on the car page.

## Issues Resolved

All fixes were committed directly to the milestone branch (no linked GitHub issues).

- `fix: use Car::transfer() in deletion hook to update denormalized owner fields`
- `fix: add missing LogCategories import to pdf-viewer.php` (fatal error on first page load)
- `fix: drop fk_cars_user_id FK if present (ON DELETE SET NULL race condition)`
