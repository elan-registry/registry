# ADR-012: Adopt Brevo (Sendinblue) for Transactional Email Delivery

## Status

**In Review** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

The Elan Registry sends transactional emails for critical user workflows:

- User registration verification
- Password reset
- Owner-to-owner contact (car detail page)
- User feedback to admin
- Transfer request notifications (four types: request to owner, admin alert, response to requester, notification to previous owner)
- Admin-to-owner data quality messages
- Car verification requests
- Inactive account grace period warnings
- Passwordless login links

Historically, the application uses PHPMailer bundled with UserSpice (`users/helpers/helpers.php`, lines 158-237) as the sole mail transport, with SMTP configuration stored in the `email` database table and read at runtime on every send.

### The A2 Hosting SMTP Constraint

The application is hosted on A2 Hosting shared hosting. A2 explicitly blocks outbound connections to external SMTP servers (ports 25, 465, 587) for Web Hosting and Reseller accounts:

> All outbound mail is routed through A2's shared MailChannels instance. External SMTP connections are blocked.
>
> — [A2 Hosting: Using External SMTP Servers](https://www.a2hosting.com/kb/getting-started-guide/setting-up-e-mail/using-external-smtp-servers-to-send-e-mail/)

This means:

- **Gmail SMTP (`smtp.gmail.com:587`)**: Blocked. SMTP connections to external servers fail at the network level.
- **Local mail (sendmail/PHP `mail()`)**: No external connection required, but all mail is routed through A2's shared MailChannels spam filter. Every email from every site on that shared hosting instance flows through the same IP reputation pool, creating unpredictable deliverability based on other users' email practices.
- **HTTP API (Brevo, SendGrid, Mailgun)**: A2 explicitly permits HTTP API access on standard ports (80, 443). Their documentation states: "Use of an HTTP API using standard ports is not limited for Web Hosting and Reseller accounts."

### Problem Statement

The current local mail approach creates two critical issues:

1. **No visibility into delivery status.** When a user reports "I never received the password reset email," there is no way to determine if it was rejected by their provider, flagged as spam, or blocked by MailChannels without filing a support ticket with A2 Hosting.

2. **Reputation risk from shared MailChannels pool.** The registry's automated emails (transfer notifications, verification emails) share IP reputation with every other site on the same A2 shared hosting instance. If another site on the pool is sending spam or is poorly maintained, the registry's legitimate emails may be filtered or rate-limited. This is beyond the application's control and difficult to diagnose.

Developers and AI code agents need a clearer path: explicit delivery logging, per-message event tracking, and isolation from other sites' email practices.

### Current Email Architecture

**Core Sending Function** (`users/helpers/helpers.php`, lines 158-237):

```php
function email($to, $subject, $body, $from = null, $reply_to = null, $html = true) {
    // Fetch SMTP config from database
    // Create PHPMailer instance
    // Send via SMTP
}
```

**Database Configuration** (`email` table):

The SMTP server, username, and password are stored in the `email` database table and loaded before every send. This allows runtime reconfiguration without redeploying code.

**UserSpice Plugin Override Hook** (`usersc/scripts/email_function_override.php`):

UserSpice provides a plugin-based mechanism to override email delivery. This file is sourced at Phase 1.12 (late in page load, after initialization is complete) and can replace the core `email()` function with a custom implementation. The override is not currently used.

**Custom Email Templates** (`usersc/classes/EmailTemplate.php`):

Branded HTML email layout wrapping all email content with registry styling, header, and footer.

**Transfer Email Notifications** (`usersc/includes/transfer_email_notifications.php`):

Specialized functions for sending ownership transfer workflow emails. These call the core `email()` function indirectly through wrapper functions.

**Logging Infrastructure** (`usersc/classes/LogCategories.php`):

Already defines `LOG_CATEGORY_SENDINBLUE = 'SendinblueDebug'` constant for future Brevo integration logging.

### Brevo Plugin Status

The Brevo (formerly Sendinblue) integration is partially implemented:

- Brevo PHP SDK is installed at `/usersc/plugins/sendinblue/` via Composer dependency `getbrevo/brevo-php`
- Plugin directory exists but is inactive (`status=2` in plugins.ini.php)
- Plugin follows UserSpice's plugin system conventions (database table `plg_sendinblue` for configuration storage)
- API key can be configured via UserSpice plugin settings UI

## Decision

Adopt **Brevo (Sendinblue) for transactional email delivery** using their HTTP API (`POST /v3/smtp/email`) to replace PHPMailer's local SMTP connectivity. Activate the existing Brevo plugin to intercept email sends and route them through Brevo's infrastructure.

### Integration Model

The Brevo plugin (`/usersc/plugins/sendinblue/`) will implement the UserSpice override hook:

**Step 1: Load Override Hook** (`usersc/scripts/email_function_override.php`)

UserSpice loads this at Phase 1.12 (late in page load). The override replaces the core `email()` function:

```php
// Pseudo-code outline
function email($to, $subject, $body, $from = null, $reply_to = null, $html = true) {
    // Delegate to BrevoEmailService::send()
    // Log success or failure via LogCategories::LOG_CATEGORY_SENDINBLUE
}
```

**Step 2: Delegate to Brevo Service Class** (`usersc/plugins/sendinblue/BrevoEmailService.php`)

```php
class BrevoEmailService {
    public static function send($to, $subject, $body, $from, $reply_to, $html = true) {
        $apiKey = self::getApiKey(); // from plg_sendinblue.api_key
        $apiInstance = new Brevo\Client\Api\TransactionalEmailsApi(
            new GuzzleHttp\Client(),
            $config->setApiKey('api-key', $apiKey)
        );

        $sendSmtpEmail = new Brevo\Client\Model\SendSmtpEmail();
        $sendSmtpEmail->setTo([['email' => $to]]);
        $sendSmtpEmail->setSubject($subject);
        $sendSmtpEmail->setHtmlContent($html ? $body : null);
        $sendSmtpEmail->setTextContent(!$html ? $body : null);
        $sendSmtpEmail->setFrom(['email' => $from ?? 'noreply@elanregistry.org']);
        if ($reply_to) {
            $sendSmtpEmail->setReplyTo(['email' => $reply_to]);
        }

        try {
            $result = $apiInstance->sendTransacEmail($sendSmtpEmail);
            logger(getUser()['id'], LogCategories::LOG_CATEGORY_SENDINBLUE,
                "Email sent via Brevo to $to | Result: " . json_encode($result));
            return true;
        } catch (Exception $e) {
            logger(getUser()['id'], LogCategories::LOG_CATEGORY_SENDINBLUE,
                "Brevo send failed: " . $e->getMessage());
            return false;
        }
    }
}
```

**Step 3: Configuration Management** (Database `plg_sendinblue` Table)

The Brevo plugin stores configuration in a database table:

| Column | Type | Description |
|---|---|---|
| `id` | int PK | Plugin instance ID |
| `api_key` | varchar(255) | Brevo API key (not encrypted, see Consequences) |
| `enabled` | bool | Plugin enabled/disabled status |
| `from_email` | varchar(255) | Sender email address |
| `from_name` | varchar(255) | Sender display name |

Alternatively, the API key could be migrated to SecureEnvPHP (ADR-005) for encryption at rest, but this requires a separate migration task.

### Brevo HTTP API Details

Brevo's transactional email API operates entirely over HTTPS (port 443):

```
POST https://api.brevo.com/v3/smtp/email
Authorization: api-key <API_KEY>
Content-Type: application/json
```

Request body (simplified):

```json
{
  "sender": {
    "email": "noreply@elanregistry.org",
    "name": "Lotus Elan Registry"
  },
  "to": [{ "email": "user@example.com" }],
  "subject": "Verify your email",
  "htmlContent": "<html>...</html>",
  "replyTo": { "email": "support@elanregistry.org" }
}
```

Response (success):

```json
{
  "messageId": "<messageId>"
}
```

**No SMTP Configuration Needed**: Unlike PHPMailer's SMTP setup, Brevo requires no server/port/credentials configuration. Only the API key is needed.

### Logging and Debugging

All Brevo sends are logged via `logger()` using `LogCategories::LOG_CATEGORY_SENDINBLUE`:

```php
logger($userId, LogCategories::LOG_CATEGORY_SENDINBLUE,
    "Email sent via Brevo | To: $to | Subject: $subject | MessageId: $messageId");

logger($userId, LogCategories::LOG_CATEGORY_SENDINBLUE,
    "Brevo send failed: " . $e->getMessage());
```

Developers can then query the application `log` table for `LogCategories::LOG_CATEGORY_SENDINBLUE` entries to trace individual email sends and their success/failure status.

### Brevo Dashboard Features

Once activated, all sends are visible in the Brevo dashboard:

- **Sent**: Message accepted by Brevo API
- **Delivered**: Message accepted by recipient's mail server
- **Opened**: Recipient opened the email (if tracking enabled)
- **Bounced**: Message rejected by recipient's mail server (hard bounce, rate limit, etc.)
- **Spam Reported**: Recipient marked as spam
- **Unsubscribed**: Recipient clicked unsubscribe link

All logs are retained indefinitely (no automatic cleanup). This enables long-tail debugging of "why didn't the user receive their password reset?" months after the fact.

### Free Tier Capacity

Brevo's free tier includes:

- **300 emails per day** with no monthly cap and no expiration
- **Unlimited contacts** stored
- **Full log retention** and event tracking
- **SPF, DKIM, DMARC** authentication setup wizard included

Current registry email volume is well below 300/day:

- Daily active users: ~50 (avg)
- Transfer notifications: ~2-4 per week
- Password resets: ~1-3 per week
- Other transactional: ~5-10 per week
- **Total**: <10/day typical; <50/day peak

Free tier is sufficient indefinitely unless the registry experiences viral growth.

### No PHPMailer Fallback

There is no fallback to PHPMailer. If the Brevo API call fails, the email is not sent. This is an intentional design choice:

- **PHPMailer via local mail is not a viable fallback.** The same A2 Hosting constraints (shared MailChannels pool, no delivery visibility, reputation risk) that motivated adopting Brevo make local mail unsuitable as a fallback path.
- **Silent fallback would mask failures.** If sends silently fell back to local mail, delivery failures would go undetected — defeating the primary purpose of adopting Brevo (visibility and debugging).
- **Failed sends are logged.** The `LogCategories::LOG_CATEGORY_SENDINBLUE` entries in the application log table surface API failures for investigation.

## Consequences

### Positive

- **Resolves A2 SMTP blocking entirely.** HTTP API on port 443 is explicitly permitted by A2. No network-level blocking or configuration workarounds needed.

- **Complete delivery visibility.** Every email is logged with timestamp, recipient, subject, delivery status, bounces, spam flags, and opens. Debugging "why didn't they receive it?" becomes a simple dashboard lookup instead of an A2 support ticket.

- **Isolated from shared IP reputation.** Brevo manages sending from its own dedicated infrastructure. Registry emails are no longer affected by spam from other A2 Hosting sites on the shared MailChannels pool.

- **Brevo manages domain authentication.** SPF, DKIM, and DMARC setup are handled by Brevo's guided wizard. No manual DNS record management or compliance burden on operators.

- **Zero cost at current volume.** Free tier covers 300/day with unlimited retention. Registry operates well below this threshold with no monthly billing.

- **GDPR alignment.** Brevo is an EU-headquartered company (Paris, France) with stronger data residency and GDPR compliance posture than US-based alternatives. DPA available.

- **UserSpice plugin isolation.** Integration is confined to `/usersc/plugins/sendinblue/`. Core application email code (`users/helpers/helpers.php`) remains unchanged. Switching providers in the future requires only plugin replacement, not application refactoring.

- **Existing partial implementation.** The Brevo SDK is already installed at `/usersc/plugins/sendinblue/`. Plugin activation requires only development of the override hook and service class, not full third-party integration.

- **Low operational complexity.** Configuration is a single API key in the database. No certificate management, SMTP credentials rotation, or server configuration changes.

### Negative

- **External SaaS dependency with no fallback.** If Brevo experiences an outage, email delivery fails. There is no fallback to PHPMailer or local mail — the same constraints that motivated adopting Brevo (shared MailChannels pool, no visibility) make local mail unsuitable as a fallback. Users affected by the outage have no visibility that their password reset or transfer notification didn't send. Application continues running but email functionality is unavailable.

- **Recipient data becomes Brevo's.** All recipient addresses, email subject lines, and send timestamps are transmitted to Brevo's infrastructure. Recipient data becomes a third-party processor relationship under GDPR. A Data Processing Agreement (DPA) is required and must be documented.

- **No automatic retry/queue mechanism.** If the Brevo API call fails (network error, API temporarily unavailable, rate limit), the email is not sent and not queued. There is no fallback path. The application must implement its own retry queue if critical emails (e.g., password resets) require guaranteed delivery.

- **Shared free tier IP pool.** Brevo's free tier sends from shared IP addresses along with thousands of other free-tier users. Higher-reputation senders on premium tiers use dedicated IPs. Shared IP reputation could theoretically be affected by other free-tier users' practices, though less severe than the local MailChannels pool.

- **API key stored in plaintext database.** The Brevo API key is stored in the `plg_sendinblue.api_key` column without encryption. While database access is already restricted, this is asymmetric with ADR-005 (encrypted environment variables). Mitigation: migrate API key to SecureEnvPHP in a follow-up task.

- **Brand naming inconsistency.** The company rebranded from Sendinblue to Brevo in 2023, but the plugin directory is still named `/usersc/plugins/sendinblue/`. This is a cosmetic issue but may confuse developers unfamiliar with the rebrand. Follow-up refactor recommended.

- **API key exposure risk in leaked database.** Unlike environment variables (ADR-005), if a database backup or SQL dump leaks, the Brevo API key is exposed in plaintext. An attacker could send emails on behalf of the registry or access email delivery logs. Mitigation: enforce strict database backup access controls and document in security runbooks.

### Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Brevo API outage causes silent email failure | Low-Medium | Medium | Error logging with LogCategories::LOG_CATEGORY_SENDINBLUE; monitor Brevo status page; implement manual retry mechanism for critical emails (password reset, transfer notifications); consider adding optional queue table for guaranteed delivery |
| Free tier volume (300/day) is exceeded | Very Low | Low | Current volume <10/day typical; monitor via Brevo dashboard; upgrade to paid plan if needed; no hard blocking; graceful degradation to local mail if API rate-limited |
| Brevo API key leaked via database backup or SQL injection | Low | High | Enforce strict backup access controls; use SecureEnvPHP (ADR-005) to encrypt API key in environment (follow-up task); API key rotatable in Brevo dashboard; monitor API usage for unauthorized sends |
| Brevo pricing or terms change | Low | Medium | HTTP API integration is provider-agnostic at transport layer; switching providers requires only plugin replacement, not application email refactoring; maintain separation of concerns via UserSpice plugin system |
| Recipient data DPA not in place | Low | High | Execute Brevo DPA immediately; document in Privacy Statement that Brevo is a data processor; conduct DPIA for GDPR compliance; periodic review of DPA terms |
| API key exposure in leaked version control | Very Low | High | `.env.key` file (if stored there) is in `.gitignore`; pre-commit hooks should catch secrets; GitGuardian CI check prevents plaintext keys in commits |
| Plugin naming confusion (sendinblue vs brevo) | Present | Low | No functional impact; rename plugin directory in follow-up refactor; update documentation; developer education |
| Brevo outage leaves no email delivery path | Low | Medium | Monitor Brevo status page; failed sends logged via LogCategories; consider future email queue table for critical emails; Brevo's historical uptime is strong |

## Alternatives Considered

### Continue Using Local Mail (sendmail/MailChannels)

The application currently relies on PHP's `mail()` function and PHPMailer, which routes through A2 Hosting's shared MailChannels instance. No external connection needed.

**Rejected because:**

- **No delivery visibility.** No way to determine if emails are being filtered, rate-limited, or bouncing. Debugging delivery issues requires A2 support tickets with multi-day turnaround.
- **Shared IP reputation risk.** All emails from all sites on the shared hosting instance flow through the same IP. If another site is sending spam or is poorly maintained, the registry's legitimate emails may be filtered or rate-limited without visibility into why.
- **No per-message tracking.** Impossible to trace individual email failures, bounces, or spam reports without manual log inspection and A2 support intervention.
- **Maintenance burden.** Any mail delivery issue requires debugging via A2 panels and support channels; no self-service diagnostics.

### Gmail SMTP (`smtp.gmail.com:587`)

Natural first choice for small applications. Requires SMTP credentials for a Gmail account or workspace account.

**Rejected because:**

- **A2 blocks external SMTP connections.** Outbound SMTP to ports 25, 465, 587 is blocked at the network level on A2 shared hosting. Gmail SMTP connections fail before TLS even negotiates.
- **Google enforces sending rate limits.** Google limits authentication logins and enforces per-sender rate limits on free Gmail accounts. High-volume sends are rate-limited or trigger security challenges.
- **OAuth2 complexity.** Production Gmail SMTP requires OAuth2, not simple username/password. Additional complexity in credential management.
- **Shared IP repuation within Gmail.** Free tier sends from Gmail's shared infrastructure; reputation effects are still possible if gmail.com itself is on spam blocklists (rare but possible during outages).

### Mailgun (HTTP API)

Industry-standard transactional email service with HTTP API, web dashboard, and event webhooks.

**Rejected because:**

- **Limited free tier.** Mailgun's trial period expires after 3 months with no permanent free tier. Production deployment requires paid subscription.
- **Restricted log retention on free tier.** Delivery logs are retained for only 3 days on free/lower-tier plans. For a slow-moving registry with infrequent failures, 3-day retention is insufficient for debugging why a user never received a password reset weeks ago.
- **More aggressive enforcement of free tier limits.** Mailgun is more restrictive with free-tier account suspension and requires credit card for verification.

### SendGrid (Twilio)

Popular transactional email service (owned by Twilio). HTTP API with web dashboard and event webhooks.

**Rejected because:**

- **More restrictive free tier.** 100 emails per day free tier is more restrictive than Brevo's 300/day and still requires paid plan for production use.
- **Less comprehensive free-tier dashboard.** Free tier lacks full delivery log UI and event tracking compared to Brevo. Premium tiers required for most debugging features.
- **More aggressive account suspension on free plans.** SendGrid (Twilio) has reputation for suspending free trial accounts aggressively if volume exceeds expectations.

### Amazon SES (AWS Simple Email Service)

Lowest per-email cost at high volume. AWS-native service with CloudWatch integration and SNS event notifications.

**Rejected because:**

- **Sandbox approval required.** New AWS accounts must request removal from sandbox mode before sending to external addresses. Requires AWS support ticket and verification process.
- **No native delivery dashboard.** Event visibility requires CloudWatch integration and custom log analysis. Less user-friendly than Brevo's built-in dashboard.
- **Heavyweight AWS SDK.** SES requires AWS SDK for PHP, which pulls in large transitive dependencies. Overkill for a single service.
- **No existing AWS infrastructure.** Application is hosted on A2 Hosting, not AWS. SES integration would be an isolated AWS account with no other infrastructure integration.
- **US data residency.** AWS default regions are US-based (Virginia, N. California). Weaker GDPR posture than Brevo's EU-native status for recipient data storage.

### Postmark (ActiveCampaign)

Transactional email specialist with strong deliverability, HTTP API, and comprehensive email templates.

**Rejected because:**

- **No permanent free tier.** Free trial with credit card required; paid subscription required for production.
- **Overkill for current use case.** Postmark's advanced features (template engine, MJML support, webhook signatures) exceed the registry's simple transactional email needs.
- **Fewer alternatives considered by team.** Brevo was the plugin already partially implemented; switching to Postmark would require abandoning the existing integration.

### Implement Custom Email Queue with Retry

Instead of external SaaS, implement a custom email queue table (`email_queue`) with background job processor. Emails are enqueued with retry logic for local SMTP failures.

**Rejected because:**

- **Increases complexity without solving root issue.** Queue system handles retries but doesn't solve the underlying problem: A2 blocks external SMTP and local MailChannels provides no visibility.
- **Requires background job processor (Gearman, RabbitMQ, cron).** A2 shared hosting provides neither; cron-based processing is unreliable and resource-intensive.
- **Postpones SaaS solution without preventing it.** Implementing a queue eventually requires integration with external email provider anyway once retry limits are exhausted.

## References

- **A2 Hosting SMTP Blocking**: [Using External SMTP Servers](https://www.a2hosting.com/kb/getting-started-guide/setting-up-e-mail/using-external-smtp-servers-to-send-e-mail/)
- **A2 Hosting MailChannels Overview**: [A2 Hosting Blog: MailChannels Protection](https://www.a2hosting.com/blog/mailchannels-protecting-our-customers-email-accounts/)
- **Brevo PHP SDK**: [getbrevo/brevo-php on Packagist](https://packagist.org/packages/getbrevo/brevo-php)
- **Brevo SMTP Integration**: [Brevo API Docs: SMTP Integration](https://developers.brevo.com/docs/smtp-integration)
- **Brevo Transactional Email API**: [Brevo API Docs: Send Transactional Email](https://developers.brevo.com/docs/send-transactional-email)
- **Brevo Delivery Logs**: [Brevo Help: Transactional Email Logs](https://help.brevo.com/hc/en-us/articles/360021533839)
- **Brevo GDPR Compliance**: [Brevo: GDPR Compliance](https://www.brevo.com/company/gdpr/)
- **Brevo Data Processing Agreement**: [Brevo Help: Data Processing Agreement](https://help.brevo.com/hc/en-us/articles/15403782599570)
- **Brevo Free Plan Limits**: [Brevo Help: Plans and Pricing](https://help.brevo.com/hc/en-us/articles/208580669)
- **Plugin Implementation**: [/usersc/plugins/sendinblue/](../../usersc/plugins/sendinblue/)
- **Current Email Function**: [users/helpers/helpers.php](../../users/helpers/helpers.php) (lines 158-237)
- **Email Configuration Table**: [Database schema: email table](../development/DATABASE.md)
- **UserSpice Plugin Hook**: [usersc/scripts/email_function_override.php](../../usersc/scripts/email_function_override.php)
- **Custom Email Templates**: [usersc/classes/EmailTemplate.php](../../usersc/classes/EmailTemplate.php)
- **Transfer Notifications**: [usersc/includes/transfer_email_notifications.php](../../usersc/includes/transfer_email_notifications.php)
- **Log Categories**: [usersc/classes/LogCategories.php](../../usersc/classes/LogCategories.php)
- **Related ADRs**:
  - ADR-001: UserSpice authentication and plugin architecture
  - ADR-005: Encrypted environment variables (future API key storage)
  - ADR-008: Car ownership transfer workflow (primary email use case)
- **Nygard ADR Format**: [Documenting Architecture Decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
