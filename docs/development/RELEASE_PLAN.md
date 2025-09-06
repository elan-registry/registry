# Elan Registry Release Plan
*Updated: September 5, 2025*

## Current Version
**v2.7.0** (Production)

---

## 📋 **Upcoming Releases**

### 🚨 **v2.7.1: Critical Fixes** *(September 12, 2025)*
**Estimated Development Time**: 1.5 weeks
**Type:** Patch Release - Critical Bug Fixes  
**Priority:** IMMEDIATE

#### Issues:
- **#250** - CRITICAL: Fix mobile accessibility - Enable zooming for users with vision impairments
  - **Impact:** Critical accessibility compliance issue
  - **Effort:** Small
- **#239** - HIGH PRIORITY: Add missing type declarations and input validation to Car class
  - **Impact:** Foundation for all future Car class improvements
  - **Effort:** Extra Small  
- **#241** - MEDIUM PRIORITY: Improve Car class security and error handling
  - **Impact:** Security hardening of core functionality
  - **Effort:** Medium
- **#169** - Bug: Photos from some sources are not properly rotated
  - **Impact:** Image display quality fix
  - **Effort:** Large
- **#263** - Update UserSpice core for v2.7.1 compatibility
  - **Impact:** Framework compatibility verification
  - **Effort:** Small
- **#274** - Remove outdated migration plan document
  - **Impact:** Documentation cleanup after completed migration work
  - **Effort:** Extra Small

**Deployment Notes:** Critical accessibility fix for mobile users, security improvements to Car class, image rotation fix, UserSpice compatibility verification, documentation cleanup

### 🔄 **v2.8.0: Core Stability** *(October 28, 2025)*
**Type:** Minor Release - Stability & UX Improvements  
**Priority:** HIGH  
**Estimated Development Time**: 3.5 weeks

#### Core Issues:
- **#237** - Replace deprecated display_errors() and display_successes() functions
  - **Impact:** Eliminates deprecated function usage
  - **Effort:** High Priority
- **#255** - Enhance error handling with UserSpice logger integration
  - **Impact:** Better error tracking and debugging
  - **Effort:** High Priority
- **#261** - UX: Improve error messages for better user experience
  - **Impact:** Clearer user feedback and guidance
  - **Effort:** Small
- **#251** - FIX: Image layout shifts - Add aspect ratios to prevent CLS issues
  - **Impact:** Performance improvement, better Core Web Vitals
  - **Effort:** High Priority
- **#246** - Homepage 'One of the Cars' should only select cars with valid images
  - **Impact:** Better homepage presentation quality
  - **Effort:** Medium

#### Database Cleanup:
- **#267** - Remove usersview database view and replace with proper user data access
  - **Impact:** Database modernization, removes view dependency
  - **Effort:** Medium (3 locations to update)
- **#264** - Update UserSpice core for v2.8.0 stability improvements
  - **Impact:** Framework compatibility verification
  - **Effort:** High Priority

#### Codebase Cleanup:
- **#273** - Remove backward compatibility redirect files from app/ directory
  - **Impact:** Removes 9 temporary redirect files from file reorganization
  - **Effort:** Extra Small (simple file deletion after verification)

#### Admin Interface Improvements:
- **#269** - Create ElanRegistry-specific owner management interface for profile editing
  - **Impact:** Enables profile editing from car quality reports, geolocation updates
  - **Effort:** Large (new interface with multiple integrations)
- **#270** - Consolidate admin interfaces: Manage Cars, Owners, Data Quality and Fix Scripts  
  - **Impact:** Unified admin experience, better organization
  - **Effort:** Medium (interface reorganization and navigation)

**Deployment Notes:** Improved error handling and logging, better UX with clearer error messages, performance improvements to image loading, database view cleanup, comprehensive admin interface overhaul

### 🏗️ **v2.9.0: Admin & Data Management** *(November 15, 2025)*
**Estimated Development Time**: 2 weeks
**Type:** Minor Release - Admin Features & Search Enhancement  
**Priority:** MEDIUM

#### Issues:
- **#229** - Self-Service Car Ownership Transfer System
  - **Impact:** 90% reduction in manual transfer work
  - **Effort:** High Priority
- **#168** - Feature: Enhanced search capability for list_cars and list_factory
  - **Impact:** Better user experience for finding cars
  - **Effort:** Large

**Deployment Notes:** New self-service features for users, enhanced search capabilities, admin workflow improvements

### 🚀 **v3.0.0: Admin Automation** *(January 15, 2026)*
**Estimated Development Time**: 4 weeks
**Type:** Major Release - Automation & Performance  
**Priority:** FUTURE

#### Automation & Performance:
- **#230** - Automated Owner Data Freshness Campaign System
  - **Impact:** Automated data quality maintenance
  - **Effort:** High Priority (includes verification code work)
- **#260** - SECURITY: Enhance verification code validation with format checking
  - **Impact:** Part of #230 automation system
  - **Effort:** Small (integrated with #230)
- **#217** - Performance Optimization - Dependencies and Assets
  - **Impact:** 30% page load improvement
  - **Effort:** Extra Large
- **#218** - Google Maps Modernization - Upgrade to AdvancedMarkerElement
  - **Impact:** Future-proofs maps functionality
  - **Effort:** Medium

#### Database Architecture:
- **#268** - Remove users_carsview database view and integrate with Car class architecture
  - **Impact:** Database modernization, removes complex view dependency
  - **Effort:** High (complex JOIN logic refactoring)
- **#242** - LOW PRIORITY: Refactor Car class architecture and modernize codebase
  - **Impact:** Foundation for database view removal
  - **Effort:** Extra Large

#### Testing & Quality:
- **#215** - Database Integration Testing
  - **Impact:** Production readiness validation
  - **Effort:** Large
- **#216** - Browser-based Functional Testing Enhancement
  - **Impact:** Improved cross-browser compatibility
  - **Effort:** Large

**Deployment Notes:**
- **⚠️ MAJOR VERSION** - Potential breaking changes
- Automated systems may change admin workflows
- Performance optimizations may affect integrations
- Full regression testing required

---

## 📈 **Release Schedule Summary**

| Version | Target Date | Type | Focus | Issues | Est. Dev Time |
|---------|-------------|------|--------|---------|---------------|
| **v2.7.1** | Sep 12, 2025 | Patch | Critical Fixes | 6 | 1.5 weeks |
| **v2.8.0** | Oct 28, 2025 | Minor | Core Stability | 18 | 3.5 weeks |  
| **v2.9.0** | Nov 15, 2025 | Minor | Admin & Data | 10 | 2 weeks |
| **v3.0.0** | Jan 15, 2026 | Major | Automation | 24 | 4 weeks |

**Total Timeline:** ~18 weeks (4.5 months) - Extended for comprehensive admin interface work  
**Total Development Time:** ~11 weeks active development  
**Total Issues:** 58 open issues across all milestones  
**Admin Interfaces:** Unified admin dashboard with 4 consolidated management areas  
**Database Views:** 2 legacy views scheduled for removal  
**Vacation Period:** Sept 15 - Oct 5, 2025 (accounted for in dates)

### Version History & Semantic Versioning

| Version | Release Date | Type | Key Changes |
|---------|-------------|------|-------------|
| v2.7.0 | Current | Minor | Database improvements, security fixes |
| v2.7.1 | Sep 12, 2025 | Patch | Critical accessibility & security |
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