-- ==================================================================
-- ELAN REGISTRY - ESSENTIAL CONFIGURATION SCRIPT
-- ==================================================================
-- This script configures a fresh UserSpice 5.9 installation with
-- essential Elan Registry settings and configurations
-- Run AFTER 1-schema.sql and 2-reference-data.sql
-- ==================================================================

-- ==================================================================
-- 1. UPDATE CORE SETTINGS TABLE WITH ELAN REGISTRY CONFIGURATION
-- ==================================================================

-- Add new Elan Registry specific columns to settings table
-- Note: These columns are already added by 1-schema.sql, this updates values

UPDATE settings SET
  -- Basic Site Configuration
  site_name = 'Lotus Elan Registry',
  recaptcha = 0,
  req_cap = 1,
  req_num = 1,
  copyright = 'Lotus Elan Registry and UniBrain',
  template = 'ElanRegistry',
  show_tos = 0,

  -- User Management & Authentication
  auto_assign_un = 1,
  permission_restriction = 1,
  session_manager = 1,
  reset_vericode_expiry = 90,
  email_login = 2,

  -- System Administration
  backup_dest = 'backup/',
  cron_ip = '::1',
  container_open_class = 'container',
  redirect_uri_after_login = 'users/account.php',
  
  -- Enhanced Widgets (add Elan Registry specific widgets)
  widgets = 'settings,misc,tools,plugins,snapshot,active-users,a_quick_access,dbclean,logins,permissions,t_logs,u_security_logs',
  
  -- CSS Framework Configuration
  us_css1 = '../users/css/color_schemes/muted.css',
  us_css2 = '../users/css/datatables.css',
  us_css3 = '../usersc/css/custom.css',
  
  -- System Configuration Defaults
  elan_backup_age = 1,
  elan_image_dir = 'userimages/',
  elan_image_max = 6,
  
  -- CDN Resource Management (Production URLs with integrity hashes)
  -- NOTE: CDN values are HTML-encoded because template uses html_entity_decode()
  elan_jquery_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js&quot;&gt;&lt;/script&gt;',
  elan_bootstrap_js_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js&quot; integrity=&quot;sha384-ho+j7jyWK8fNQe+A12Hb8AhRq26LrZ/JpcUGGOn+Y7RsweNrtN/tE3MoK7ZeZDyx&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;',
  elan_bootstrap_css_cdn = '&lt;link rel=&quot;stylesheet&quot; href=&quot;https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css&quot; integrity=&quot;sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2&quot; crossorigin=&quot;anonymous&quot;&gt;',
  elan_popper_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js&quot; integrity=&quot;sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;',
  elan_fontawesome_cdn = '&lt;script src=&quot;https://kit.fontawesome.com/2d8f489b15.js&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;',
  elan_bootswatch_cdn = '&lt;!-- Bootswatch --&gt; &lt;link rel=&quot;stylesheet&quot; href=&quot;https://cdnjs.cloudflare.com/ajax/libs/bootswatch/4.6.0/simplex/bootstrap.min.css&quot; integrity=&quot;sha512-9hj+qhrmo7MUSzKG3nwkDWncL1x8e2d1wfJxufofoBMMLXlqlqvjpT0V0blusJ8CFx9fs9Ru7ICYkVrz62Q33w==&quot; crossorigin=&quot;anonymous&quot; /&gt;',
  elan_datatables_js_cdn = '&lt;script type=&quot;text/javascript&quot; src=&quot;https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/rg-1.1.2/sc-2.0.3/sb-1.0.1/sp-1.2.2/datatables.min.js&quot;&gt;&lt;/script&gt;',
  elan_datatables_css_cdn = '&lt;link rel=&quot;stylesheet&quot; type=&quot;text/css&quot; href=&quot;https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/rg-1.1.2/sc-2.0.3/sb-1.0.1/sp-1.2.2/datatables.min.css&quot; /&gt;',
  elan_datepicker_js_cdn = '&lt;script src=&quot;https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js&quot; integrity=&quot;sha512-T/tUfKSV1bihCnd+MxKD0Hm1uBBroVYBOYSk1knyvQ9VyZJpc/ALb4P0r6ubwVPSGB2GvjeoMAJJImBG12TiaQ==&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;',
  elan_datepicker_css_cdn = '&lt;link rel=&quot;stylesheet&quot; href=&quot;https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css&quot; integrity=&quot;sha512-mSYUmp1HYZDFaVKK//63EcZq4iFWFjxSL+Z3T/aCt4IO9Cejm03q3NKKYN6pFQzY0SBOr8h+eCIAZHPXcpZaNw==&quot; crossorigin=&quot;anonymous&quot; /&gt;',
  elan_jquery_ui_cdn = '&lt;script src=&quot;https://code.jquery.com/ui/1.12.1/jquery-ui.min.js&quot; integrity=&quot;sha256-VazP97ZCwtekAsvgPBSUwPFKdrwD3unUfSGVYrahUqU=&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;',
  elan_dropzone_js_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/dropzone@5.7.6/dist/min/dropzone.min.js&quot; integrity=&quot;sha256-jj9KUPHT4VOIR8ZhUcJB66aiEAwt+eLk+10MeVKSbio=&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;',
  elan_dropzone_css_cdn = '&lt;link rel=&quot;stylesheet&quot; href=&quot;https://cdn.jsdelivr.net/npm/dropzone@5.7.6/dist/min/dropzone.min.css&quot; integrity=&quot;sha256-n/Cuyrm+v15Nim0mJ2ZrElHlCk8raJs/57WeCsIzDr4=&quot; crossorigin=&quot;anonymous&quot;&gt;',
  
  -- SPAM Cleanup System Configuration (Development settings)
  elan_spam_cleanup_enabled = 1,        -- Enabled for testing
  elan_spam_cleanup_dry_run = 1,        -- Dry-run mode for safety
  elan_spam_inactive_days = 2,          -- Short period for testing
  elan_spam_grace_period_days = 1,      -- Short grace period for testing
  elan_spam_max_deletions = 20,         -- Maximum 20 deletions per run
  elan_spam_max_percentage = 5.00,      -- Maximum 5% of users per run
  elan_spam_email_notifications = 1,    -- Email notifications enabled

  -- Image Upload & Display Configuration
  elan_image_upload_max_size = 3.00,    -- Maximum upload size in MB
  elan_image_display_max_size = 2048,   -- Maximum display width in pixels
  elan_image_thumbnail_sizes = '100,300,768,1024,2048',  -- Responsive thumbnail sizes

  -- Chart.js CDN for Statistics (v4.4.0) - Full script tag with SRI hash
  elan_chartjs_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js&quot; integrity=&quot;sha384-FcQlsUOd0TJjROrBxhJdUhXTUgNJQxTMcxZe6nHbaEfFL1zjQ+bq/uRoBQxb0KMo&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;',

  -- Administrative Notification Emails
  elan_admin_emails = 'registrar@elanregistry.org'  -- Comma-separated admin emails for system notifications

