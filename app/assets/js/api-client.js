/**
 * ElanRegistry Frontend API Client
 *
 * Standardized AJAX client for Pattern A API responses with centralized
 * error handling, CSRF token management, and request cancellation support.
 *
 * Pattern A Response Format:
 * {
 *   "success": true|false,
 *   "message": "Human-readable message",
 *   "optional_data": "additional fields as needed"
 * }
 *
 * @requires Fetch API (all modern browsers)
 * @requires AbortController (all modern browsers)
 * @version 1.0.0
 */

/**
 * Custom error class for general API errors
 * @class ApiError
 */
class ApiError extends Error {
    /**
     * Create an API error
     * @param {string} message - Error message
     * @param {number} status - HTTP status code
     * @param {*} response - Full response object
     */
    constructor(message, status = 0, response = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.response = response;
    }
}

/**
 * Custom error class for validation errors (422)
 * @class ApiValidationError
 */
class ApiValidationError extends Error {
    /**
     * Create a validation error
     * @param {string} message - Error message
     * @param {Object} errors - Field-level validation errors
     */
    constructor(message, errors = {}) {
        super(message);
        this.name = 'ApiValidationError';
        this.errors = errors;
    }
}

/**
 * Custom error class for cancelled requests
 * @class ApiCancelledError
 */
class ApiCancelledError extends Error {
    /**
     * Create a cancelled request error
     * @param {string} message - Error message
     * @param {string} requestId - ID of the cancelled request
     */
    constructor(message = 'Request cancelled', requestId = null) {
        super(message);
        this.name = 'ApiCancelledError';
        this.requestId = requestId;
    }
}

/**
 * Notification Helper for user feedback
 *
 * Provides unified toast/alert notifications for API responses,
 * errors, and validation feedback.
 *
 * @class NotificationHelper
 */
class NotificationHelper {
    /**
     * Display a notification toast
     * @param {string} message - Message text
     * @param {string} type - Notification type: 'success', 'error', 'warning', 'info'
     * @param {number} duration - Display duration in milliseconds (0 = persistent)
     */
    static show(message, type = 'info', duration = 5000) {
        // Delegate to UserSpice toast functions for unified notification system
        const usFunction = {
            'success': 'usSuccess',
            'error': 'usError',
            'warning': 'usInfo',
            'info': 'usInfo'
        }[type] || 'usInfo';

        if (typeof window[usFunction] === 'function') {
            window[usFunction](message);
        } else {
            // Fallback if UserSpice toast functions not available
            console.warn('UserSpice toast function not available:', usFunction);
            console.warn(`[${type}] ${message}`);
        }
    }

    /**
     * Display field-level validation errors
     * @param {Object} errors - Validation errors object with field names as keys
     * @example
     * NotificationHelper.showValidationErrors({
     *   'email': 'Invalid email format',
     *   'password': 'Password must be at least 8 characters'
     * });
     */
    static showValidationErrors(errors) {
        if (!errors || typeof errors !== 'object') {
            return;
        }

        const errorMessages = Object.values(errors)
            .flat()
            .filter(msg => msg && typeof msg === 'string');

        if (errorMessages.length === 0) {
            return;
        }

        // Display each error
        errorMessages.forEach(errorMsg => {
            this.show(errorMsg, 'error', 5000);
        });

        // Also try to highlight form fields if they exist
        Object.keys(errors).forEach(fieldName => {
            const field = document.querySelector(`[name="${fieldName}"]`);
            if (field) {
                field.classList.add('is-invalid');
            }
        });
    }

    /**
     * Escape HTML to prevent XSS attacks
     * @param {string} text - Text to escape
     * @returns {string} Escaped HTML
     */
    static escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

/**
 * ElanRegistry API Client
 *
 * Standardized AJAX client for communicating with backend API endpoints
 * using the Pattern A response format.
 *
 * Features:
 * - Automatic CSRF token injection
 * - Fetch API with async/await support
 * - Request cancellation via AbortController
 * - Custom error classes for different error types
 * - Pattern A response validation
 * - Request timeout support
 *
 * @class ElanRegistryAPI
 * @example
 * const api = new ElanRegistryAPI();
 *
 * try {
 *     const result = await api.post('app/endpoint.php', {
 *         car_id: 123
 *     });
 *
 *     NotificationHelper.show(result.message, 'success');
 * } catch (error) {
 *     if (error instanceof ApiValidationError) {
 *         NotificationHelper.showValidationErrors(error.errors);
 *     } else {
 *         NotificationHelper.show(error.message, 'error');
 *     }
 * }
 */
class ElanRegistryAPI {
    /**
     * Create a new API client instance
     * @param {Object} options - Configuration options
     * @param {string} options.baseUrl - Base URL for API endpoints (default: document root)
     * @param {number} options.timeout - Request timeout in milliseconds (default: 30000)
     * @param {string} options.csrfToken - CSRF token (auto-detected if not provided)
     */
    constructor(options = {}) {
        this.baseUrl = options.baseUrl || '/';
        this.timeout = options.timeout || 30000;
        this.csrfToken = options.csrfToken || this.getCsrfToken();
        this.activeRequests = new Map();
    }

