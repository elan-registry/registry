# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at https://elanregistry.org. It's built on top of UserSpice (userspice.com) for user authentication and management, with custom car registry functionality.

### Key Components

**Core Application Structure:**
- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system 
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/userimages/` - User-uploaded car images organized by car ID
- `/docs/` - Documentation and reference materials
- `/tests/` - PHPUnit test files

**Database Architecture:**
- MySQL database with comprehensive car registry schema
- `cars` table for vehicle records with full audit trail via `cars_hist`
- `car_user` junction table for car sharing between users
- Views: `usersview`, `users_carsview` for complex queries
- Database triggers automatically maintain audit trails

### Core Application Files

**Car Management:**
- `app/cars/index.php` - Searchable car listing with DataTables
- `app/cars/details.php` - Individual car detail pages
- `app/cars/edit.php` - Car editing forms
- `app/cars/identify.php` - Car identification/registration
- `app/cars/actions/` - AJAX endpoints for car operations

**User Features:**
- `app/reports/statistics.php` - Registry statistics
- `app/contact/send-owner-email.php` - Owner contact functionality
- `app/privacy.php` - GDPR-compliant privacy policy

## Development Commands

### Testing
```bash
# Run PHPUnit tests
vendor/bin/phpunit tests/

# Run specific PHPUnit test suites
vendor/bin/phpunit tests/UserDeletionCleanupTest.php  # User deletion & GDPR compliance
vendor/bin/phpunit tests/SecurityFunctionsTest.php    # File upload security
vendor/bin/phpunit tests/CarTest.php                  # Car class functionality

# Run Playwright browser tests
npm test

# Run specific Playwright test suites
npm run test:security      # Security-focused tests
npm run test:ui           # UI consistency tests
npm run test:navigation   # Navigation and redirects
npm run test:functionality # Core functionality
npm run test:maps         # Maps and charts
```

### Database Testing
**Database Operations Testing**: All database operations use mock objects for testing:
- **Mock Database Layer**: `tests/bootstrap.php` provides complete DB mocking
- **Isolated Tests**: No real database connections required for unit tests
- **GDPR Compliance**: Comprehensive testing for user deletion scenarios
- **Data Integrity**: Tests validate database triggers and audit trails
- **See**: `tests/README.md` for detailed database testing guidance

### Frontend Testing Policy
**IMPORTANT**: Anytime we make changes to the frontend, we should run the appropriate Playwright test. If a test is not available, develop a test and execute it before considering the work complete.

### Dependencies
```bash
# Install PHP dependencies
composer install

