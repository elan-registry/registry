# Installation Guide

This document provides step-by-step instructions for setting up the
Lotus Elan Registry application on your server.

## Prerequisites

- **PHP 8.2.29+** required (tested with PHP 8.2.29 CLI built Jul 11 2025)
- MySQL 8.0+
- Composer for dependency management
- Web server (Apache/Nginx) with mod_rewrite enabled
- Git for version control

**PHP Version Notes:**

- Minimum: PHP 8.1 (basic functionality)
- Recommended: PHP 8.2.29+ (full compatibility and PHPUnit 12 support)
- Development Environment: PHP 8.2.29 (cli) verified working

### Required API Keys and External Services

The Elan Registry requires several API keys for full functionality:

**Essential for Core Features:**

- **Google Maps API Key** - Required for map displays on car detail
  pages and statistics
- **Google Geocoding API Key** - Required for location coordinate
  lookup during user registration

**Optional Services:**

- **reCAPTCHA Keys (Site Key + Secret Key)** - Optional, needed only
  if you want spam protection on forms
- **Brevo/Sendinblue API Key** - For email delivery (300 emails/day
  free tier)
  - Alternative: Standard SMTP configuration can be used instead

**Development vs Production:**

- **Development**: API keys can be stored in environment variables for
  testing
- **Production**: API keys are stored securely in the database settings
  table

### Email Service Requirements

**Development Environment:**

- **Mailtrap.io account** (recommended) - Free service for email
  testing without sending real emails

**Production Environment (choose one):**

- **Brevo/Sendinblue account** with API credentials (300 emails/day free tier)
- **Alternative**: Any SMTP service (Gmail, SendGrid, AWS SES, etc.)
- **Standard SMTP** configuration supported

## Installation Steps

### 1. Install UserSpice Framework

The Elan Registry is built on top of UserSpice for user authentication and management.