    /**
     * Extract CSRF token from the DOM
     * Checks multiple possible locations for the token:
     * 1. <input name="csrf"> field
     * 2. <input id="csrf"> field
     * 3. Data attribute on document
     *
     * @returns {string} CSRF token or empty string if not found
     */
    getCsrfToken() {
        // Check for CSRF input with name attribute (most common)
        let csrfInput = document.querySelector('input[name="csrf"]');
        if (csrfInput) {
            return csrfInput.value || '';
        }

        // Check for CSRF input with id attribute
        csrfInput = document.getElementById('csrf');
        if (csrfInput) {
            return csrfInput.value || '';
        }

        // Check for data attribute
        const token = document.documentElement.getAttribute('data-csrf-token');
        return token || '';
    }

    /**
     * Generate a unique request ID for tracking and cancellation
     * @returns {string} Unique request identifier
     */
    generateRequestId() {
        return `req_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }

    /**
     * Build a complete URL from endpoint and parameters
     * @param {string} endpoint - API endpoint path
     * @param {Object} params - URL parameters
     * @returns {string} Complete URL
     */
    buildUrl(endpoint, params = {}) {
        // Build URL - use string concatenation to avoid new URL() constructor issues
        let url = endpoint;

        // If endpoint is relative (doesn't start with http:// or https://), prepend origin
        if (!endpoint.match(/^https?:\/\//)) {
            // Construct origin from window.location
            const origin = window.location.origin ||
                          (window.location.protocol + '//' + window.location.host);

            // If endpoint doesn't start with /, add it
            if (!endpoint.startsWith('/')) {
                url = '/' + endpoint;
            } else {
                url = endpoint;
            }

            url = origin + url;
        }

        // Add query parameters
        if (Object.keys(params).length > 0) {
            const queryParams = new URLSearchParams();
            Object.keys(params).forEach(key => {
                if (params[key] !== null && params[key] !== undefined) {
                    queryParams.append(key, params[key]);
                }
            });
            const queryString = queryParams.toString();
            if (queryString) {
                url += (url.includes('?') ? '&' : '?') + queryString;
            }
        }

        return url;
    }

    /**
     * Core request handler using Fetch API
     *
     * @param {string} endpoint - API endpoint path
     * @param {Object} options - Request options
     * @param {string} options.method - HTTP method (default: 'GET')
     * @param {Object} options.data - Request body data
     * @param {string} options.requestId - Custom request ID for tracking
     * @param {AbortSignal} options.signal - Abort signal for cancellation
     * @returns {Promise<Object>} Parsed response data
     * @throws {ApiError} On HTTP errors or invalid responses
     * @throws {ApiValidationError} On validation errors (422)
     * @throws {ApiCancelledError} On cancelled requests
     */
    async request(endpoint, options = {}) {
        const method = options.method || 'GET';
        const requestId = options.requestId || this.generateRequestId();
        const controller = new AbortController();

        // Setup timeout
        const timeoutId = setTimeout(() => {
            controller.abort();
        }, this.timeout);

        try {
            // Track active request
            this.activeRequests.set(requestId, controller);

            // Build request configuration
            const fetchOptions = {
                method: method,
                signal: controller.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            };

            // Add CSRF token for POST/PUT/DELETE requests
            if (['POST', 'PUT', 'DELETE'].includes(method.toUpperCase())) {
                if (this.csrfToken) {
                    fetchOptions.headers['X-CSRF-Token'] = this.csrfToken;
                }

                // Add data if present
                if (options.data) {
                    const formData = new FormData();

                    // Handle both plain objects and FormData
                    if (options.data instanceof FormData) {
                        Object.entries(options.data).forEach(([key, value]) => {
                            formData.append(key, value);
                        });
                    } else if (typeof options.data === 'object') {
                        Object.keys(options.data).forEach(key => {
                            const value = options.data[key];

                            if (value instanceof File || value instanceof Blob) {
                                formData.append(key, value);
                            } else if (Array.isArray(value)) {
                                value.forEach((item, index) => {
                                    formData.append(`${key}[]`, item);
                                });
                            } else {
                                formData.append(key, value);
                            }
                        });
                    }

                    // Add CSRF token to form data as well
                    if (this.csrfToken) {
                        formData.append('csrf', this.csrfToken);
                    }

                    fetchOptions.body = formData;
                }
            }

            // Make the request
            const response = await fetch(
                this.buildUrl(endpoint, options.params || {}),
                fetchOptions
            );

            clearTimeout(timeoutId);

            // Handle HTTP errors
            if (!response.ok) {
                const contentType = response.headers.get('content-type');
                let errorData = null;

                // Try to parse error response
                if (contentType && contentType.includes('application/json')) {
                    try {
                        errorData = await response.json();
                    } catch (_e) {
                        // Ignore JSON parsing errors
                    }
                }

                // Handle 422 Validation errors specially
                if (response.status === 422 && errorData) {
                    throw new ApiValidationError(
                        errorData.message || 'Validation error',
                        errorData.errors || {}
                    );
                }

                // Handle other HTTP errors
                throw new ApiError(
                    errorData?.message || `HTTP ${response.status}: ${response.statusText}`,
                    response.status,
                    errorData
                );
            }

            // Parse response
            const contentType = response.headers.get('content-type');
            let data;

            if (contentType && contentType.includes('application/json')) {
                data = await response.json();
            } else {
                const text = await response.text();
                try {
                    data = JSON.parse(text);
                } catch (_e) {
                    // If not JSON, treat as plain text response
                    data = { success: true, message: text };
                }
            }

            // Validate Pattern A response format
            if (typeof data === 'object' && data !== null) {
                // Check for success property
                if (typeof data.success !== 'boolean') {
                    console.warn('API response missing "success" property:', data);
                }

                // Check for message property (recommended but not required)
                if (data.success === false && !data.message) {
                    throw new ApiError('An error occurred', response.status, data);
                }

                // If success is false, handle as error
                if (data.success === false) {
                    // Check if it's a validation error
                    if (data.errors && typeof data.errors === 'object') {
                        throw new ApiValidationError(data.message || 'Validation error', data.errors);
                    }

                    throw new ApiError(data.message || 'Request failed', response.status, data);
                }

                return data;
            }

            return { success: true, data: data };

        } catch (error) {
            clearTimeout(timeoutId);

            // Handle request cancellation
            if (error.name === 'AbortError') {
                throw new ApiCancelledError('Request cancelled', requestId);
            }

            // Re-throw known error types
            if (error instanceof ApiError || error instanceof ApiValidationError || error instanceof ApiCancelledError) {
                throw error;
            }

            // Wrap other errors
            throw new ApiError(error.message || 'Unknown error occurred', 0, error);

        } finally {
            // Clean up
            this.activeRequests.delete(requestId);
            clearTimeout(timeoutId);
        }
    }

    /**
     * Make a GET request to an endpoint
     *
     * @param {string} endpoint - API endpoint path
     * @param {Object} params - URL query parameters
     * @param {Object} options - Additional request options
     * @returns {Promise<Object>} Response data
     * @throws {ApiError} On request failure
     * @example
     * const cars = await api.get('app/action/getDataTables.php', {
     *     draw: 1,
     *     start: 0,
     *     length: 10
     * });
     */
    async get(endpoint, params = {}, options = {}) {
        return this.request(endpoint, {
            ...options,
            method: 'GET',
            params: params
        });
    }

    /**
     * Make a POST request to an endpoint
     *
     * @param {string} endpoint - API endpoint path
     * @param {Object} data - Request body data
     * @param {Object} options - Additional request options
     * @returns {Promise<Object>} Response data
     * @throws {ApiError} On request failure
     * @throws {ApiValidationError} On validation errors
     * @example
     * const result = await api.post('app/action/process-car-details.php', {
     *     car_id: 123,
     *     year: 2020
     * });
     */
    async post(endpoint, data = {}, options = {}) {
        return this.request(endpoint, {
            ...options,
            method: 'POST',
            data: data
        });
    }

    /**
     * Make a PUT request to an endpoint
     *
     * @param {string} endpoint - API endpoint path
     * @param {Object} data - Request body data
     * @param {Object} options - Additional request options
     * @returns {Promise<Object>} Response data
     * @throws {ApiError} On request failure
     */
    async put(endpoint, data = {}, options = {}) {
        return this.request(endpoint, {
            ...options,
            method: 'PUT',
            data: data
        });
    }

    /**
     * Make a DELETE request to an endpoint
     *
     * @param {string} endpoint - API endpoint path
     * @param {Object} data - Request body data
     * @param {Object} options - Additional request options
     * @returns {Promise<Object>} Response data
     * @throws {ApiError} On request failure
     */
    async delete(endpoint, data = {}, options = {}) {
        return this.request(endpoint, {
            ...options,
            method: 'DELETE',
            data: data
        });
    }

    /**
     * Cancel a specific request by ID
     *
     * @param {string} requestId - ID of the request to cancel
     * @returns {boolean} True if request was cancelled, false if not found
     * @example
     * const requestId = 'req_123456_abcdef';
     * api.cancel(requestId);
     */
    cancel(requestId) {
        const controller = this.activeRequests.get(requestId);
        if (controller) {
            controller.abort();
            this.activeRequests.delete(requestId);
            return true;
        }
        return false;
    }

    /**
     * Cancel all pending requests
     * @example
     * api.cancelAll();
     */
    cancelAll() {
        this.activeRequests.forEach(controller => {
            controller.abort();
        });
        this.activeRequests.clear();
    }
}

// Export to global scope for use in pages
window.ElanRegistryAPI = ElanRegistryAPI;
window.NotificationHelper = NotificationHelper;
window.ApiError = ApiError;
window.ApiValidationError = ApiValidationError;
window.ApiCancelledError = ApiCancelledError;