WHERE id = 1;

-- ==================================================================
-- 2. CREATE ADDITIONAL PERMISSION LEVEL
-- ==================================================================

-- Add Editor permission level (between User and Administrator)
INSERT INTO permissions (id, name, descrip)
VALUES (3, 'Editor', '')
ON DUPLICATE KEY UPDATE
  name = 'Editor',
  descrip = '';

-- ==================================================================  
-- 3. PLACEHOLDER CONFIGURATIONS (REQUIRE MANUAL SETUP)
-- ==================================================================

-- NOTE: These settings require manual configuration with actual API keys and credentials

-- Google API Keys (PLACEHOLDER - Replace with actual keys)
UPDATE settings SET 
  elan_google_maps_key = '[GOOGLE_MAPS_API_KEY_REQUIRED]',
  elan_google_geo_key = '[GOOGLE_GEOCODING_API_KEY_REQUIRED]'
WHERE id = 1;

-- NOTE: reCAPTCHA configuration removed - plugin is optional
-- If you install reCAPTCHA plugin later, configure keys via Admin Panel → Plugin Manager

-- ==================================================================
-- 4. PLUGIN CONFIGURATION
-- ==================================================================

-- Configure Plugin Hooks (includes reCAPTCHA, autoassignun, hooker, getsettings)
INSERT INTO `us_plugin_hooks` (`id`, `page`, `folder`, `position`, `hook`, `disabled`) VALUES
(10, 'admin.php?view=user', 'hooker', 'form', 'hooks/user_form_hook.php', 0),
(12, 'login.php', 'getsettings', 'bottom', 'hooks/loginbottom.php', 0),
(13, 'login.php', 'recaptcha', 'post', 'hooks/loginpost.php', 0),
(14, 'login.php', 'recaptcha', 'bottom', 'hooks/loginbottom.php', 0),
(15, 'joinAttempt', 'recaptcha', 'body', 'hooks/joinattemptbody.php', 0),
(16, 'join.php', 'recaptcha', 'bottom', 'hooks/joinbottom.php', 0),
(17, 'forgot_password.php', 'recaptcha', 'post', 'hooks/forgotpasswordpost.php', 0),
(18, 'forgot_password.php', 'recaptcha', 'bottom', 'hooks/forgotpasswordbottom.php', 0),
(19, 'joinAttempt', 'autoassignun', 'body', 'hooks/username_replace.php', 0),
(20, 'join.php', 'autoassignun', 'bottom', 'hooks/username_field_removal.php', 0),
(23, 'account.php', 'hooker', 'body', 'hooks/account_body_hook.php', 0),
(24, 'account.php', 'hooker', 'bottom', 'hooks/account_bottom_hook.php', 0),
(25, 'admin.php?view=user', 'userspice_core', 'form', 'hooks/tags_admin_user_form.php', 0),
(26, 'admin.php?view=user', 'userspice_core', 'post', 'hooks/tags_admin_user_post.php', 0),
(27, 'login', 'recaptcha', 'form', 'hooks/loginform.php', 1),
(28, 'login', 'recaptcha', 'bottom', 'hooks/loginbottom.php', 1),
(29, 'login', 'recaptcha', 'post', 'hooks/loginpost.php', 1)
ON DUPLICATE KEY UPDATE
  page = VALUES(page),
  folder = VALUES(folder),
  position = VALUES(position),
  hook = VALUES(hook),
  disabled = VALUES(disabled);

