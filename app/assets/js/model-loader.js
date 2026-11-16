/**
 * Model Loader Module
 *
 * Dynamically loads car model data from server instead of hardcoded JavaScript.
 * Replaces cardefinition.js with API-driven model selection.
 *
 * Features:
 * - Lazy loads all models on first use (cached client-side)
 * - Populates model dropdown based on selected year
 * - Compatible with existing form structure
 * - Rejects on an API failure, so the caller can show an error and a retry
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

/* global ElanRegistryAPI */
/* exported ModelLoader */

(function(window) {
    'use strict';

    const ModelLoader = {
        // Cache for model data to avoid repeated API calls
        modelCache: null,

        // API endpoints
        apiUrl: null, // Set during initialization

        /**
         * Initialize model loader with API URL
         * @param {string} apiUrl - Full URL to models.php endpoint (app/api/cars/models.php)
         */
        init: function(apiUrl) {
            this.apiUrl = apiUrl;
        },

        /**
         * Load all models from server (cached after the first success)
         *
         * A failure is not cached, so the next call asks the server again.
         *
         * @returns {Promise<Object>} Model data grouped by year
         * @throws {Error} When the request fails or the response has no model data
         */
        loadAllModels: async function() {
            if (this.modelCache) {
                return this.modelCache;
            }

            const api = new ElanRegistryAPI();
            const response = await api.post(this.apiUrl, {
                action: 'getAllYearModels'
            });

            if (!response.success || !response.yearModels) {
                throw new Error('Failed to load models: ' + (response.message || 'no model data'));
            }

            this.modelCache = response.yearModels;
            return this.modelCache;
        },

        /**
         * Populate model dropdown for a specific year
         * @param {string|number} year - Year (1963-1974)
         * @param {jQuery} $modelSelect - jQuery model dropdown element
         * @returns {Promise<void>}
         * @throws {Error} When loadAllModels() fails; the dropdown is not changed
         */
        populateModelDropdown: async function(year, $modelSelect) {
            if (!year || !$modelSelect || $modelSelect.length === 0) {
                return;
            }

            // Load model data (cached after first call)
            const models = await this.loadAllModels();

            // Clear existing options except placeholder
            $modelSelect.find('option:not(:first)').remove();

            // Get models for selected year
            const yearModels = models[year] || [];

            // Add model options
            yearModels.forEach(function(model) {
                $modelSelect.append(new Option(model.text, model.value));
            });

            // Enable/disable dropdown based on availability
            $modelSelect.prop('disabled', yearModels.length === 0);

            // Trigger change event for validation
            $modelSelect.trigger('change');
        }
    };

    // Expose to global scope
    window.ModelLoader = ModelLoader;

})(window);
