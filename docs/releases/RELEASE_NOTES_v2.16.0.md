# Elan Registry v2.16.0 Release Notes

**Release Date:** February 2026
**Type:** Minor Release - Data Quality & Validation

## REQUIRED ACTIONS AFTER DEPLOYMENT

Run the following FIX script to populate the `car_models` reference table with Lotus Elan model data:

- `FIX/26-Load-Car-Models.php` - Loads 24 Lotus Elan model definitions (1963-1974)

Navigate to `/FIX/` admin page and run the script, or execute via CLI.

## User-Facing Changes

### New Features

- **Phase 2: CarModel UI Integration** ([#577](https://github.com/unibrain1/elanregistry/issues/577)):
  Dynamic, database-driven car model selection. Model dropdown now populates automatically based on selected year with AJAX.
  Replaces hardcoded JavaScript with database-driven approach for easier maintenance and better validation.

### Improvements

- **Model Validation** ([#577](https://github.com/unibrain1/elanregistry/issues/577)):
  Added server-side validation to prevent invalid model combinations. Form now rejects unsupported series/variant combinations.

- **Testing Infrastructure** ([various](https://github.com/unibrain1/elanregistry/pull/591)):
  Added 83 new tests (33 integration, 10 validation). Car transfer guide ([#579](https://github.com/unibrain1/elanregistry/issues/579)),
  car history color coding ([#578](https://github.com/unibrain1/elanregistry/issues/578)), and privacy statement updates ([#580](https://github.com/unibrain1/elanregistry/issues/580)).

### Bug Fixes

- **JavaScript Errors** ([#577](https://github.com/unibrain1/elanregistry/issues/577)): Fixed syntax error when adding new cars.
- **CSRF Error Responses** ([#585](https://github.com/unibrain1/elanregistry/issues/585), [#582](https://github.com/unibrain1/elanregistry/issues/582)):
  Standardized CSRF failures to return HTTP 403 with JSON instead of HTML.
- **API Error Standardization** ([#583](https://github.com/unibrain1/elanregistry/issues/583), [#581](https://github.com/unibrain1/elanregistry/issues/581)):
  Unified error response format across all AJAX endpoints.

## Technical Changes

- **CarModel Reference Class** ([#577](https://github.com/unibrain1/elanregistry/issues/577)):
  New read-only class for querying model definitions from `car_models` database table.
  Provides 7 query methods for year filtering, series lookup, and model validation.

- **AJAX Model Endpoint** ([#577](https://github.com/unibrain1/elanregistry/issues/577)):
  New `/app/cars/actions/get-models.php` endpoint with CSRF validation and AJAX-only protection.
  Returns models grouped by year with client-side caching for performance.

- **PHP Code Quality** ([#575](https://github.com/unibrain1/elanregistry/issues/575)):
  Added return type declarations to 9 functions in FIX scripts. Status: 49 files checked, 0 errors, 100% compliant.

- **Server Globals Modernization** ([#575](https://github.com/unibrain1/elanregistry/issues/575)):
  Replaced direct `$_SERVER` access with project globals (`$php_self`, `$method`, `$is_https`).

- **ESLint Fixes** ([#575](https://github.com/unibrain1/elanregistry/issues/575)):
  Fixed 26 ESLint warnings in JavaScript files. All code now passes validation with 0 errors.

- **Test Infrastructure** ([#577](https://github.com/unibrain1/elanregistry/issues/577)):
  Auto-loading database fixtures in integration test bootstrap. Mock CarModel for fast unit tests with 9 valid combinations.

- **Development Guidelines** ([#575](https://github.com/unibrain1/elanregistry/issues/575)):
  Updated CLAUDE.md to require software-developer agent for all code work. Added security review requirement.

## Issues Resolved

- [#575](https://github.com/unibrain1/elanregistry/issues/575) — Fix code quality violations
- [#577](https://github.com/unibrain1/elanregistry/issues/577) — Create car_models database table and CarModel reference class
- [#578](https://github.com/unibrain1/elanregistry/issues/578) — Car history color coding information
- [#579](https://github.com/unibrain1/elanregistry/issues/579) — Car transfer guide and FAQ
- [#580](https://github.com/unibrain1/elanregistry/issues/580) — Privacy statement updates
- [#581](https://github.com/unibrain1/elanregistry/issues/581) — Standardize Registry Link AJAX endpoint path and add CSRF token
- [#582](https://github.com/unibrain1/elanregistry/issues/582) — Return JSON instead of HTML on CSRF token failures
- [#583](https://github.com/unibrain1/elanregistry/issues/583) — Standardize getDataTables.php error responses to ApiResponse Pattern A
- [#585](https://github.com/unibrain1/elanregistry/issues/585) — Standardize CSRF error responses to HTTP 403 Forbidden

## Summary

9 issues resolved across Phase 2 CarModel integration, code quality standards, and CSRF error handling.
38 commits merged with 83 new tests, 100% PHP code compliance, and 100% backward compatibility with v2.15.2.
Key improvements: dynamic model loading, unified error handling, enhanced test infrastructure, and modernized development guidelines.
