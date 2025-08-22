<?php
/**
 * reCAPTCHA Test Setup Script
 * Sets up test reCAPTCHA configuration for development
 */

// Google reCAPTCHA Test Keys (these work for localhost testing)
$TEST_SITE_KEY = '***REMOVED***';
$TEST_SECRET_KEY = '***REMOVED***';

echo "Google reCAPTCHA Test Configuration Setup\n";
echo "=========================================\n\n";

echo "Test Keys (for localhost/development):\n";
echo "Site Key: $TEST_SITE_KEY\n";
echo "Secret Key: $TEST_SECRET_KEY\n\n";

echo "These test keys:\n";
echo "- Work for localhost and 127.0.0.1\n";
echo "- Always pass validation (for testing)\n";
echo "- Should NOT be used in production\n\n";

echo "Manual Setup Steps:\n";
echo "1. Enable reCAPTCHA plugin: Change 'recaptcha = 0' to 'recaptcha = 1' in usersc/plugins/plugins.ini.php\n";
echo "2. Configure database settings (via admin panel or direct database update)\n";
echo "3. Test login form with reCAPTCHA\n";
echo "4. Once working, get production keys for elanregistry.org\n\n";

echo "Database Update Query (if needed):\n";
echo "UPDATE settings SET \n";
echo "  recaptcha = 1,\n";
echo "  recap_public = '$TEST_SITE_KEY',\n";
echo "  recap_private = '$TEST_SECRET_KEY',\n";
echo "  recap_version = 2,\n";
echo "  recap_type = 1;\n\n";

echo "Production Keys Setup:\n";
echo "1. Visit https://www.google.com/recaptcha/admin\n";
echo "2. Create new site for 'elanregistry.org'\n";
echo "3. Choose reCAPTCHA v2 'I'm not a robot' checkbox\n";
echo "4. Add domains: elanregistry.org, www.elanregistry.org\n";
echo "5. Copy the keys to database settings\n";

?>