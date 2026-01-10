# Elan Registry v2.11.0 Release Notes

**Release Date:** January 9, 2026
**Type:** Minor Release - Architecture, Documentation & Organization

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### Manual Navigation Menu Updates

⚠️ Custom menu items pointing to old story URLs must be manually updated by admin

1. **Update Custom Menu Items** *(via Admin Panel > Menus)*
   - Check for custom menu items pointing to `/stories/...` URLs
   - Update them to point to `/docs/stories/...` URLs
   - Affected URLs:
     - `/stories/SGO_2F/` → `/docs/stories/SGO_2F/`
     - `/stories/brian_walton/` → `/docs/stories/brian_walton/`
     - `/stories/type26register.php` → `/docs/stories/type26register.php`
   - Confirm successful update:
     - Menu items load correct pages
     - No 404 errors when clicking menu links

### Autoloader Activation

✅ **No manual actions required** - The new autoloader activates automatically on deployment with zero configuration needed.

**🎯 Success Criteria:**
- ✅ Core navigation updated *(COMPLETED)*
- ⏳ Custom menu items verified *(PENDING - requires admin review)*
- ✅ All custom classes load automatically without explicit requires
- ✅ Existing functionality continues to work without modification
- ✅ No breaking changes to current codebase
- ✅ PAGE_LOADING_FLOW.md documentation available for developer reference

## 👤 User-Facing Changes

### URL Changes
- **Story URLs have moved**: All car stories and Type 26 archive have moved from `/stories/*` to `/docs/stories/*`
- **No automatic redirects**: Old bookmarks will result in 404 errors (low-traffic documentation section)
- **Updated paths**:
  - Car Stories landing page remains at `/docs/car-stories.php`
  - SGO 2F story now at `/docs/stories/SGO_2F/`
  - Brian Walton rally story now at `/docs/stories/brian_walton/`
  - Type 26 archive now at `/docs/stories/type26register.php`

### Internal Improvements
**No other visible changes for end users** - Additional changes focus on internal architecture improvements and developer documentation that enhance code maintainability without affecting user-facing functionality.

## 🔧 Admin-Facing Changes

### Documentation Organization
- **Consolidated documentation structure**: All reference content now under `/docs/` directory
- **Cleaner root directory**: Removed `/stories/` from root level for better project organization
- **Consistent patterns**: Follows same pattern as identification guide move (Issue #359)
- **PAGE_LOADING_FLOW.md**: Comprehensive developer reference documenting the complete file loading sequence
  - Traces all 40-60+ files loaded during page initialization
  - Documents 4 major phases: core init, template prep, page execution, footer
  - Clarifies autoloader scope and class loading mechanisms
  - Provides troubleshooting guide for common initialization issues
  - Shows when global variables become available
  - Includes integration points for custom code

### Architecture Improvements
- **Unified Class Autoloading**: Consolidated all custom class loading into a single hybrid autoloader that supports both current non-namespaced classes and future namespaced classes
- **Improved Code Organization**: Moved all exception classes to `usersc/classes/exceptions/` and admin utilities to `usersc/classes/admin/` for better structure
- **Reduced Code Complexity**: Eliminated 10+ explicit class includes across the codebase, replaced with automatic on-demand loading
- **Future-Ready Architecture**: Enables gradual namespace migration (see issue #407) without breaking changes or code modifications

### Technical Benefits
- **Performance**: PSR-4 fast path for namespaced classes (< 0.1ms), cached iterator for non-namespaced classes (< 1ms)
- **Maintainability**: Single autoloader replaces fragmented loading logic, easier to understand and maintain
- **Developer Experience**: New classes are automatically discovered, no manual includes needed
- **Testing**: Comprehensive test suite (7 tests, 35 assertions) ensures reliability
- **Onboarding**: New developers can quickly understand the page loading sequence and initialization flow
- **UserSpice Integration**: Added story paths to `z_us_root.php` configuration

## 📋 Issues Resolved in This Release

[#360](https://github.com/unibrain1/elanregistry/issues/360) - Move stories/ directory to docs/ for better organization

[#426](https://github.com/unibrain1/elanregistry/issues/426) - Architecture: Create unified autoloader for usersc/classes directory

---

**Documentation Added**:
- `docs/development/PAGE_LOADING_FLOW.md` - Complete reference for understanding file loading sequence and initialization phases
- Comprehensive documentation for all car stories and Type 26 archive content

**Related Work**: This release establishes the foundation for [#407](https://github.com/unibrain1/elanregistry/issues/407) - a phased namespace migration strategy that will gradually modernize the codebase while maintaining backward compatibility.
