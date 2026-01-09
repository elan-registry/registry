# Elan Registry v2.11.0 Release Notes

**Release Date:** TBD

**Type:** Minor Release - Documentation Organization

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### Manual Navigation Menu Updates

⚠️ Custom menu items pointing to old story URLs must be manually
updated by admin

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

**🎯 Success Criteria:**

- ✅ Core navigation updated *(COMPLETED)*
- ⏳ Custom menu items verified *(PENDING - requires admin review)*

## 👤 User-Facing Changes

### URL Changes

- **Story URLs have moved**: All car stories and Type 26 archive have
  moved from `/stories/*` to `/docs/stories/*`
- **No automatic redirects**: Old bookmarks will result in 404 errors
  (low-traffic documentation section)
- **Updated paths**:
  - Car Stories landing page remains at `/docs/car-stories.php`
  - SGO 2F story now at `/docs/stories/SGO_2F/`
  - Brian Walton rally story now at `/docs/stories/brian_walton/`
  - Type 26 archive now at `/docs/stories/type26register.php`

## 🔧 Admin-Facing Changes

### Documentation Organization

- **Consolidated documentation structure**: All reference content now
  under `/docs/` directory
- **Cleaner root directory**: Removed `/stories/` from root level for
  better project organization
- **Consistent patterns**: Follows same pattern as identification guide
  move (Issue #359)

### Technical Changes

- **UserSpice path configuration updated**: Added new story paths to
  `z_us_root.php`
- **Navigation references updated**: Homepage and documentation center
  links point to new locations
- **Removed redundant file**: Deleted `stories/stories.php` (redundant
  with `docs/car-stories.php`)

### Development Documentation

- **Page Loading Flow Documentation**: Added comprehensive
  `PAGE_LOADING_FLOW.md` documenting the complete file loading sequence,
  initialization phases, and execution order for debugging and
  understanding the application startup process

## 📋 Issues Resolved in This Release

[#360](https://github.com/unibrain1/elanregistry/issues/360) -
Move stories/ directory to docs/ for better organization
