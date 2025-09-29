# Lotus Elan Registry - Product Requirements Document

**Version:** 1.0  
**Date:** August 25, 2025  
**Status:** Draft  
**Owner:** Elan Registry Team  

## Table of Contents
1. [Executive Summary](#1-executive-summary)
2. [Business Requirements](#2-business-requirements)
3. [User Personas & Use Cases](#3-user-personas--use-cases)
4. [Current State Analysis](#4-current-state-analysis)
5. [Feature Specifications](#5-feature-specifications)
6. [Technical Architecture](#6-technical-architecture)
7. [Data Management](#7-data-management)
8. [User Experience Requirements](#8-user-experience-requirements)
9. [Security & Compliance](#9-security--compliance)
10. [Performance & Scalability](#10-performance--scalability)
11. [Success Metrics](#11-success-metrics)
12. [Development Roadmap](#12-development-roadmap)
13. [Risk Assessment](#13-risk-assessment)
14. [Appendices](#14-appendices)

---

## 1. Executive Summary

### 1.1 Mission Statement
The Lotus Elan Registry serves as the definitive global database for Lotus Elan vehicle owners, providing comprehensive vehicle tracking, owner networking, and historical preservation of these iconic British sports cars.

### 1.2 Current Status
- **Production URL:** https://elanregistry.org
- **Active Users:** Registry community of Lotus Elan enthusiasts
- **Vehicle Records:** Comprehensive database of Elan chassis records with factory information
- **Technology Stack:** PHP/MySQL web application built on UserSpice framework
- **Development Phase:** Multi-phase enhancement program addressing critical stability (Phase 1) through long-term features (Phase 5)

### 1.3 Strategic Objectives
1. **Data Integrity:** CRITICAL PRIORITY - Maintain 100% accurate, synchronized vehicle and owner information with zero tolerance for data inconsistencies
2. **Registry Growth:** PRIMARY SUCCESS METRIC - Achieve continued growth of registered cars and seamless ownership transfers
3. **Community Building:** Facilitate connections between Elan owners worldwide  
4. **Historical Preservation:** Document and preserve Elan heritage and specifications
5. **User Experience:** Provide intuitive, reliable platform for registry interactions
6. **Technical Modernization:** Evolve legacy codebase while maintaining stability

### 1.4 Business Value
- **Primary:** Centralized repository for Elan vehicle documentation and verification
- **Secondary:**Research tool for Elan history, specifications, and market trends
- **Revenue Model:** Non-profit volunteer community service with no monetization planned
- **Competitive Position:** Only dedicated global Lotus Elan registry platform

---

## 2. Business Requirements

### 2.1 Core Business Functions

#### 2.1.1 Vehicle Registry Management
- **Requirement:** Comprehensive tracking of individual Elan vehicles
- **Scope:** Chassis numbers, specifications, ownership history, current status
- **Business Rule:** Each vehicle must have unique identification and complete audit trail
- **Success Criteria:** 100% data consistency between vehicle records and ownership information

#### 2.1.2 Owner Profile Management  
- **Requirement:** User account system linking owners to their vehicles
- **Scope:** Contact information, location, multiple vehicle ownership
- **Business Rule:** Location changes must automatically synchronize to all owned vehicles
- **Success Criteria:** Real-time location consistency across profile and vehicle records

#### 2.1.3 Factory Information Integration
- **Requirement:** Link vehicles to original factory specifications and production data
- **Scope:** Serial number matching, variant identification, specification lookup
- **Business Rule:** Factory data provides authoritative reference for vehicle authenticity
- **Success Criteria:** Automated matching of user vehicles to factory records

#### 2.1.4 Community Interaction Features
- **Requirement:** Enable communication between registry members
- **Scope:** Owner contact, vehicle inquiries, community networking
- **Business Rule:** Privacy-protected communication through registry platform
- **Success Criteria:** Secure messaging without exposing personal contact details

#### 2.1.5 Ownership Management & Data Freshness
- **Critical Business Need:** Cars rarely get updated after initial registration
- **Ownership Transfer Problem:** New owners cannot easily claim existing registry entries
- **Current Process:** Manual administrator intervention required for ownership changes
- **Required Solution:** Self-service ownership transfer system with verification
- **Data Freshness Challenge:** Lack of periodic owner engagement leads to stale information
- **Required Solution:** Automated outreach system to encourage updates (photos, comments, sale status)
- **Success Criteria:** 
  - Reduce manual administrator workload for ownership transfers by 90%
  - Increase data update frequency through systematic owner re-engagement
  - Streamline new owner onboarding for existing vehicles

### 2.2 Business Constraints
- **Legacy System:** Must maintain compatibility with existing UserSpice authentication
- **Data Migration:** Cannot lose existing registry data during enhancements
- **Uptime Requirements:** Minimal disruption to live production system
- **Budget Constraints:** Development resources limited to volunteer/community contributions
- **Non-Profit Model:** No revenue generation planned - purely community service
- **Market Position:** Unique position as the only comprehensive Lotus Elan registry globally

### 2.3 Compliance Requirements
- **Data Privacy:** GDPR compliance for international user base
- **Email Communications:** CAN-SPAM compliance for registry communications  
- **Image Rights:** User-uploaded vehicle photos require usage rights management
- **Geographic Data:** Compliance with mapping service terms of use

---

## 3. User Personas & Use Cases

### 3.1 Primary Personas

#### 3.1.1 Elan Owner (Primary User)
**Profile:**
- Current or former owner of Lotus Elan vehicle(s)
- Age range: 40-75, predominantly male
- Technical comfort level: Moderate to high
- Geographic distribution: Global, with detailed statistical breakdown available on registry statistics page
- Market coverage: Registry tracks subset of total 13,202 Lotus Elans produced (exact numbers available on statistics page)

**Primary Goals:**
- Register and document their Elan(s) in the official registry
- Connect with other owners for parts, advice, and community
- Verify authenticity and history of vehicles
- Update vehicle and personal information as needed

**Key Use Cases:**
- New owner registration and vehicle entry
- Vehicle specification lookup and verification
- Location updates (moving, vehicle relocation)
- Contacting other owners about similar vehicles
- Accessing factory records and documentation

**Pain Points:**
- Complex vehicle identification and specification entry
- Difficulty finding specific parts or technical information
- Maintaining accurate records when circumstances change
- Photo management and organization

#### 3.1.2 Registry Administrator (Power User)
**Profile:**
- Volunteer administrator with deep Elan knowledge
- Technical expertise in vehicle specifications and history
- Authority to resolve disputes and manage data quality

**Primary Goals:**
- **HIGHEST PRIORITY:** Maintain absolute data integrity across the entire registry
- Resolve duplicate records and conflicting information immediately
- Assist users with registration and technical issues
- Manage community standards and policies
- Monitor and prevent any data corruption or inconsistencies

**Key Use Cases:**
- Review and approve new vehicle registrations
- Merge duplicate records and reassign vehicle ownership
- Investigate and resolve data inconsistencies
- User support and technical assistance
- Database maintenance and cleanup operations

**Pain Points:**
- Manual processes for data quality management
- Limited tools for identifying and resolving duplicates
- Time-intensive user support requests
- Complex database operations requiring technical knowledge

#### 3.1.3 Elan Enthusiast (Secondary User)  
**Profile:**
- Interested in Elan vehicles but may not currently own one
- Potential buyers researching vehicles
- Historians and researchers studying Elan heritage

**Primary Goals:**
- Research Elan specifications and production information
- Browse available vehicles and ownership patterns
- Access historical data and documentation
- Connect with the Elan community

**Key Use Cases:**
- Search and browse vehicle listings
- View statistical reports and trends
- Access factory documentation and specifications
- Research vehicle history and authenticity

**Pain Points:**
- Limited access to detailed information without ownership
- Difficulty navigating large amounts of historical data
- Unclear contact processes for vehicle inquiries

### 3.2 User Journey Mapping

#### 3.2.1 New Owner Registration Journey
1. **Discovery:** User learns about registry through community or research
2. **Registration:** Creates account with UserSpice authentication system
3. **Profile Setup:** Enters personal information and location details
4. **Vehicle Entry:** Adds vehicle(s) with chassis numbers and specifications
5. **Verification:** System matches against factory records where possible
6. **Community Integration:** Explores other owners and vehicles
7. **Ongoing Maintenance:** Updates information as needed over time

#### 3.2.2 Vehicle Research Journey
1. **Initial Search:** User searches for specific chassis number or model variant
2. **Specification Lookup:** Accesses factory information and technical details
3. **Owner Contact:** Uses registry to connect with current or previous owners
4. **Historical Research:** Reviews vehicle history and documentation
5. **Market Research:** Analyzes similar vehicles and ownership patterns

---

## 4. Current State Analysis

### 4.1 Technical Architecture Assessment
- **Framework:** UserSpice 4.x user management system with custom registry extensions
- **Database:** MySQL 8.0+ with comprehensive car and user data schema
- **Frontend:** Bootstrap 4/5 responsive design with jQuery and DataTables
- **Hosting:** A2 Hosting with git-based deployment automation
- **Security:** CSRF protection, prepared statements, bcrypt password hashing

### 4.2 Feature Inventory
#### 4.2.1 Core Features (Production)
- ✅ User registration and authentication
- ✅ Vehicle record management with history tracking  
- ✅ Factory information integration and lookup
- ✅ Image upload and management system
- ✅ Geographic location tracking with Google Maps
- ✅ Statistical reporting and charts
- ✅ Owner contact functionality
- ✅ Administrative tools for data management

#### 4.2.2 Recent Enhancements (v2.3.1)
- ✅ Location synchronization between profiles and vehicles (Issue #193)
- ✅ Enhanced geocoding with error handling and recovery (Issue #146)  
- ✅ Comprehensive Content Security Policy implementation
- ✅ Automated testing framework with 35/35 tests passing
- ✅ Database cleanup and optimization tools

### 4.3 Known Issues and Technical Debt
#### 4.3.1 Phase 1 Critical Issues (Remaining)
- 🔧 Issue #169: Photo rotation problems from certain sources
- 🔧 Image display integration needs updates for Car class architecture

#### 4.3.2 Phase 2-5 Enhancement Opportunities  
- 🔄 Admin interface improvements for car management
- 🔄 Enhanced search capabilities across vehicle and factory data
- 🔄 Google Maps modernization to AdvancedMarkerElement
- 🔄 Performance optimization for dependencies and assets

### 4.4 Infrastructure Status
- **Reliability:** Stable production environment with git-based deployment
- **Performance:** Adequate for current user base, optimization opportunities exist
- **Security:** Comprehensive implementation with regular updates
- **Monitoring:** Manual monitoring with logging infrastructure in place
- **Backup:** Database and file backup systems operational

---

## 5. Feature Specifications

### 5.1 Phase 1: Critical Stability (In Progress)

#### 5.1.1 Photo Rotation Fix (Issue #169)
**Priority:** High - Phase 1 Critical  
**Business Impact:** User experience degradation for uploaded photos

**Requirements:**
- Automatically detect and correct photo orientation from EXIF data
- Support common photo sources: mobile devices, digital cameras, web uploads
- Maintain original image quality during rotation processing
- Apply rotation to all generated thumbnail sizes consistently

**Acceptance Criteria:**
- Photos display in correct orientation regardless of upload source
- No user intervention required for orientation correction
- Existing photos can be reprocessed if needed
- Performance impact minimal (< 100ms additional processing time)

**Technical Specifications:**
- Use PHP EXIF functions to read orientation metadata
- Implement GD or ImageMagick rotation processing
- Update existing image resize class to include orientation correction
- Apply to all image sizes: 100px, 300px, 600px, 1024px, 2048px thumbnails

#### 5.1.2 Image Display Integration Update (Issue #214)
**Priority:** Medium - Phase 2 Core  
**Business Impact:** Technical debt and maintainability

**Requirements:**
- Update image display components to work with modernized Car class architecture
- Ensure consistent image rendering across all vehicle detail views
- Maintain backward compatibility with existing image data

**Acceptance Criteria:**
- All vehicle images display correctly in detail views, listings, and admin interfaces
- Car class integration provides consistent image access methods
- No broken image displays or rendering issues
- Admin tools can manage images effectively

### 5.2 Phase 2: Core Enhancements

#### 5.2.1 Self-Service Ownership Transfer System (NEW - Critical Business Need)
**Priority:** High - Phase 2 Core  
**Business Impact:** CRITICAL - Currently requires manual administrator intervention  
**Effort:** Large

**Problem Statement:**
- New car owners cannot easily claim existing registry entries
- Manual administrator process creates bottlenecks and delays
- Reduces data accuracy when ownership changes aren't recorded

**Technical Requirements:**
- Secure verification system for ownership claims
- Automated workflow with email confirmations
- Audit trail for all ownership transfers
- Integration with existing car history tracking

**User Stories:**
- **New Owner:** "I just bought car chassis ABC123 and want to update the registry"
- **Previous Owner:** "I need to transfer my car to the new owner quickly"
- **Administrator:** "I want ownership transfers to happen automatically without my intervention"

**Success Criteria:**
- 90% reduction in manual administrator workload for ownership transfers
- Average ownership transfer completion time < 24 hours
- Zero data loss during transfer process
- Complete audit trail of all ownership changes

#### 5.2.2 Automated Data Freshness System (NEW - Critical Business Need)
**Priority:** High - Phase 2 Core  
**Business Impact:** CRITICAL - Data becomes stale without owner re-engagement  
**Effort:** Medium

**Problem Statement:**
- Owners rarely return to update information after initial registration
- No systematic outreach to encourage data updates
- Missing notifications when cars are sold
- Registry data becomes increasingly outdated over time

**Technical Requirements:**
- Automated email campaign system
- Periodic owner engagement workflows
- "Sold" status update mechanisms
- Photo and comment update reminders
- Integration with existing user notification system

**User Stories:**
- **Car Owner:** "I should be reminded annually to update my car's information"
- **Registry:** "I want fresh data and photos to keep the registry valuable"
- **Community:** "I want to know which cars are still actively owned vs. sold"

**Success Criteria:**
- 50% increase in annual data update frequency
- 80% response rate to outreach campaigns
- Accurate "sold" status for transferred vehicles
- Measurable improvement in data completeness scores

#### 5.2.3 Enhanced Admin Car Management Interface (Issue #213)
**Priority:** Medium - Phase 2 Core  
**Effort:** Extra Large

**Requirements:**
- Modernize admin interface for car management operations
- Streamline duplicate detection and resolution processes
- Improve bulk operations and data management tools
- Enhanced search and filtering capabilities

**Business Value:**
- Reduced administrative overhead
- Improved data quality through better tooling
- Faster resolution of user support issues

#### 5.2.4 Email-Based Login System (NEW - Critical User Experience)
**Priority:** High - Phase 2 Core  
**Business Impact:** CRITICAL - Major source of user support requests  
**Effort:** Medium

**Problem Statement:**
- Users frequently have issues with login credentials
- Username-based login creates confusion and support burden
- Password recovery processes are cumbersome
- Significant administrator time spent on login assistance

**Technical Requirements:**
- Email-based authentication system
- Integration with existing UserSpice framework
- Backward compatibility during transition period
- Improved password recovery workflows
- Single sign-on experience improvements

**User Stories:**
- **Registry User:** "I want to login with my email address instead of remembering a username"
- **Returning User:** "I should be able to easily recover access to my account"
- **Administrator:** "I want to spend less time helping users with login issues"

**Success Criteria:**
- 75% reduction in login-related support requests
- 90% successful first-attempt login rate
- Seamless migration of existing user accounts
- Improved user onboarding completion rate

#### 5.2.5 Automated User Account Cleanup System (NEW - Critical Admin Efficiency)
**Priority:** High - Phase 2 Core  
**Business Impact:** CRITICAL - Reduces database bloat and administrative overhead  
**Effort:** Medium

**Problem Statement:**
- SPAM user accounts create database pollution
- Users register but never add cars, creating inactive accounts
- Manual cleanup is time-intensive for administrators
- Database performance degradation from unused accounts

**Technical Requirements:**
- **FULLY AUTOMATIC**: Zero manual intervention required
- Automated detection of accounts with no cars after 30 days
- Automatic SPAM user identification and immediate removal
- Grace period notifications before legitimate account deletion
- Comprehensive audit trail for all automated cleanup actions
- Integration with existing user management system
- Scheduled automated execution (daily/weekly cleanup jobs)

**User Stories:**
- **Administrator:** "I want SPAM and inactive accounts automatically cleaned up"
- **Registry:** "I need clean data without fake or unused accounts"
- **System:** "I should perform optimally without database bloat"

**Success Criteria:**
- **100% automatic operation** - no manual administrator involvement
- Automatic removal of accounts with no cars after 30-day grace period
- Immediate automatic purging of detected SPAM accounts
- Zero false positives in legitimate account cleanup
- Measurable database performance improvement
- Complete elimination of manual user cleanup workload

#### 5.2.6 Duplicate Car Detection and Research Tool (NEW - Critical Data Integrity)
**Priority:** High - Phase 2 Core  
**Business Impact:** CRITICAL - Essential for maintaining data integrity and registry accuracy  
**Effort:** Medium

**Problem Statement:**
- Multiple users may register the same car with different chassis number formats
- Same car may appear under different owners (previous/current)
- No systematic way to identify potential duplicate entries
- Manual detection is time-intensive and error-prone
- Data integrity compromised by duplicate records

**Technical Requirements:**
- [ ] Automated duplicate detection algorithms based on chassis numbers
- [ ] Fuzzy matching for similar chassis formats (e.g., "26-1234" vs "261234")
- [ ] Admin dashboard for reviewing potential duplicates
- [ ] Side-by-side comparison interface for suspected duplicates
- [ ] Merge functionality for confirmed duplicate records
- [ ] Historical data preservation during merge operations
- [ ] Integration with existing audit trail system

**Detection Criteria:**
- Chassis number variations (with/without dashes, leading zeros)
- Similar vehicle specifications (year, model, series, color)
- Geographic proximity of owners
- Similar photos or descriptions
- Factory information matches

**User Stories:**
- **Administrator:** "I need to easily identify and resolve duplicate car entries"
- **Data Quality Manager:** "I want systematic detection of potential duplicates"
- **Registry User:** "I should be notified if I'm trying to register an existing car"
- **Community:** "The registry should have clean, accurate data without duplicates"

**Success Criteria:**
- [ ] Automated detection of 95%+ potential duplicate entries
- [ ] Admin interface for efficient duplicate review and resolution
- [ ] Zero data loss during duplicate merge operations
- [ ] Proactive duplicate prevention during new car registration
- [ ] Comprehensive audit trail for all duplicate resolution actions

#### 5.2.7 Enhanced Search Capabilities (Issue #168)  
**Priority:** Low - Phase 2 Core  
**Effort:** Low

**Requirements:**
- Advanced search across vehicle specifications, chassis numbers, and factory data
- Filter combinations for model, year, variant, location, and ownership status
- Performance optimization for large dataset searches
- Export capabilities for search results

**Business Value:**
- Improved user experience for vehicle research
- Better data accessibility for enthusiasts and researchers
- Reduced support requests for information lookup

### 5.3 Phase 3-5: Advanced Features

#### 5.3.1 Enhanced Statistics Dashboard with Update Tracking (NEW - Data Insights)
**Priority:** Medium - Phase 3 UX Improvements  
**Business Impact:** Valuable insights into registry health and user engagement  
**Effort:** Medium

**Problem Statement:**
- Current statistics only show new car registrations
- No visibility into data freshness and update activity
- Unable to measure impact of data freshness initiatives
- Missing key metrics for registry health assessment

**Technical Requirements:**
- Track and display car record update statistics alongside new registrations
- Historical timeline showing updates vs. new entries over time
- Breakdown by update type (photos, comments, ownership changes, specifications)
- Integration with existing Google Charts visualization system
- Database schema enhancements to track update frequency and types

**User Stories:**
- **Registry Administrator:** "I want to see how often cars are being updated, not just added"
- **Community Member:** "I want to understand registry activity and data freshness"
- **Data Analyst:** "I need metrics to measure the success of engagement initiatives"

**Success Criteria:**
- Clear visualization of update activity trends over time
- Ability to distinguish between new registrations and existing car updates
- Measurable improvement in data freshness tracking
- Enhanced registry health monitoring capabilities

#### 5.3.2 Google Maps Modernization (Issue #218)
**Priority:** Low - Phase 4 Optional  
**Technical:** Upgrade to AdvancedMarkerElement API

#### 5.3.3 Performance Optimization (Issue #217)  
**Priority:** Medium - Phase 4 Optional  
**Technical:** Dependencies, assets, and load time improvements

#### 5.3.4 Comment Analysis and Data Structure Optimization (NEW - Future Task)
**Priority:** Medium - Phase 4-5 Data Quality  
**Business Impact:** Improved searchability and data consistency  
**Effort:** Large (Analysis + Implementation)

**Problem Statement:**
- Valuable information is trapped in unstructured comment fields
- Common data patterns appear repeatedly in comments (modifications, parts, history)
- Search functionality cannot find cars based on comment content
- Data mining opportunities are limited by unstructured format

**Analysis Requirements:**
- **Phase 1 - Data Mining**: Comprehensive review of all existing comments
- Identify frequently mentioned structured data patterns:
  - Engine modifications (Weber carbs, performance parts)
  - Restoration details (paint codes, interior colors, dates)
  - Historical information (previous owners, significant events)
  - Parts and components (gearbox types, differential ratios)
  - Geographic history (where car has been located)

**Implementation Requirements:**
- **Phase 2 - Schema Enhancement**: Add new structured fields for common patterns
- **Phase 3 - Data Migration**: Extract structured data from existing comments
- **Phase 4 - Enhanced Forms**: Update car entry/edit forms with new fields
- **Phase 5 - Search Integration**: Enable search across new structured fields

**User Stories:**
- **Car Owner:** "I want to easily record my Weber carburetor upgrade in a searchable way"
- **Researcher:** "I want to find all cars with specific modifications or restoration details"
- **Registry:** "I want to preserve valuable information in a searchable, structured format"

**Success Criteria:**
- Identification of top 10-15 most common structured data patterns
- Migration of structured data from comments without data loss
- Enhanced search capabilities across new structured fields
- Improved data consistency and quality
- Maintained backward compatibility with existing comment system

**Technical Considerations:**
- Natural language processing for comment analysis
- Database schema evolution planning
- Data migration scripts with validation
- Enhanced search indexing
- User interface updates for new structured fields

#### 5.3.5 Grafana Statistics Dashboard (Issue #208)
**Priority:** Low - Phase 5 Long-term  
**Advanced:** Enhanced analytics and reporting capabilities

---

## 6. Technical Architecture

### 6.1 System Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Production Environment                    │
│                     (elanregistry.org)                     │
└─────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────┐
│                    Web Application Layer                    │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐│
│  │   UserSpice     │  │    Custom       │  │   Static        ││
│  │ Authentication  │  │   Registry      │  │   Assets        ││
│  │   Framework     │  │   Extensions    │  │   (Images)      ││
│  └─────────────────┘  └─────────────────┘  └─────────────────┘│
└─────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                        │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐│
│  │      Car        │  │     User        │  │    Admin        ││
│  │   Management    │  │   Management    │  │     Tools       ││
│  │                 │  │                 │  │                 ││
│  └─────────────────┘  └─────────────────┘  └─────────────────┘│
└─────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────┐
│                     Data Layer                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐│
│  │   MySQL 8.0+    │  │   File System   │  │   External      ││
│  │   Database      │  │   (Images)      │  │   APIs          ││
│  │                 │  │                 │  │ (Google Maps)   ││
│  └─────────────────┘  └─────────────────┘  └─────────────────┘│
└─────────────────────────────────────────────────────────────┘
```

### 6.2 Database Architecture

#### 6.2.1 Core Tables
- **`users`**: UserSpice authentication and basic user information
- **`profiles`**: Extended user information with location and preferences
- **`cars`**: Vehicle records with specifications and current ownership
- **`cars_hist`**: Complete audit trail of all vehicle changes
- **`car_user`**: Junction table for vehicle ownership relationships
- **`elan_factory_info`**: Factory production data and specifications

#### 6.2.2 Key Views
- **`usersview`**: Combined user and profile information
- **`users_carsview`**: Comprehensive vehicle and owner data for listings

#### 6.2.3 Data Relationships
```sql
users (1) ←→ (1) profiles
users (1) ←→ (∞) car_user ←→ (∞) cars
cars (1) ←→ (∞) cars_hist
cars (∞) ←→ (1) elan_factory_info [chassis matching]
```

### 6.3 Application Components

#### 6.3.1 Directory Structure
```
/app/                   # Custom registry application
├── cars/              # Vehicle management
├── contact/           # Communication features  
├── reports/           # Statistics and reporting
├── views/             # Shared view components
└── assets/            # Application-specific assets

/usersc/               # UserSpice customizations
├── classes/           # Extended classes (Car, etc.)
├── templates/         # ElanRegistry custom template
└── scripts/           # Custom hooks and extensions

/users/                # UserSpice framework core
/userimages/           # User-uploaded vehicle images
/FIX/                  # Administrative database tools
```

#### 6.3.2 Key Classes
- **`Car`**: Vehicle management with history tracking and image handling
- **`User`**: Extended UserSpice user management
- **Database utilities**: Secure query handling and connection management

### 6.4 External Integrations

#### 6.4.1 Google Maps Platform
- **Geocoding API**: Address to coordinate conversion
- **Maps JavaScript API**: Interactive map display
- **Usage**: Location tracking, visualization, and geographic search

#### 6.4.2 Content Delivery Networks
- **Bootstrap CDN**: UI framework and components
- **jQuery CDN**: JavaScript functionality
- **FontAwesome**: Icon library

#### 6.4.3 Email Services  
- **SMTP Configuration**: Transactional email delivery
- **Templates**: User notifications and administrative communications

---

## 7. Data Management

### 7.1 Data Models

#### 7.1.1 Vehicle Data Model
```sql
cars {
  id: INT PRIMARY KEY AUTO_INCREMENT
  user_id: INT FOREIGN KEY → users.id
  chassis: VARCHAR(20) UNIQUE
  year: INT
  model: VARCHAR(50)
  series: VARCHAR(20) 
  variant: VARCHAR(20)
  type: VARCHAR(20)
  color: VARCHAR(50)
  engine: VARCHAR(20)
  purchasedate: DATE
  solddate: DATE
  city: VARCHAR(50)
  state: VARCHAR(50) 
  country: VARCHAR(50)
  lat: DECIMAL(10,4)
  lon: DECIMAL(10,4)
  website: VARCHAR(255)
  comments: TEXT
  image: TEXT (JSON)
  ctime: DATETIME
  mtime: DATETIME
}
```

#### 7.1.2 User Profile Data Model
```sql  
profiles {
  id: INT PRIMARY KEY AUTO_INCREMENT
  user_id: INT FOREIGN KEY → users.id
  city: VARCHAR(50)
  state: VARCHAR(50)
  country: VARCHAR(50) 
  lat: DECIMAL(10,4)
  lon: DECIMAL(10,4)
  website: VARCHAR(255)
  bio: TEXT
}
```

#### 7.1.3 Audit Trail Data Model
```sql
cars_hist {
  id: INT PRIMARY KEY AUTO_INCREMENT
  car_id: INT FOREIGN KEY → cars.id
  operation: ENUM('CREATE','UPDATE','DELETE','LOCATION_SYNC','NEWOWNER','DUPLICATE')
  comments: TEXT
  [All car fields for point-in-time snapshot]
  timestamp: DATETIME DEFAULT CURRENT_TIMESTAMP
}
```

### 7.2 Data Synchronization Rules

#### 7.2.1 Location Synchronization (Issue #193 - Implemented)
**Business Rule:** When a user updates their profile location, all owned vehicles must automatically inherit the new location.

**Implementation:**
1. Profile location change triggers geocoding via Google Maps API
2. On successful geocoding, profile coordinates are updated
3. All vehicles owned by user are automatically synchronized with new location
4. History records are created with `LOCATION_SYNC` operation type
5. User receives confirmation of vehicles synchronized

**Data Flow:**
```
User Settings Update → Profile Geocoding → Profile Update → Car Sync → History Logging
```

#### 7.2.2 Factory Information Matching
**Business Rule:** Vehicle chassis numbers should automatically match against factory production records where available.

**Implementation:**
- Primary match: Exact chassis number match
- Secondary match: Last 5 digits for post-1970 vehicles  
- Fallback: Manual lookup and verification by administrators

### 7.3 Data Quality Management

#### 7.3.1 Validation Rules
- **Chassis Numbers**: Format validation based on year and model
- **Geographic Data**: Coordinate validation and reverse geocoding verification
- **Image Data**: File type, size, and security validation
- **Email Addresses**: RFC compliance and verification workflow

#### 7.3.2 Duplicate Detection and Resolution  
- **Vehicle Duplicates**: Automatic detection by chassis number and specifications
- **User Duplicates**: Email and profile similarity analysis
- **Resolution Workflow**: Administrative tools for merging and cleanup

#### 7.3.3 Data Backup and Recovery
- **Database Backup**: Automated daily backups with retention policy
- **Image Backup**: File system backup with disaster recovery procedures
- **Version Control**: Git-based code deployment with rollback capabilities

---

## 8. User Experience Requirements

### 8.1 Design Principles

#### 8.1.1 Accessibility
- **WCAG 2.1 AA Compliance**: Accessible to users with disabilities
- **Keyboard Navigation**: Full functionality without mouse
- **Screen Reader Support**: Semantic HTML and ARIA labels
- **Color Contrast**: Minimum 4.5:1 ratio for text content

#### 8.1.2 Responsive Design
- **Mobile-First**: Optimized for smartphone and tablet usage
- **Breakpoints**: Bootstrap responsive grid system
- **Touch-Friendly**: Appropriate touch targets and gestures
- **Performance**: Optimized images and minimal data usage

#### 8.1.3 Usability Standards
- **Intuitive Navigation**: Clear information architecture
- **Progressive Disclosure**: Advanced features available but not overwhelming
- **Error Prevention**: Client-side validation and clear error messages
- **Feedback**: Immediate response to user actions with status indicators

### 8.2 Interface Requirements

#### 8.2.1 Vehicle Registration Flow
**Requirements:**
- Step-by-step wizard for new vehicle entry
- Auto-completion for known specifications
- Real-time validation of chassis numbers
- Preview mode before final submission

**Success Criteria:**
- 90% completion rate for started registrations
- < 5 minutes average time to complete
- < 2% error rate requiring support intervention

#### 8.2.2 Search and Discovery
**Requirements:** 
- Advanced search with multiple filter combinations
- Auto-suggest for common search terms
- Map-based geographic search
- Export capabilities for search results

**Success Criteria:**
- Search results returned in < 2 seconds
- 95% user satisfaction with search relevance
- Clear "no results" messaging with suggestions

#### 8.2.3 Image Management
**Requirements:**
- Drag-and-drop image upload interface
- Automatic image optimization and thumbnail generation
- Image reordering and organization tools
- Bulk upload capabilities for multiple images

**Success Criteria:**
- Support for common image formats (JPEG, PNG, WebP)
- Automatic EXIF rotation correction
- Maximum 5MB individual file size
- Visual upload progress indicators

### 8.3 Content Strategy

#### 8.3.1 Help and Documentation
- **Contextual Help**: In-line guidance and tooltips
- **User Guide**: Comprehensive documentation for all features
- **FAQ**: Common questions and troubleshooting
- **Video Tutorials**: Screen-recorded walkthroughs for complex tasks

#### 8.3.2 Error Handling and Messaging
- **Clear Error Messages**: Specific, actionable information
- **Success Confirmation**: Positive reinforcement for completed actions
- **Warning Indicators**: Proactive alerts for potential issues
- **Recovery Guidance**: Steps to resolve error conditions

---

## 9. Security & Compliance

### 9.1 Authentication and Authorization

#### 9.1.1 User Authentication
- **Framework**: UserSpice 4.x with bcrypt password hashing
- **Session Management**: Secure session handling with regeneration
- **Password Policy**: Minimum complexity requirements
- **Account Security**: Failed login attempt monitoring and lockout

#### 9.1.2 Authorization Model
- **Role-Based Access**: User, Admin, Super Admin permission levels
- **Resource-Level Security**: Page and function access controls
- **Data Ownership**: Users can only modify their own vehicle records
- **Administrative Override**: Admin capabilities for data management

#### 9.1.3 API Security
- **CSRF Protection**: Token validation for all form submissions
- **SQL Injection Prevention**: Prepared statements for all database queries
- **XSS Protection**: Input sanitization and output encoding
- **File Upload Security**: Type validation and malware scanning

### 9.2 Data Protection

#### 9.2.1 Personal Information
- **Data Minimization**: Collect only necessary information
- **Consent Management**: Clear opt-in for data collection and usage
- **Data Retention**: Automatic purging of inactive accounts
- **Export/Deletion**: User rights to access and delete their data

#### 9.2.2 Communication Privacy
- **Contact Protection**: Owner communication through platform messaging
- **Email Masking**: No direct email address exposure
- **Opt-out Mechanisms**: User control over communications
- **Anti-Spam Measures**: Rate limiting and abuse prevention

#### 9.2.3 Content Security Policy
**Implementation**: Comprehensive CSP headers preventing unauthorized resource loading
- **Script Sources**: Whitelist of approved JavaScript origins
- **Style Sources**: Controlled CSS and font loading
- **Image Sources**: Restricted to trusted domains and user uploads
- **Connection Sources**: Limited external API access

### 9.3 Compliance Framework

#### 9.3.1 GDPR Compliance (EU Users)
- **Lawful Basis**: Legitimate interest for registry functionality
- **Data Subject Rights**: Access, rectification, erasure, portability
- **Breach Notification**: 72-hour reporting procedures
- **Privacy by Design**: Built-in privacy protections

#### 9.3.2 CAN-SPAM Compliance (US Users)  
- **Clear Sender Identification**: Registry name and contact information
- **Honest Subject Lines**: Accurate email content description
- **Unsubscribe Mechanism**: One-click unsubscribe functionality
- **Physical Address**: Registry contact address in all emails

---

## 10. Performance & Scalability

### 10.1 Performance Requirements

#### 10.1.1 Global Performance Standards
- **Critical Requirement**: System must provide acceptable performance for global user base
- **Core Operations**: Car browsing, information entry, and photo uploads must be responsive worldwide
- **Geographic Considerations**: Performance must account for international latency and bandwidth variations

#### 10.1.2 Response Time Targets
- **Page Load Time**: < 3 seconds for 95th percentile globally
- **Database Queries**: < 500ms for complex searches
- **Image Loading**: < 2 seconds for high-resolution images (critical for global users)
- **API Responses**: < 1 second for geocoding and data lookups
- **Photo Uploads**: Efficient processing for multiple image uploads from any location

#### 10.1.2 Throughput Requirements
- **Concurrent Users**: Support for 100+ simultaneous users
- **Database Connections**: Efficient connection pooling
- **File Uploads**: Multiple simultaneous image uploads
- **Search Volume**: 1000+ searches per hour during peak usage

#### 10.1.3 Resource Optimization
- **Database Indexing**: Optimized queries with proper indexing for global search performance
- **Image Compression**: Automatic optimization maintaining quality while reducing global transfer times
- **CDN Utilization**: External resources from content delivery networks for worldwide access
- **Cloudflare CDN**: Primary caching layer providing global performance optimization
- **Caching Strategy**: Multi-layer caching (Cloudflare, application-level, and browser) optimized for international users
- **Data Integrity Focus**: All optimization must maintain strict data accuracy and consistency

### 10.2 Scalability Architecture

#### 10.2.1 Database Scalability
- **Query Optimization**: Efficient SQL with minimal table scans
- **Index Strategy**: Composite indexes for common search patterns
- **Archival Strategy**: Historical data retention and archival procedures
- **Backup Performance**: Non-blocking backup procedures

#### 10.2.2 File System Scalability
- **Image Storage**: Organized directory structure by vehicle ID
- **Thumbnail Generation**: On-demand creation with caching
- **Storage Limits**: Per-user and system-wide storage quotas
- **Content Delivery**: Optimized serving of static assets

#### 10.2.3 Application Scalability
- **Code Organization**: Modular architecture for maintainability
- **Memory Management**: Efficient object lifecycle and cleanup
- **Error Handling**: Graceful degradation under load
- **Monitoring**: Performance metrics and alerting

---

## 11. Success Metrics

### 11.1 System Performance Metrics

#### 11.1.1 Reliability Metrics
- **Uptime Target**: 99.5% availability (< 4 hours downtime/month)
- **Error Rate**: < 1% of requests result in server errors
- **Data Integrity**: 100% consistency between related records
- **Backup Success**: 100% successful automated backups

#### 11.1.2 Performance Metrics
- **Average Response Time**: < 2 seconds for page loads
- **Search Performance**: < 1 second for database searches
- **Image Loading**: < 3 seconds for gallery views
- **Mobile Performance**: Lighthouse score > 80

### 11.2 User Engagement Metrics

#### 11.2.1 Usage Analytics
- **Active Users**: Monthly and daily active user counts
- **Session Duration**: Average time spent per visit
- **Page Views**: Most accessed content and features
- **Feature Adoption**: Uptake of new functionality

#### 11.2.2 Registry Growth Metrics (PRIMARY SUCCESS INDICATORS)
- **Vehicle Registration Growth**: Continued increase in registered cars month-over-month
  - Target: Steady growth trajectory maintaining registry expansion
  - Key metric: Total registered vehicles as percentage of estimated global Elan population
- **Successful Ownership Transfers**: Cars successfully transferred to new owners
  - Target: Smooth ownership transitions without data loss
  - Key metric: Number of cars that change ownership and remain actively updated
- **Profile Completeness**: Percentage of complete user profiles
- **Image Uploads**: Photos added per vehicle on average
- **Community Interaction**: Owner-to-owner communications

### 11.3 Business Value Metrics

#### 11.3.1 Data Quality Metrics
- **Record Accuracy**: Percentage of verified vehicle information
- **Duplicate Resolution**: Time to resolve duplicate records
- **Factory Matching**: Percentage of vehicles matched to factory data
- **Geographic Coverage**: Distribution of registered vehicles globally
- **Data Structure Optimization**: (Future) Percentage of unstructured comment data converted to searchable structured fields

#### 11.3.2 Ownership & Community Health Metrics
- **Ownership Transfer Success Rate**: Percentage of successful car ownership changes
  - Target: >95% successful transfers without administrative intervention
  - Measure: Time from transfer request to completion
- **Registry Completeness**: Percentage of global Elan population registered
  - Based on factory production numbers vs. registry entries
  - Target: Continuous improvement in market coverage
- **Active Car Records**: Vehicles with recent updates or owner engagement
  - Target: >60% of registered cars updated within past 2 years
  - **Future Enhancement**: Statistics page will track update frequency alongside new registrations
- **New Owner Integration**: Success rate of new owners claiming existing registry entries
  - Target: Seamless transition maintaining historical data integrity
- **Support Request Volume**: Tickets per active user per month (target: significant reduction)
- **User Retention**: Percentage of users active after 6 months
- **Community Growth**: Net new registrations per month (both users and vehicles)

---

## 12. Development Roadmap

### 12.1 Phase 1: Critical Stability (Q3-Q4 2025)

#### 12.1.1 Immediate Priorities
**Target Completion**: September 2025
- ✅ **Issue #193**: Location synchronization (Completed)
- ✅ **Issue #146**: Geocoding error handling (Completed)  
- 🔧 **Issue #169**: Photo rotation correction (In Progress)
- 🔧 **Issue #214**: Image display integration update

**Success Criteria:**
- All Phase 1 critical bugs resolved
- System stability > 99% uptime
- User-reported issues < 5 per month

#### 12.1.2 Testing and Quality Assurance
- **Automated Testing**: 100% pass rate for existing test suite
- **Manual Testing**: User acceptance testing for critical workflows
- **Performance Testing**: Load testing under expected usage patterns
- **Security Audit**: Vulnerability assessment and penetration testing

### 12.2 Phase 2: Core Enhancements (Q1-Q2 2026)

#### 12.2.1 Feature Development
**Target Completion**: June 2026
- **PRIORITY 1 - Ownership Transfer System**: Self-service car ownership claims and transfers (CRITICAL BUSINESS NEED)
- **PRIORITY 2 - Data Freshness System**: Automated owner outreach and data update campaigns (CRITICAL BUSINESS NEED)
- **PRIORITY 3 - Email-Based Login System**: Reduce login-related support requests (CRITICAL USER EXPERIENCE)
- **PRIORITY 4 - Automated User Cleanup**: Purge SPAM users and inactive accounts without cars (CRITICAL ADMIN BURDEN)
- **PRIORITY 5 - Duplicate Car Detection Tool**: Research and resolve duplicate car entries (CRITICAL DATA INTEGRITY)
- **Enhanced Admin Interface**: Streamlined management tools
- **Advanced Search**: Multi-criteria vehicle and owner search
- **Bulk Operations**: Administrative efficiency improvements
- **Mobile Optimization**: Enhanced mobile user experience

**Success Criteria:**
- **Ownership Transfers**: 90% reduction in manual administrator workload
- **Data Freshness**: 50% increase in annual data update frequency
- **Login System**: 75% reduction in login-related support requests
- **User Cleanup**: 100% automatic operation with zero manual intervention required
- **Duplicate Detection**: 95%+ automated detection of potential duplicates with efficient resolution tools
- Admin task completion time reduced by 50%
- Search satisfaction > 90% user approval
- Mobile usage increased by 25%

### 12.3 Phase 3-5: Advanced Features (2026-2027)

#### 12.3.1 Long-term Enhancements
- **Phase 3**: UX improvements, enhanced statistics dashboard with update tracking
- **Phase 4**: Performance optimization, API modernization, comment analysis and data structure optimization
- **Phase 5**: Advanced analytics, community features, implementation of structured data fields

**Success Criteria:**
- User engagement increased by 40%
- System performance improved by 60%
- Feature adoption > 75% for major enhancements

### 12.4 Maintenance and Operations

#### 12.4.1 Ongoing Activities  
- **Security Updates**: Monthly security patches and updates
- **Performance Monitoring**: Continuous system health monitoring
- **User Support**: Community-driven support and documentation
- **Data Backup**: Daily automated backups with disaster recovery testing

#### 12.4.2 Technical Debt Management
- **Code Quality**: Regular code reviews and refactoring
- **Documentation**: Maintained developer and user documentation  
- **Testing Coverage**: Expanded automated test coverage
- **Dependency Updates**: Regular framework and library updates

---

## 13. Risk Assessment

### 13.1 Technical Risks

#### 13.1.1 High-Impact Risks
**Data Loss or Corruption**
- **Probability**: Low
- **Impact**: Critical  
- **Mitigation**: Daily automated backups, database replication, comprehensive testing
- **Contingency**: Disaster recovery procedures with < 24-hour restore time

**Security Breach**  
- **Probability**: Medium
- **Impact**: High
- **Mitigation**: Security audits, penetration testing, access controls
- **Contingency**: Incident response plan, user notification procedures

**Third-Party Service Failure**
- **Probability**: Medium  
- **Impact**: Medium
- **Mitigation**: Service redundancy, graceful degradation, alternative providers
- **Contingency**: Manual processes, cached data utilization

#### 13.1.2 Medium-Impact Risks
**Performance Degradation**
- **Probability**: Medium
- **Impact**: Medium
- **Mitigation**: Performance monitoring, capacity planning, optimization
- **Contingency**: Scaling procedures, resource allocation

**Legacy System Compatibility**
- **Probability**: Low
- **Impact**: Medium  
- **Mitigation**: Thorough testing, backwards compatibility maintenance
- **Contingency**: Rollback procedures, version control

### 13.2 Business Risks

#### 13.2.1 User Adoption Risks
**Low Feature Adoption**
- **Probability**: Medium
- **Impact**: Medium
- **Mitigation**: User feedback integration, iterative development
- **Contingency**: Feature refinement, additional user education

**Community Fragmentation**
- **Probability**: Low
- **Impact**: High
- **Mitigation**: Community engagement, transparent communication
- **Contingency**: Community outreach, feedback incorporation

#### 13.2.2 Resource Risks
**Development Resource Constraints**
- **Probability**: High
- **Impact**: Medium
- **Mitigation**: Phase-based prioritization, community contributions
- **Contingency**: Reduced scope, extended timelines

**Hosting and Infrastructure Costs**
- **Probability**: Low
- **Impact**: Medium
- **Mitigation**: Cost monitoring, efficiency optimization
- **Contingency**: Alternative hosting, community funding

---

## 14. Appendices

### 14.1 Technical Specifications

#### 14.1.1 System Requirements
- **PHP**: 7.4+ (8.x recommended)
- **MySQL**: 8.0+ with InnoDB storage engine  
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **SSL Certificate**: Required for production deployment
- **Memory**: 512MB+ PHP memory limit
- **Storage**: 10GB+ for application and user images

#### 14.1.2 Browser Compatibility
- **Modern Browsers**: Chrome 90+, Firefox 88+, Safari 14+, Edge 90+
- **Mobile Browsers**: iOS Safari 14+, Chrome Mobile 90+
- **Progressive Enhancement**: Core functionality in older browsers
- **JavaScript**: ES6+ with polyfills for legacy support

### 14.2 Database Schema Details

#### 14.2.1 Table Relationships
```sql
-- Core user and vehicle relationships
users ↔ profiles (1:1)
users ↔ car_user ↔ cars (M:N)
cars ↔ cars_hist (1:M)
cars ↔ elan_factory_info (M:1, optional)

-- Administrative tables  
fix_script_runs (tracking)
country (reference data)
email (system configuration)
```

#### 14.2.2 Index Strategy
```sql
-- Performance-critical indexes
cars: INDEX(chassis), INDEX(user_id), INDEX(year, model)
cars_hist: INDEX(car_id, timestamp)
profiles: INDEX(user_id), INDEX(lat, lon)
elan_factory_info: INDEX(serial)
```

### 14.3 API Documentation

#### 14.3.1 Internal APIs
- **Car Management**: CRUD operations with history tracking
- **User Profile**: Location updates with geocoding
- **Image Upload**: Secure file handling with validation
- **Search**: Multi-criteria vehicle and factory data search

#### 14.3.2 External API Dependencies
- **Google Maps Geocoding API**: Address to coordinate conversion
- **Google Maps JavaScript API**: Interactive map displays
- **Email Service**: SMTP configuration for notifications

### 14.4 Deployment Procedures

#### 14.4.1 Production Deployment
1. **Code Deployment**: Git push to production remote
2. **Database Updates**: Migration scripts for schema changes
3. **Configuration**: Environment variable updates
4. **Testing**: Post-deployment verification checklist  
5. **Monitoring**: Performance and error rate monitoring

#### 14.4.2 Rollback Procedures
1. **Git Revert**: Immediate code rollback capability
2. **Database Restore**: Point-in-time recovery from backups
3. **Configuration Restore**: Environment variable rollback
4. **User Communication**: Incident notification procedures

---

**Document History**
- **v1.0** - August 25, 2025 - Initial PRD creation
- **Future versions** - Updates based on development progress and user feedback

**Approval and Sign-off**
- **Technical Review**: [Pending]
- **Business Stakeholder Review**: [Pending]  
- **Final Approval**: [Pending]