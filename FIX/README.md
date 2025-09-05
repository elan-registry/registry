# FIX Directory - Administrative Scripts

The FIX directory contains administrative cleanup and maintenance scripts for the Elan Registry application.

## 🚀 **How to Access Scripts**

### Option 1: FIX Menu Interface (Recommended)
1. Navigate to: `/FIX/index.php`
2. View all available scripts with run status indicators
3. Click "Run Script" button to execute in a new window
4. Use "Return to FIX Menu" button to close script window and return
5. Scripts show ✅ if previously run, ➖ if never run

### Option 2: Direct Access
- All operational scripts can be accessed directly via URL
- Example: `/FIX/05-Database-Column-Standardization-carid-to-car_id.php`
- Template files are blocked for security

## 📋 **Current Scripts**

| Script | Status | Description |
|--------|--------|-------------|
| `02-Cleanup-Orphaned-Profiles.php` | ✅ | Clean up orphaned user profile records |
| `03-Remove-Duplicate-History.php` | ✅ | Remove duplicate entries from cars_hist table |
| `04-Regeocode-Null-Coordinates.php` | ✅ | Fix missing geocoding data for user locations |
| `05-Database-Column-Standardization-carid-to-car_id.php` | ➖ | Standardize column naming from carid to car_id |
| `06-Cleanup-Orphaned-Car-User-Records.php` | ✅ | Clean up orphaned car-user relationship records |

## 🔧 **Creating New FIX Scripts**

### Step-by-Step Instructions:

1. **Start with Template**
   ```bash
   cp FIX/_TEMPLATE_Fix-Script.php FIX/06-My-New-Script.php
   ```

2. **Use Sequential Numbering**
   - Format: `##-Descriptive-Name.php`
   - Check existing scripts for next available number
   - Example: `08-User-Data-Migration.php` (next available)

3. **Replace Template Placeholders**
   - `[SCRIPT_NAME]` → Your script name
   - `[SCRIPT_DESCRIPTION]` → Brief description
   - `[ISSUE_NUMBER]` → GitHub issue number
   - `[ISSUE_TITLE]` → Issue title
   - `[ADDITIONAL_NOTES]` → Any special notes
   - `[ICON_NAME]` → FontAwesome icon name
   - `[SCRIPT_TITLE]` → Display title
   - `[BULLET_POINT_1-5]` → What the script does
   - `[ACTION_NAME]` → Action verb (e.g., "Cleanup", "Migration")

4. **Implement Script Logic**
   - Add your processing logic in the main PHP section
   - Use `outputMessage()` function for progress reporting
   - Update `$global_attempts` and `$global_successes` counters
   - Use transactions for database operations

5. **Test Script**
   - Test via FIX menu interface
   - Verify progress reporting works
   - Ensure error handling functions correctly
   - Check completion recording in `fix_script_runs` table

## 🔒 **Security & Access Control**

### `.htaccess` Configuration
Multiple `.htaccess` files work together to secure FIX scripts:

**Root `.htaccess`**: 
- **Allows**: `index.php` and numbered/named scripts (`##-*.php`, `Name-*.php`)
- **Blocks**: All other FIX directory files

**FIX/.htaccess**:
- **Blocks**: Template files (`_TEMPLATE*.php`)  
- **Blocks**: Directory browsing
- **Requires**: UserSpice authentication for all scripts

### Best Practices
- Always require authentication: `securePage($_SERVER['PHP_SELF'])`
- Use database transactions for atomic operations
- Provide detailed error reporting and logging
- Include rollback capabilities for destructive operations
- Generate comprehensive completion reports
- **Return Button**: Use window-aware JavaScript: `onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='index.php';}"`

## 📊 **Script Features**

All FIX scripts include these standard features:

### User Interface
- **Bootstrap 4 Responsive Design**: Professional appearance
- **Progress Tracking**: Real-time progress bars and status updates
- **Two-Column Layout**: Progress/summary on left, detailed log on right
- **Status Indicators**: Visual feedback with icons and colors
- **Completion Summary**: Final statistics and next steps

### Functionality
- **Run Status Tracking**: Automatic completion recording
- **Error Handling**: Comprehensive exception management
- **Database Safety**: Transaction support and rollback capabilities
- **Progress Reporting**: Consistent messaging with timestamps
- **Authentication**: UserSpice security integration

### Code Standards
- **PHP 7+ Compatibility**: Modern PHP features and syntax
- **Error Reporting**: Full error display for debugging
- **Documentation**: Comprehensive inline comments
- **Consistent Formatting**: Following project coding standards

## 📦 **Archiving Scripts**

When scripts are no longer needed:
1. Move completed/obsolete scripts to `_ARCHIVE/` directory
2. Update this README to remove archived scripts
3. Update script count at bottom of document
4. Archive preserves work for future reference without cluttering active scripts

**Archived Scripts:**
- `01-Move-Images.php` - Image organization (completed)
- `07-Generate-Test-Data-For-SPAM-Cleanup.php` - Test data generation (no longer needed)

## 🚨 **Important Notes**

1. **Database Backups**: Always backup before running destructive scripts
2. **Maintenance Windows**: Run major migrations during low-traffic periods
3. **Testing**: Test scripts on development environment first
4. **Monitoring**: Monitor application after script execution
5. **Documentation**: Update this README when adding new scripts

## 📞 **Support**

For issues with FIX scripts:
1. Check script execution logs in browser console
2. Review database logs for transaction errors
3. Verify UserSpice authentication is working
4. Confirm required database tables exist
5. Check file permissions on FIX directory

---

**Last Updated:** September 2025
**Current Script Count:** 5 operational scripts