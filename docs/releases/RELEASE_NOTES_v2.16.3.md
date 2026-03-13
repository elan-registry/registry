# Elan Registry v2.16.3 Release Notes

**Release Date:** TBD
**Type:** Patch Release - jQuery security upgrade

## REQUIRED ACTIONS AFTER DEPLOYMENT

Update the jQuery CDN tag in the admin settings page:

1. Go to **Admin Panel → Settings → CDN Settings**
2. In the **jQuery CDN URL** field, replace the existing value with:

   ```html
   <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs" crossorigin="anonymous"></script>
   ```

3. Click **Save**

This updates the site-wide jQuery from 3.6.0 to 3.7.1. The error pages
(403/404/500) are updated automatically by the code deploy.

## Technical Changes

- **jQuery Upgrade: Error Pages**
  ([#605](https://github.com/unibrain1/elanregistry/issues/605)): Upgraded
  jQuery slim from 3.5.1 to 3.7.1 with SRI integrity hash on 403, 404, and
  500 error pages. Adds SRI protection previously missing from these pages.

- **jQuery Upgrade: Seed SQL**
  ([#605](https://github.com/unibrain1/elanregistry/issues/605)): Updated
  seed database configuration to reference jQuery 3.7.1 with SRI hash for
  new installations.

- **jQuery Upgrade: Admin Placeholder**
  ([#605](https://github.com/unibrain1/elanregistry/issues/605)): Updated
  admin settings placeholder text to reflect jQuery 3.7.1.

## Issues Resolved

- [#605](https://github.com/unibrain1/elanregistry/issues/605) — Upgrade jQuery to 3.7.x

## Summary

Upgrades jQuery to 3.7.1 with SRI integrity hashes across error pages
(from 3.5.1) and seed configuration. Production site-wide jQuery requires
a manual admin settings update from 3.6.0 to 3.7.1.
