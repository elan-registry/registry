# Elan Registry Release Plan
*Updated: September 7, 2025*

## Current Version
**v2.7.0** (Production)

---

## 📋 **Upcoming Releases**

### 🚨 **v2.7.1: Critical Fixes** *(September 12, 2025)*
**Estimated Development Time**: 1.5 weeks
**Type:** Patch Release - Critical Bug Fixes  
**Priority:** IMMEDIATE

#### Issues (✅ ALL 12 COMPLETED - 100%):
- **#169** ✅ **COMPLETED** - Bug: Photos from some sources are not properly rotated
  - **Impact:** EXIF orientation handling for mobile uploads
  - **Effort:** Large
- **#239** ✅ **COMPLETED** - Add missing type declarations and input validation to Car class
  - **Impact:** Foundation for all future Car class improvements
  - **Effort:** Extra Small  
- **#241** ✅ **COMPLETED** - Improve Car class security and error handling
  - **Impact:** Security hardening of core functionality
  - **Effort:** Medium
- **#250** ✅ **COMPLETED** - Fix mobile accessibility - Enable zooming for users with vision impairments
  - **Impact:** Critical accessibility compliance issue
  - **Effort:** Small
- **#263** ✅ **COMPLETED** - Update UserSpice core for v2.7.1 compatibility
  - **Impact:** Framework compatibility verification
  - **Effort:** Small
- **#271** ✅ **COMPLETED** - Fix PHP Warning in cleanup script
  - **Impact:** Eliminate PHP warnings in admin scripts
  - **Effort:** Small
- **#272** ✅ **COMPLETED** - Archive outdated fix scripts and rollback files
  - **Impact:** Clean up outdated administrative files
  - **Effort:** Small
- **#274** ✅ **COMPLETED** - Remove outdated migration plan document
  - **Impact:** Documentation cleanup after completed migration work
  - **Effort:** Extra Small
- **#279** ✅ **COMPLETED** - Clean up debug console messages in car management JavaScript
  - **Impact:** Production browser console cleanliness
  - **Effort:** Extra Small
- **#280** ✅ **COMPLETED** - Fix Dropzone JSON parse error and PHP deprecation warnings  
  - **Impact:** Eliminate JSON parse errors and PHP 8+ warnings
  - **Effort:** Small
- **#281** ✅ **COMPLETED** - Improve car update success/error messages for better UX
  - **Impact:** Cleaner success messages and prominent error display
  - **Effort:** Small
- **#282** ✅ **COMPLETED** - Fix 'There was a problem updating' car validation error
  - **Impact:** Resolve car update validation failures
  - **Effort:** Small

**✅ MILESTONE COMPLETE** - Ready for production deployment  
**Deployment Notes:** Complete critical fixes including mobile accessibility compliance, comprehensive Car class security improvements, EXIF image orientation handling with privacy protection, production console cleanup, enhanced UX messaging, car validation fixes, UserSpice compatibility verification, and administrative documentation cleanup

### 🔄 **v2.8.0: Core Stability** *(October 28, 2025)*
**Type:** Minor Release - Stability, Performance & Code Quality  
**Priority:** HIGH  
**Total Issues:** 14 issues across 4 logical work packages  
**Estimated Development Time**: 3-4 weeks (focused scope)

---

## 📦 **WORK PACKAGE 1: Framework Stability** (Week 1)
**Focus:** Core framework upgrades and deprecated function cleanup  
**Priority:** CRITICAL - Must complete first  
**Estimated Time:** 1 week

### Issues:
- **#237** - Replace deprecated display_errors() and display_successes() functions
  - **Impact:** Eliminates deprecated function usage across application
  - **Effort:** High Priority (affects multiple pages)
- **#264** - Update UserSpice core for v2.8.0 stability improvements
  - **Impact:** Framework compatibility and security updates
  - **Effort:** High Priority (testing required)
- **#255** - Enhance error handling with UserSpice logger integration
  - **Impact:** Better error tracking and debugging capabilities
  - **Effort:** High Priority (system-wide impact)

---

## 📦 **WORK PACKAGE 2: User Experience & Performance** (Week 2)
**Focus:** UI improvements, accessibility, and Core Web Vitals  
**Priority:** HIGH  
**Estimated Time:** 1 week

### Issues:
- **#261** - UX: Improve error messages for better user experience
  - **Impact:** Clearer user feedback and guidance
  - **Effort:** Small (enhanced by Package 1 logger work)
- **#251** - FIX: Image layout shifts - Add aspect ratios to prevent CLS issues
  - **Impact:** Performance improvement, better Core Web Vitals scores
  - **Effort:** High Priority (affects SEO rankings)
- **#253** - ACCESSIBILITY: Fix insufficient color contrast in text elements
  - **Impact:** WCAG 2.1 compliance improvement
  - **Effort:** Small (CSS adjustments)
- **#252** - OPTIMIZE: Fix render-blocking CSS to improve page load performance
  - **Impact:** Faster initial page loads
  - **Effort:** Medium (critical CSS extraction)
- **#246** - Homepage 'One of the Cars' should only select cars with valid images
  - **Impact:** Better homepage presentation quality
  - **Effort:** Small (query optimization)

