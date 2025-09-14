-- ==================================================================
-- ELAN REGISTRY - ESSENTIAL CONFIGURATION SCRIPT (Section 5.3)
-- ==================================================================
-- This script configures a fresh UserSpice 5.9 installation with 
-- essential Elan Registry settings and configurations
-- Run AFTER 5.1-schema.sql and 5.2-import_reference_data.sql
-- ==================================================================

-- ==================================================================
-- 1. UPDATE CORE SETTINGS TABLE WITH ELAN REGISTRY CONFIGURATION
-- ==================================================================

-- Add new Elan Registry specific columns to settings table
-- Note: These columns are already added by 5.1-schema.sql, this updates values

UPDATE settings SET 
  -- Basic Site Configuration
  site_name = 'Lotus Elan Registry',
  recaptcha = 1,
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
  cron_ip = 'localhost',
  container_open_class = 'container',
  redirect_uri_after_login = 'users/account.php',
  
  -- Enhanced Widgets (add Elan Registry specific widgets)
  widgets = 'settings,misc,tools,plugins,snapshot,active-users,a_quick_access,dbclean,logins,permissions,t_logs,u_security_logs',
  
  -- CSS Framework Configuration
  us_css1 = '../users/css/color_schemes/muted.css',
  us_css2 = '../users/css/datatables.css',
  us_css3 = '../usersc/css/custom.css',
  
  -- System Configuration Defaults
  elan_backup_age = 31,
  elan_image_dir = 'userimages/',
  elan_image_max = 6,
  
  -- CDN Resource Management (Production URLs with integrity hashes)
  elan_jquery_cdn = '<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"></script>',
  elan_bootstrap_js_cdn = '<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ho+j7jyWK8fNQe+A12Hb8AhRq26LrZ/JpcUGGOn+Y7RsweNrtN/tE3MoK7ZeZDyx" crossorigin="anonymous"></script>',
  elan_bootstrap_css_cdn = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css" integrity="sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2" crossorigin="anonymous">',
  elan_popper_cdn = '<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>',
  elan_fontawesome_cdn = '<script src="https://kit.fontawesome.com/2d8f489b15.js" crossorigin="anonymous"></script>',
  elan_bootswatch_cdn = '<!-- Bootswatch --> <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/4.6.0/simplex/bootstrap.min.css" integrity="sha512-9hj+qhrmo7MUSzKG3nwkDWncL1x8e2d1wfJxufofoBMMLXlqlqvjpT0V0blusJ8CFx9fs9Ru7ICYkVrz62Q33w==" crossorigin="anonymous" />',
  elan_datatables_js_cdn = '<script type="text/javascript" src="https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/rg-1.1.2/sc-2.0.3/sb-1.0.1/sp-1.2.2/datatables.min.js"></script>',
  elan_datatables_css_cdn = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/rg-1.1.2/sc-2.0.3/sb-1.0.1/sp-1.2.2/datatables.min.css" />',
  elan_datepicker_js_cdn = '<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js" integrity="sha512-T/tUfKSV1bihCnd+MxKD0Hm1uBBroVYBOYSk1knyvQ9VyZJpc/ALb4P0r6ubwVPSGB2GvjeoMAJJImBG12TiaQ==" crossorigin="anonymous"></script>',
  elan_datepicker_css_cdn = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css" integrity="sha512-mSYUmp1HYZDFaVKK//63EcZq4iFWFjxSL+Z3T/aCt4IO9Cejm03q3NKKYN6pFQzY0SBOr8h+eCIAZHPXcpZaNw==" crossorigin="anonymous" />',
  elan_jquery_ui_cdn = '<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js" integrity="sha256-VazP97ZCwtekAsvgPBSUwPFKdrwD3unUfSGVYrahUqU=" crossorigin="anonymous"></script>',
  elan_dropzone_js_cdn = '<script src="https://cdn.jsdelivr.net/npm/dropzone@5.7.6/dist/min/dropzone.min.js" integrity="sha256-jj9KUPHT4VOIR8ZhUcJB66aiEAwt+eLk+10MeVKSbio=" crossorigin="anonymous"></script>',
  elan_dropzone_css_cdn = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/dropzone@5.7.6/dist/min/dropzone.min.css" integrity="sha256-n/Cuyrm+v15Nim0mJ2ZrElHlCk8raJs/57WeCsIzDr4=" crossorigin="anonymous">',
  
  -- SPAM Cleanup System Configuration (Safe defaults)
  elan_spam_cleanup_enabled = 0,        -- Start disabled for safety
  elan_spam_cleanup_dry_run = 1,        -- Start in dry-run mode
  elan_spam_inactive_days = 30,         -- Conservative 30 days
  elan_spam_grace_period_days = 7,      -- 7 day grace period
  elan_spam_max_deletions = 50,         -- Maximum 50 deletions per run
  elan_spam_max_percentage = 5.00,      -- Maximum 5% of users per run  
  elan_spam_email_notifications = 0     -- Start with notifications disabled