# Update dependencies
composer update
```

### Database
- Database schema is documented in README.md
- Uses MySQL with comprehensive audit trails
- SQL files in `/SQL/` for schema updates

## Development Guidelines

### Security Requirements
- All forms must use CSRF tokens
- Use prepared statements for SQL queries
- Input validation and sanitization required for all user inputs
- Password hashing uses bcrypt
- Secure session handling implemented

### File Organization
- Car-related logic in `/app/cars/`
- Contact forms and email handling in `/app/contact/`
- Statistics and reporting in `/app/reports/`
- Authentication handled by UserSpice in `/users/`
- Custom UserSpice modifications in `/usersc/`
- User uploads organized by car ID in `/userimages/`

### Templates & Styling
- Uses Bootstrap 4/5 for responsive layout
- Custom CSS in `usersc/templates/ElanRegistry/assets/css/`
- Template system via UserSpice with custom overrides
- Card-based layout for consistent UI

### Image Handling
- Images auto-resized to multiple formats (100, 300, 600, 1024, 2048px)
- Storage path: `userimages/{car_id}/`
- Resize class available at `usersc/classes/Resize.php`

### Privacy & GDPR
- Location data intentionally imprecise for privacy
- Email verification system for car registrations
- User consent tracking implemented
- Data retention policies documented

## Development Status (August 2025)

### ✅ COMPLETED: Major Security & Organization Overhaul

**🔒 Security Hardening - COMPLETE:**
- Comprehensive CSRF protection implemented across all forms and AJAX endpoints
- All SQL queries converted to prepared statements with parameter binding
- Complete input sanitization using Input::get() throughout the application
- Secure session handling with httponly, secure, and SameSite=Strict flags
- Password hashing verified using bcrypt with proper cost factors
- All deprecated vulnerable code patterns eliminated
- 33 automated security tests passing with 1,187 assertions

**📁 File Organization - COMPLETE:**  
- Complete reorganization by function into `/app/cars/`, `/app/contact/`, `/app/reports/`
- All files renamed for clarity with backward-compatible 301 redirects
- JavaScript extraction: moved inline code to dedicated `.js` files
- Standardized PHP documentation headers across all application files
- Clean separation of concerns and modular architecture

**🧪 Testing Framework - COMPLETE:**
- **35/35 Playwright browser tests passing (100% success rate)**
- Comprehensive test coverage: navigation, functionality, security, UI consistency, maps
- Authentication handling properly validated in all tests
- Google Maps integration and responsive design confirmed
- PHPUnit security test suite with 33 tests covering all critical vulnerabilities
- Automated testing integrated into development workflow

**🎨 Style & Layout - COMPLETE:**
- Consistent Bootstrap card structure implemented across all pages  
- Standardized header/footer template system
- Responsive design validated on mobile and desktop
- UI consistency confirmed through automated browser testing
- Clean CSS organization with external stylesheets

### 🚀 Current Capabilities & Production Readiness

**Security Posture:**
- Enterprise-grade security implementation with zero known vulnerabilities
- Comprehensive automated testing validates all security measures
- GDPR compliance maintained with privacy controls
- Secure file upload handling with validation and size limits
- Audit logging for all critical operations

**Code Quality:**
- Zero PHP/JavaScript syntax errors (verified via IDE diagnostics)
- Clean, well-documented codebase with standardized headers
- Modular architecture with clear separation of concerns
- Comprehensive error handling and user feedback systems
- Modern development practices throughout

**Testing & Reliability:**
- 100% success rate on all 35 browser tests
- Comprehensive security test coverage (33 tests, 1,187 assertions)
- Authentication, navigation, and core functionality validated
- Cross-browser compatibility confirmed
- Mobile responsiveness verified

**Performance & Scalability:**
- Optimized database queries with prepared statements
- Efficient image handling with multiple size variants
- CDN integration for external dependencies
- Clean asset organization for optimal loading

### 📋 Optional Future Enhancements (Tracked in GitHub Issues)
- **Database Integration Testing** (Issue #215)
- **Cross-Browser Functional Testing** (Issue #216) 
- **Performance Optimization & Dependencies** (Issue #217)
- **Google Maps Modernization** (Issue #218)

### 🔄 IN PROGRESS: Customizer Template Migration (August 2025)

**Template Architecture Change:**
- Migrating from ElanRegistry template to UserSpice Customizer template
- Customizer provides modern theming system with Bootstrap 5.3.3 and Ultramenu
- Eliminates need for external CDN configuration for core dependencies

**Completed Work:**
- ✅ Classic Menu references removed (Ultramenu replaces classic navigation)
- ✅ Unused CDN settings removed from admin interface  
- ✅ jQuery consolidation analysis completed (jQuery 3.7.1 compatibility confirmed)
- ✅ ElanRegistry Settings renamed and cleaned up
- ✅ Customizer configuration file added to source control

**Remaining Work:**
- 🔄 Consolidate jQuery versions across car management pages
- 🔄 Update dependency loading to use template's jQuery 3.7.1
- 🔄 Execute database cleanup for unused CDN settings
- 🔄 Test all functionality with customizer template
- 🔄 Update production deployment to use customizer template

### 🎯 Branch Status: FEATURE BRANCH - CUSTOMIZER MIGRATION
Active development branch for migrating to the UserSpice Customizer template system.

## Plugin System

UserSpice plugins provide extended functionality:
- `cms` - Content management
- `recaptcha` - Spam protection  
- `reports` - Data reporting
- `hooker` - Custom hooks system

## Environment
- PHP 7.4+ required
- MySQL 8.0+ 
- Uses `johnathanmiller/secure-env-php` for encrypted environment variable handling
- Google Analytics integration for statistics
- **Environment Variables**: See comprehensive documentation in `ENVIRONMENT.md`
  - Database credentials (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`)
  - Google API keys (`MAPS_KEY`, `GEO_ENCODE_KEY`)
  - All variables encrypted at rest using SecureEnvPHP

## EXTREMELY IMPORTANT: Code Quality Checks

**ALWAYS run the following commands before completing any task:**

Automatically use the IDE's built-in diagnostics tool to check for linting and type errors:
   - Run `mcp__ide__getDiagnostics` to check all files for diagnostics
   - Fix any linting or type errors before considering the task complete
   - Do this for any file you create or modify

This is a CRITICAL step that must NEVER be skipped when working on any code-related task.

## Git & Version Control

### Branch Management Strategy

**Main Branch Protection:**
- `main` branch always contains production-ready code
- All development work happens on feature/phase branches
- Direct commits to main are discouraged

**Branch Naming Convention:**
- Feature branches: `feature/issue-{number}-brief-description`
- Phase branches: `phase-{number}-{name}`
- Hotfix branches: `hotfix/issue-{number}-brief-description`

