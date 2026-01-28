<!-- markdownlint-disable MD013 -->

# Architecture Guide

This document provides comprehensive architecture information for the Lotus
Elan Registry application.

## Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at <https://elanregistry.org>. It's built on top of UserSpice (userspice.com) for user authentication and management, with custom car registry functionality.

## Core Application Structure

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/userimages/` - User-uploaded car images organized by car ID
- `/docs/` - Documentation organized by category (faq/, faq/admin/, development/, testing/)
- `/usersc/classes/` - Custom application classes and utilities
- `/tests/` - PHPUnit and Playwright test files

## UserSpice Management Requirements

**CRITICAL:** When working with UserSpice-managed pages:

1. **New Directories with PHP Files**: When adding new folders containing PHP files, update the `$path` array in `/z_us_root.php` to include the new directory path. This ensures proper path resolution and security monitoring. !securePage($_SERVER['PHP_SELF']) directive, verify the directory is in the `$path` array in `/z_us_root.php`

   ```php
   // Example: Adding 'app/reports/api/' directory
   $path = ['', 'users/', 'usersc/', 'app/', 'app/reports/',
            'app/reports/api/', ...];
   ```

2. **securePage() Authentication**: Pages that use `securePage($_SERVER['PHP_SELF'])` are managed by UserSpice's permission system. When creating new pages with `securePage()`:
   - The page must be manually added to UserSpice's page management system
   - Set appropriate permissions through UserSpice admin interface
   - Without proper page registration, `securePage()` will redirect to login/unauthorized pages

## Database Architecture

> **For complete database schema documentation, see
> [DATABASE.md](DATABASE.md)**

Key tables: `cars`/`cars_hist` (vehicle records with audit), `car_user`/`car_user_hist` (ownership relationships), `car_transfer_requests` (transfer workflow), `users`/`profiles` (owner data), `elan_factory_info` (factory specs).

The `cars` table has 3 audit triggers (INSERT/UPDATE/DELETE). Owner data is automatically synchronized from user profiles to car records.

**See [DATABASE.md](DATABASE.md)** for complete schema, triggers, special accounts, and data synchronization details.

## Ownership Transfer System

The application implements a self-service ownership transfer workflow that
allows current owners to approve or deny transfer requests from new owners.

### Transfer Workflow

1. **Request Initiation**: New owner submits transfer request with car details
   - System generates unique security token
   - Request stored in `car_transfer_requests` table with status "pending"
   - Token expires after configured time period

2. **Current Owner Notification**: Current owner receives notification
   - Can approve or deny the transfer request
   - Response tracked with timestamp

3. **Admin Review** (optional): Administrators can review pending requests
   - View all transfer requests in admin consolidated interface
   - Add administrative notes
   - Manually approve, deny, or mark as completed

4. **Transfer Completion**: On approval
   - Car ownership transferred to new owner via `car_user` table
   - Car data updated with submitted information
   - Request status updated to "completed"
   - All changes logged to audit trails

### Transfer Request Data

The `car_transfer_requests` table stores:

- **Metadata**: Request ID, status, dates, security token
- **User References**: Requesting user, current owner (via car ID)
- **Submitted Data**: Complete snapshot of car data fields (15 fields)
- **Administrative**: Notes, denial reasons, timestamps

### Security Features

- Unique security tokens prevent unauthorized access
- Token expiration prevents stale requests
- Admin oversight and manual intervention capability
- Complete audit trail of all transfer actions

## Class Architecture & Integration Patterns

Domain classes in `/usersc/classes/` follow PascalCase naming, use `DB::getInstance()` singleton, custom exceptions in `/usersc/exceptions/`, and `logger()` for audit logging.

**Key function:** `getUserWithProfile($userId)` in `/usersc/includes/custom_functions.php` - primary function for combined user+profile data access with safe defaults.

**See [CLASSES.md](CLASSES.md)** for complete class documentation, **[INTEGRATION.md](INTEGRATION.md)** for UserSpice integration and owner data patterns, and **[BACKUP_SYSTEM.md](BACKUP_SYSTEM.md)** for BackupManager API.

## Documentation System

**Unified Documentation Viewer**: `/docs/view.php`

- **Purpose**: Displays markdown documents with proper formatting and access control
- **Features**: Security validation, XSS protection, responsive design, breadcrumb navigation
- **Access Control**: Public documents in `/docs/faq/`, admin documents in `/docs/faq/admin/`

**Documentation Utilities**:

- **MarkdownParser** (`/usersc/classes/MarkdownParser.php`) - Converts markdown to HTML with security features
- **DocumentConfig** (`/usersc/classes/DocumentConfig.php`) - Manages document metadata and access control

**Key Documentation Files**:

- User guides: `/docs/faq/CAR_TRANSFER_USER_GUIDE.md`, `/docs/faq/CAR_TRANSFER_FAQ.md`
- Admin guides: `/docs/faq/admin/CAR_TRANSFER_ADMIN_GUIDE.md`
- Development docs: See `/CLAUDE.md` for complete index

## Key Application Files

- `app/cars/index.php` - Searchable car listing with DataTables
- `app/cars/details.php` - Individual car detail pages
- `app/cars/edit.php` - Car editing forms
- `app/reports/statistics.php` - Registry analytics & statistics with Chart.js (tabbed interface)
- `app/contact/send-owner-email.php` - Owner contact functionality

**Key Features:**

- Responsive Bootstrap-themed charts
- Lazy loading for performance
- Environment-based caching system
- API endpoints for dynamic data loading
- Comprehensive analytics dashboard
