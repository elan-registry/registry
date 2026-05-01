# FIX/_ARCHIVE — Removed One-Time Migration Scripts

These scripts were deleted from the repository as they are one-time administrative
migrations that have already been executed. They are preserved here as a record.

All scripts were run against the production database. To recover any script, check
git history: `git log --all --full-history -- FIX/_ARCHIVE/<filename>`

## Removed Scripts

| Script | Purpose | Notes |
| --- | --- | --- |
| `01-Move-Images.php` | Move User Images Script | Migrated image files to new directory structure |
| `02-Cleanup-Orphaned-Profiles.php` | Cleanup Orphaned Profiles Script | Removed profiles with no corresponding user |
| `03-Remove-Duplicate-History.php` | Remove Duplicate History Records Script | Deduplicated car ownership history |
| `04-Regeocode-Null-Coordinates.php` | Re-geocode Null/Zero Coordinates Script | Backfilled lat/lon for owners with empty coordinates |
| `05-Database-Column-Standardization-carid-to-car_id.php` | Database Column Standardization | Renamed `carid` → `car_id` across all tables |
| `06-Cleanup-Orphaned-Car-User-Records.php` | Cleanup Orphaned Car-User Records | Removed car_user join rows with no valid car or user |
| `07-Generate-Test-Data-For-SPAM-Cleanup.php` | Generate Test Data for SPAM Cleanup | Test data generation only; no prod data changed |
| `07-Remove-Deprecated-Username-Column.php` | Remove Deprecated Username Column | Dropped username column from profile table |
| `08-Database-Index-Optimization.php` | Database Index Optimization | Added covering indexes on frequently queried columns |
| `08-Fix-Car-History-Triggers-Username-Column.php` | Fix Car History Triggers | Updated DB triggers to remove username column references |
| `09-Validate-And-Fix-Car-Image-Data.php` | Car Image Data Validation and Correction | Fixed orphaned/mislinked image records |
| `10-Regenerate-Optimized-Thumbnails.php` | Thumbnail Optimization | Regenerated thumbnails at new optimized sizes |
| `12-Verify-Username-Field-Removal.php` | Username Field Removal Verification | Verification/reporting script only; no data changes |
| `13-Debug-Username-Column-Drop.php` | Debug Username Column Drop | Diagnostic only; no data changes |
| `14-Update-Admin-Page-Permissions.php` | Update Admin Page Permissions | Updated UserSpice page permission records |
| `15-Fix-Page-Permissions.php` | Fix Page Permissions | Corrected page permission mismatches |
| `16-Convert-Tables-to-InnoDB.php` | Convert Tables to InnoDB | Converted all MyISAM tables to InnoDB |
| `17-Add-SRI-To-CDN-Resources.php` | Add SRI to CDN Resources | Added Subresource Integrity hashes + upgraded DataTables |
| `18-Update-Stories-Directory-Paths.php` | Update Stories Directory Paths | Updated story file paths after directory restructure |
| `19-Add-Select-Extension-DataTables-CDN.php` | Optimize DataTables CDN | Removed unused DataTables extensions from CDN config |
| `20-Backfill-Location-Coordinates.php` | Backfill Location Coordinates | Geocoded owner addresses with missing coordinates |
| `23-Optimize-CDN-Resources.php` | Optimize CDN Resources | Switched all CDN links to minified versions with SRI |

## Recovery

To restore any script:

```bash
git show HEAD~<n>:FIX/_ARCHIVE/<filename>.php > recovered-script.php
```

Or search full history:

```bash
git log --all --oneline -- FIX/_ARCHIVE/<filename>.php
git show <commit>:FIX/_ARCHIVE/<filename>.php
```