-- Note: Plugin-specific settings are typically stored in individual plugin tables
-- The following may require manual setup:

-- Brevo/Sendinblue Plugin Configuration (OPTIONAL)
-- (Stored in plg_sendinblue table - requires manual API key setup)

-- reCAPTCHA Plugin Configuration (OPTIONAL)
-- (Configure via Admin Panel → Plugin Manager when plugin is activated)

-- Auto-assign Username Plugin (REQUIRED)
-- (Uses auto_assign_un setting configured above)



-- ==================================================================
-- 4. PAGE MANAGEMENT AND SECURITY CONFIGURATION
-- ==================================================================

-- Insert all Elan Registry pages with proper security settings
-- These pages are essential for the registry functionality

-- First, update existing UserSpice pages to match production settings
-- Note: Some UserSpice pages may be added automatically by plugins or updates

-- Insert all Elan Registry specific pages
INSERT INTO `pages` (`id`, `page`, `title`, `private`, `re_auth`, `core`) VALUES
(211, 'app/version.php', '', 0, 0, 0),
(218, 'app/privacy.php', NULL, 0, 0, 0),
(229, 'app/cars/index.php', NULL, 0, 0, 0),
(230, 'app/cars/details.php', NULL, 0, 0, 0),
(231, 'app/cars/edit.php', NULL, 1, 0, 0),
(232, 'app/cars/factory.php', NULL, 0, 0, 0),
(233, 'app/cars/identify.php', NULL, 0, 0, 0),
(235, 'app/cars/mapmarkers.xml.php', NULL, 0, 0, 0),
(236, 'app/contact/index.php', NULL, 1, 0, 0),
(237, 'app/contact/owner.php', NULL, 1, 0, 0),
(238, 'app/contact/send-feedback.php', NULL, 1, 0, 0),
(239, 'app/contact/send-owner-email.php', NULL, 1, 0, 0),
(240, 'app/reports/statistics.php', NULL, 0, 0, 0),
(241, 'app/cars/actions/check-chassis.php', NULL, 1, 0, 0),
(242, 'app/cars/actions/edit.php', NULL, 1, 0, 0),
(243, 'app/cars/actions/history.php', NULL, 1, 0, 0),
(261, 'app/cars/actions/validateChassis.php', NULL, 1, 0, 0),
(278, 'app/reports/api/statistics-data.php', NULL, 0, 0, 0),
(294, 'app/admin/manage-consolidated.php', 'Admin - Consolidated Management Interface', 1, 0, 0),
(295, 'app/cars/actions/request-transfer.php', NULL, 1, 0, 0),
(299, 'app/admin/includes/process-admin-contact.php', 'Admin - Process Contact Form', 1, 0, 0),
(300, 'app/admin/includes/tab-car_mgmt.php', 'Admin - Car Management Tab', 1, 0, 0),
(301, 'app/admin/includes/tab-cleanup.php', 'Admin - Cleanup Management Tab', 1, 0, 0),
(304, 'app/admin/includes/tab-placeholder.php', 'Admin - Tab Placeholder', 1, 0, 0),
(305, 'app/admin/includes/tab-settings.php', 'Admin - Settings Tab', 1, 0, 0),
(306, 'app/admin/includes/tab-system.php', 'Admin - System Management Tab', 1, 0, 0),
(309, 'app/admin/includes/load-owner-info.php', 'Admin - Load Owner Information', 1, 0, 0),
(310, 'app/admin/includes/load-owner-profile.php', 'Admin - Load Owner Profile', 1, 0, 0),
(311, 'app/admin/includes/process-owner-search.php', 'Admin - Owner Search Process', 1, 0, 0),
(312, 'app/admin/includes/process-owner-sync-location.php', 'Admin - Owner Location Sync', 1, 0, 0),
(313, 'app/admin/includes/process-owner-update.php', 'Admin - Owner Update Process', 1, 0, 0),
(314, 'app/admin/includes/tab-manage_cars.php', 'Admin - Manage Cars Tab', 1, 0, 0),
(315, 'app/admin/includes/tab-owner_mgmt.php', 'Admin - Owner Management Tab', 1, 0, 0),
(328, 'app/admin/verify/_email_template.php', NULL, 1, 0, 0),
(329, 'app/admin/verify/index.php', NULL, 1, 0, 0),
(330, 'app/admin/verify/send_email.php', NULL, 1, 0, 0),
(331, 'app/admin/verify/verify_car.php', NULL, 1, 0, 0),
(337, 'app/admin/includes/system/schema-operations.php', NULL, 1, 0, 0)
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  private = VALUES(private),
  re_auth = VALUES(re_auth),
  core = VALUES(core);

