/**
 * LocationPicker - Modern location collection component
 *
 * Provides unified location input with:
 * - HTML5 Geolocation (GPS) for mobile users
 * - Autocomplete search via Photon/Nominatim APIs
 * - SessionStorage caching for performance
 * - XSS-safe rendering
 * - Keyboard navigation support
 *
 * @package ElanRegistry
 * @version 2.12.0
 * @since 2.11.0
 * @link https://github.com/unibrain1/elanregistry/issues/245
 */

(function() {
    'use strict';

    /**
     * LocationPicker class
     */
    class LocationPicker {
        /**
         * Constructor
         *
         * @param {Object} options Configuration options
         * @param {string} options.containerId Container element ID
         * @param {string} options.csrfToken CSRF token for AJAX requests
         * @param {string} options.urlRoot URL root for AJAX endpoints (default: '/')
         * @param {Function} options.onSelect Callback when location selected
         * @param {Function} options.onGPSError Callback(error) when the GPS lookup fails —
         *        the widget itself has no server-reporting opinion; callers on pages where
         *        an unreported failure matters (e.g. a required-field registration blocker)
         *        can hook in their own beacon/logging here. Optional, no-op by default.
         * @param {Function} options.onSearchError Callback(status) when a location search
         *        fails. status is the HTTP status, or 0 for a network or JSON error. The
         *        picker calls it once for each distinct status, so a visitor who keeps
         *        typing does not send a report for each keystroke. Optional, no-op by default.
         * @param {boolean} options.showGPS Show GPS button (default: true)
         * @param {boolean} options.required Is location required (default: true)
         * @param {boolean} options.requireSelection Keep the input invalid until a location
         *        is selected from the results or GPS, or set with setLocation()
         *        (default: false). Opt-in: only the join form needs a location chosen
         *        before submit.
         */
        constructor(options) {
            this.options = Object.assign({
                containerId: 'location-picker-container',
                csrfToken: '',
                urlRoot: '/',
                onSelect: null,
                onGPSError: null,
                onSearchError: null,
                showGPS: true,
                required: true,
                requireSelection: false
            }, options);

            this.container = document.getElementById(this.options.containerId);
            if (!this.container) {
                console.error('LocationPicker: Container element not found:', this.options.containerId);
                return;
            }

            this.debounceTimer = null;
            this.selectedLocation = null;
            this.currentFocusIndex = -1;
            this.reportedSearchStatuses = new Set();

            this.init();
        }

        /**
         * Initialize the location picker
         */
        init() {
            this.render();
            this.attachEventListeners();

            this.syncValidity();
        }

        /**
         * Get the visible search input
         *
         * @returns {HTMLInputElement|null} The input, or null before render()
         */
        getInput() {
            return document.getElementById(`${this.options.containerId}-input`);
        }

        /**
         * Keep the input invalid until a location is selected. Does nothing
         * unless requireSelection is on, so other pages keep the plain
         * required check.
         *
         * @returns {void}
         */
        syncValidity() {
            const input = this.getInput();
            if (this.options.requireSelection && input) {
                input.setCustomValidity(this.selectedLocation ? '' : this.selectionMessage);
            }
        }

        /**
         * Write a location to the hidden form fields, or empty them for null
         *
         * @param {Object|null} location Location with city, state, country, lat, lon
         * @returns {void}
         */
        writeHiddenFields(location) {
            ['city', 'state', 'country', 'lat', 'lon'].forEach(field => {
                const el = document.getElementById(`${this.options.containerId}-${field}`);
                if (el) {
                    el.value = (location && location[field]) || '';
                }
            });
        }

        /**
         * Render the location picker HTML
         */
        render() {
            const gpsButton = this.options.showGPS && this.isGeolocationAvailable() ?
                `<button type="button"
                         class="btn btn-sm btn-outline-primary mb-2 w-100"
                         id="${this.options.containerId}-gps-btn"
                         aria-label="Use current GPS location">
                    <i class="fas fa-location-arrow"></i> Use My Current Location
                </button>
                ${this.options.showGPS ? '<div class="text-center text-muted mb-2"><small>OR</small></div>' : ''}` : '';

            // render() sets this once, because the text depends on whether the
            // GPS button shows. It is the one source for both the native
            // validity message and the .invalid-feedback text, so they cannot differ.
            this.selectionMessage = gpsButton ?
                'Select your town or city from the list, or press Use My Current Location.' :
                'Select your town or city from the list.';
            const showFeedback = this.options.required || this.options.requireSelection;

            this.container.innerHTML = `
                ${gpsButton}

                <div class="mb-3 position-relative">
                    <label for="${this.options.containerId}-input">
                        Location ${this.options.required ? '<span class="text-danger">*</span>' : ''}
                    </label>
                    <div class="input-group${showFeedback ? ' has-validation' : ''}">
                        <input type="text"
                               class="form-control"
                               id="${this.options.containerId}-input"
                               placeholder="Start typing city, state, or country..."
                               autocomplete="off"
                               ${this.options.required ? 'required' : ''}
                               aria-label="Search location"
                               aria-autocomplete="list"
                               aria-controls="${this.options.containerId}-results"
                               ${showFeedback ? `aria-describedby="${this.options.containerId}-feedback"` : ''}
                               aria-expanded="false">
                        <span class="input-group-text d-none" id="${this.options.containerId}-spinner">
                                <i class="fas fa-spinner fa-spin"></i>
                            </span>
                        ${showFeedback ? `<div class="invalid-feedback" id="${this.options.containerId}-feedback">${this.selectionMessage}</div>` : ''}
                    </div>
                    <small class="form-text text-muted">Type at least 2 characters to see suggestions</small>

                    <!-- Autocomplete results dropdown -->
                    <div class="list-group position-absolute w-100"
                         id="${this.options.containerId}-results"
                         style="z-index: 1000; max-height: 300px; overflow-y: auto; display: none;"
                         role="listbox"
                         aria-label="Location suggestions">
                    </div>
                </div>

                <!-- Hidden fields for form submission -->
                <input type="hidden" name="city" id="${this.options.containerId}-city" value="">
                <input type="hidden" name="state" id="${this.options.containerId}-state" value="">
                <input type="hidden" name="country" id="${this.options.containerId}-country" value="">
                <input type="hidden" name="lat" id="${this.options.containerId}-lat" value="">
                <input type="hidden" name="lon" id="${this.options.containerId}-lon" value="">

                <!-- Selected location display -->
                <div class="alert alert-success mt-2 d-none"
                     id="${this.options.containerId}-selected"
                     role="status">
                    <i class="fas fa-check-circle"></i>
                    <strong>Selected:</strong> <span id="${this.options.containerId}-selected-text"></span>
                    <br><small class="text-muted">Coordinates: <span id="${this.options.containerId}-coords"></span></small>
                </div>

                <!-- Error message display -->
                <div class="alert alert-danger mt-2 d-none"
                     id="${this.options.containerId}-error"
                     role="alert">
                </div>
            `;
        }

        /**
         * Attach event listeners
         */
        attachEventListeners() {
            const input = this.getInput();
            const gpsBtn = document.getElementById(`${this.options.containerId}-gps-btn`);

            if (input) {
                input.addEventListener('input', this.handleInput.bind(this));
                input.addEventListener('keydown', this.handleKeyDown.bind(this));
                input.addEventListener('blur', () => {
                    // Delay hiding results to allow click events
                    setTimeout(() => this.hideResults(), 200);
                });
            }

            if (gpsBtn) {
                gpsBtn.addEventListener('click', this.handleGPSClick.bind(this));
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!this.container.contains(e.target)) {
                    this.hideResults();
                }
            });
        }

        /**
         * Handle input changes (with debounce)
         */
        handleInput(e) {
            const query = e.target.value.trim();

            if (this.options.requireSelection) {
                // Typed text no longer matches the selected location, so the old
                // coordinates must not submit with it.
                if (this.selectedLocation) {
                    this.clearSelection();
                }
                this.syncValidity();
            }

            if (query.length < 2) {
                this.hideResults();
                return;
            }

            // Clear previous timer
            clearTimeout(this.debounceTimer);

            // Set new timer (300ms debounce)
            this.debounceTimer = setTimeout(() => {
                this.searchLocation(query);
            }, 300);
        }

        /**
         * Handle keyboard navigation
         */
        handleKeyDown(e) {
            const results = document.getElementById(`${this.options.containerId}-results`);
            const items = results.querySelectorAll('.list-group-item');

            if (!items.length) return;

            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.currentFocusIndex = Math.min(this.currentFocusIndex + 1, items.length - 1);
                    this.updateFocus(items);
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    this.currentFocusIndex = Math.max(this.currentFocusIndex - 1, 0);
                    this.updateFocus(items);
                    break;

                case 'Enter':
                    e.preventDefault();
                    if (this.currentFocusIndex >= 0) {
                        items[this.currentFocusIndex].click();
                    }
                    break;

                case 'Escape':
                    e.preventDefault();
                    this.hideResults();
                    break;
            }
        }

        /**
         * Update focus styling for keyboard navigation
         */
        updateFocus(items) {
            items.forEach((item, index) => {
                if (index === this.currentFocusIndex) {
                    item.classList.add('active');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });
        }

        /**
         * Search for locations
         */
        async searchLocation(query) {
            // Check session cache first
            const cacheKey = `location_cache_${query}`;
            const cached = this.getSessionCache(cacheKey);

            if (cached) {
                this.displayResults(cached);
                return;
            }

            // Show loading spinner
            this.showLoading(true);
            this.hideError();

            let status = 0;
            try {
                const response = await fetch(this.options.urlRoot + 'app/api/shared/location-search.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        query: query,
                        csrf: this.options.csrfToken,
                        limit: '8'
                    })
                });

                if (!response.ok) {
                    status = response.status;
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || 'Search failed');
                }

                // Cache results
                this.setSessionCache(cacheKey, data.results);

                // Display results
                this.displayResults(data.results);

            } catch (error) {
                console.error('Location search error:', error);
                this.showError(this.searchErrorMessage(status));
                this.reportSearchError(status);
            } finally {
                this.showLoading(false);
            }
        }

        /**
         * Plain-language next step for a failed search
         *
         * @param {number} status HTTP status, or 0 for a network or JSON error
         * @returns {string} Message for the error box
         */
        searchErrorMessage(status) {
            // A 403 is the CSRF check: the token from page render is stale,
            // and only a reload gets a new one.
            if (status === 403) {
                return 'This page is out of date. Reload the page, then type your town or city again.';
            }
            const gpsBtn = document.getElementById(`${this.options.containerId}-gps-btn`);
            return 'We could not search for locations. Try again in a moment.' +
                (gpsBtn ? ' Or press Use My Current Location.' : '');
        }

        /**
         * Invoke the caller-supplied onSearchError callback once for each
         * distinct status. Each keystroke can start a search, and the join
         * page reports through the shared join_failure_beacon rate limit.
         * A throw from the callback must not break the picker.
         *
         * @param {number} status HTTP status, or 0 for a network or JSON error
         * @returns {void}
         */
        reportSearchError(status) {
            if (typeof this.options.onSearchError !== 'function' || this.reportedSearchStatuses.has(status)) {
                return;
            }
            this.reportedSearchStatuses.add(status);
            try {
                this.options.onSearchError(status);
            } catch (callbackError) {
                console.error('LocationPicker: onSearchError callback threw', callbackError);
            }
        }

        /**
         * Filter and rank results to prioritize cities
         */
        filterAndRankResults(results) {
            // Keywords to deprioritize (universities, colleges, stations, etc.)
            const deprioritizeKeywords = [
                'university', 'college', 'school', 'station', 'airport',
                'hospital', 'museum', 'library', 'park', 'building',
                'hotel', 'restaurant', 'mall', 'center', 'centre'
            ];

            // Score each result
            const scored = results.map(location => {
                let score = 100;
                const displayLower = (location.display || '').toLowerCase();

                // Penalize if contains deprioritize keywords
                deprioritizeKeywords.forEach(keyword => {
                    if (displayLower.includes(keyword)) {
                        score -= 50;
                    }
                });

                // Boost if it's just city, state, country (no extra details)
                const parts = location.display.split(',').map(p => p.trim());
                if (parts.length <= 3) {
                    score += 20;
                }

                // Boost if city name matches
                if (location.city) {
                    score += 10;
                }

                return { location, score };
            });

            // Sort by score (highest first)
            scored.sort((a, b) => b.score - a.score);

            // Remove duplicates (same city + state + country)
            const seen = new Set();
            const unique = scored.filter(item => {
                const key = `${item.location.city}|${item.location.state}|${item.location.country}`.toLowerCase();
                if (seen.has(key)) {
                    return false;
                }
                seen.add(key);
                return true;
            });

            // Return top 5 results
            return unique.slice(0, 5).map(item => item.location);
        }

        /**
         * Display search results
         */
        displayResults(results) {
            const resultsContainer = document.getElementById(`${this.options.containerId}-results`);
            const input = this.getInput();

            if (!results || results.length === 0) {
                resultsContainer.innerHTML = `
                    <div class="list-group-item text-muted">
                        <i class="fas fa-info-circle"></i> No locations found. Try a different search term.
                    </div>
                `;
                this.showResults();
                return;
            }

            // Filter and rank results
            const filteredResults = this.filterAndRankResults(results);

            resultsContainer.innerHTML = filteredResults.map(location => `
                <button type="button"
                        class="list-group-item list-group-item-action"
                        data-location='${this.escapeAttribute(JSON.stringify(location))}'
                        role="option">
                    <div>
                        <strong>${this.escapeHtml(location.city || location.display)}</strong>
                        <br>
                        <small class="text-muted">${this.escapeHtml(location.display)}</small>
                    </div>
                </button>
            `).join('');

            // Attach click handlers
            resultsContainer.querySelectorAll('.list-group-item').forEach(item => {
                item.addEventListener('click', () => {
                    const location = JSON.parse(item.dataset.location);
                    this.selectLocation(location);
                });
            });

            this.currentFocusIndex = -1;
            this.showResults();
            input.setAttribute('aria-expanded', 'true');
        }

        /**
         * Handle GPS button click
         */
        async handleGPSClick() {
            if (!navigator.geolocation) {
                this.showError('This browser cannot share your location. Type your town or city in the Location box and select it from the list.');
                // A webview lacking the Geolocation API entirely is a real
                // registration blocker (location is required) — report it the
                // same as any other GPS failure rather than returning silently.
                this.callOnGPSError({ code: 0, message: 'geolocation_unsupported' });
                return;
            }

            const btn = document.getElementById(`${this.options.containerId}-gps-btn`);
            const originalText = btn.innerHTML;

            // Update button state
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';

            this.hideError();

            try {
                const position = await this.getCurrentPosition();
                await this.reverseGeocode(position.coords.latitude, position.coords.longitude);
            } catch (error) {
                console.error('GPS error:', error);

                // A GeolocationPositionError (raw permission/unavailable/timeout
                // failure) has a numeric .code and no specific message shown yet —
                // build one here. A rethrown reverseGeocode() failure has already
                // shown its own specific message (and no .code), so don't
                // overwrite it with the generic fallback text.
                if (typeof error.code === 'number') {
                    const fallback = ' Type your town or city in the Location box and select it from the list.';
                    let message = 'We could not get your location.';
                    if (error.code === 1) {
                        message = 'Location access is off.';
                    } else if (error.code === 2) {
                        message = 'We could not find your location.';
                    } else if (error.code === 3) {
                        message = 'Finding your location took too long.';
                    }
                    this.showError(message + fallback);
                }

                this.callOnGPSError(error);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }

        /**
         * Invoke the caller-supplied onGPSError callback, if any, guarded
         * against it throwing — this is caller-supplied code (see
         * onGPSError's own doc comment on the constructor above), and a
         * throw here must not escape into a new, differently-shaped
         * unhandled rejection on top of the GPS failure it was invoked to
         * report.
         *
         * On the join page today, this callback's only job is calling
         * window.elanReportJoinFailure() (join.php's onGPSError), whose own
         * body cannot throw synchronously (see app/assets/js/
         * join-form-beacon.js — its only calls are document.querySelector,
         * URLSearchParams, and fetch(), none of which throw for this
         * input). If a throw ever DOES reach here, it degrades to a
         * console.error only — no server-side trace — which is an accepted
         * gap given the above, not a live path #1690 needs to guard against
         * today. If onGPSError is ever changed to do something riskier,
         * revisit whether console.error alone is still sufficient.
         */
        callOnGPSError(error) {
            if (typeof this.options.onGPSError !== 'function') {
                return;
            }
            try {
                this.options.onGPSError(error);
            } catch (callbackError) {
                console.error('LocationPicker: onGPSError callback threw', callbackError);
            }
        }

        /**
         * Get current GPS position (promisified)
         */
        getCurrentPosition() {
            return new Promise((resolve, reject) => {
                navigator.geolocation.getCurrentPosition(resolve, reject, {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                });
            });
        }

        /**
         * Reverse geocode coordinates
         */
        async reverseGeocode(lat, lon) {
            this.showLoading(true);

            try {
                const response = await fetch(this.options.urlRoot + 'app/api/shared/location-reverse.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        lat: lat.toString(),
                        lon: lon.toString(),
                        csrf: this.options.csrfToken
                    })
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || 'Reverse geocoding failed');
                }

                // Select the location
                this.selectLocation(data.location);

            } catch (error) {
                console.error('Reverse geocoding error:', error);
                this.showError('We could not find a town for your location. Type your town or city in the Location box and select it from the list.');
                // Rethrow so the caller's own catch (handleGPSClick(), which reports
                // via onGPSError) sees this too — a reverse-geocode failure blocks
                // the required location field exactly like a raw geolocation error
                // does, and swallowing it here would leave it just as unreported
                // server-side as the original #1690 bug (#1690 follow-up hardening).
                throw error;
            } finally {
                this.showLoading(false);
            }
        }

        /**
         * Select a location
         */
        selectLocation(location) {
            this.selectedLocation = location;

            this.writeHiddenFields(location);

            // Update input field
            const input = this.getInput();
            input.value = location.display || `${location.city}, ${location.state}, ${location.country}`;
            this.syncValidity();
            // A value set in code fires no event. The form's own listeners (the
            // join form's validation alert) need one to see the field is now valid.
            input.dispatchEvent(new Event('change', { bubbles: true }));

            // Show selected location
            const selectedDiv = document.getElementById(`${this.options.containerId}-selected`);
            const selectedText = document.getElementById(`${this.options.containerId}-selected-text`);
            const coords = document.getElementById(`${this.options.containerId}-coords`);

            selectedText.textContent = location.display || `${location.city}, ${location.state}, ${location.country}`;
            coords.textContent = `${location.lat}, ${location.lon}`;
            selectedDiv.classList.remove('d-none');

            // Hide results
            this.hideResults();
            this.hideError();

            // Call callback
            if (typeof this.options.onSelect === 'function') {
                this.options.onSelect(location);
            }
        }

        /**
         * Show/hide loading spinner
         */
        showLoading(show) {
            const spinner = document.getElementById(`${this.options.containerId}-spinner`);
            if (spinner) {
                if (show) {
                    spinner.classList.remove('d-none');
                } else {
                    spinner.classList.add('d-none');
                }
            }
        }

        /**
         * Show results dropdown
         */
        showResults() {
            const results = document.getElementById(`${this.options.containerId}-results`);
            if (results) {
                results.style.display = 'block';
            }
        }

        /**
         * Hide results dropdown
         */
        hideResults() {
            const results = document.getElementById(`${this.options.containerId}-results`);
            const input = this.getInput();

            if (results) {
                results.style.display = 'none';
            }

            if (input) {
                input.setAttribute('aria-expanded', 'false');
            }

            this.currentFocusIndex = -1;
        }

        /**
         * Show error message
         */
        showError(message) {
            const errorDiv = document.getElementById(`${this.options.containerId}-error`);
            if (errorDiv) {
                errorDiv.textContent = message;
                errorDiv.classList.remove('d-none');
            }
        }

        /**
         * Hide error message
         */
        hideError() {
            const errorDiv = document.getElementById(`${this.options.containerId}-error`);
            if (errorDiv) {
                errorDiv.classList.add('d-none');
            }
        }

        /**
         * Set location programmatically (for pre-populating forms)
         *
         * @param {Object} location - Location object with city, state, country, lat, lon
         * @param {string} displayText - Display text for the location
         */
        setLocation(location, displayText) {
            // A pre-filled location counts as selected, so requireSelection does
            // not block a form that pre-fills.
            this.selectedLocation = location;
            this.writeHiddenFields(location);

            // Same text as the "Selected:" box, as selectLocation() does. An empty
            // input would stay valueMissing when required is on.
            const input = this.getInput();
            if (input) {
                input.value = displayText;
            }

            // Show selected location display
            const selectedDiv = document.getElementById(`${this.options.containerId}-selected`);
            const selectedText = document.getElementById(`${this.options.containerId}-selected-text`);
            const coordsText = document.getElementById(`${this.options.containerId}-coords`);

            if (selectedDiv && selectedText) {
                selectedText.textContent = displayText;
                selectedDiv.classList.remove('d-none');
            }

            if (coordsText && location.lat && location.lon) {
                coordsText.textContent = `${location.lat}, ${location.lon}`;
            }

            this.syncValidity();
        }

        /**
         * Clear the selected location, its hidden fields, and the "Selected:" box
         *
         * @returns {void}
         */
        clearSelection() {
            this.selectedLocation = null;
            this.writeHiddenFields(null);
            document.getElementById(`${this.options.containerId}-selected`).classList.add('d-none');
        }

        /**
         * Check if geolocation is available
         */
        isGeolocationAvailable() {
            return 'geolocation' in navigator;
        }

        /**
         * Get value from session storage cache
         */
        getSessionCache(key) {
            try {
                const cached = sessionStorage.getItem(key);
                return cached ? JSON.parse(cached) : null;
            } catch (_e) {
                return null;
            }
        }

        /**
         * Set value in session storage cache
         */
        setSessionCache(key, value) {
            try {
                sessionStorage.setItem(key, JSON.stringify(value));
            } catch (e) {
                // Session storage full or not available
                console.warn('SessionStorage not available:', e);
            }
        }

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Escape attribute value to prevent XSS
         */
        escapeAttribute(text) {
            return text
                .replace(/&/g, '&amp;')
                .replace(/'/g, '&#39;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }
    }

    // Export to global scope
    window.LocationPicker = LocationPicker;

})();
