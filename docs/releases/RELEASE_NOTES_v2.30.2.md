# Elan Registry v2.30.2 Release Notes

**Release Date:** September 7, 2026
**Type:** Minor Release - The go-live gate — no real email sends until this closes. Automatic bounce/delivery detection, webhook reconciliation, and auto-clearing on confirmed email change.

## Required Actions After Deployment

1. Run pending migrations (`composer migrate`) — adds `cars.email_bounced_address`,
   `cars.email_suppressed`, the new `er_email_events` table, and
   `er_verification_settings.unmatched_webhook_recipient_count`. See #1887.
2. Generate and set `BREVO_WEBHOOK_TOKEN` on **each environment that will
   receive real Brevo webhook calls** (test.elanregistry.org and
   elanregistry.org — not needed on dev, see note below). This is the bearer
   token `app/api/webhooks/brevo.php` requires on every inbound request; an
   empty/missing value rejects all requests (fail-closed), so the receiver is
   inert until this is set.
   - Generate a token with at least 32 bytes of cryptographic randomness, e.g.:
     ```bash
     openssl rand -hex 32
     ```
   - Add it to that environment's `.env` (not `.env.example`, which stays a
     placeholder):
     ```
     BREVO_WEBHOOK_TOKEN=<the generated value>
     ```
     `chmod 600 .env` if not already set.
   - Configure the same value as the bearer token/custom header Brevo sends
     with each webhook call for this domain (webhook registration and
     verification is #1888's job — do this step when #1888 configures the
     Brevo-side webhook URL for that environment).
   - Treat this token as a credential: do not commit it, do not log it (the
     endpoint only ever logs a hashed prefix on rejection), and rotate it by
     generating a new value and updating both sides (`.env` and the Brevo
     webhook config) together — a rotation with only one side updated causes
     every webhook call to be rejected until both match again.

**Note:** Development environments do not have access to Brevo and cannot receive real inbound Brevo webhooks (no public URL reaches a dev machine) — `BREVO_WEBHOOK_TOKEN` is not needed there. Webhook-dependent testing (#1887, #1888, #1889, #1890, #1923) will rely on synthetic/captured payloads for local dev and unit tests, with real end-to-end webhook verification happening only on test.elanregistry.org.

## User-Facing Changes

(No user-facing changes yet — content will be added as issues complete.)

## Admin-Facing Changes

- New "Verification System" tab on the admin management page (`app/admin/index.php?tab=verification`), visible to admins and editors. Shows whether Brevo email delivery and the cron transport are configured/healthy, and lets an admin turn the site-wide verification feature switch on or off. The switch defaults **off** and ships with no way to enable real verification sends until a future release — this issue only builds the gate. Enabling the switch is blocked while Brevo isn't configured; disabling it is never blocked, even mid-incident, so an admin can always turn it off. A dashboard banner appears if the switch is left on with a broken prerequisite. (#1926)

## Issues Resolved

- [#1887](https://github.com/elan-registry/registry/issues/1887) — feat: automatic bounce & delivery-status detection via Brevo webhooks
- WIP: [#1888](https://github.com/elan-registry/registry/issues/1888) — chore: configure & verify the Brevo webhook
- WIP: [#1889](https://github.com/elan-registry/registry/issues/1889) — feat: nightly Brevo delivery-event reconciliation job
- WIP: [#1890](https://github.com/elan-registry/registry/issues/1890) — feat: auto-clear email_bounced when the owner confirms an email change
- WIP: [#1922](https://github.com/elan-registry/registry/issues/1922) — email: investigate sender reputation — Outlook.com auto-junks registrar@ mail; Brevo suppression list unread
- WIP: [#1923](https://github.com/elan-registry/registry/issues/1923) — feat: import Brevo's suppression list (blockedContacts) into owner email status
- WIP: [#1924](https://github.com/elan-registry/registry/issues/1924) — feat: show email bounce / suppression / verification state on the admin user view hook
- [#1926](https://github.com/elan-registry/registry/issues/1926) — feat: verification-system feature switch with Brevo prerequisite check and admin warning
- [#1968](https://github.com/elan-registry/registry/issues/1968) — chore: raise dev, then test and prod, to PHP 8.4 before 8.2 security EOL
- [#2001](https://github.com/elan-registry/registry/issues/2001) — chore: extract cron transport interval into a shared, discoverable constant (`CRON_TRANSPORT_INTERVAL_MINUTES` in `usersc/includes/config.php`); no behavior change