-- Insert permission-page relationships
-- Permission 1 = User, 2 = Administrator, 3 = Editor
INSERT IGNORE INTO `permission_page_matches` (`permission_id`, `page_id`) VALUES
-- User (permission_id = 1) - Standard user pages
(1, 3), (1, 24), (1, 81), (1, 106), (1, 231), (1, 236), (1, 237), (1, 238), (1, 239),
(1, 241), (1, 242), (1, 243), (1, 259), (1, 261), (1, 295),

-- Administrator (permission_id = 2) - Full admin access
(2, 68), (2, 157), (2, 256), (2, 280), (2, 283), (2, 294), (2, 299), (2, 300),
(2, 301), (2, 304), (2, 305), (2, 306), (2, 308), (2, 309), (2, 310), (2, 311),
(2, 312), (2, 313), (2, 314), (2, 315), (2, 317), (2, 327), (2, 331), (2, 336),

-- Editor (permission_id = 3) - Content editor access
(3, 3), (3, 4), (3, 157), (3, 256), (3, 283), (3, 294), (3, 299), (3, 300),
(3, 301), (3, 304), (3, 305), (3, 306), (3, 308), (3, 309), (3, 310), (3, 311),
(3, 312), (3, 313), (3, 314), (3, 315), (3, 317), (3, 327), (3, 328), (3, 329),
(3, 330), (3, 331), (3, 336);

