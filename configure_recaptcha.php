<?php
/**
 * reCAPTCHA Configuration Script
 * Updates database settings with proper reCAPTCHA keys
 */

require_once 'users/init.php';

// Check if we have the keys from command line arguments or form submission
$site_key = '';
$secret_key = '';

if ($_POST) {
    $site_key = trim($_POST['site_key'] ?? '');
    $secret_key = trim($_POST['secret_key'] ?? '');
    $version = (int)($_POST['version'] ?? 2);
    $type = (int)($_POST['type'] ?? 1);
    
    if (!empty($site_key) && !empty($secret_key)) {
        try {
            $db = DB::getInstance();
            
            // Update the settings
            $result = $db->update('settings', 1, [
                'recaptcha' => 1,
                'recap_public' => $site_key,
                'recap_private' => $secret_key,
                'recap_version' => $version,
                'recap_type' => $type
            ]);
            
            if ($result) {
                echo "<div style='background: #d4edda; padding: 10px; border: 1px solid #c3e6cb; border-radius: 5px; margin: 10px 0;'>";
                echo "<h3>✅ reCAPTCHA Configuration Updated Successfully!</h3>";
                echo "<p>Settings have been saved to the database.</p>";
                echo "<p><strong>Next steps:</strong></p>";
                echo "<ul>";
                echo "<li>Test the login form to ensure reCAPTCHA displays</li>";
                echo "<li>Try logging in to verify the validation works</li>";
                echo "<li>If all works well, delete this configuration script</li>";
                echo "</ul>";
                echo "</div>";
            } else {
                echo "<div style='background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 10px 0;'>";
                echo "<h3>❌ Database Update Failed</h3>";
                echo "<p>Could not update the settings in the database.</p>";
                echo "</div>";
            }
            
        } catch (Exception $e) {
            echo "<div style='background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 10px 0;'>";
            echo "<h3>❌ Error: " . htmlspecialchars($e->getMessage()) . "</h3>";
            echo "</div>";
        }
    } else {
        echo "<div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; border-radius: 5px; margin: 10px 0;'>";
        echo "<h3>⚠️ Missing Keys</h3>";
        echo "<p>Please provide both Site Key and Secret Key.</p>";
        echo "</div>";
    }
}

// Show current configuration
try {
    $db = DB::getInstance();
    $settings = $db->query("SELECT recaptcha, recap_public, recap_private, recap_version, recap_type FROM settings")->first();
    
    echo "<h2>Current reCAPTCHA Configuration:</h2>";
    echo "<table border='1' style='border-collapse: collapse; padding: 8px; margin-bottom: 20px;'>";
    echo "<tr><td><strong>Setting</strong></td><td><strong>Value</strong></td></tr>";
    echo "<tr><td>Enabled</td><td>" . ($settings->recaptcha ? "Yes" : "No") . "</td></tr>";
    echo "<tr><td>Site Key</td><td>" . (strlen($settings->recap_public) > 10 ? substr($settings->recap_public, 0, 20) . "..." : "Not set") . "</td></tr>";
    echo "<tr><td>Secret Key</td><td>" . (strlen($settings->recap_private) > 10 ? "Set (hidden)" : "Not set") . "</td></tr>";
    echo "<tr><td>Version</td><td>v" . $settings->recap_version . "</td></tr>";
    echo "<tr><td>Type</td><td>" . ($settings->recap_type == 1 ? "Checkbox" : "Invisible") . "</td></tr>";
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>reCAPTCHA Configuration</title>
</head>
<body>
    <h1>reCAPTCHA Configuration</h1>
    
    <div style='background: #cff4fc; padding: 10px; border: 1px solid #b6effb; border-radius: 5px; margin: 10px 0;'>
        <h3>📋 Setup Instructions:</h3>
        <ol>
            <li>Go to <a href="https://www.google.com/recaptcha/admin" target="_blank">Google reCAPTCHA Admin</a></li>
            <li>Create a new site for "elanregistry.org"</li>
            <li>Choose <strong>reCAPTCHA v2</strong> → <strong>"I'm not a robot" Checkbox</strong></li>
            <li>Add domains: <code>elanregistry.org</code> and <code>www.elanregistry.org</code></li>
            <li>Copy the keys and paste them below</li>
        </ol>
    </div>
    
    <form method="post">
        <table>
            <tr>
                <td><label for="site_key">Site Key:</label></td>
                <td><input type="text" name="site_key" id="site_key" size="50" placeholder="6L..." required></td>
            </tr>
            <tr>
                <td><label for="secret_key">Secret Key:</label></td>
                <td><input type="password" name="secret_key" id="secret_key" size="50" placeholder="6L..." required></td>
            </tr>
            <tr>
                <td><label for="version">Version:</label></td>
                <td>
                    <select name="version" id="version">
                        <option value="2" selected>v2 (Recommended)</option>
                        <option value="3">v3</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td><label for="type">Type:</label></td>
                <td>
                    <select name="type" id="type">
                        <option value="1" selected>Checkbox (v2 only)</option>
                        <option value="2">Invisible</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <input type="submit" value="Update reCAPTCHA Configuration" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px;">
                </td>
            </tr>
        </table>
    </form>
    
    <div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; border-radius: 5px; margin: 20px 0;'>
        <h3>⚠️ Security Note:</h3>
        <p>Delete this configuration script after use to prevent unauthorized access to your settings.</p>
    </div>
</body>
</html>