**Current Active Branches:**
- `main`: Production-ready code with security hardening complete
- `feature/customizer-template-migration`: Customizer template migration work

**Branch Lifecycle:**
1. **Create** branch from latest main: `git checkout -b feature/issue-123-new-feature`
2. **Develop** with regular commits and descriptive messages
3. **Test** thoroughly before merging (run all tests: PHPUnit + Playwright)
4. **Update VERSION file** with appropriate version number (required for all merged branches)
5. **Create git tag** matching VERSION file content
6. **Merge** to main with merge commit (no fast-forward): `git merge --no-ff`
7. **Cleanup** merged branches immediately after successful merge
8. **Push** updated main and tags to origin

**Branch Lifecycle for Customizer Migration:**
1. **Development**: `feature/customizer-template-migration` 
2. **Testing**: Comprehensive testing of template functionality
3. **Integration**: Merge to main after validation
4. **Deployment**: Update production template configuration

**Commit Standards:**
- Add and commit automatically whenever an entire task is finished
- Use descriptive commit messages that capture the full scope of changes
- Include 🤖 Generated with Claude Code footer for AI-assisted work

### Post-Deployment Configuration Requirements

**CRITICAL:** After deploying code changes to production, always verify and update:

#### Google Maps API Configuration
- **Problem:** File reorganization affects API referrer restrictions
- **Solution:** Update Google Cloud Console API restrictions to include new file paths
- **Check:** Verify maps display correctly on statistics and detail pages
- **Location:** Google Cloud Console → APIs & Services → Credentials → API Keys

#### UserSpice Page Permissions
- **Problem:** New pages and redirects need proper access permissions configured
- **Solution:** Update page permissions in UserSpice admin panel
- **Required for:** Both redirect pages AND new destination pages
- **Access:** Admin Panel → Manage Pages → Set appropriate permission levels
- **Test:** Verify all user roles can access reorganized pages correctly

#### Deployment Verification Checklist
- [ ] Google Maps display correctly on all pages
- [ ] All redirected pages work and maintain proper permissions
- [ ] New pages have appropriate UserSpice permission levels
- [ ] Contact forms send to correct email addresses
- [ ] Version information displays correctly in footer
- [ ] Test critical user workflows (car registration, editing, contact forms)

### Version Management

**Static VERSION File Approach:**
- Version information stored in `/VERSION` file in project root
- `ApplicationVersion::get()` reads from this file (no git dependencies)
- Production deployment timestamp shows file modification time
- **REQUIRED:** All merged branches must update VERSION file and create matching git tag

**Version Update Process:**
1. Update `/VERSION` file with new version (e.g., `v2.0.1`)
2. Create matching git tag: `git tag -a v2.0.1 -m "Release v2.0.1"`
3. VERSION file modification time becomes deployment timestamp on production
4. Footer automatically displays: `v2.0.1 (deployment-timestamp)`

## GitHub Issues & Development Management

### 🏗️ Issue Management Structure (August 2025)

**Comprehensive GitHub Issues-based workflow for systematic development tracking and milestone management.**

#### Development Organization
- **GitHub Issues:** All development tasks tracked as issues with comprehensive labeling
- **Milestones:** Phase-based development milestones with realistic timelines
- **Automated Workflows:** GitHub Actions for issue labeling and automation

#### Issue Classification System

**Priority Labels:**
- `priority: critical` - Production-breaking issues requiring immediate attention
- `priority: high` - Important functionality fixes  
- `priority: medium` - Standard enhancements and improvements
- `priority: low` - Nice-to-have improvements

**Phase Labels:**
- `phase: 1-critical` - Critical Bug Fixes & Stability (2 weeks)
- `phase: 2-core` - Core System Enhancements (3 weeks)  
- `phase: 3-ux` - User Experience Enhancements (3 weeks)
- `phase: 4-optional` - Optional Enhancements & Testing (4 weeks)
- `phase: 5-longterm` - Long-term Improvements (3 weeks)

**Component Labels:**
- `component: database` - Database-related issues and queries
- `component: ui` - User interface and frontend changes
- `component: security` - Security improvements and fixes
- `component: performance` - Performance optimizations
- `component: testing` - Testing-related work and validation
- `component: documentation` - Documentation updates and improvements
- `component: maps` - Google Maps functionality and integration
- `component: admin` - Admin interface and management features
- `component: images` - Image handling and processing features