-- ==================================================================
-- 5. MENU SYSTEM CONFIGURATION
-- ==================================================================

-- Configure the complete Elan Registry menu system
-- This creates a comprehensive navigation structure for the registry

-- Clear existing menus and rebuild with Elan Registry structure
-- ============================================================================
-- MENU SYSTEM CONFIGURATION - CLASSIC MENU SYSTEM
-- ============================================================================
-- 
-- Configure Classic Menu system used by ElanRegistry template (menus table)
--
-- Clear existing menus and rebuild with Elan Registry structure
DELETE FROM groups_menus WHERE group_id IN (0, 2, 3);
DELETE FROM menus; -- Remove ALL existing menus - complete replacement

-- Insert Elan Registry menu items (CLASSIC MENU SYSTEM)
INSERT INTO `menus` (`id`, `menu_title`, `parent`, `dropdown`, `logged_in`, `display_order`, `label`, `link`, `icon_class`) VALUES
-- Core UserSpice menu items (required for structure)
(2, 'main', -1, 1, 1, 140, 'Admin', '', 'fa fa-fw fa-cogs'),
(3, 'main', 43, 0, 1, 110, '{{username}}', 'users/account.php', 'fa fa-fw fa-user'),
(5, 'main', -1, 0, 0, 30, '{{register}}', 'users/join.php', 'fa fa-fw fa-plus-square'),
(6, 'main', -1, 0, 0, 90, '{{login}}', 'users/login.php', 'fa fa-fw fa-sign-in-alt'),
(9, 'main', 2, 0, 1, 60, '{{dashboard}}', 'users/admin.php', 'fa fa-fw fa-cogs'),
(15, 'main', 43, 0, 1, 1000, '{{hr}}', '', ''),
(16, 'main', 43, 0, 1, 99999, '{{logout}}', 'users/logout.php', 'fa fa-fw fa-sign-out'),
(17, 'main', -1, 0, 0, 0, '{{home}}', '', 'fa fa-fw fa-home'),
(25, 'main', -1, 0, 0, 20, 'List Cars', 'app/cars/index.php', 'fa fa-fw fa-car'),
(29, 'main', 27, 0, 1, 1, 'List Cars', 'app/list_cars.php', ''),
(31, 'main', 30, 1, 1, 99999, 'Dashboard', 'users/admin.php', ''),
(34, 'main', 30, 1, 1, 99999, '{{logout}}', 'users/logout.php', 'fa fa-fw fa-sign-out'),
(38, 'main', -1, 0, 1, 0, '{{home}}', '#', 'fa fa-fw fa-home'),
(39, 'main', -1, 0, 1, 20, 'List Cars', 'app/cars/index.php', 'fa fa-fw fa-car'),
(41, 'main', 61, 0, 0, 10, 'Identification Guide', 'app/cars/identify.php', 'fa fa-fw fa-binoculars'),
(42, 'main', -1, 0, 1, 30, 'Add Car', 'app/cars/edit.php', 'fa fa-fw fa-plus'),
(43, 'main', -1, 1, 1, 99999, '{{account}}', '#', 'fa fa-fw fa-user'),
(45, 'main', 2, 0, 1, 50, '{{hr}}', '#', ''),
(47, 'main', -1, 0, 1, 80, 'Feedback', 'app/contact/index.php', 'fa fa-fw fa-comments'),
(48, 'main', -1, 0, 0, 40, 'Statistics', 'app/reports/statistics.php', 'fa fa-fw fa-pie-chart'),
(49, 'main', -1, 0, 1, 40, 'Statistics', 'app/reports/statistics.php', 'fa fa-fw fa-pie-chart'),
(54, 'main', 61, 0, 0, 20, 'Factory Data', 'app/cars/factory.php', 'fa fa-fw fa-list-alt'),
(61, 'main', -1, 1, 0, 50, 'Technical Resources', '#', 'fa fa-fw fa-tools'),
(62, 'main', 61, 0, 1, 30, 'Reference Library - Tech Manuals', 'docs/reference-library.php', 'fa fa-fw fa-book'),
(63, 'main', -1, 0, 0, 70, 'Car Stories', 'docs/car-stories.php', 'fa fa-fw fa-book-open'),
(64, 'main', -1, 1, 1, 50, 'Technical Resources', '#', 'fa fa-fw fa-tools'),
(65, 'main', 64, 0, 1, 10, 'Identification Guide', 'app/cars/identify.php', 'fa fa-fw fa-binoculars'),
(66, 'main', 64, 0, 1, 20, 'Factory Data', 'app/cars/factory.php', 'fa fa-fw fa-list-alt'),
(67, 'main', 64, 0, 1, 30, 'Reference Library - Tech Manuals', 'docs/reference-library.php', 'fa fa-fw fa-book'),
(68, 'main', -1, 0, 1, 70, 'Car Stories', 'docs/car-stories.php', 'fa fa-fw fa-book-open'),
(71, 'main', 2, 0, 1, 1, 'Manage Registry', 'app/admin/manage-consolidated.php', 'fa fa-fw fa-car'),
(72, 'main', -1, 0, 0, 85, 'FAQ', 'docs/faq/index.php', 'fa fa-fw fa-question-circle'),
(73, 'main', -1, 0, 1, 85, 'FAQ', 'docs/faq/index.php', 'fa fa-fw fa-question-circle'),
(74, 'main', 2, 0, 1, 5, 'Admin Guide', 'docs/faq/admin/index.php', 'fa fa-fw fa-question-circle')
ON DUPLICATE KEY UPDATE
  menu_title = VALUES(menu_title),
  parent = VALUES(parent),
  dropdown = VALUES(dropdown),
  logged_in = VALUES(logged_in),
  display_order = VALUES(display_order),
  label = VALUES(label),
  link = VALUES(link),
  icon_class = VALUES(icon_class);

