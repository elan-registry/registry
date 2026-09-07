# Elan Registry v2.30.2 Release Notes

**Release Date:** September 7, 2026
**Type:** Minor Release - The go-live gate — no real email sends until this closes. Automatic bounce/delivery detection, webhook reconciliation, and auto-clearing on confirmed email change.

## Required Actions After Deployment

None. (Brevo webhook registration, BREVO_WEBHOOK_TOKEN environment variable setup, and `email_events` table migration will be added as issues complete.)

**Note:** Development environments do not have access to Brevo and cannot receive real inbound Brevo webhooks (no public URL reaches a dev machine). Webhook-dependent testing (#1887, #1888, #1889, #1890, #1923) will rely on synthetic/captured payloads for local dev and unit tests, with real end-to-end webhook verification happening only on test.elanregistry.org.

## User-Facing Changes

(No user-facing changes yet — content will be added as issues complete.)

## Admin-Facing Changes

(No admin-facing changes yet — content will be added as issues complete.)

## Issues Resolved

- WIP: [#1887](https://github.com/elan-registry/registry/issues/1887) — feat: automatic bounce & delivery-status detection via Brevo webhooks
- WIP: [#1888](https://github.com/elan-registry/registry/issues/1888) — chore: configure & verify the Brevo webhook
- WIP: [#1889](https://github.com/elan-registry/registry/issues/1889) — feat: nightly Brevo delivery-event reconciliation job
- WIP: [#1890](https://github.com/elan-registry/registry/issues/1890) — feat: auto-clear email_bounced when the owner confirms an email change
- WIP: [#1922](https://github.com/elan-registry/registry/issues/1922) — email: investigate sender reputation — Outlook.com auto-junks registrar@ mail; Brevo suppression list unread
- WIP: [#1923](https://github.com/elan-registry/registry/issues/1923) — feat: import Brevo's suppression list (blockedContacts) into owner email status
- WIP: [#1924](https://github.com/elan-registry/registry/issues/1924) — feat: show email bounce / suppression / verification state on the admin user view hook
- WIP: [#1926](https://github.com/elan-registry/registry/issues/1926) — feat: verification-system feature switch with Brevo prerequisite check and admin warning
- WIP: [#1968](https://github.com/elan-registry/registry/issues/1968) — chore: raise dev, then test and prod, to PHP 8.3 before 8.2 security EOL
