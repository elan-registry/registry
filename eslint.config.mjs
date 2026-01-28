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
 *   npx eslint app/js/            # Check JS files
 *   npx eslint --fix app/js/      # Auto-fix where possible
 */

export default [
    {
        // Global ignores
        ignores: [
            "**/vendor/**",
            "**/node_modules/**",
            "**/users/**",           // UserSpice core - don't lint
            "**/*.min.js",           // Minified files
            "**/test-results/**",
            "**/playwright-report/**",
        ],
    },
    {
        // JavaScript files in app directory
        files: ["app/**/*.js", "usersc/**/*.js"],
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

                // Google Maps
                google: "readonly",
                MarkerClusterer: "readonly",

                // ElanRegistry custom globals
                ElanRegistryAPI: "readonly",
                NotificationHelper: "readonly",
                ApiError: "readonly",
                ApiValidationError: "readonly",
                ApiCancelledError: "readonly",
            },
        },
        rules: {
            // =================================================================
            // ERROR LEVEL - These catch real bugs
            // =================================================================

            // Catch undefined variables (typos, missing imports)
            "no-undef": "error",

            // Catch accidental reassignment of function parameters
            "no-param-reassign": ["error", { props: false }],

            // Prevent accidental global variable creation
            "no-implicit-globals": "error",

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
        // Test files - more lenient
        files: ["tests/**/*.js", "**/*.test.js", "**/*.spec.js"],
        languageOptions: {
            globals: {
                // Playwright globals
                test: "readonly",
                expect: "readonly",
                describe: "readonly",
                beforeEach: "readonly",
                afterEach: "readonly",
                beforeAll: "readonly",
                afterAll: "readonly",
                page: "readonly",
            },
        },
        rules: {
            // Allow console in tests
            "no-console": "off",
        },
    },
];
