# Elan Registry v2.30.6 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Tagged Mail on Sendinblue 1.8.0

On local and test, every registry email carries a Brevo tag on sendinblue
1.8.0, so delivery events for verification mail reach the registry. UserSpice
is current on local and test. Production receives all of this in v2.30.9.
The upstream posts for #2347 and #2355 are pending (low priority).

## User-Facing Changes

- After you change your password, all saved sign-ins are cleared, on every
  device. Your current session stays open. ([#2253](https://github.com/elan-registry/registry/issues/2253))
- Repeated wrong TOTP codes now lock the login form for a short time. If the
  TOTP key file is unreadable, the form shows a "temporarily unavailable"
  message. ([#2253](https://github.com/elan-registry/registry/issues/2253))

## Admin-Facing Changes

- Verification needs sendinblue plugin 1.8.0 or later with the bundled
  override active. ([#2184](https://github.com/elan-registry/registry/issues/2184))
- The admin Verification tab and the enable toggle now show why Brevo is not
  ready. ([#2184](https://github.com/elan-registry/registry/issues/2184))
- All mail now carries a Brevo tag, so once verification is enabled (v2.30.9)
  the nightly reconciliation job reports a higher `ignoredByTag` count. ([#2184](https://github.com/elan-registry/registry/issues/2184))
- Local and test run UserSpice 6.1.7, and production stays on 6.1.4 until v2.30.9.
  ([#2253](https://github.com/elan-registry/registry/issues/2253))

## Issues Resolved

- [#2184](https://github.com/elan-registry/registry/issues/2184) —
  Tag all mail sent through Brevo and require sendinblue plugin 1.8.0 or later.
- [#2253](https://github.com/elan-registry/registry/issues/2253) —
  Upgrade UserSpice from 6.1.4 to 6.1.7 on local and test, and port its security
  fixes into the project copies of `login.php`, `join.php`,
  `user_settings.php`, `navigation.php` and `default_login.php`. Add
  `session.use_strict_mode` to `users/init.php`.
- [#2321](https://github.com/elan-registry/registry/issues/2321) —
  Move the last items of #2208 to `CLEANUP_LEDGER.md` and delete the
  `cleanup-ledger` label.
- [#2326](https://github.com/elan-registry/registry/issues/2326) —
  Stop `.htaccess` from writing a web-readable error log into the docroot.
- [#2347](https://github.com/elan-registry/registry/issues/2347) —
  Re-audit the sendinblue 1.8.0 `configure.php` and record the result in the
  issue.
- [#2355](https://github.com/elan-registry/registry/issues/2355) —
  Add a tested `sendinblueRecordEvent()` patch to propose to the sendinblue
  plugin upstream.