---

## 📦 **WORK PACKAGE 3: Code Architecture & OOP Modernization** (Week 3)
**Focus:** Clean code practices and modern PHP patterns  
**Priority:** MEDIUM  
**Estimated Time:** 3-4 days

### Issues (Sequential Dependencies):
- **#276** - Move findByOwner to Car class as static method
  - **Impact:** Proper OOP factory method pattern
  - **Effort:** Extra Small (30 minutes)
- **#277** - Rename CarHelpers to CarView for proper separation of concerns
  - **Impact:** Clear MVC separation (Model vs View)
  - **Effort:** Extra Small (15 minutes)
  - **Dependencies:** Requires #276
- **#278** - Replace deprecated function calls with new class methods  
  - **Impact:** Modern OOP patterns, better IDE support
  - **Effort:** Small (1-2 hours search/replace)
  - **Dependencies:** Requires #276 and #277
- **#273** - Remove backward compatibility redirect files from app/ directory
  - **Impact:** Cleanup 9 temporary files from reorganization
  - **Effort:** Extra Small (file deletion after verification)

---

## 📦 **WORK PACKAGE 4: Performance Optimization** (Week 4)
**Focus:** Database and query performance improvements  
**Priority:** MEDIUM  
**Estimated Time:** 3-4 days

### Issues:
- **#240** - MEDIUM PRIORITY: Optimize Car class database queries and performance
  - **Impact:** Faster car data operations, reduced server load
  - **Effort:** Medium (query optimization and caching)
- **#176** - Add image sizes to configuration
  - **Impact:** Centralized image size management
  - **Effort:** Small (configuration extraction)

---

**Deployment Notes:** Enhanced error handling and logging, significant performance improvements, modern code architecture, UX and accessibility improvements, clean codebase with modern OOP patterns

### 🏗️ **v2.9.0: Admin & Data Management** *(November 15, 2025)*
**Estimated Development Time**: 3 weeks  
**Type:** Minor Release - Admin Features & Data Management  
**Priority:** MEDIUM  
**Total Issues:** 8 issues (moved from v2.8.0 + existing)

#### Database Modernization:
- **#267** - Remove usersview database view and replace with proper user data access
  - **Impact:** Database modernization, removes view dependency  
  - **Effort:** Medium (3 locations to update with JOIN queries)

#### Admin Interface Overhaul:
- **#269** - Create ElanRegistry-specific owner management interface for profile editing
  - **Impact:** Enables profile editing from car quality reports, geolocation updates
  - **Effort:** Large (new interface with multiple integrations)
- **#270** - Consolidate admin interfaces: Manage Cars, Owners, Data Quality and Fix Scripts
  - **Impact:** Unified admin dashboard experience
  - **Effort:** Medium (interface reorganization and navigation)

#### User Experience Improvements:
- **#229** - Self-Service Car Ownership Transfer System
  - **Impact:** 90% reduction in manual transfer work
  - **Effort:** High Priority
- **#168** - Feature: Enhanced search capability for list_cars and list_factory
  - **Impact:** Better user experience for finding cars
  - **Effort:** Large

#### Data Validation & Quality:
- **#188** - Website Field Validation on user_settings.php
  - **Impact:** Better data quality and user experience
  - **Effort:** Small (URL validation enhancement)
- **#161** - Error logging improvements
  - **Impact:** Enhanced debugging capabilities (complements v2.8.0 logger work)
  - **Effort:** Medium
- **#10** - User Add and Account Update - Data Validation
  - **Impact:** Comprehensive input validation across user forms
  - **Effort:** Medium (form validation overhaul)

**Deployment Notes:** Comprehensive admin interface overhaul, self-service user features, enhanced search capabilities, database modernization, improved data validation and quality controls

### 🚀 **v3.0.0: Admin Automation** *(January 15, 2026)*
**Estimated Development Time**: 12 weeks (8 weeks testing infrastructure + 4 weeks automation)
**Type:** Major Release - Testing Infrastructure & Automation  
**Priority:** FUTURE

#### Testing Infrastructure (CRITICAL FIRST):
- **#275** - Test Framework Modernization: Enable UserSpice Integration Testing
  - **Impact:** **REQUIRED FOUNDATION** for all other v3.0.0 development
  - **Effort:** Extra Large (8 weeks - must complete first)
  - **Priority:** 🚨 **HIGHEST** - Blocks all other v3.0.0 work

#### Automation & Performance:
- **#230** - Automated Owner Data Freshness Campaign System
  - **Impact:** Automated data quality maintenance
  - **Effort:** High Priority (includes verification code work)
  - **Dependencies:** Requires #275 (testing infrastructure)
