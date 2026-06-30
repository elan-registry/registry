# Email System

The Lotus Elan Registry uses Brevo (formerly Sendinblue) as its transactional email service
for production and staging environments. This document covers account setup, configuration,
troubleshooting, and the developer API.

## Overview

Brevo provides reliable email delivery via HTTP API. We chose Brevo because A2 Hosting blocks outbound SMTP ports; Brevo uses port 443 (HTTPS) which is always open.

**Plugin location:** `usersc/plugins/sendinblue/`

**How it works:** The plugin's `override.php` file globally overrides UserSpice's built-in
`email()` function. When the plugin is active, all calls to `email()` throughout the codebase
are routed through Brevo's API. When the plugin is deactivated, UserSpice's native
PHPMailer-based `email()` is used instead.

**Architecture decision:** See
[ADR-012: Adopt Brevo for Transactional Email Delivery](adr/ADR-012-adopt-brevo-for-transactional-email-delivery.md)
for the full rationale and evaluation of alternatives.

## Brevo Account Setup (One-Time)

Follow these steps for a fresh Brevo account:

1. Create an account at [brevo.com](https://brevo.com)

2. Generate an API key:
   - Log in to Brevo
   - Go to My Profile → Settings → SMTP & API
   - Click API keys & MCP
   - Click Generate a new API key
   - Copy the key to a secure location

3. Add a sender:
   - Go to My Profile → Settings → Senders/Domains/IP
   - Click Senders
   - Click Add Sender
   - Enter the sender name and email address (e.g., `Lotus Elan Registry <noreply@elanregistry.org>`)

4. Add the sending domain:
   - Go to My Profile → Settings → Senders/Domains/IP
   - Click Domains
   - Click Add a Domain
   - Enter your domain (e.g., `elanregistry.org`)

5. Add DNS records:
   - Brevo displays the DNS records you must add to your registrar
   - Copy each record:
     - One TXT record (Brevo domain ownership verification)
     - Two DKIM records (email authentication)
     - One DMARC record (email policy)
   - Add all records to your DNS provider (typically your domain registrar)

6. Verify DNS configuration:
   - Return to Brevo
   - Click Check Configuration
   - Brevo confirms all records are detected and propagated
   - DNS propagation may take several minutes; wait and retry if needed

Once verified, your account is ready for the registry to use.

## Plugin Configuration

After your Brevo account is set up and DNS is verified:

1. Log in to the registry admin panel
2. Go to Admin → Plugins
3. Click Configure Brevo
4. Enter the API key from the account setup above
5. Set the sender name (display name for "From" field)
6. Set the sender email address (must match a verified sender in Brevo)
7. Set the reply-to address (where replies to registry emails should go)
8. Click Save Configuration
9. Click Activate Override to route all `email()` calls through Brevo
10. Click Test Email to send a test message to your email address and verify end-to-end delivery

## Environment Setup

### Production and Staging

Both `elanregistry.org` and `test.elanregistry.org` use the same Brevo account and API key. No environment-specific configuration is needed.

### Local Development

Use Mailtrap to capture all email for debugging and development:

1. Create a Mailtrap account at [mailtrap.io](https://mailtrap.io)
2. Get your Mailtrap SMTP credentials from the project inbox settings
3. In the registry admin panel, go to Admin → Plugins
4. Deactivate the Brevo plugin
5. Go to Admin → Settings → Email and update the SMTP settings with your Mailtrap credentials
6. All emails sent locally will be captured in Mailtrap's inbox for inspection

To switch back to Brevo for production testing: re-enter the Brevo API key in the plugin configuration and reactivate the override.

## Verifying Email Delivery

### Brevo Dashboard

Log in to Brevo and navigate to Transactional → Email to view:

- **Real Time:** Live feed of all outbound emails with delivery status
- **Statistics:** Delivery rates, bounces, complaints, and trends
- **Logs:** Searchable history of all sent messages with timestamps and recipient details

### Application Logs

In the registry admin panel:

1. Go to Admin → Logs
2. Filter by log category `sendinblue`
3. View all plugin-level send attempts, errors, and exceptions

Logs include timestamps, recipient addresses, and any API error messages returned by Brevo.

## Troubleshooting

### Emails Not Sending

Check the UserSpice logs (category `sendinblue`) for the API error message returned by Brevo.

**Common causes:**

- **Invalid or expired API key:** Generate a new API key in Brevo (My Profile → Settings → SMTP & API) and update the plugin configuration.
- **Domain not verified:** Check Brevo's domain status
  (My Profile → Settings → Senders/Domains/IP → Domains).
  Click Check Configuration again. DNS propagation may take time.
- **Sender email not verified:** Ensure the sender email address matches a verified sender in Brevo (My Profile → Settings → Senders/Domains/IP → Senders).

### Brevo IP Whitelist

Brevo enables an IP whitelist by default. If API calls fail with authorization errors on a new server:

1. Log in to Brevo
2. Go to My Profile → Settings → Security
3. Check IP Security settings
4. Add the server IP to the whitelist or disable the whitelist

This is not required for the current elanregistry.org or test.elanregistry.org deployments.

### "Forgot Password" Link Hidden on Login Page

The plugin configuration page shows a warning and hides the forgot-password link when:

- The override is active (Brevo plugin is enabled), AND
- UserSpice still contains placeholder SMTP values

This is a safety indicator. Email delivery is not affected — the plugin routes all emails
through Brevo regardless. You can safely ignore this warning once Brevo is properly configured.

### Domain Verification Fails

After adding DNS records to your registrar:

1. Wait 5–15 minutes for DNS propagation
2. Return to Brevo and click Check Configuration again
3. If verification still fails, use a DNS lookup tool (e.g., `dig`, `nslookup`) to confirm
   the records are published:

   ```bash
   dig elanregistry.org TXT
   dig _dkim.elanregistry.org TXT
   ```

4. Verify the record values match exactly what Brevo expects

## Developer Reference

### sendinblue() Function

The plugin provides the `sendinblue()` function, which is called by the overridden `email()` function.

**Signature:**

```php
// Effective signature (implementation in usersc/plugins/sendinblue/functions.php lacks type hints)
sendinblue($to, $subject, $body, $to_name = "", $options = []): bool
```

**Parameters:**

| Parameter | Type | Description |
| --- | --- | --- |
| `$to` | string | Recipient email address (required) |
| `$subject` | string | Email subject line (required) |
| `$body` | string | HTML email body (required) |
| `$to_name` | string | Recipient display name (optional) |
| `$options` | array | Per-send overrides and attachments (optional) |

**Returns:** `true` on success, `false` on any failure (API error, missing required fields, invalid email address, etc.).

### $options Array Keys

These keys apply when calling `sendinblue()` directly. See [Calling via email()](#calling-via-email) below for the different key names used through the override.

| Key | Type | Description |
| --- | --- | --- |
| `from` | string | Override sender email address |
| `from_name` | string | Override sender display name |
| `reply` | string | Override reply-to email address |
| `reply_name` | string | Override reply-to display name (only honoured when calling `sendinblue()` directly — not forwarded by the `email()` override) |
| `template` | int | Brevo template ID for templated emails |
| `params` | array | Template variable substitutions (key => value pairs) |
| `attachments` | array | Array of `['content' => base64string, 'name' => 'filename.pdf']` |

### Recommended Calling Pattern

Always check the return value and log failures:

```php
$result = email($to, $subject, $body);
if ($result !== true) {
    $safeLog = preg_replace('/[\r\n\t]/', '', $to);
    logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
        "Email SEND FAILED to {$safeLog}");
}
```

### Calling via email()

Most application code calls `email()` rather than `sendinblue()` directly. The `override.php`
shim translates UserSpice's `email()` option keys to Brevo option keys:

| `email()` option key | Maps to `sendinblue()` key |
| --- | --- |
| `email` | `from` |
| `name` | `from_name` |
| `replyTo` | `reply` |

> **Note:** `reply_name` is not forwarded by the override. Pass it only when calling `sendinblue()` directly.

Example using `email()`:

```php
$opts = [
    'email'   => 'registrar@elanregistry.org',
    'name'    => 'Registry Registrar',
    'replyTo' => 'support@elanregistry.org',
];
email($to, $subject, $body, $opts);
```

### Overriding Sender or Reply-To (sendinblue() directly)

When calling `sendinblue()` directly, use its native keys:

```php
$options = [
    'from'       => 'registrar@elanregistry.org',
    'from_name'  => 'Registry Registrar',
    'reply'      => 'support@elanregistry.org',
    'reply_name' => 'Registry Support',
];
sendinblue($to, $subject, $body, '', $options);
```

### Sending Templated Emails

If you have a Brevo template configured, use the `template` and `params` keys. `sendinblue()`
always requires a non-empty `$body` regardless of whether a template is used (a legacy
unconditional guard) — pass a placeholder string when the template supplies all content:

```php
$options = [
    'template' => 42,  // Brevo template ID
    'params'   => [
        'car_year'   => 1973,
        'car_model'  => 'Lotus Elan S4',
        'owner_name' => 'John Doe',
    ],
];

sendinblue('owner@example.com', 'Your Car Registration', '(template)', '', $options);
```

### Sending Attachments

The `email()` override does not forward the `attachments` key — use `sendinblue()` directly:

```php
$attachmentContent = base64_encode(file_get_contents('/path/to/receipt.pdf'));

$options = [
    'attachments' => [
        [
            'content' => $attachmentContent,
            'name'    => 'receipt.pdf',
        ],
    ],
];

sendinblue($to, 'Your Receipt', $body, '', $options);
```

### Admin Email Recipients

Registry notification emails are sent to one or more admin addresses configured in the database.
Use `getAdminEmails()` from `usersc/includes/custom_functions.php` to retrieve them:

```php
$adminEmails = array_map('trim', explode(',', getAdminEmails()));
foreach ($adminEmails as $adminEmail) {
    $result = email($adminEmail, $subject, $body);
    if ($result !== true) {
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Admin alert failed to: {$adminEmail}");
    }
}
```

Admin addresses are managed at Admin → Settings → Admin Emails. The default is `registrar@elanregistry.org`.

## Related Documentation

- [CLASSES.md](CLASSES.md) — EmailTemplate class for branded HTML email wrappers
- [Email Colors (design-system.php)](../../app/admin/design-system.php) — Email token → hex mapping and template structure (admin only)
- [ADR-012: Adopt Brevo for Transactional Email Delivery](adr/ADR-012-adopt-brevo-for-transactional-email-delivery.md) — Architecture decision record
- [DATABASE.md](DATABASE.md) — Database schema reference (includes `plg_sendinblue` table)
