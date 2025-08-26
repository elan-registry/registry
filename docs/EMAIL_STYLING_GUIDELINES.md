# Email Styling Guidelines - Lotus Elan Registry

**Version**: 1.0  
**Last Updated**: August 26, 2025  
**Purpose**: Consistent email branding across all registry communications

## Color Palette

All Lotus Elan Registry emails should use the Bootstrap Simplex color scheme that matches the car_details page and main application interface.

### Primary Colors

| Color Name | Hex Code | Usage | Bootstrap Class |
|------------|----------|-------|-----------------|
| **Info Blue** | `#029acf` | Email headers, primary branding | `--info` |
| **Success Green** | `#469408` | Action buttons, highlights, Lotus branding text | `--success` |
| **Card Background** | `#f8f9fa` | Footer backgrounds, subtle sections | Card header background |
| **Warning Red** | `#dc2626` | Warning boxes, urgent notices | Standard warning |

### Secondary Colors

| Color Name | Hex Code | Usage |
|------------|----------|-------|
| **Success Green Hover** | `#3a7006` | Button hover states |
| **Light Success** | `#cbe1ba` | Success message backgrounds |
| **Light Info** | `#b8e3f2` | Info message backgrounds |
| **Body Text** | `#333333` | Main content text |
| **Muted Text** | `#6b7280` | Footer text, less important info |
| **Border Gray** | `#dee2e6` | Borders, dividers |

## Standard Email Structure

### HTML Email Template Structure

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lotus Elan Registry - [Email Subject]</title>
    <style>
        /* Standard Lotus Elan Registry Email Styles */
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #029acf; color: white; padding: 20px; text-align: center; }
        .logo { width: 48px; height: 48px; margin-bottom: 10px; }
        .content { padding: 30px; }
        .warning-box { background-color: #fef2f2; border: 2px solid #dc2626; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .info-box { background-color: #b8e3f2; border: 2px solid #029acf; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .success-box { background-color: #cbe1ba; border: 2px solid #469408; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .action-buttons { text-align: center; margin: 30px 0; }
        .btn { display: inline-block; padding: 12px 24px; margin: 10px; background-color: #469408; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .btn:hover { background-color: #3a7006; }
        .btn-secondary { background-color: #029acf; }
        .btn-secondary:hover { background-color: #0284c7; }
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #dee2e6; color: #6b7280; font-size: 14px; }
        .lotus-green { color: #469408; }
        .lotus-blue { color: #029acf; }
        @media only screen and (max-width: 600px) {
            .content { padding: 20px; }
            .btn { display: block; margin: 10px 0; }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <img src="https://elanregistry.org/usersc/templates/ElanRegistry/assets/images/logo-72x72.png" alt="Lotus Logo" class="logo">
            <h1>Lotus Elan Registry</h1>
            <p>[Email Type/Subject]</p>
        </div>
        
        <div class="content">
            <!-- Email content here -->
        </div>
        
        <div class="footer">
            <p><strong>The Lotus Elan Registry Team</strong></p>
            <p><a href="https://elanregistry.org">https://elanregistry.org</a></p>
            <p>Preserving the legacy of Colin Chapman's masterpiece since 2003</p>
            <hr style="border: none; border-top: 1px solid #dee2e6; margin: 15px 0;">
            <p><small>This is an automated message. Please do not reply to this email.</small></p>
        </div>
    </div>
</body>
</html>
```

## Component Guidelines

### Headers
- **Background**: Info Blue (`#029acf`)
- **Text**: White
- **Logo**: 48x48px Lotus logo
- **Standard tagline**: "Preserving the legacy of Colin Chapman's masterpiece since 2003"

### Content Areas
- **Background**: White (`#ffffff`)
- **Padding**: 30px (20px on mobile)
- **Text**: Dark gray (`#333333`)

### Action Buttons
- **Primary Button**: Success Green (`#469408`) with hover (`#3a7006`)
- **Secondary Button**: Info Blue (`#029acf`) with hover (`#0284c7`)
- **Border radius**: 5px
- **Padding**: 12px 24px
- **Font weight**: Bold

### Message Boxes
- **Warning**: Red border (`#dc2626`) with light red background (`#fef2f2`)
- **Info**: Blue border (`#029acf`) with light blue background (`#b8e3f2`)
- **Success**: Green border (`#469408`) with light green background (`#cbe1ba`)
- **Border radius**: 8px
- **Padding**: 20px

### Footer
- **Background**: Light gray (`#f8f9fa`)
- **Border**: Top border (`#dee2e6`)
- **Text color**: Muted gray (`#6b7280`)
- **Font size**: 14px

## Email Types and Templates

### 1. Account Notifications
- **Header**: "Account Notice" or "Account Update"
- **Primary color**: Info Blue
- **Use cases**: Login alerts, profile changes, security notices

### 2. Car Registry Updates
- **Header**: "Registry Update" or "Car Information"
- **Primary color**: Success Green
- **Use cases**: Car approved, car updated, ownership changes

### 3. System Messages
- **Header**: "System Notice" or "Registry Maintenance"
- **Primary color**: Info Blue
- **Use cases**: Maintenance notices, system updates, policy changes

### 4. Warning/Urgent Messages
- **Header**: "Important Notice" or "Action Required"
- **Prominent warning box**: Red warning styling
- **Use cases**: Account deletion warnings, security alerts, policy violations

## Text Styling

### Headings
- **H1**: Registry name in header (white text)
- **H2**: Main content headings (`color: #333`)
- **H3**: Sub-section headings (`color: #333`)

### Special Text Classes
- `.lotus-green`: Success green text (`#469408`) for highlighting Lotus/registry references
- `.lotus-blue`: Info blue text (`#029acf`) for technical information
- **Bold**: Use `<strong>` for emphasis, especially registry name mentions

### Links
- **Standard links**: Use default browser styling with underline
- **Button links**: Use `.btn` classes for primary actions
- **Footer links**: Inherit footer text color (`#6b7280`)

## Mobile Responsiveness

All emails must include mobile-responsive design:

```css
@media only screen and (max-width: 600px) {
    .content { padding: 20px; }
    .btn { display: block; margin: 10px 0; }
    .email-container { margin: 0 10px; }
}
```

## Plain Text Fallback

Every HTML email must include a plain text version:

```
LOTUS ELAN REGISTRY - [Email Subject]

Dear [Username],

[Email content in plain text format]

---
The Lotus Elan Registry Team
https://elanregistry.org
Preserving the legacy of Colin Chapman's masterpiece since 2003

This is an automated message. Please do not reply to this email.
```

## Implementation Notes

### For Developers
1. **Always include both HTML and plain text versions**
2. **Use the exact hex codes** specified in this guide
3. **Test emails in Mailtrap.io** during development
4. **Maintain 90%+ email client compatibility** by keeping CSS simple
5. **Use inline CSS** for maximum compatibility when needed

### Brand Consistency
- The color scheme directly matches the car_details page and Bootstrap Simplex theme
- `table-success` highlighting uses Success Green (`#469408`)
- `table-info` sections use Info Blue (`#029acf`)
- Card headers use the same light gray (`#f8f9fa`)

### Assets
- **Logo URL**: `https://elanregistry.org/usersc/templates/ElanRegistry/assets/images/logo-72x72.png`
- **Registry URL**: `https://elanregistry.org`
- **Login URL**: `https://elanregistry.org/users/login.php`

## Example Implementations

This styling guide is already implemented in:
- **SPAM Cleanup System**: Grace period email notifications (`/users/cron/spam_inactive_cleanup.php`)

Future email implementations should follow this exact color scheme and structure for consistent user experience across all registry communications.