- **#260** - SECURITY: Enhance verification code validation with format checking
  - **Impact:** Part of #230 automation system
  - **Effort:** Small (integrated with #230)
- **#217** - Performance Optimization - Dependencies and Assets
  - **Impact:** 30% page load improvement
  - **Effort:** Extra Large
  - **Dependencies:** Requires #275 (performance regression testing)
- **#218** - Google Maps Modernization - Upgrade to AdvancedMarkerElement
  - **Impact:** Future-proofs maps functionality
  - **Effort:** Medium

#### Database Architecture:
- **#268** - Remove users_carsview database view and integrate with Car class architecture
  - **Impact:** Database modernization, removes complex view dependency
  - **Effort:** High (complex JOIN logic refactoring)
  - **Dependencies:** Requires #275 (database testing infrastructure)
- **#242** - LOW PRIORITY: Refactor Car class architecture and modernize codebase
  - **Impact:** Foundation for database view removal
  - **Effort:** Extra Large
  - **Dependencies:** Requires #275 (comprehensive testing for refactoring safety)

#### Testing & Quality:
- **#215** - Database Integration Testing
  - **Impact:** Production readiness validation
  - **Effort:** Large
  - **Dependencies:** **SUPERSEDED BY #275** (comprehensive test framework)
- **#216** - Browser-based Functional Testing Enhancement
  - **Impact:** Improved cross-browser compatibility
  - **Effort:** Large
  - **Dependencies:** Works with #275 (integrated testing approach)

**Deployment Notes:**
- **⚠️ MAJOR VERSION** - Potential breaking changes
- Automated systems may change admin workflows
- Performance optimizations may affect integrations
- Full regression testing required

---

## 📈 **Release Schedule Summary**

| Version | Target Date | Type | Focus | Issues | Est. Dev Time |
|---------|-------------|------|--------|---------|---------------|
| **v2.7.1** | Sep 12, 2025 | Patch | Critical Fixes | 12 (✅ ALL COMPLETED) | 1.5 weeks |
| **v2.8.0** | Oct 28, 2025 | Minor | Core Stability | 18 | 3.5 weeks |  
| **v2.9.0** | Nov 15, 2025 | Minor | Admin & Data | 10 | 2 weeks |
| **v3.0.0** | Jan 15, 2026 | Major | Automation | 24 | 4 weeks |

**Total Timeline:** ~18 weeks (4.5 months) - Extended for comprehensive admin interface work  
**Total Development Time:** ~11 weeks active development  
**Total Issues:** 64 total issues (12 completed in v2.7.1, 52 remaining across future milestones)  
**Admin Interfaces:** Unified admin dashboard with 4 consolidated management areas  
**Database Views:** 2 legacy views scheduled for removal  
**Vacation Period:** Sept 15 - Oct 5, 2025 (accounted for in dates)

### Version History & Semantic Versioning

| Version | Release Date | Type | Key Changes |
|---------|-------------|------|-------------|
| v2.7.0 | Current | Minor | Database improvements, security fixes |
| v2.7.1 | ✅ Complete | Patch | Critical accessibility, EXIF handling, Car class security |
| v2.8.0 | Oct 28, 2025 | Minor | Core stability, admin interface overhaul |
| v2.9.0 | Nov 15, 2025 | Minor | Admin & data management features |
| v3.0.0 | Jan 15, 2026 | Major | Automation & performance overhaul |

## 🎯 **Strategic Rationale**

### Version-Based Release Strategy
The new release plan organizes development around semantic versioning with clear priorities:

#### Immediate Focus (v2.7.1)
- **Critical Accessibility**: Mobile zooming compliance (#250)
- **Foundation Security**: Car class type safety (#239, #241) 
- **Zero Breaking Changes**: Safe patch deployment

#### Stability Phase (v2.8.0)  
- **Deprecated Function Cleanup**: Remove legacy code (#237)
- **Enhanced Error Handling**: Better debugging and UX (#255, #261)
- **Performance Optimization**: Image loading improvements (#251, #246)

#### Feature Phase (v2.9.0)
- **Self-Service Features**: Reduce admin workload (#229)
- **Search Enhancement**: Better user experience (#168)
- **Admin Workflow**: Streamlined management tools

#### Automation Phase (v3.0.0)
- **Major Automation**: Data freshness campaigns (#230)
- **Security Integration**: Enhanced verification (#260 within #230)
- **Performance Overhaul**: Comprehensive optimizations (#217, #218)
- **Testing Infrastructure**: Comprehensive validation (#215, #216)

### Key Changes from Previous Plan
1. **Vacation Accommodation**: Dates adjusted for Sept 15 - Oct 5 vacation period
2. **Issue Migration**: 34 legacy issues migrated from closed Phase milestones  
3. **Unplanned Issues**: 14 additional unplanned issues identified and assigned
4. **UserSpice Integration**: Added UserSpice compatibility tasks for each release
5. **Timeline Extension**: 14 weeks vs 18 weeks (realistic with vacation)
6. **Version Alignment**: Proper semantic versioning based on current v2.7.0
7. **Priority Clarity**: Immediate → High → Medium → Future
8. **Breaking Change Management**: Major version for significant changes
9. **Issue Consolidation**: Verification code work integrated into #230

**Milestone Cleanup**: ✅ Closed 9 completed legacy milestones  
**v2.7.0 Tag**: ✅ Created and pushed to both GitHub and production  
**Complete Planning**: ✅ All 50 open issues now have milestone assignments

This approach balances immediate stability needs with long-term automation goals while respecting semantic versioning principles and vacation scheduling.