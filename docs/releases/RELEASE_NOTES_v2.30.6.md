# Elan Registry v2.30.6 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Tagged Mail on Sendinblue 1.8.0

On local and test, every registry email carries a Brevo tag on sendinblue
1.8.0, so delivery events for verification mail reach the registry. UserSpice
is current on local and test. Production receives all of this in v2.30.9.

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- One-sentence description of what changed and its benefit to users. ([#NNN](https://github.com/elan-registry/registry/issues/NNN))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- **Deploy step:** update the sendinblue plugin to 1.8.0 or later, run its
  migrations, activate the override, and set "Tag untagged mail" to "Calling
  file name". After each plugin update, open "Show file contents" under
  "Override Update Available" on the plugin page, then click "Replace my
  override.php with the bundled version". Brevo reports not ready below 1.8.0,
  when the plugin is inactive, or when `override.php` differs from
  `override.RENAME.php`. Then verification cannot be turned on
  and sends nothing. If the switch is already on, the Brevo webhook
  drops events and logs a `VerificationConfigWarning`. See
  [DEPLOYMENT.md](../development/DEPLOYMENT.md#brevo-sendinblue-plugin).
  ([#2184](https://github.com/elan-registry/registry/issues/2184))
- The admin Verification tab and the enable toggle now show why Brevo is not ready.
  ([#2184](https://github.com/elan-registry/registry/issues/2184))
- All mail now carries a Brevo tag. Verification mail has `car_verification`.
  Other mail has the name of the calling file, for example `forgot_password`.
  The nightly reconciliation job reports a higher `ignoredByTag` count,
  because it ignores all other tags.
  ([#2184](https://github.com/elan-registry/registry/issues/2184))
- **Deploy step (test only):** upgrade UserSpice on test to 6.1.7 when
  you deploy v2.30.6. First back up the database and `users/`. Then go to
  Admin Dashboard > Updates. If the updater stops at 6.1.6, install the
  6.1.7 patch zip from <https://userspice.com/updates/>. Open
  `users/updates/` until migration `2026-09-26a` is applied. Then check
  that Settings > `behind_reverse_proxy` is correct for the host. Then,
  with TOTP on, test these steps that did not run locally: log in with a
  TOTP code, and send wrong codes until the form locks. Make the TOTP key
  file unreadable and check the "temporarily unavailable" message. Check
  that `users.logins` goes up once per login, and not when TOTP fails.
  Production gets this upgrade in v2.30.9
  ([#2088](https://github.com/elan-registry/registry/issues/2088)).
  ([#2253](https://github.com/elan-registry/registry/issues/2253))

## Issues Resolved

- [#2184](https://github.com/elan-registry/registry/issues/2184) — Tag all mail sent through Brevo, and require sendinblue plugin 1.8.0 or later.
- [#2253](https://github.com/elan-registry/registry/issues/2253) — Upgrade
  UserSpice from 6.1.4 to 6.1.7 on local and test (production in v2.30.9),
  and port its security fixes into the project's `login.php`, `join.php` and
  `user_settings.php` copies.
- [#2321](https://github.com/elan-registry/registry/issues/2321) — Close
  #2208, move its last items to `CLEANUP_LEDGER.md`, and delete the
  `cleanup-ledger` label.
- [#2326](https://github.com/elan-registry/registry/issues/2326) — Stop
  `.htaccess` from writing a web-readable error log into the docroot, and deny
  that filename.
- [#2347](https://github.com/elan-registry/registry/issues/2347) — Re-audit
  the sendinblue 1.8.0 `configure.php` against audit Rules 02, 06 and 11, and
  record the result in the issue. No change to the site. The upstream post is
  still to do.
- [#2355](https://github.com/elan-registry/registry/issues/2355) — Add a
  tested patch for `sendinblueRecordEvent()` to propose to the sendinblue
  plugin upstream, with a request for a message-log retention setting. No
  change to the site. The upstream post is still to do.
