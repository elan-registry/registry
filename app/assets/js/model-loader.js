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
 * - Falls back gracefully if API fails
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
         * Load all models from server (called once, then cached)
         * @returns {Promise<Object>} Model data grouped by year
         */
        loadAllModels: async function() {
            if (this.modelCache) {
                return this.modelCache;
            }

            try {
                const api = new ElanRegistryAPI();
                const response = await api.post(this.apiUrl, {
                    action: 'getAllYearModels'
                });

                if (response.success && response.yearModels) {
                    this.modelCache = response.yearModels;
                    return this.modelCache;
                } else {
                    console.error('Failed to load models:', response.message);
                    return {};
                }

            } catch (error) {
                console.error('Error loading models:', error);
                return {};
            }
        },

        /**
         * Populate model dropdown for a specific year
         * @param {string|number} year - Year (1963-1974)
         * @param {jQuery} $modelSelect - jQuery model dropdown element
         * @returns {Promise<void>}
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
        },

        /**
         * Get model by value (for validation)
         * @param {string} modelValue - Model value like "S4|FHC|36"
         * @returns {Promise<Object|null>} Model object or null if not found
         */
        getModelByValue: async function(modelValue) {
            const models = await this.loadAllModels();

            // Search through all years
            for (const year in models) {
                const found = models[year].find(m => m.value === modelValue);
                if (found) {
                    return found;
                }
            }

            return null;
        }
    };

    // Expose to global scope
    window.ModelLoader = ModelLoader;

})(window);