WHERE id = 1;

-- ==================================================================
-- 2. CREATE ADDITIONAL PERMISSION LEVEL
-- ==================================================================

-- Add Editor permission level (between User and Administrator)
INSERT INTO permissions (id, name, descrip) 
VALUES (3, 'Editor', 'Content Editor - Can manage registry content but not system settings')
ON DUPLICATE KEY UPDATE 
  name = 'Editor', 
  descrip = 'Content Editor - Can manage registry content but not system settings';

-- ==================================================================  
-- 3. PLACEHOLDER CONFIGURATIONS (REQUIRE MANUAL SETUP)
-- ==================================================================

-- NOTE: These settings require manual configuration with actual API keys and credentials

-- Google API Keys (PLACEHOLDER - Replace with actual keys)
UPDATE settings SET 
  elan_google_maps_key = '[GOOGLE_MAPS_API_KEY_REQUIRED]',
  elan_google_geo_key = '[GOOGLE_GEOCODING_API_KEY_REQUIRED]'
WHERE id = 1;

-- reCAPTCHA Keys (PLACEHOLDER - Replace with actual keys)  
UPDATE settings SET
  recap_public = '[RECAPTCHA_SITE_KEY_REQUIRED]',
  recap_private = '[RECAPTCHA_SECRET_KEY_REQUIRED]',
  recap_type = 1,
  recap_version = 2
WHERE id = 1;

-- ==================================================================
-- 4. PLUGIN CONFIGURATION PLACEHOLDERS
-- ==================================================================

-- Note: Plugin-specific settings are typically stored in individual plugin tables
-- The following are common configurations that may need manual setup:

-- Brevo/Sendinblue Plugin Configuration
-- (Stored in plg_sendinblue table - requires manual API key setup)

-- reCAPTCHA Plugin Configuration  
-- (Uses recap_public/recap_private settings configured above)

-- Auto-assign Username Plugin
-- (Uses auto_assign_un setting configured above)



-- ==================================================================
-- 4. PAGE MANAGEMENT AND SECURITY CONFIGURATION
-- ==================================================================

-- Insert all Elan Registry pages with proper security settings
-- These pages are essential for the registry functionality

-- First, update existing UserSpice pages to match production settings
-- Add missing UserSpice pages that exist in production
INSERT INTO `pages` (`page`, `title`, `private`, `re_auth`, `core`) VALUES
('users/passkeys.php', 'Passkey Management', 1, 0, 1),
('users/passwordless.php', 'Passwordless Login', 1, 0, 1),
('users/release_blacklist.php', 'Release Blacklist', 1, 0, 1),
('users/totp_management.php', 'TOTP Management', 1, 0, 1),
('users/totp_verification.php', 'TOTP Verification', 1, 0, 1)
ON DUPLICATE KEY UPDATE 
  title = VALUES(title),
  private = VALUES(private),
  re_auth = VALUES(re_auth),
  core = VALUES(core);

