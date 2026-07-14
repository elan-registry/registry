/**
 * ESLint Configuration for Elan Registry
 *
 * Minimal configuration focused on catching real bugs without
 * being overly strict on style preferences.
 *
 * Philosophy:
 * - Catch undefined variables and typos
 * - Flag unused variables (likely dead code)
 * - Enforce safe equality checks
 * - Don't nitpick style - that's what code review is for
 *
 * Usage:
 *   npm run lint          # Check all project JS
 *   npm run lint:fix      # Auto-fix where possible
 */

export default [
    {
        // Global ignores
        ignores: [
            "**/vendor/**",
            "**/node_modules/**",
            "**/users/**",           // UserSpice upstream framework - don't lint
            "**/*.min.js",           // Minified files
            "**/test-results/**",
            "**/playwright-report/**",
        ],
    },
    {
        // JavaScript files in app directory
        files: ["app/**/*.js"],
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: "script",  // Most files are traditional scripts, not modules
            globals: {
                // Browser globals
                window: "readonly",
                document: "readonly",
                console: "readonly",
                alert: "readonly",
                confirm: "readonly",
                setTimeout: "readonly",
                setInterval: "readonly",
                clearTimeout: "readonly",
                clearInterval: "readonly",
                fetch: "readonly",
                FormData: "readonly",
                URLSearchParams: "readonly",
                AbortController: "readonly",
                localStorage: "readonly",
                sessionStorage: "readonly",
                navigator: "readonly",
                location: "readonly",
                history: "readonly",
                Event: "readonly",
                CustomEvent: "readonly",
                MouseEvent: "readonly",
                KeyboardEvent: "readonly",
                HTMLElement: "readonly",
                NodeList: "readonly",

                // jQuery (used throughout the app)
                $: "readonly",
                jQuery: "readonly",

                // Bootstrap
                bootstrap: "readonly",

                // DataTables
                DataTable: "readonly",

                // Chart.js
                Chart: "readonly",

                // MapLibre GL JS (self-hosted, loaded via <script> tag)
                maplibregl: "readonly",

                // ElanRegistry custom globals
                ElanRegistryAPI: "readonly",
                NotificationHelper: "readonly",
                ApiError: "readonly",
                ApiValidationError: "readonly",
                ApiCancelledError: "readonly",

                // Additional browser APIs
                URL: "readonly",
                File: "readonly",
                Blob: "readonly",
                IntersectionObserver: "readonly",
                MutationObserver: "readonly",
                ResizeObserver: "readonly",
                requestAnimationFrame: "readonly",
                cancelAnimationFrame: "readonly",
                getComputedStyle: "readonly",
                matchMedia: "readonly",
                performance: "readonly",
                Image: "readonly",
                DOMParser: "readonly",
                XMLSerializer: "readonly",
                atob: "readonly",
                btoa: "readonly",
                TextEncoder: "readonly",
                TextDecoder: "readonly",
                Option: "readonly",
                Audio: "readonly",
                Worker: "readonly",
                Notification: "readonly",
                WebSocket: "readonly",
                EventSource: "readonly",

                // Page-specific config objects (injected by PHP)
                carDetailsConfig: "readonly",
                pageConfig: "readonly",
                ELAN_CONFIG: "readonly",
                img_path: "writable",
                img_root: "readonly",

                // Admin panel globals (defined in manage-consolidated.js)
                showNotification: "readonly",

                // Standard browser globals
                event: "readonly",
            },
        },
        rules: {
            // =================================================================
            // ERROR LEVEL - These catch real bugs
            // =================================================================

            // Catch undefined variables (typos, missing imports)
            "no-undef": "error",

            // Warn about reassignment of function parameters
            // Note: Legacy codebase has this pattern in some places
            "no-param-reassign": ["warn", { props: false }],

            // Warn about global function declarations
            // Note: Legacy codebase uses global functions called from HTML onclick handlers
            "no-implicit-globals": "warn",

            // Catch unreachable code
            "no-unreachable": "error",

            // Catch constant conditions in loops (likely bugs)
            "no-constant-condition": ["error", { checkLoops: true }],

            // =================================================================
            // WARNING LEVEL - Likely issues but may have valid uses
            // =================================================================

            // Unused variables (likely dead code or typos)
            "no-unused-vars": ["warn", {
                vars: "all",
                args: "none",  // Don't warn about unused function args
                caughtErrors: "all",
                caughtErrorsIgnorePattern: "^_",  // Allow _unused naming convention in catch
                ignoreRestSiblings: true,
                varsIgnorePattern: "^_",  // Allow _unused naming convention
            }],

            // Prefer === over == (safer comparisons)
            "eqeqeq": ["warn", "smart"],  // "smart" allows == for null checks

            // Warn about console.log (OK for debugging, remove for prod)
            "no-console": ["warn", {
                allow: ["warn", "error"],  // Allow console.warn/error
            }],

            // =================================================================
            // OFF - Style preferences handled by code review, not linting
            // =================================================================

            // These are style choices, not bugs:
            "semi": "off",
            "quotes": "off",
            "indent": "off",
            "comma-dangle": "off",
            "no-trailing-spaces": "off",
            "space-before-function-paren": "off",
            "object-curly-spacing": "off",
            "array-bracket-spacing": "off",
            "no-multiple-empty-lines": "off",
            "padded-blocks": "off",
            "brace-style": "off",
            "keyword-spacing": "off",
        },
    },
    {
        // Node.js scripts and Playwright config files.
        // These also include browser globals because Playwright auth-setup
        // scripts use page.waitForFunction(), whose predicate runs in browser
        // scope and accesses window.location.
        files: ["scripts/*.js", "playwright.config*.js"],
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: "commonjs",
            globals: {
                // Node.js
                require: "readonly",
                module: "writable",
                exports: "writable",
                __dirname: "readonly",
                __filename: "readonly",
                process: "readonly",
                console: "readonly",
                Buffer: "readonly",
                setTimeout: "readonly",
                clearTimeout: "readonly",
                Promise: "readonly",
                URL: "readonly",
                // Browser globals (accessed inside page.evaluate() callbacks)
                window: "readonly",
                document: "readonly",
            },
        },
        rules: {
            "no-undef": "error",
            "no-unused-vars": ["warn", {
                vars: "all",
                args: "none",
                caughtErrors: "all",
                caughtErrorsIgnorePattern: "^_",
                varsIgnorePattern: "^_",
            }],
            "no-console": "off",
            "no-unreachable": "error",
        },
    },
    {
        // Playwright test files — specs and helpers.
        //
        // These files are Node.js modules but frequently call page.evaluate()
        // whose callbacks run inside the browser. Browser globals ($, window,
        // document, etc.) therefore appear as bare identifiers even though the
        // surrounding file is Node.js. We declare them here so ESLint can still
        // catch real undefined-variable bugs without flagging page.evaluate()
        // browser references as false positives.
        files: ["tests/**/*.js", "**/*.test.js", "**/*.spec.js"],
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: "commonjs",
            globals: {
                // Node.js
                require: "readonly",
                module: "writable",
                exports: "writable",
                __dirname: "readonly",
                process: "readonly",
                console: "readonly",
                URL: "readonly",
                setTimeout: "readonly",
                clearTimeout: "readonly",
                Promise: "readonly",
                Buffer: "readonly",
                // Playwright test runner
                test: "readonly",
                expect: "readonly",
                describe: "readonly",
                beforeEach: "readonly",
                afterEach: "readonly",
                beforeAll: "readonly",
                afterAll: "readonly",
                page: "readonly",
                browser: "readonly",
                context: "readonly",
                // Browser globals used inside page.evaluate() callbacks
                window: "readonly",
                document: "readonly",
                alert: "readonly",
                confirm: "readonly",
                fetch: "readonly",
                FormData: "readonly",
                URLSearchParams: "readonly",
                localStorage: "readonly",
                sessionStorage: "readonly",
                navigator: "readonly",
                location: "readonly",
                history: "readonly",
                Event: "readonly",
                CustomEvent: "readonly",
                HTMLElement: "readonly",
                NodeList: "readonly",
                Blob: "readonly",
                File: "readonly",
                Image: "readonly",
                DOMParser: "readonly",
                atob: "readonly",
                btoa: "readonly",
                getComputedStyle: "readonly",
                requestAnimationFrame: "readonly",
                performance: "readonly",
                MutationObserver: "readonly",
                IntersectionObserver: "readonly",
                // jQuery (used in page.evaluate() on pages that load jQuery)
                $: "readonly",
                jQuery: "readonly",
                // Bootstrap JS (used in page.evaluate() on pages that load Bootstrap)
                bootstrap: "readonly",
                // DataTables (used in page.evaluate() for XSS renderer tests)
                DataTable: "readonly",
                // Page-level globals injected by PHP into the browser page
                NEW_CAR_IDS: "readonly",
                escapeHtml: "readonly",
                ELAN_CONFIG: "readonly",
                img_root: "readonly",
                carDetailsConfig: "readonly",
                pageConfig: "readonly",
                ElanRegistryAPI: "readonly",
                us_url_root: "readonly",
                csrf: "readonly",
            },
        },
        rules: {
            "no-undef": "error",
            "no-unused-vars": ["warn", {
                vars: "all",
                args: "none",
                caughtErrors: "all",
                caughtErrorsIgnorePattern: "^_",
                varsIgnorePattern: "^_",
            }],
            "no-console": "off",
            "no-unreachable": "error",
        },
    },
];
