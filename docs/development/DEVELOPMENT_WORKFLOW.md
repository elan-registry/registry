# Development Workflow

This document provides detailed development processes, patterns, and workflows for the Lotus Elan Registry application.

## 🔄 Data Flow: User Registration to Car Management

The system maintains location synchronization between user profiles and car records through the following flow:

### 1. New User Registration (`usersc/scripts/during_user_creation.php`)

- User provides location information (city, state, country) during registration
- Location is automatically geocoded using Google Maps API via
  `ElanRegistryOwner::geocodeAddress()`
- Coordinates are stored in the `profiles` table linked to the user account

### 2. Car Creation (`app/cars/actions/edit.php`)

- When a user creates a new car record, owner profile data is copied to the car
- Location fields copied: `city`, `state`, `country`, `lat`, `lon` (lines 167-171)
- This ensures the car initially has the same location as its owner

### 3. Car Record History (`usersc/classes/Car.php`)

- Any changes to car records trigger automatic history tracking
- Changes are recorded in the `cars_hist` table with timestamps and operation types
- History preserves audit trail of all car modifications

### 4. Owner Location Updates (`usersc/user_settings.php`) 

- When an owner changes their location in user settings:
  - Profile location is updated and re-geocoded (lines 235-245)
  - All cars owned by the user are automatically synchronized (lines 244-277)
  - Car records are updated with new location coordinates  
  - History entries are created with `LOCATION_SYNC` operation type
  - Users receive confirmation of how many cars were synchronized

## 🏗️ File Organization Standards

### Application Structure

- Car-related logic in `/app/cars/`
- Contact forms and email handling in `/app/contact/`
- Statistics and reporting in `/app/reports/`
- Authentication handled by UserSpice in `/users/`
- Custom UserSpice modifications in `/usersc/`
- User uploads organized by car ID in `/userimages/`
- **Database fixes**: All database fix scripts must be placed in `/FIX/` directory using the established PHP format with progress reporting and error handling

### Templates & Styling

- Uses Bootstrap 4/5 for responsive layout
- Custom CSS in `usersc/templates/ElanRegistry/assets/css/`
- Custom branding assets in `usersc/templates/ElanRegistry/assets/images/` (v2.12.0+):
  - **Logo assets** (load image in the size needed, never scale):
    - `Lotus-logo-40x40.png` (3.5 KB) - Used in navigation/header
    - `Lotus-logo-3000x3000.png` (673 KB) - Source file for generating other sizes
    - `logo-72x72.png` (small logo alternate)
  - `favicon.ico` (browser tab icon)
  - **Note:** Always use right-sized images instead of loading large images and scaling via CSS
- Template system via UserSpice with custom overrides
- Card-based layout for consistent UI

### CSS Minification Workflow (v2.12.0+)

When modifying CSS, you MUST regenerate the minified version. Edit `consolidated.css` (source), generate `consolidated.min.css` (production), and commit both files.

**See [CSS_AND_ASSETS.md](CSS_AND_ASSETS.md)** for complete CSS workflow, minification steps, SRI hashes, and CDN management.

## 📧 Email Template Standards

**All email communications MUST use the professional HTML email template format established in the spam cleanup system.**

### ✅ Required Email Template Structure

```php
// Use email_body() function with dedicated template files
$template = array(
    'variable1' => $value1,
    'variable2' => $value2
);
$body = email_body('_email_template_name.php', $template);
$email_sent = email($recipient, $subject, $body, $options);
```

### Template Design Standards

**Based on `users/cron/spam_inactive_cleanup.php` HTML email template:**

- **HTML5 Doctype**: `<!DOCTYPE html>` with proper meta tags
- **Responsive Design**: Mobile-friendly with `@media` queries
- **Professional Styling**: Clean CSS with registry branding colors
  - Primary Blue: `#029acf` (headers, accents)
  - Lotus Green: `#469408` (highlights, CTAs) 
  - Background: `#f4f4f4` with white content container
- **Registry Branding**: Official logo and consistent footer
- **Accessibility**: Semantic HTML structure with proper contrast
- **Content Structure**:
  - Header section with logo and title
  - Main content with clear typography
  - Professional footer with registry information

### Template File Location

- All email templates stored in `usersc/views/_email_*.php`
- Use descriptive naming: `_email_feedback.php`, `_email_contact_owner.php`
- Include proper variable escaping: `<?= htmlspecialchars($variable) ?>`

### Benefits

- ✅ **Professional Appearance** - Consistent with registry branding
- ✅ **Mobile Responsive** - Works across all email clients
- ✅ **Brand Consistency** - Matches website design language
- ✅ **Security** - Proper input sanitization and escaping

## 🛠️ Administrative Tools

### SPAM and Inactive User Cleanup System

**Issue #232** - Comprehensive automated cleanup system for maintaining database quality:

**Features:**

- **Automated SPAM Detection**: Identifies legacy data anomalies (1969 dates) and suspicious registration patterns
- **Inactive User Management**: Grace period notifications and cleanup for users with no cars after 30+ days
- **Safety Mechanisms**: Multiple percentage limits, maximum deletion counts, and dry-run testing
- **Email Integration**: Grace period notifications via UserSpice email system (supports Mailtrap.io for dev testing)
- **Comprehensive Logging**: All actions tracked via UserSpice logging system with searchable categories

### FIX Directory Scripts

The `/FIX/` directory contains administrative cleanup scripts with the following features:

