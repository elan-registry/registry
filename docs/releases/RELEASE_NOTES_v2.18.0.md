# Elan Registry v2.18.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Documentation v2

## Required Actions After Deployment

1. **Run `./scripts/install-dependencies.sh --prod`** (#631) — installs `vlucas/phpdotenv`
   (replaces abandoned `johnathanmiller/secure-env-php`). Must run before the site boots.

2. **Migrate credentials to `.env`** (#631) — create `.env` from `.env.enc` data, set
   `chmod 600 .env`, then securely delete `.env.enc` and `.env.key`:

   ```bash
   chmod 600 .env
   shred -vfz -n 3 .env.enc .env.key
   ```

3. **Add Turnstile keys to `.env`** (#630) — obtain keys from Cloudflare Dashboard → Turnstile:

   ```
   TURNSTILE_SITE_KEY=your_site_key
   TURNSTILE_SECRET_KEY=your_secret_key
   ```

4. **Register Turnstile hooks via Hooker plugin** (#630) — Admin → Plugin Manager →
   Hooker → Configure. Add five hooks pointing to the new files in
   `usersc/plugins/hooker/hooks/`:

   | Page | Position | Hook file |
   |------|----------|-----------|
   | `login.php` | `form` | `hooks/login_form_turnstile.php` |
   | `login.php` | `post` | `hooks/login_post_turnstile.php` |
   | `join.php` | `form` | `hooks/join_form_turnstile.php` |
   | `joinAttempt` | `body` | `hooks/join_post_turnstile.php` |
   | `forgot_password.php` | `post` | `hooks/forgotpassword_post_turnstile.php` |

5. Verify URL redirects in `docs/.htaccess` are working after docs reorganization (#559)
6. Run `./scripts/setup-git-hooks.sh` on each developer machine to install new git hooks (#684)

## User-Facing Changes

### New Features

- **Cloudflare Turnstile CAPTCHA** ([#630](https://github.com/unibrain1/elanregistry/issues/630)):
  Login, registration, and password-reset forms now use Cloudflare Turnstile instead of
  Google reCAPTCHA, eliminating Google data sharing and improving GDPR posture for EU members.

### Improvements

- **Documentation reorganized by intent** ([#559](https://github.com/unibrain1/elanregistry/issues/559)):
  Documentation is now structured around owner intent — "How do I use the registry?" (Owner
  Guides) vs. "Tell me about my car" (Technical Reference) — with a simplified 5-item
  navigation menu replacing the previous 9-item structure.

## Technical Changes

- **Documentation portal template consolidation** ([#350](https://github.com/unibrain1/elanregistry/issues/350)):
  Shared `DocumentPortalTemplate` class eliminates ~60% of duplicated HTML across
  documentation portal pages.
- **MarkdownParser XSS hardening** ([#635](https://github.com/unibrain1/elanregistry/issues/635)):
  `sanitizeHtml()` now uses a DOM-based attribute allowlist to strip event handlers and
  block `javascript:`, `data:`, and `vbscript:` URIs (including control-character and
  mixed-case bypass variants) from allowed HTML tags.
- **Replace abandoned secure-env-php with phpdotenv** ([#631](https://github.com/unibrain1/elanregistry/issues/631)):
  Swaps the first-boot env library for the actively maintained `vlucas/phpdotenv`; credentials
  move from `.env.enc` + `.env.key` to plaintext `.env` with `chmod 600`.
- **Remove stale test artifacts** ([#686](https://github.com/unibrain1/elanregistry/issues/686)):
  Removes 7 committed artifact files (migration reports, deprecated PHPUnit config, Selenium
  suite) and adds `tests/reports/` and `tests/javascript/` to `.gitignore`.
- **Admin include XSS hardening** ([#675](https://github.com/unibrain1/elanregistry/issues/675)):
  Audit and hardening of 26 Semgrep-flagged echo points across four admin include files.
- **VERSION file auto-update via git hooks** ([#684](https://github.com/unibrain1/elanregistry/issues/684)):
  Post-commit, post-merge, and post-checkout hooks keep the VERSION file current on developer
  machines without relying on the PHP fallback in ApplicationVersion.

## Issues Resolved

- [#350](https://github.com/unibrain1/elanregistry/issues/350) — Consolidate Documentation Portal Templates and Reduce Duplication
- [#559](https://github.com/unibrain1/elanregistry/issues/559) — Reorganize documentation by user intent instead of format
- [#630](https://github.com/unibrain1/elanregistry/issues/630) — Replace Google reCAPTCHA plugin with Cloudflare Turnstile
- [#631](https://github.com/unibrain1/elanregistry/issues/631) — Replace abandoned johnathanmiller/secure-env-php with vlucas/phpdotenv
- [#635](https://github.com/unibrain1/elanregistry/issues/635) — Improve MarkdownParser::sanitizeHtml() to strip event handlers from allowed tags
- [#675](https://github.com/unibrain1/elanregistry/issues/675) — security: audit admin include files for unescaped echo points (XSS hardening)
- [#684](https://github.com/unibrain1/elanregistry/issues/684) — chore: auto-update VERSION file via post-commit/post-checkout git hooks
- [#686](https://github.com/unibrain1/elanregistry/issues/686) — chore: remove stale test artifacts and update .gitignore

## Summary

[To be completed on release: total issues resolved, PRs merged, key themes.]
