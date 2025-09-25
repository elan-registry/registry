/**
 * logging-standard.js
 * JavaScript Logging Standards for Elan Registry
 *
 * This file defines the logging standards for client-side JavaScript.
 * Use this as a reference for consistent logging practices.
 */

/**
 * LOGGING STANDARDS
 * =================
 *
 * 1. Production Code:
 *    - Use console.error() for genuine errors that need investigation
 *    - Avoid console.log(), console.info(), console.debug() in production
 *    - Remove all commented-out console statements before committing
 *
 * 2. Development:
 *    - Temporary console.log() is acceptable during development
 *    - MUST be removed before code review/commit
 *    - Use meaningful, descriptive log messages
 *
 * 3. Tests:
 *    - console.log() is acceptable in test files for test output
 *    - Use descriptive messages with clear prefixes (✅, ❌, ℹ️)
 *
 * 4. Server-side:
 *    - Use PHP logger() function for all server-side logging
 *    - JavaScript console methods are for client-side only
 */

/**
 * APPROVED PATTERNS
 * =================
 */

// ✅ GOOD - Error logging for production debugging
function handleAjaxError(xhr, textStatus, errorThrown) {
    console.error('AJAX request failed:', {
        url: xhr.responseURL,
        status: xhr.status,
        error: errorThrown
    });
}

// ✅ GOOD - Error logging with context
function initializeDataTable() {
    try {
        $('#table').DataTable({...});
    } catch (error) {
        console.error('Failed to initialize DataTable:', error);
        throw error;
    }
}

/**
 * DISCOURAGED PATTERNS
 * ====================
 */

// ❌ BAD - Debug logging left in production
// console.log('Initializing component...');

// ❌ BAD - Commented-out debug code
// console.log('Debug info:', data);

// ❌ BAD - Non-descriptive messages
// console.log('here');
// console.log(data);

/**
 * MIGRATION GUIDE
 * ===============
 *
 * When cleaning up existing code:
 * 1. Remove all commented console statements
 * 2. Convert useful debug info to proper error handling
 * 3. Keep only console.error() for genuine error conditions
 * 4. Test thoroughly after cleanup
 */