-- Insert all Elan Registry specific pages
INSERT INTO `pages` (`id`, `page`, `title`, `private`, `re_auth`, `core`) VALUES
(241, 'app/cars/actions/check-chassis.php', NULL, 1, 0, 0),
(242, 'app/cars/actions/edit.php', NULL, 1, 0, 0),
(243, 'app/cars/actions/history.php', NULL, 1, 0, 0),
(261, 'app/cars/actions/validateChassis.php', NULL, 1, 0, 0),
(230, 'app/cars/details.php', NULL, 0, 0, 0),
(231, 'app/cars/edit.php', NULL, 1, 0, 0),
(232, 'app/cars/factory.php', NULL, 0, 0, 0),
(233, 'app/cars/identify.php', NULL, 0, 0, 0),
(229, 'app/cars/index.php', NULL, 0, 0, 0),
(234, 'app/cars/manage.php', NULL, 1, 0, 0),
(235, 'app/cars/mapmarkers.xml.php', NULL, 0, 0, 0),
(126, 'app/car_details.php', 'Car Details', 0, 0, 0),
(223, 'app/contact.php', NULL, 1, 0, 0),
(236, 'app/contact/index.php', NULL, 1, 0, 0),
(237, 'app/contact/owner.php', NULL, 1, 0, 0),
(238, 'app/contact/send-feedback.php', NULL, 1, 0, 0),
(239, 'app/contact/send-owner-email.php', NULL, 1, 0, 0),
(224, 'app/contact_owner.php', NULL, 1, 0, 0),
(225, 'app/contact/send-owner-email.php', NULL, 1, 0, 0),
(125, 'app/edit_car.php', 'UpdateCar', 1, 0, 0),
(123, 'app/identification.php', 'Identification Guide', 0, 0, 0),
(119, 'app/list_cars.php', 'Car Listing', 0, 0, 0),
(122, 'app/list_factory.php', 'Factory Data', 0, 0, 0),
(120, 'app/manage_cars.php', 'Manage Cars', 1, 0, 0),
(121, 'app/cars/mapmarkers.xml.php', 'Map Markers', 0, 0, 0),
(245, 'app/privacy.php', NULL, 0, 0, 0),
(246, 'app/reports/analytics.php', NULL, 0, 0, 0),
(247, 'app/reports/data-quality.php', NULL, 0, 0, 0),
(248, 'app/reports/statistics.php', NULL, 0, 0, 0),
(249, 'app/statistics.php', NULL, 0, 0, 0),
(250, 'app/terms.php', NULL, 0, 0, 0),
(251, 'app/view_car.php', NULL, 0, 0, 0),
(252, 'car_details.php', NULL, 0, 0, 0),
(253, 'edit_car.php', NULL, 1, 0, 0),
(254, 'identification.php', NULL, 0, 0, 0),
(255, 'list_cars.php', NULL, 0, 0, 0),
(256, 'list_factory.php', NULL, 0, 0, 0),
(257, 'manage_cars.php', NULL, 1, 0, 0),
(258, 'statistics.php', NULL, 0, 0, 0),
(259, 'view_car.php', NULL, 0, 0, 0)
ON DUPLICATE KEY UPDATE 
  title = VALUES(title),
  private = VALUES(private),
  re_auth = VALUES(re_auth),
  core = VALUES(core);

