<?php
/**
 * reCAPTCHA Configuration Diagnostic Script
 * Identifies missing configuration for proper reCAPTCHA setup
 */

require_once 'users/init.php';

echo "<h1>reCAPTCHA Configuration Diagnostic</h1>";

// Check current settings from database
try {
    $db = DB::getInstance();
    $settings = $db->query("SELECT recaptcha, recap_public, recap_private, recap_version, recap_type FROM settings")->first();
    
    echo "<h2>Current Database Configuration:</h2>";
    echo "<table border='1' style='border-collapse: collapse; padding: 8px;'>";
    echo "<tr><td><strong>Setting</strong></td><td><strong>Value</strong></td><td><strong>Status</strong></td></tr>";
    
    // Check each setting
    $issues = [];
    
    // reCAPTCHA enabled
    $enabled = $settings->recaptcha ? "✅ Enabled" : "❌ Disabled";
    echo "<tr><td>reCAPTCHA Enabled</td><td>{$settings->recaptcha}</td><td>$enabled</td></tr>";
    
    // Site key
    $siteKeyLength = strlen($settings->recap_public);
    $siteKeyStatus = $siteKeyLength > 30 ? "✅ Present ($siteKeyLength chars)" : "❌ Missing/Too Short";
    if ($siteKeyLength <= 30) $issues[] = "Missing or invalid Site Key";
    echo "<tr><td>Site Key</td><td>" . substr($settings->recap_public, 0, 10) . "...</td><td>$siteKeyStatus</td></tr>";
    
    // Secret key
    $secretKeyLength = strlen($settings->recap_private);
    $secretKeyStatus = $secretKeyLength > 30 ? "✅ Present ($secretKeyLength chars)" : "❌ Missing/Too Short";
    if ($secretKeyLength <= 30) $issues[] = "Missing or invalid Secret Key";
    echo "<tr><td>Secret Key</td><td>" . substr($settings->recap_private, 0, 10) . "...</td><td>$secretKeyStatus</td></tr>";
    
    // Version
    $versionStatus = in_array($settings->recap_version, [2, 3]) ? "✅ Valid" : "❌ Invalid";
    if (!in_array($settings->recap_version, [2, 3])) $issues[] = "Invalid reCAPTCHA version";
    echo "<tr><td>Version</td><td>{$settings->recap_version}</td><td>$versionStatus</td></tr>";
    
    // Type
    $typeStatus = in_array($settings->recap_type, [1, 2]) ? "✅ Valid" : "❌ Invalid";
    if (!in_array($settings->recap_type, [1, 2])) $issues[] = "Invalid reCAPTCHA type";
    $typeName = $settings->recap_type == 1 ? "Checkbox (V2 Only)" : "Invisible";
    echo "<tr><td>Type</td><td>{$settings->recap_type} ($typeName)</td><td>$typeStatus</td></tr>";
    
    echo "</table>";
    
    // Issues summary
    if (empty($issues)) {
        echo "<h2>✅ Configuration Status: READY</h2>";
        echo "<p>All reCAPTCHA settings appear to be properly configured!</p>";
    } else {
        echo "<h2>❌ Configuration Issues Found:</h2>";
        echo "<ul>";
        foreach ($issues as $issue) {
            echo "<li>$issue</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<h2>❌ Database Error:</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}

// Check plugin status
echo "<h2>Plugin Status:</h2>";
$pluginsFile = $abs_us_root . $us_url_root . 'usersc/plugins/plugins.ini.php';
if (file_exists($pluginsFile)) {
    $plugins = file_get_contents($pluginsFile);
    if (strpos($plugins, 'recaptcha = 1') !== false) {
        echo "✅ Plugin is ACTIVE<br>";
    } elseif (strpos($plugins, 'recaptcha = 0') !== false) {
        echo "⚠️ Plugin is temporarily DISABLED<br>";
    } else {
        echo "❓ Plugin status unclear<br>";
    }
} else {
    echo "❌ Plugin configuration file not found<br>";
}

// Check hook files
echo "<h2>Hook Files:</h2>";
$hookFiles = [
    'loginform.php' => $abs_us_root . $us_url_root . 'usersc/plugins/recaptcha/hooks/loginform.php',
    'loginpost.php' => $abs_us_root . $us_url_root . 'usersc/plugins/recaptcha/hooks/loginpost.php'
];

foreach ($hookFiles as $name => $path) {
    if (file_exists($path)) {
        echo "✅ $name exists<br>";
    } else {
        echo "❌ $name missing<br>";
    }
}

// Domain information
echo "<h2>Domain Information:</h2>";
echo "Current Domain: <strong>" . $_SERVER['HTTP_HOST'] . "</strong><br>";
echo "Make sure this domain is registered in your Google reCAPTCHA console.<br>";

// Instructions
echo "<h2>Next Steps:</h2>";
if (!empty($issues)) {
    echo "<ol>";
    if (in_array("Missing or invalid Site Key", $issues) || in_array("Missing or invalid Secret Key", $issues)) {
        echo "<li><strong>Get Google reCAPTCHA Keys:</strong>";
        echo "<ul>";
        echo "<li>Visit <a href='https://www.google.com/recaptcha/admin' target='_blank'>Google reCAPTCHA Admin</a></li>";
        echo "<li>Create a new site or use existing keys</li>";
        echo "<li>Add domain: <strong>" . $_SERVER['HTTP_HOST'] . "</strong></li>";
        echo "<li>Copy the Site Key and Secret Key</li>";
        echo "</ul></li>";
        echo "<li><strong>Update Database:</strong> Go to UserSpice Admin → Plugins → reCAPTCHA Settings</li>";
    }
    echo "<li><strong>Test Configuration:</strong> Re-enable plugin and test login</li>";
    echo "</ol>";
} else {
    echo "<p>✅ Configuration looks good! You should be able to re-enable the reCAPTCHA plugin safely.</p>";
}

echo "<hr>";
echo "<p><em>Note: Delete this file after diagnosis is complete.</em></p>";
?>