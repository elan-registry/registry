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
         * @param {boolean} options.showGPS Show GPS button (default: true)
         * @param {boolean} options.required Is location required (default: true)
         */
        constructor(options) {
            this.options = Object.assign({
                containerId: 'location-picker-container',
                csrfToken: '',
                urlRoot: '/',
                onSelect: null,
                showGPS: true,
                required: true
            }, options);

            this.container = document.getElementById(this.options.containerId);
            if (!this.container) {
                console.error('LocationPicker: Container element not found:', this.options.containerId);
                return;
            }

            this.debounceTimer = null;
            this.selectedLocation = null;
            this.currentFocusIndex = -1;

            this.init();
        }

        /**
         * Initialize the location picker
         */
        init() {
            this.render();
            this.attachEventListeners();
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

            this.container.innerHTML = `
                ${gpsButton}

                <div class="form-group position-relative">
                    <label for="${this.options.containerId}-input">
                        Location ${this.options.required ? '<span class="text-danger">*</span>' : ''}
                    </label>
                    <div class="input-group">
                        <input type="text"
                               class="form-control"
                               id="${this.options.containerId}-input"
                               placeholder="Start typing city, state, or country..."
                               autocomplete="off"
                               ${this.options.required ? 'required' : ''}
                               aria-label="Search location"
                               aria-autocomplete="list"
                               aria-controls="${this.options.containerId}-results"
                               aria-expanded="false">
                        <div class="input-group-append d-none" id="${this.options.containerId}-spinner">
                            <span class="input-group-text">
                                <i class="fas fa-spinner fa-spin"></i>
                            </span>
                        </div>
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
            const input = document.getElementById(`${this.options.containerId}-input`);
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

            try {
                const response = await fetch(this.options.urlRoot + 'app/action/location-search.php', {
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
                this.showError('Unable to search locations. Please try again.');
            } finally {
                this.showLoading(false);
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

            // Remove duplicates (same city + country)
            const seen = new Set();
            const unique = scored.filter(item => {
                const key = `${item.location.city}|${item.location.country}`.toLowerCase();
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
            const input = document.getElementById(`${this.options.containerId}-input`);

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
                this.showError('Geolocation is not supported by your browser');
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

                let message = 'Unable to get your location. ';
                if (error.code === 1) {
                    message += 'Please enable location permissions.';
                } else if (error.code === 2) {
                    message += 'Location unavailable.';
                } else if (error.code === 3) {
                    message += 'Request timed out.';
                } else {
                    message += 'Please try manual search instead.';
                }

                this.showError(message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
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
                const response = await fetch(this.options.urlRoot + 'app/action/location-reverse.php', {
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
                this.showError('Unable to determine address from GPS coordinates. Please try manual search.');
            } finally {
                this.showLoading(false);
            }
        }

        /**
         * Select a location
         */
        selectLocation(location) {
            this.selectedLocation = location;

            // Populate hidden fields
            document.getElementById(`${this.options.containerId}-city`).value = location.city || '';
            document.getElementById(`${this.options.containerId}-state`).value = location.state || '';
            document.getElementById(`${this.options.containerId}-country`).value = location.country || '';
            document.getElementById(`${this.options.containerId}-lat`).value = location.lat || '';
            document.getElementById(`${this.options.containerId}-lon`).value = location.lon || '';

            // Update input field
            const input = document.getElementById(`${this.options.containerId}-input`);
            input.value = location.display || `${location.city}, ${location.state}, ${location.country}`;

            // Show selected location
            const selectedDiv = document.getElementById(`${this.options.containerId}-selected`);
            const selectedText = document.getElementById(`${this.options.containerId}-selected-text`);
            const coords = document.getElementById(`${this.options.containerId}-coords`);

            selectedText.textContent = location.display || `${location.city}, ${location.state}, ${location.country}`;
            coords.textContent = `${location.lat}, ${location.lon}`;
            selectedDiv.classList.remove('d-none');

            // Hide results
            this.hideResults();

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
            const input = document.getElementById(`${this.options.containerId}-input`);

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
            // Populate hidden fields
            const cityField = document.getElementById(`${this.options.containerId}-city`);
            const stateField = document.getElementById(`${this.options.containerId}-state`);
            const countryField = document.getElementById(`${this.options.containerId}-country`);
            const latField = document.getElementById(`${this.options.containerId}-lat`);
            const lonField = document.getElementById(`${this.options.containerId}-lon`);

            if (cityField) cityField.value = location.city || '';
            if (stateField) stateField.value = location.state || '';
            if (countryField) countryField.value = location.country || '';
            if (latField) latField.value = location.lat || '';
            if (lonField) lonField.value = location.lon || '';

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