-- Insert permission-page relationships for Administrator and Editor permissions
INSERT INTO `permission_page_matches` (`permission_id`, `page_id`) VALUES
-- Administrator permissions (permission_id = 2)
(2, (SELECT id FROM pages WHERE page = 'app/reports/data-quality.php')),
(2, (SELECT id FROM pages WHERE page = 'app/cars/manage.php')),
(2, (SELECT id FROM pages WHERE page = 'app/manage_cars.php')),
(2, (SELECT id FROM pages WHERE page = 'manage_cars.php')),
(2, (SELECT id FROM pages WHERE page = 'statistics.php')),
-- Editor permissions (permission_id = 3)  
(3, (SELECT id FROM pages WHERE page = 'users/login.php')),
(3, (SELECT id FROM pages WHERE page = 'users/index.php')),
(3, (SELECT id FROM pages WHERE page = 'app/identification.php'))
ON DUPLICATE KEY UPDATE 
  permission_id = VALUES(permission_id),
  page_id = VALUES(page_id);

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
DELETE FROM menus WHERE id > 20; -- Keep base UserSpice menus, add registry menus

-- Insert Elan Registry menu items (CLASSIC MENU SYSTEM)
INSERT INTO `menus` (`id`, `menu_title`, `parent`, `dropdown`, `logged_in`, `display_order`, `label`, `link`, `icon_class`) VALUES
-- Main navigation items
(25, 'main', -1, 0, 0, 20, 'List Cars', 'app/list_cars.php', 'fa fa-fw fa-car'),
(38, 'main', -1, 0, 1, 0, '{{home}}', '#', 'fa fa-fw fa-home'),
(39, 'main', -1, 0, 1, 20, 'List Cars', 'app/list_cars.php', 'fa fa-fw fa-car'),
(42, 'main', -1, 0, 1, 30, 'Add Car', 'app/edit_car.php', 'fa fa-fw fa-plus'),
(43, 'main', -1, 1, 1, 99999, '{{account}}', '#', 'fa fa-fw fa-user'),
(47, 'main', -1, 0, 1, 80, 'Feedback', 'app/contact.php', 'fa fa-fw fa-comments'),
(48, 'main', -1, 0, 0, 40, 'Statistics', 'app/statistics.php', 'fa fa-fw fa-pie-chart'),
(49, 'main', -1, 0, 1, 40, 'Statistics', 'app/statistics.php', 'fa fa-fw fa-pie-chart'),
(61, 'main', -1, 1, 0, 50, 'Technical Resources', '#', 'fa fa-fw fa-tools'),
(63, 'main', -1, 0, 0, 70, 'Car Stories', 'docs/car-stories.php', 'fa fa-fw fa-book-open'),
(64, 'main', -1, 1, 1, 50, 'Technical Resources', '#', 'fa fa-fw fa-tools'),
(68, 'main', -1, 0, 1, 70, 'Car Stories', 'docs/car-stories.php', 'fa fa-fw fa-book-open'),

-- Admin dropdown items (parent = 2)
(44, 'main', 2, 0, 1, 1, 'Manage Cars', 'app/manage_cars.php', 'fa fa-fw fa-car'),
(45, 'main', 2, 0, 1, 50, '{{hr}}', '#', ''),
(53, 'main', 2, 0, 1, 10, 'Fixes', 'FIX/index.php', 'fa fa-fw fa-wrench'),
(60, 'main', 2, 0, 1, 20, 'Data Quality', 'app/reports/data-quality.php', 'fa fa-fw fa-clipboard-check'),

-- Account dropdown items (parent = 43)  
(3, 'main', 43, 0, 1, 110, '{{username}}', 'users/account.php', 'fa fa-fw fa-user'),
(15, 'main', 43, 0, 1, 1000, '{{hr}}', '', ''),
(16, 'main', 43, 0, 1, 99999, '{{logout}}', 'users/logout.php', 'fa fa-fw fa-sign-out'),

