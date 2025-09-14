-- Migration: Remove Analytics Page Permissions
-- Issue #285: Remove analytics.php permissions after migrating to statistics.php
-- Date: 2025-01-15

-- Remove analytics.php from permissions table
DELETE FROM permissions WHERE name = 'app/reports/analytics.php';

-- Update statistics page description to reflect new comprehensive nature
UPDATE permissions SET
    name = 'Registry Analytics & Statistics'
WHERE name = 'app/reports/statistics.php' AND name IS NULL;

-- Note: If the above UPDATE doesn't work because name field is the filename,
-- we can add a description field or leave as is since it's already functional