-- Configure menu permissions via groups_menus
-- group_id 0 = Public/All Users, 1 = Editors, 2 = Administrator, 3 = Editor
INSERT INTO `groups_menus` (`group_id`, `menu_id`) VALUES
-- Public/All Users (group_id = 0) - Available to everyone (logged in and out)
(0, 1), (0, 3), (0, 4), (0, 5), (0, 6), (0, 7), (0, 8), (0, 15), (0, 16),
(0, 17), (0, 18), (0, 19), (0, 20), (0, 21), (0, 22), (0, 24), (0, 25),
(0, 26), (0, 27), (0, 29), (0, 31), (0, 32), (0, 33), (0, 34), (0, 35),
(0, 36), (0, 37), (0, 38), (0, 39), (0, 40), (0, 41), (0, 42), (0, 43),
(0, 46), (0, 47), (0, 48), (0, 49), (0, 50), (0, 51), (0, 52), (0, 54),
(0, 55), (0, 58), (0, 59), (0, 61), (0, 62), (0, 63), (0, 64), (0, 65),
(0, 66), (0, 67), (0, 68), (0, 72), (0, 73),

-- Group 1 specific (if used)
(1, 70),

-- Administrator (group_id = 2) - Admin-only menus
(2, 2), (2, 9), (2, 10), (2, 11), (2, 12), (2, 13), (2, 14), (2, 30),
(2, 44), (2, 45), (2, 53), (2, 60), (2, 69), (2, 71), (2, 74),

