# Menu System Synchronization

This document describes the Menu System Sync tool implemented to address [Issue #297](https://github.com/unibrain1/elanregistry/issues/297): "Develop a method to export menu system from development and apply to production."

## Overview

The Menu Sync System provides a complete solution for maintaining synchronized menu configurations across development, test, and production environments. This addresses the need identified with v2.8.1 where menu items changed due to redirect removal.

## Environment Configuration

The system operates across three environments:

- **Development:** `http://localhost:9999/elan_registry`
- **Test:** `https://test.elanregistry.org`
- **Production:** `https://elanregistry.org`

Environment detection is automatic based on URL patterns.

## Components

### 1. Core Export/Import Tool: `scripts/menu-sync.php`

**Purpose:** Manual menu export/import with web interface
**Access:** Direct URL access (requires UserSpice authentication)
**Requirements:**
- Added to UserSpice pages table for security
- `scripts/` directory added to `z_us_root.php` path array
- Administrator permissions required

**Features:**
- Environment detection and validation
- Export from development only (safety measure)
- Import to any environment with backup
- JSON format for version control compatibility
- Web interface for ease of use

**Usage:**
```bash
# Access via web browser
https://elanregistry.org/scripts/menu-sync.php

# Exports create timestamped JSON files
menu_export_20250115_143022.json
```

### 2. FIX System Integration: `FIX/11-Menu-System-Sync.php`

**Purpose:** Import tool following established FIX script patterns
**Access:** Via FIX system menu or direct URL
**Features:**
- Automatic backup before import
- Progress tracking with detailed logging
- Rollback capability
- File upload interface for import
- Integration with existing FIX workflow

**Safety Features:**
- Creates timestamped backup: `menu_sync_backup_production_20250115_143022.sql`
- Transaction-based operations
- Environment validation
- Comprehensive logging

### 3. GitHub Action Automation: `.github/workflows/menu-sync.yml`

**Purpose:** Automated menu sync on deployment
**Triggers:**
- Push to main/production branches
- Changes to menu-related files
- Manual workflow dispatch

**Features:**
- Automatic export on menu file changes
- Artifact storage for manual import
- PR comments for menu changes
- Deployment summary generation

## Data Structure

### Data Tables Managed

**Common to Both Menu Systems:**
```sql
-- Page definitions (always included)
pages (id, page, title, private, re_auth, core)

-- Page permissions (always included)
permission_page_matches (permission_id, page_id)
```

**Classic Menu System (Current):**
```sql
-- Menu structure
menus (id, menu_title, parent, dropdown, logged_in, display_order, label, link, icon_class)

-- Menu group permissions
groups_menus (group_id, menu_id)
```

**UltraMenu System (Future):**
```sql
-- UltraMenu structure
us_menus (id, menu_name, type, z_index, show_active, theme, disabled)

-- Additional UltraMenu tables (to be defined)
```

### JSON Export Format

**Note:** Pages and permissions are always included in exports for both menu systems.

**Classic Menu Export:**
```json
{
  "export_info": {
    "timestamp": "2025-01-15T10:30:00Z",
    "source_environment": "development",
    "menu_system": "classic",
    "version": "1.0",
    "commit_hash": "abc123...",
    "branch": "main"
  },
  "pages": [
    {
      "id": 241,
      "page": "app/cars/actions/check-chassis.php",
      "title": null,
      "private": 1,
      "re_auth": 0,
      "core": 0
    }
  ],
  "permissions": [
    {
      "permission_id": 2,
      "page_id": 241
    }
  ],
  "menus": [
    {
      "id": 25,
      "menu_title": "main",
      "parent": -1,
      "dropdown": 0,
      "logged_in": 0,
      "display_order": 20,
      "label": "List Cars",
      "link": "app/list_cars.php",
      "icon_class": "fa fa-fw fa-car"
    }
  ],
  "menu_permissions": [
    {
      "group_id": 0,
      "menu_id": 25
    }
  ]
}
```

**UltraMenu Export (Future):**
```json
{
  "export_info": {
    "timestamp": "2025-01-15T10:30:00Z",
    "source_environment": "development",
    "menu_system": "ultramenu",
    "version": "1.0"
  },
  "pages": [...],
  "permissions": [...],
  "us_menus": [
    {
      "id": 1,
      "menu_name": "Main Menu",
      "type": "horizontal",
      "z_index": 100,
      "show_active": 1,
      "theme": "dark",
      "disabled": 0
    }
  ]
}
```

### UltraMenu System (Future Migration)

The system is designed to support future UltraMenu migration and handles template-to-menu-system mapping:

```sql
-- UltraMenu structure
us_menus (id, menu_name, type, z_index, show_active, theme, disabled)

-- Additional UltraMenu tables as needed
```

**Template-Based Detection:**
- `ElanRegistry` template → Classic Menu (current)
- UltraMenu tables exist for future compatibility but are not active
- Detection logic prioritizes active template over table existence

**Future Conversion Considerations:**
When converting to UltraMenu or adding new templates:
1. Update template mapping in `detectMenuSystem()` function
2. Test menu detection with new template configuration
3. Verify export/import handles both menu systems correctly
4. Test menu sync between environments with mixed systems
5. Update backup logic to include all relevant UltraMenu tables

## Installation & Setup

### Initial Setup Requirements

1. **Add scripts directory to UserSpice path:**
   ```php
   // In z_us_root.php, add 'scripts/' to the $path array
   $path = ['', 'users/', 'usersc/', 'app/', 'scripts/', ...];
   ```

2. **Register the menu-sync page in UserSpice:**
   ```sql
   -- Add page to pages table
   INSERT INTO pages (page, title, private, re_auth, core)
   VALUES ('scripts/menu-sync.php', 'Menu System Sync Tool', 1, 0, 0);

   -- Grant administrator access
   INSERT INTO permission_page_matches (permission_id, page_id)
   SELECT 2, id FROM pages WHERE page = 'scripts/menu-sync.php';
   ```

3. **Verify directory permissions:**
   - Ensure `scripts/.htaccess` allows PHP file access
   - Verify `FIX/backups/` directory exists and is writable

## Workflow

### Development to Production Sync

1. **Export from Development**
   ```bash
   # Access scripts/menu-sync.php in development
   # Click "Export Menus" button
   # Download generated JSON file
   ```

2. **Import to Production**
   ```bash
   # Access FIX/11-Menu-System-Sync.php in production
   # Upload JSON export file
   # Review import details and click import
   # Verify menu changes applied correctly
   ```

3. **Automated Process (Future)**
   ```bash
   # GitHub Action triggers on push to main
   # Exports menu configuration automatically
   # Stores as artifact for manual deployment
   ```

### Safety and Rollback

**Automatic Backup:**
```sql
-- Backup created before each import
mysqldump tables > menu_sync_backup_production_20250115_143022.sql
```

**Rollback Process:**
```bash
# If issues occur after import, restore backup
mysql database < menu_sync_backup_production_20250115_143022.sql
```

## Security Considerations

### Environment Validation
- Export only allowed from development environment
- Import validates source environment differs from target
- URL pattern matching prevents accidental operations

### Backup Requirements
- Automatic backup before any import operation
- Timestamped backup files for audit trail
- Rollback capability for quick recovery

### File Security
- JSON exports contain no sensitive data
- Backup files stored in restricted FIX/backups directory
- GitHub artifacts have 30-day retention

## Testing

### Manual Testing Checklist

**Export Process:**
- [ ] Export generates valid JSON file
- [ ] Export contains all menu data
- [ ] Export includes proper metadata
- [ ] Export only works from development

**Import Process:**
- [ ] Import creates backup before changes
- [ ] Import validates JSON format
- [ ] Import applies all menu changes
- [ ] Import logs progress correctly
- [ ] Rollback restores original state

**Environment Detection:**
- [ ] Development environment detected correctly
- [ ] Test environment detected correctly
- [ ] Production environment detected correctly
- [ ] Invalid environments rejected

### Integration Testing

**Menu Functionality:**
- [ ] All menu items display correctly
- [ ] Dropdown menus work properly
- [ ] User permissions respected
- [ ] Navigation links function correctly
- [ ] Icon classes render properly

## Troubleshooting

### Common Issues

**Export Fails:**
- Verify running from development environment
- Check database connectivity
- Ensure proper UserSpice permissions

**Import Fails:**
- Validate JSON file format
- Check backup directory permissions
- Verify database write permissions
- Review error logs for specific issues

**Menu Display Issues:**
- Clear browser cache
- Check template compatibility
- Verify CSS class definitions
- Review UserSpice permission settings

### Debug Information

**Environment Detection:**
```php
// Check current environment
$env = detectEnvironment();
echo "Current environment: " . $env;
```

**Menu System Detection:**
```php
// Check menu system type
$system = detectMenuSystem($db);
echo "Menu system: " . $system;
```

## Future Enhancements

### UltraMenu Migration Support
- Add UltraMenu table export/import
- Update JSON schema for new structure
- Provide migration path from Classic to UltraMenu

### Advanced Automation
- Direct database connection for GitHub Actions
- Automated testing post-import
- Slack/Discord notifications
- Multi-environment deployment pipelines

### Additional Features
- Menu validation (broken links, missing pages)
- Environment comparison tool
- Menu structure documentation generator
- Incremental sync (delta changes only)

## References

- [Issue #297](https://github.com/unibrain1/elanregistry/issues/297) - Original feature request
- [Environment Configuration](../development/ENVIRONMENT.md) - Environment setup details
- [FIX System Documentation](../technical/FIX_SYSTEM.md) - FIX script patterns
- [UserSpice Documentation](https://userspice.com) - Menu system reference