<?php

declare(strict_types=1);

/**
 * Security Validation Functions
 *
 * Custom security validation functions for the Elan Registry.
 * Provides input validation and sanitization to prevent common attacks.
 *
 * @package ElanRegistry
 * @author Elan Registry Development Team
 */

/**
 * Validate and sanitize redirect parameter to prevent SQL injection and other attacks
 *
 * @param string $redirect The redirect parameter to validate
 * @return string Sanitized redirect path or empty string if invalid
 */
function validateRedirectParameter(string $redirect): string
{
    if (empty($redirect)) {
        return '';
    }

    // Security: Prevent SQL injection, XSS, and path traversal
    // Block suspicious patterns that could indicate SQL injection
    $suspicious_patterns = [
        'randomblob',  // SQLite injection
        'sleep',       // MySQL time-based injection
        'benchmark',   // MySQL time-based injection
        'waitfor',     // MSSQL time-based injection
        'pg_sleep',    // PostgreSQL time-based injection
        'union',       // SQL union attacks
        'select',      // SQL select statements
        'insert',      // SQL insert statements
        'update',      // SQL update statements
        'delete',      // SQL delete statements
        'drop',        // SQL drop statements
        '<script',     // XSS attacks
        'javascript:', // JavaScript protocol
        'data:',       // Data URI scheme
        'vbscript:',   // VBScript protocol
        '../',         // Path traversal
        '..\\',        // Path traversal (Windows)
    ];

    $redirect_lower = strtolower($redirect);
    foreach ($suspicious_patterns as $pattern) {
        if (strpos($redirect_lower, $pattern) !== false) {
            logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Suspicious redirect parameter blocked: ' . $redirect);
            return '';
        }
    }

    // Whitelist: Only allow specific paths or patterns
    // Allow paths that start with known safe patterns
    $allowed_patterns = [
        'index.php',
        'account.php',
        'app/',
        'usersc/',
    ];

    $is_allowed = false;
    foreach ($allowed_patterns as $pattern) {
        if (strpos($redirect, $pattern) === 0) {
            $is_allowed = true;
            break;
        }
    }

    if (!$is_allowed) {
        logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Redirect to non-whitelisted path blocked: ' . $redirect);
        return '';
    }

    return $redirect;
}