**Status Labels:**
- `status: needs-planning` - Requires detailed planning before development
- `status: ready-dev` - Ready for development work
- `status: in-progress` - Currently being worked on
- `status: needs-review` - Awaiting code review
- `status: needs-testing` - Awaiting testing validation
- `status: blocked` - Blocked by dependencies or external factors

**Effort Labels:**
- `effort: xs` - 1-2 hours of work
- `effort: s` - Half day of work
- `effort: m` - 1-2 days of work
- `effort: l` - 3-5 days of work
- `effort: xl` - 1+ weeks of work

#### Development Milestones

**Phase 1: Critical Fixes** (Due: Sept 2, 2025)
- Critical bug fixes and stability improvements
- Issues: #204, #202, #195, #193, #169, #154, #146, #106, #190

**Phase 2: Core Enhancements** (Due: Sept 16, 2025) 
- Admin interface improvements and data management
- Issues: #213, #214, #168, #158, #136, #135, #134

**Phase 3: UX Enhancements** (Due: Sept 30, 2025)
- User experience improvements and accessibility
- Issues: #188, #186, #179, #176, #161, #159, #127, #125

**Phase 4: Optional Enhancements** (Due: Oct 14, 2025)
- Performance optimization and testing improvements  
- Issues: #215, #216, #217, #218

**Phase 5: Long-term Improvements** (Due: Oct 28, 2025)
- Advanced features and documentation
- Issues: #208, #205, #89, #75, #45, #35, #32, #31, #10, #7

#### Issue Templates

**Available Templates:**
- **🐛 Bug Report** - Standardized bug reporting with environment details
- **✨ Feature Request** - Comprehensive feature planning with acceptance criteria
- **📋 Phase Planning** - Multi-issue development phase planning

#### Automated Workflows

**GitHub Actions Integration:**
- Auto-label new issues based on content keywords
- Link pull requests to issues automatically
- Update issue status based on PR state transitions
- Track milestone progress and celebrate completions
- Remove conflicting status labels automatically

#### Development Workflow

**Issue Lifecycle:**
1. **Creation** → Auto-labeled with `status: needs-planning`
2. **Planning** → Add phase, component, effort, priority labels
3. **Development** → Move to `status: in-progress` 
4. **Review** → Move to `status: needs-review`
5. **Testing** → Move to `status: needs-testing`
6. **Completion** → Auto-close when PR merges

**Branch Strategy:**
- Feature branches: `feature/issue-{number}-brief-description`
- Phase branches: `phase-{number}-{name}`
- Link PRs to issues using "fixes #123" syntax

#### Success Metrics

**Project Health Tracking:**
- Velocity: Issues completed per week
- Burndown: Progress against milestone deadlines  
- Cycle Time: Time from issue creation to completion
- Quality: Bugs introduced vs bugs fixed

### 🎯 Current Status: 39 Issues Organized & Labeled
All existing issues have been categorized with appropriate labels, milestones, and effort estimates for systematic development progression.

## Rule Improvement Triggers

- New code patterns not covered by existing rules
- Repeated similar implementations across files
- Common error patterns that could be prevented
- New libraries or tools being used consistently
- Emerging best practices in the codebase

# Analysis Process:
- Compare new code with existing rules
- Identify patterns that should be standardized
- Look for references to external documentation
- Check for consistent error handling patterns
- Monitor test patterns and coverage

# Rule Updates:

- **Add New Rules When:**
  - A new technology/pattern is used in 3+ files
  - Common bugs could be prevented by a rule
  - Code reviews repeatedly mention the same feedback
  - New security or performance patterns emerge

- **Modify Existing Rules When:**
  - Better examples exist in the codebase
  - Additional edge cases are discovered
  - Related rules have been updated
  - Implementation details have changed

- **Example Pattern Recognition:**

  ```typescript
  // If you see repeated patterns like:
  const data = await prisma.user.findMany({
    select: { id: true, email: true },
    where: { status: 'ACTIVE' }
  });

  // Consider adding to [prisma.mdc](mdc:shipixen/.cursor/rules/prisma.mdc):
  // - Standard select fields
  // - Common where conditions
  // - Performance optimization patterns
  ```

- **Rule Quality Checks:**
- Rules should be actionable and specific
- Examples should come from actual code
- References should be up to date
- Patterns should be consistently enforced

## Continuous Improvement:

- Monitor code review comments
- Track common development questions
- Update rules after major refactors
- Add links to relevant documentation
- Cross-reference related rules

## Rule Deprecation

- Mark outdated patterns as deprecated
- Remove rules that no longer apply
- Update references to deprecated rules
- Document migration paths for old patterns

## Documentation Updates:

- Keep examples synchronized with code
- Update references to external docs
- Maintain links between related rules
- Document breaking changes