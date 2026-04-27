-- FIX #405: Remove database-driven CDN configuration columns
-- Run once on each deployed environment after deploying the #405 code changes.
ALTER TABLE settings
    DROP COLUMN elan_jquery_cdn,
    DROP COLUMN elan_jquery_ui_cdn,
    DROP COLUMN elan_bootstrap_css_cdn,
    DROP COLUMN elan_bootstrap_js_cdn,
    DROP COLUMN elan_popper_cdn,
    DROP COLUMN elan_bootswatch_cdn,
    DROP COLUMN elan_fontawesome_cdn,
    DROP COLUMN elan_datatables_js_cdn,
    DROP COLUMN elan_datatables_css_cdn,
    DROP COLUMN elan_datepicker_js_cdn,
    DROP COLUMN elan_datepicker_css_cdn,
    DROP COLUMN elan_dropzone_js_cdn,
    DROP COLUMN elan_dropzone_css_cdn,
    DROP COLUMN elan_chartjs_cdn;
