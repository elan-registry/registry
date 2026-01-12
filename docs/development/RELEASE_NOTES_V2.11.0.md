# Elan Registry v2.11.0 Release Notes

**Release Date:** TBD
**Type:** Minor Release - User Experience Enhancements

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### Performance Optimization: Run FIX Script #19

This script optimizes DataTables CDN by removing 5 unused extensions.

1. **Run FIX Script #19** *(via web browser)*
   - Navigate to: `/FIX/19-Add-Select-Extension-DataTables-CDN.php`
   - Review the optimization changes (removes RowGroup, Scroller, Select,
     SearchBuilder, SearchPanes)
   - Click "Continue - Optimize CDN URLs"
   - Confirm successful completion:
     - Script shows "CDN OPTIMIZATION COMPLETED SUCCESSFULLY"
     - Database backup was created
     - Settings table updated with optimized CDN URLs

2. **Clear Browser Cache** *(all users)*
   - Press Ctrl+Shift+Delete (Windows/Linux) or Cmd+Shift+Delete (Mac)
   - Clear cached images and files
   - Hard refresh DataTables pages (Ctrl+Shift+R or Cmd+Shift+R)

**Success Criteria:**

- FIX Script #19 completed successfully *(PENDING - requires manual
  execution)*
- DataTables pages load correctly with no console errors *(PENDING - verify
  after FIX script)*
- Page load performance improved (fewer resources loaded) *(PENDING - verify
  after cache clear)*

## 👤 User-Facing Changes

No visible changes for end users in this release.

## 🔧 Admin-Facing Changes

### Performance Optimization

- **DataTables CDN Optimized**: Removed 5 unused extensions from DataTables
  CDN URLs
  - **What Changed**: Analyzed actual DataTables usage across all pages
  - **Removed**: RowGroup, Scroller, Select, SearchBuilder, SearchPanes
    (unused extensions)
  - **Kept**: DataTables Core, FixedHeader, Responsive (actively used)
  - **Impact**: 62.5% reduction in loaded extensions (from 8 to 3)
  - **Benefits**:
    - Smaller bundle size → faster page loads
    - Less JavaScript to parse → improved browser performance
    - Reduced bandwidth usage
    - Cleaner, more maintainable configuration
  - **Action Required**: Run FIX Script #19 after deployment

### Optimized Extension Configuration

DataTables now loads only essential extensions:

1. DataTables Core (dt-1.10.23) - Base functionality
2. FixedHeader (fh-3.1.8) - Sticky table headers
3. Responsive (r-2.2.7) - Mobile-responsive tables

**Removed (unused):**

- ~~RowGroup (rg-1.1.2)~~ - Row grouping (not used)
- ~~Scroller (sc-2.0.3)~~ - Virtual scrolling (not used)
- ~~Select (sl-1.3.3)~~ - Row selection (not used)
- ~~SearchBuilder (sb-1.0.1)~~ - Query builder (not used, poor UX with
  server-side)
- ~~SearchPanes (sp-1.2.2)~~ - Faceted search (not used, poor UX with
  server-side)

## 📋 Issues Resolved in This Release

[#168](https://github.com/unibrain1/elanregistry/issues/168) - Feature:
Enhanced search capability for list_cars and list_factory (Closed as Won't Fix
after prototyping)

---

## 📝 Development Notes

### Issue #168 Investigation Summary

Prototyped both DataTables SearchPanes and SearchBuilder extensions for
enhanced search functionality. Both approaches were found to have **significant
UX issues** with our server-side DataTables implementation:

**SearchPanes Issues:**

- Shows empty filter panes because it requires ALL data to build filters
- Incompatible with server-side processing without major backend refactoring
- Depends on Select extension (also unused)

**SearchBuilder Issues:**

- Too complex for average users (requires understanding query logic)
- Not intuitive compared to simple visual filters

**Resolution:**

- Closed issue #168 as "Won't Fix" for current release
- Created FIX #19 to optimize DataTables CDN by removing ALL unused extensions
- Analysis revealed we were loading 5 unused extensions (62.5% of loaded
  extensions)
- Optimized to load only what we use: Core, FixedHeader, Responsive
- Existing global search functionality is sufficient for current user needs

**Performance Impact:**

- Before: 8 extensions loaded (5 unused)
- After: 3 extensions loaded (0 unused)
- Result: Faster page loads, reduced bandwidth, cleaner configuration

**Alternative Solutions for Future:**

- Custom dropdown filters above tables
- Column-specific search boxes
- Dedicated advanced search page
- Can be revisited if strong user demand emerges