-- Technical Resources dropdown items (parent = 61 for public, parent = 64 for logged in)
(41, 'main', 61, 0, 0, 10, 'Identification Guide', 'app/identification.php', 'fa fa-fw fa-binoculars'),
(54, 'main', 61, 0, 0, 20, 'Factory Data', 'app/list_factory.php', 'fa fa-fw fa-list-alt'),
(62, 'main', 61, 0, 1, 30, 'Reference Library - Tech Manuals', 'docs/reference-library.php', 'fa fa-fw fa-book'),
(65, 'main', 64, 0, 1, 10, 'Identification Guide', 'app/identification.php', 'fa fa-fw fa-binoculars'),
(66, 'main', 64, 0, 1, 20, 'Factory Data', 'app/list_factory.php', 'fa fa-fw fa-list-alt'),
(67, 'main', 64, 0, 1, 30, 'Reference Library - Tech Manuals', 'docs/reference-library.php', 'fa fa-fw fa-book')
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
-- group_id 0 = Public, 2 = Administrator, 3 = Editor
INSERT INTO `groups_menus` (`group_id`, `menu_id`) VALUES
-- Public menu items (group_id = 0)
(0, 25),  -- List Cars (public)
(0, 41),  -- Identification Guide
(0, 48),  -- Statistics (public)
(0, 54),  -- Factory Data
(0, 61),  -- Technical Resources dropdown
(0, 63),  -- Car Stories

-- Administrator menu items (group_id = 2)
(2, 2),   -- Admin dropdown
(2, 44),  -- Manage Cars
(2, 45),  -- HR separator
(2, 53),  -- Fixes
(2, 60),  -- Data Quality

-- Editor menu items (group_id = 3)  
(3, 2),   -- Admin dropdown (limited access)
(3, 44),  -- Manage Cars
(3, 45),  -- HR separator
(3, 53),  -- Fixes
(3, 60),  -- Data Quality

-- Common logged-in menu items (added to all user groups)
(0, 3),   -- Username link in account dropdown
(0, 15),  -- HR separator in account dropdown  
(0, 16),  -- Logout
(0, 38),  -- Home (logged in)
(0, 39),  -- List Cars (logged in)
(0, 42),  -- Add Car
(0, 43),  -- Account dropdown
(0, 47),  -- Feedback
(0, 49),  -- Statistics (logged in)
(0, 62),  -- Reference Library
(0, 64),  -- Technical Resources (logged in)
(0, 65),  -- Identification Guide (logged in)
(0, 66),  -- Factory Data (logged in)
(0, 67),  -- Reference Library (logged in)
(0, 68)   -- Car Stories (logged in)
ON DUPLICATE KEY UPDATE 
  group_id = VALUES(group_id),
  menu_id = VALUES(menu_id);

-- Record this configuration script completion
INSERT INTO `fix_script_runs` (`script_name`, `status`, `notes`) 
VALUES ('database/5.3-essential_config.sql', 'completed', 'Essential Elan Registry configuration: settings + permissions + 46 pages + 22 menus + security')
ON DUPLICATE KEY UPDATE 
run_date = CURRENT_TIMESTAMP,
status = 'completed',
notes = 'Schema update re-applied';


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

2. **reCAPTCHA Keys**
   - Replace [RECAPTCHA_SITE_KEY_REQUIRED] with actual reCAPTCHA site key
   - Replace [RECAPTCHA_SECRET_KEY_REQUIRED] with actual reCAPTCHA secret key
   - Configure domain restrictions in Google reCAPTCHA Console

3. **Email Service (Brevo/Sendinblue)**
   - Create Brevo/Sendinblue account and obtain API credentials
   - Configure API key in plugin settings via UserSpice admin panel
   - Set up email templates and sender verification


7. **Security Configuration**
   - Review and adjust SPAM cleanup settings if needed
   - Test reCAPTCHA functionality on registration and contact forms
   - Verify CDN resources load correctly and CSP policy allows them

RECOMMENDED TESTING AFTER CONFIGURATION:
- User registration with auto-assigned username
- Google Maps display on car detail pages  
- Email delivery for notifications
- reCAPTCHA on forms
- File upload functionality
- Page access permissions for different user levels
*/