- **Run Status Tracking**: Scripts automatically record completion in the `fix_script_runs` table
- **Status Indicators**: Index page shows ✅ for completed scripts, ➖ for unrun scripts
- **Last Run Times**: Displays when each script was last executed
- **Outline Buttons**: Red outline buttons for safe script execution access
- **Progress Reporting**: All scripts use consistent progress messaging with timestamps

#### Creating New FIX Scripts

**IMPORTANT**: Always use the template when creating new FIX scripts:

1. **Copy Template**: Start with `/FIX/_TEMPLATE_Fix-Script.php`
2. **Replace Placeholders**: Update all bracketed placeholders with appropriate values
3. **Use Sequential Numbering**: Name scripts as `##-Descriptive-Name.php` (e.g., `06-New-Feature-Cleanup.php`)
4. **Standard Features Included**: Two-column layout, progress tracking, error handling, authentication
5. **Access Control**: Root and FIX `.htaccess` files allow all operational scripts to run directly
6. **Script Access**: Scripts can be accessed via `/FIX/index.php` menu interface or direct URLs
7. **Template Protection**: Only `_TEMPLATE*` files are blocked from direct access

## 🎨 UI/UX Standards

### Message Handling Standards

**All error and success messages MUST use the modern UserSpice session-based messaging system for consistent UX.**

#### ✅ Correct Message Pattern

```php
// Set error messages (instead of deprecated display_errors())
if (!empty($errors)) {
    foreach ($errors as $error) {
        usError($error);
    }
}

// Set success messages (instead of deprecated display_successes())  
if (!empty($successes)) {
    foreach ($successes as $success) {
        usSuccess($success);
    }
}

// Display all messages (replaces manual Bootstrap alert HTML)
sessionValMessages($errors, $successes, null);
```

#### ❌ Deprecated/Inconsistent Patterns - DO NOT USE

```php
// DEPRECATED - Do not use
display_errors($errors);
display_successes($successes);

// INCONSISTENT - Do not use custom HTML
echo '<div class="alert alert-danger">' . $error . '</div>';

// INCOMPLETE - Setting arrays without display
$errors[] = "Error message"; // Must call usError() and sessionValMessages()
```

#### Template Requirements

- ElanRegistry template includes required UserSpice message divs in `container_open.php`
- Messages appear as dismissible Bootstrap alerts with auto-timeout
- Consistent styling and accessibility across entire application

#### Benefits

- ✅ **Consistent UX** - All messages follow same pattern
- ✅ **Framework Compliance** - Uses UserSpice intended approach  
- ✅ **Accessibility** - Proper ARIA roles and screen reader support
- ✅ **Dismissible** - Auto-timeout and close button functionality

## 🔧 Development Best Practices

### PHP 8+ Compatibility & Code Quality

The application follows modern PHP 8+ development standards with comprehensive type declarations, input validation, and secure coding practices. The Car class has been fully modernized with proper OOP patterns and all legacy issues have been resolved.

#### Recommended PHP Practices

- **Always validate inputs**: Check for null/empty before string operations
- **Use type declarations**: Add return types to all methods for better debugging
- **Handle edge cases**: Graceful degradation when data is missing
- **Log errors appropriately**: Use proper error handling instead of silent failures

### Testing Environment Setup

For Playwright browser tests that require authentication:

1. Copy `.env.local.sample` to `.env.local`
2. Set `TEST_USERNAME` and `TEST_PASSWORD` with valid test account credentials
3. Ensure `.env.local` is never committed to git (it's in `.gitignore`)

### Test Environment Setup

**Test Requirements**

- **PHP 8.1+** with PHPUnit framework (8.2+ recommended for PHPUnit 12 compatibility)
- **No Database Required** - All tests use mock database system
- **File system permissions** for temporary file creation during upload tests
- **UserSpice framework** mocked for isolated testing

## 🪝 Git Hooks & Quality Gates

Pre-commit hooks enforce code quality before commits reach the repository.

### Setup (Required)

```bash
./scripts/setup-git-hooks.sh       # One-time setup
./scripts/check-hooks-status.sh    # Verify installation
```

### Pre-commit Checks

Every commit triggers a 4-step validation:

1. **PHP Coding Standards** - strict types, type declarations, PHPDoc, CSRF, SQL injection, XSS, custom exceptions
2. **Markdown Linting** - consistent formatting
3. **Regression Test Validation** - naming patterns and annotations
4. **Fast Unit Tests** (conditional) - runs when `.php`/`.json`/`phpunit.xml` modified

### Bypass

Use `git commit --no-verify` sparingly - acceptable for emergency hotfixes, non-code files, or broken hooks. Not acceptable for skipping standards during regular development.

**Troubleshooting:** See `scripts/README.md` for complete hook documentation.

## 🔐 Security & CSP Management

Content Security Policy is configured in `usersc/includes/security_headers.php`. It covers Google Services (Maps, Charts, reCAPTCHA), Cloudflare Analytics, CDN resources, and Font services.

**Key points:**

- Gravatar disabled for CSP compliance and privacy (hook in `usersc/plugins/hooker/hooks/account_body_hook.php`)
- CSP tests: `php tests/validate-csp-policy.php` (static) and `npm run test:csp` (browser)
- When adding external resources: update `security_headers.php`, update `tests/validate-csp-policy.php`, run tests

---

**📖 Related Documentation:**
- [CLAUDE.md](../../CLAUDE.md) - Essential development guidance
- [DEPLOYMENT.md](DEPLOYMENT.md) - Production deployment procedures
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Comprehensive coding standards
- [ENVIRONMENT.md](ENVIRONMENT.md) - Environment setup and configuration