-- Editor (group_id = 3) - Editor-level access
(3, 2), (3, 9), (3, 10), (3, 44), (3, 45), (3, 53), (3, 60), (3, 69),
(3, 71), (3, 74)
ON DUPLICATE KEY UPDATE
  group_id = VALUES(group_id),
  menu_id = VALUES(menu_id);

-- Record this configuration script completion
INSERT INTO `fix_script_runs` (`script_name`)
VALUES ('database/3-configuration.sql')
ON DUPLICATE KEY UPDATE
completed_at = CURRENT_TIMESTAMP;


-- ==================================================================
-- 7. POST-CONFIGURATION VERIFICATION QUERIES
-- ==================================================================

-- Verify essential settings were applied
SELECT 'Configuration verification:' as status;
SELECT 
  site_name,
  template,
  recaptcha,
  auto_assign_un,
  permission_restriction,
  elan_image_max,
  elan_spam_cleanup_enabled
FROM settings WHERE id = 1;

-- Verify Editor permission was created
SELECT 'Permissions:' as status;
SELECT id, name, descrip FROM permissions ORDER BY id;

-- Verify pages were created
SELECT 'Pages created:' as status;
SELECT COUNT(*) as total_pages, 
       SUM(CASE WHEN private = 1 THEN 1 ELSE 0 END) as private_pages,
       SUM(CASE WHEN private = 0 THEN 1 ELSE 0 END) as public_pages
FROM pages;

-- Verify menu system was configured
SELECT 'Menu system:' as status;
SELECT COUNT(*) as total_menus,
       SUM(CASE WHEN dropdown = 1 THEN 1 ELSE 0 END) as dropdown_menus,
       SUM(CASE WHEN logged_in = 1 THEN 1 ELSE 0 END) as logged_in_menus,
       SUM(CASE WHEN logged_in = 0 THEN 1 ELSE 0 END) as public_menus
FROM menus;

-- Verify plugin status
SELECT 'Active plugins:' as status;  
SELECT plugin, status FROM us_plugins WHERE status = 'active' ORDER BY plugin;

-- ==================================================================
-- MANUAL CONFIGURATION REQUIRED AFTER RUNNING THIS SCRIPT:
-- ==================================================================

/*
CRITICAL: The following items require manual configuration:

1. **Google API Keys**
   - Replace [GOOGLE_MAPS_API_KEY_REQUIRED] with actual Google Maps API key
   - Replace [GOOGLE_GEOCODING_API_KEY_REQUIRED] with actual Geocoding API key
   - Configure domain restrictions in Google Cloud Console
   - Enable billing for Google Cloud project

2. **Optional: reCAPTCHA Keys** (if you activate the reCAPTCHA plugin)
   - Install and activate reCAPTCHA plugin via Admin Panel → Plugin Manager
   - Configure reCAPTCHA site key and secret key in plugin settings
   - Configure domain restrictions in Google reCAPTCHA Console

3. **Optional: Email Service (Brevo/Sendinblue)**
   - Create Brevo/Sendinblue account and obtain API credentials
   - Install and activate Brevo Sendinblue plugin
   - Configure API key in plugin settings via UserSpice admin panel
   - Set up email templates and sender verification

4. **Security Configuration**
   - Review and adjust SPAM cleanup settings if needed
   - Verify CDN resources load correctly and CSP policy allows them

RECOMMENDED TESTING AFTER CONFIGURATION:
- User registration with auto-assigned username
- Google Maps display on car detail pages
- Email delivery for notifications (if email plugin configured)
- reCAPTCHA on forms (if reCAPTCHA plugin activated)
- File upload functionality
- Page access permissions for different user levels
*/