1. **Download UserSpice**: Visit [https://userspice.com](https://userspice.com)
   and download the latest stable release
2. **Extract and Setup**: Extract UserSpice to your web server
   directory
3. **Database Configuration**: Follow UserSpice installation wizard to
   configure database connection
4. **Initial Setup**: Complete the UserSpice installation process and
   create an admin account
5. **Verify Installation**: Ensure UserSpice is working correctly
   before proceeding
6. **Get API Key**: Get and Install an API key.  Navigate to the
   General Settings Tab

#### Required UserSpice Plugins

After completing the base UserSpice installation, install and activate
these required plugins:

**Required Plugins:**

- **`Auto Assign Usernames`** - Hides username field and auto-assigns
  usernames on registration
- **`getSettings Function`** - Provides global settings access via
  `getSettings()` function
- **`hooker`** - Custom hooks system for code injection points

**Optional Plugins:**

- **`reCAPTCHA`** - Google reCAPTCHA v2/v3 integration for spam
  protection
  - **Note**: Requires Google reCAPTCHA account and API keys
  - **Alternative**: Can run without spam protection initially
- **`Brevo Sendinblue`** - API-based email delivery replacing phpmailer
  (300 emails/day free)
  - **Note**: Requires Brevo/Sendinblue account and API credentials
  - **Alternative**: Standard SMTP configuration can be used instead

**Plugin Installation and Activation:**

1. **Install and activate required plugins** through UserSpice Admin
   Panel → Plugin Manager:
   - **`Auto Assign Usernames`**
   - **`getSettings Function`**
   - **`hooker`**

2. **Optional plugins** can be installed and activated later as needed:
   - **`reCAPTCHA`** - Install and activate when you have Google
     reCAPTCHA keys configured
   - **`Brevo Sendinblue`** - Install and activate if you want
     API-based email delivery

**Plugin Manager Configuration:**

After completing plugin installation and activation, your Plugin Manager
should look like this:

![Plugin Manager Configuration](images/plugin-manager-configuration.png)

**Correct Plugin Status:**

- ✅ **Auto Assign Usernames** - Active
- ✅ **getSettings Function** - Active
- ✅ **Hooker Plugin** - Active
- ❌ **reCAPTCHA** - Inactive (install but don't activate initially)
- ❌ **Brevo Sendinblue** - Inactive (optional for email delivery)

### 2. Clone the Elan Registry Repository

The registry code sits on top of UserSpice without overwriting core
UserSpice files. After cloning the registry, you will not be able to
login until step 4, Configure Environment Variables, is complete.

```bash
# Navigate to your web server directory (where UserSpice is installed)
cd /path/to/your/webserver

# Since directory contains UserSpice, clone to temporary directory and merge
git clone https://github.com/unibrain1/elanregistry.git temp_registry
cp -r temp_registry/* .
rm -rf temp_registry
```

**Important Notes:**

- The registry code is mostly contained in its own directory structure
- The `usersc/` directory adds to the base UserSpice installation but
  does not overwrite core files
- All customizations follow UserSpice's recommended override patterns

### 3. Install Dependencies

Install PHP dependencies using Composer. **The installation command differs
between development and production environments.**

#### Development Environment

```bash
# Install all dependencies including dev dependencies (PHPUnit, PHPStan)
composer install
```

#### Production/Test Environments

```bash
# Install ONLY production dependencies (excludes PHPUnit, PHPStan, testing tools)
composer install --no-dev --optimize-autoloader
```

**Important Notes:**

- **`--no-dev`**: Skips development dependencies (testing frameworks,
  code analysis tools)
  - PHPUnit requires PHP 8.3+ but production servers may run PHP 8.2
  - Development tools are not needed on production servers
- **`--optimize-autoloader`**: Generates optimized class autoloader for better performance

**Key Production Dependencies:**

- `johnathanmiller/secure-env-php` - Encrypted environment variable management

**Development-Only Dependencies (installed with `composer install`):**

- `phpunit/phpunit` - Unit testing framework (requires PHP 8.3+)
- `phpstan/phpstan` - Static analysis tool for code quality

### 4. Configure Environment Variables

The application uses encrypted environment variables for security. See
[`ENVIRONMENT.md`](ENVIRONMENT.md) for detailed configuration.

#### Quick Setup

Follow the
[SecureEnvPHP documentation](https://github.com/johnathanmiller/secure-env-php)
for complete instructions.

1. **Create Environment Variables**:

   ```bash
   # Create temporary plaintext .env file
   echo "DB_HOST=localhost" > .env
   echo "DB_USER=your_database_username" >> .env
   echo "DB_PASS=your_database_password" >> .env
   echo "DB_NAME=your_database_name" >> .env
   ```

2. **Encrypt and Secure**:

   ```bash
   # Execute vendor/bin/encrypt-env in your project directory
   # Follow the command prompts to encrypt your .env file
   # Press enter to accept the default values in square brackets
   # Select Y when prompted to create key
   vendor/bin/encrypt-env

   # Remove plaintext file for security
   rm .env

   # Set secure permissions
   chmod 600 .env.enc .env.key
   chown www-data:www-data .env.enc .env.key
   ```

#### Required Environment Variables

- **Database Configuration**:
  - `DB_HOST` - Database server hostname/IP
  - `DB_USER` - Database username
  - `DB_PASS` - Database password
  - `DB_NAME` - Database name

#### 4.2 Test login

Test to verify you can login to UserSpice with the encryted environment
variables. There will be errors on the page.

### 5. Database Setup

The Elan Registry database setup consists of 4 SQL scripts that must
be run in sequence:

1. **1-schema.sql** - Core database structure (required)
2. **2-reference-data.sql** - Country and factory data (required)
3. **3-configuration.sql** - Settings and menus (required)
4. **4-sample-data.sql** - Test user and car (optional, dev/test only)

#### Script Re-execution Safety

**⚠️ IMPORTANT:** The database installation scripts have different
safety levels for re-execution:

- **1-schema.sql**: ❌ **NOT SAFE** - Will fail if run multiple times.
  Creates tables, indexes, and triggers that cannot be recreated if
  they already exist. Only run once on initial setup.
- **2-reference-data.sql**: ✅ **SAFE** - Uses `ON DUPLICATE KEY UPDATE`
  to safely update existing records. Can be run multiple times.
- **3-configuration.sql**: ✅ **SAFE** - Uses `ON DUPLICATE KEY UPDATE`
  and conditional logic. Can be run multiple times to update settings.
- **4-sample-data.sql**: ✅ **SAFE** - Uses `ON DUPLICATE KEY UPDATE`.
  Can be run multiple times (development/testing only).

**Recommendation**: Run scripts in order (1 → 2 → 3 → 4). Only script
1 (schema) must be run exactly once. Scripts 2, 3, and 4 can be safely
re-run to update data or fix configuration issues.

#### Step 1: Core Database Schema

**⚠️ WARNING: This script is NOT safe to run multiple times. It will
fail if tables already exist.**

Run the schema script to transform your UserSpice installation into an
Elan Registry database:

```bash
# Import core database schema (creates 8 custom tables + enhances profiles/settings)
mysql -u username -p database_name < database/1-schema.sql
```

**What this creates:**

- **8 new tables**: cars, cars_hist, car_user, car_user_hist,
  car_transfer_requests, country, elan_factory_info, fix_script_runs
- **Enhanced profiles**: Adds 6 geographic columns (city, state,
  country, lat, lon, website)
- **Enhanced settings**: Adds 33 Elan Registry configuration
  columns
- **Audit triggers**: Automatic change tracking for cars table
- **Foreign keys**: Data integrity constraints

**Note**: If you need to re-run this script, you must first drop the
Elan Registry tables or restore from a UserSpice-only backup.

#### Step 2: Reference Data Import

**✅ SAFE: This script can be run multiple times safely.**

Import complete country and factory production reference data:

```bash
# Import reference data (249 countries + 9,762 factory records)
mysql -u username -p database_name < database/2-reference-data.sql
```

**What this includes:**

- **Complete country list**: All 249 ISO countries
- **Factory production data**: 9,762 Lotus Elan factory build records
- **Script tracking**: Records execution in fix_script_runs table

#### Step 3: Configuration Settings

**✅ SAFE: This script can be run multiple times safely.**

Apply essential Elan Registry configuration settings:

```bash
# Apply essential configuration (settings, permissions, pages, menus, CDN resources)
mysql -u username -p database_name < database/3-configuration.sql
```

**What this configures:**

- **Site branding**: Lotus Elan Registry name and copyright
- **User management**: Auto-assign usernames, enhanced permissions
- **CDN resources**: 13 external library configurations (jQuery,
  Bootstrap, Chart.js, DataTables, etc.)
- **Image settings**: Upload limits (3 MB), display sizes, thumbnail
  breakpoints
- **SPAM protection**: Cleanup system with safe defaults (disabled by
  default)
- **Template setup**: ElanRegistry template configuration
- **Editor permission**: Additional permission level between User and
  Administrator
- **Page permissions**: Security configuration for all registry pages
- **Menu system**: Complete navigation menu structure (Classic Menu
  system)

**⚠️ IMPORTANT:** Some settings require manual configuration after
installation:

- **Google Maps API Key**: Required for map displays
- **Google Geocoding API Key**: Required for location lookups
- **Admin Emails**: Update `elan_admin_emails` setting with actual
  administrator emails
- **Optional**: reCAPTCHA keys (if using reCAPTCHA plugin)
- **Optional**: Brevo/Sendinblue API credentials (if using email
  plugin)

#### Step 4: Sample Data (Optional - Development/Testing Only)

**✅ SAFE: This script can be run multiple times safely.**

Add a sample user and car for testing and demonstration:

```bash
# Add sample user with profile, car, and complete history (optional)
mysql -u username -p database_name < database/4-sample-data.sql
```

**⚠️ DEVELOPMENT/TESTING ONLY**: Do not run this script in production
environments.

**What this creates:**

- **Sample user account**: `sample_user` with email
  `sample@elanregistry.org`
- **Default password**: `password123` (⚠️ change after first login!)
- **Complete profile**: Portland, Oregon location with coordinates
  (45.51, -122.68)
- **Sample car**: 1973 Lotus Elan S4 SE FHC (Chassis: 45/0123A)
  - Complete restoration story with detailed comments
  - 3 sample car images (from existing image library)
  - Full ownership and history records
- **Standard permissions**: User-level access (can register/edit cars,
  cannot access admin features)
- **Ready for testing**: Email verified and account active

**Use for testing:**

- Car registration, editing, and viewing workflows
- Car image display and management functionality
- Car ownership and sharing features between users
- Location-based features and mapping
- User permission restrictions and access control
- Contact forms and owner communication features
- Audit trail and history tracking

#### Manual Configuration

##### A. Email Settings

- SMTP configuration for transactional emails
- **Brevo Sendinblue API configuration** (requires account signup and
  API credentials)
- **Development**: Mailtrap.io recommended for email testing
- **Production**: Brevo Sendinblue optional - other mail services can
  be used

- Configure UserSpice page permissions for all registry pages

## Post-Installation Configuration

### 1. Testing and Verification

Run the comprehensive test suite to verify installation:

```bash
# Install Node.js dependencies for testing
npm install

# Run all tests
npm test

# Run specific test suites
npm run test:security      # Security validation tests
npm run test:functionality # Core functionality tests
npm run test:navigation    # Navigation and redirects
npm run test:csp           # Content Security Policy validation

# Run PHP security tests
vendor/bin/phpunit tests/
```

### 2. Content Security Policy (CSP)

The application includes comprehensive CSP headers. Verify CSP configuration:

```bash
# Validate CSP policy
php tests/validate-csp-policy.php

# Test CSP compliance
npm run test:csp
```

## Production Deployment

### Deployment Steps

1. **Install Production Dependencies**:

   ```bash
   # On production/test servers, ALWAYS use --no-dev flag
   composer install --no-dev --optimize-autoloader
   ```

   **Why `--no-dev` is critical for production:**
   - Excludes testing frameworks (PHPUnit, PHPStan) that aren't needed in production
   - PHPUnit 11.x requires PHP 8.3+, but production servers may run PHP 8.2
   - Reduces vendor directory size and attack surface
   - Improves autoloader performance

### Security Considerations

1. **File Permissions**:

   ```bash
   # Set restrictive permissions on sensitive files
   chmod 600 .env.enc .env.key
   chown www-data:www-data .env.enc .env.key

   # Ensure web server cannot serve environment files
   # Configure .htaccess or nginx to block .env* files
   ```

2. **Database Security**:

   - Use least privilege principle for database user
   - Enable SSL/TLS for database connections
   - Restrict database access to application server only

3. **API Key Security**:
   - Use separate API keys for production
   - Configure proper domain restrictions
   - Monitor API usage and set quotas

### Deployment Verification Checklist

After deployment, verify the following:

- [ ] Google Maps display correctly on all pages
- [ ] Pages have appropriate UserSpice permission levels
- [ ] Contact forms send to correct email addresses
- [ ] Version information displays correctly in footer
- [ ] Test critical workflows: car registration, editing, contact forms
- [ ] CSP policy allows all required external resources
- [ ] All automated tests pass

## Troubleshooting

### Common Issues

1. **Environment Loading Issues**:

   - Verify `.env.key` file exists and is readable
   - Check file permissions (600) and ownership
   - Ensure files aren't corrupted during deployment

2. **Database Connection Issues**:

   - Verify credentials in encrypted environment
   - Check database server accessibility and user permissions

3. **Google Maps Issues**:

   - Verify API keys are correctly set in environment
   - Check Google Cloud Console for domain/API restrictions
   - Ensure billing is enabled for Google Cloud project

4. **Menu Navigation Issues**:
   - The ElanRegistry template uses the Classic Menu system (`menus`
     table)
   - If navigation menus are missing, verify the menu table is
     populated:

     ```sql
     SELECT COUNT(*) FROM menus; -- Should be ~33 items
     ```

   - Menu permissions are configured via `groups_menus` table

5. **Permission Issues**:
   - Verify UserSpice page permissions are configured
   - Check that all registry pages are accessible to appropriate users

### Debug Environment Loading

```php
// Check if variables loaded
if (getenv('DB_HOST') === false) {
    error_log('Environment variables not loaded');
}
```

## Support

For installation support or questions:

- Review documentation in this repository
- Check GitHub issues for known problems and solutions
- Visit [elanregistry.org](https://elanregistry.org) for live example

## References

- [UserSpice Documentation](https://userspice.com)
- [SecureEnvPHP Documentation](https://github.com/johnathanmiller/secure-env-php)
- [Google Maps API Documentation](https://developers.google.com/maps/documentation)
- [Environment Configuration Guide](ENVIRONMENT.md)
- [Development Guidelines](../../CLAUDE.md)
