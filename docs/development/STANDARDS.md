# Project Standards Guide

This document outlines project-specific coding standards and conventions for the Lotus Elan Registry.

**Note:** This complements the comprehensive [CODING_STANDARDS.md](CODING_STANDARDS.md) which covers general PHP coding standards.

## PHP 8+ Requirements

- **PHP 8+ Type Declarations**: All functions must have complete parameter and return type hints
- **Strict Typing**: New files must include `declare(strict_types=1)`
- **Custom Exceptions**: Use typed exception classes for proper error handling
- **Security First**: Follow secure coding practices outlined in coding standards
- **Documentation**: Complete PHPDoc blocks required for all public methods

## Security Requirements

- All forms must use CSRF tokens
- Use prepared statements for SQL queries
- Input validation and sanitization required for all user inputs
- Password hashing uses bcrypt
- Secure session handling implemented
- **CRITICAL**: Never commit credentials, API keys, or sensitive data to git
- Use environment variables for all sensitive configuration

## Error Logging Standards

**All error conditions MUST use UserSpice logger integration for centralized error visibility and audit trails.**

### Required Error Categories

- `SystemError` - File operations, environment issues, general system failures
- `ValidationError` - Input validation failures, invalid data, malformed requests
- `FileError` - Upload/processing failures, image operations, file system issues
- `DatabaseError` - Database operation failures, query errors, connection issues
- `CarErrors` - Car-related error conditions
- `CarActions` - Car-related user operations
- `DatabaseMaintenance` - All database maintenance operations

### Error Logging Pattern

```php
// REQUIRED: Replace error_log() calls with UserSpice logger
try {
    // Operation that might fail
    $result = riskyOperation();
} catch (Exception $e) {
    logger(
        $user->data()->id ?? 0,
        'ErrorCategory',
        'Descriptive error message: ' . $e->getMessage()
    );
    throw new SpecificException('User-friendly message');
}

// For validation errors
if (empty($requiredField)) {
    logger(
        $user->data()->id ?? 0,
        'ValidationError',
        'Required field missing: fieldName'
    );
    throw new ValidationException('Field is required');
}
```

## Message Handling Standards

**All error and success messages MUST use the modern UserSpice session-based messaging system for consistent UX.**

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

## Code Quality Requirements

**ALWAYS run the following commands before completing any task:**

- Run `mcp__ide__getDiagnostics` to check all files for diagnostics
- Fix any linting or type errors before considering the task complete
- Run appropriate test suites for modified functionality

This is a CRITICAL step that must NEVER be skipped when working on any code-related task.

## Release Notes Requirements

**ALWAYS update or create release notes when creating a pull request:**

- **Update existing release notes** if the target milestone already has a RELEASE_NOTES_V[VERSION].md file
- **Create new release notes** using the template at `docs/development/RELEASE_NOTES_TEMPLATE.md` if none exist
- **Follow the standardized structure**: Required Actions → User-Facing Changes → Admin-Facing Changes → Issues Resolved
- **Focus on impact and benefits**, not implementation details (those belong in GitHub issues)
- **Include clear testing instructions** in the Required Actions section for any manual steps needed post-deployment

**📋 See [RELEASE_NOTES_TEMPLATE.md](RELEASE_NOTES_TEMPLATE.md) for complete guidelines and structure**

## Version Release & Deployment

**For complete release and deployment procedures, see [DEPLOYMENT.md](DEPLOYMENT.md).**

**Quick Reference:**

- **MANDATORY for major/minor releases**: Release notes, GitHub release, annotated git tags
- **Optional for patch releases**: Release notes for significant patches or security fixes
- **Remote configuration**: `origin` (GitHub), `test` (staging), `prod` (live production)
- **Deployment commands**: See DEPLOYMENT.md for comprehensive workflows
