# Elan Registry v2.11.0 Release Notes

**Release Date:** January 9, 2026
**Type:** Minor Release - Architecture & Documentation Improvements

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

**✅ No manual actions required** - All changes are automatic and backward-compatible.

The new autoloader activates automatically on deployment with zero configuration needed.

**🎯 Success Criteria:**
- ✅ All custom classes load automatically without explicit requires
- ✅ Existing functionality continues to work without modification
- ✅ No breaking changes to current codebase
- ✅ PAGE_LOADING_FLOW.md documentation available for developer reference

## 👤 User-Facing Changes

**No visible changes for end users** - This release focuses on internal architecture improvements and developer documentation that enhance code maintainability without affecting user-facing functionality.

## 🔧 Admin-Facing Changes

### Architecture Improvements
- **Unified Class Autoloading**: Consolidated all custom class loading into a single hybrid autoloader that supports both current non-namespaced classes and future namespaced classes
- **Improved Code Organization**: Moved all exception classes to `usersc/classes/exceptions/` and admin utilities to `usersc/classes/admin/` for better structure
- **Reduced Code Complexity**: Eliminated 10+ explicit class includes across the codebase, replaced with automatic on-demand loading
- **Future-Ready Architecture**: Enables gradual namespace migration (see issue #407) without breaking changes or code modifications

### Documentation Improvements
- **PAGE_LOADING_FLOW.md**: Comprehensive developer reference documenting the complete file loading sequence
  - Traces all 40-60+ files loaded during page initialization
  - Documents 4 major phases: core init, template prep, page execution, footer
  - Clarifies autoloader scope and class loading mechanisms
  - Provides troubleshooting guide for common initialization issues
  - Shows when global variables become available
  - Includes integration points for custom code

### Technical Benefits
- **Performance**: PSR-4 fast path for namespaced classes (< 0.1ms), cached iterator for non-namespaced classes (< 1ms)
- **Maintainability**: Single autoloader replaces fragmented loading logic, easier to understand and maintain
- **Developer Experience**: New classes are automatically discovered, no manual includes needed
- **Testing**: Comprehensive test suite (7 tests, 35 assertions) ensures reliability
- **Onboarding**: New developers can quickly understand the page loading sequence and initialization flow

## 📋 Issues Resolved in This Release

[#426](https://github.com/unibrain1/elanregistry/issues/426) - Architecture: Create unified autoloader for usersc/classes directory

---

**Documentation Added**: `docs/development/PAGE_LOADING_FLOW.md` - Complete reference for understanding file loading sequence and initialization phases

**Related Work**: This release establishes the foundation for [#407](https://github.com/unibrain1/elanregistry/issues/407) - a phased namespace migration strategy that will gradually modernize the codebase while maintaining backward compatibility.
