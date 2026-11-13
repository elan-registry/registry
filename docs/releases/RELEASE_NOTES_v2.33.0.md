# Elan Registry v2.33.0 Release Notes

**Release Date:** November 13, 2026
**Type:** Minor Release - Search Visitors Find the Right Page

A search visitor lands on the correct production page, not a 404, a test-site page, or a duplicate URL.

## User-Facing Changes

- Old `/stats.php`, `/stories` and `/stories/` links now open the statistics and car stories pages.

## Admin-Facing Changes

## Issues Resolved

- [#2668](https://github.com/elan-registry/registry/issues/2668) — Remove ledger items that duplicate an issue or change behavior.
- WIP: [#2247](https://github.com/elan-registry/registry/issues/2247) — Redirect legacy `/stats.php` and `/stories` URLs that return 404.
- WIP: [#2246](https://github.com/elan-registry/registry/issues/2246) — Stop Google from indexing the test site.
- WIP: [#1960](https://github.com/elan-registry/registry/issues/1960) — Build the canonical URL from a clean path, not the raw request URI.
- WIP: [#1528](https://github.com/elan-registry/registry/issues/1528) — Give each car detail page its own title and description.
- WIP: [#2505](https://github.com/elan-registry/registry/issues/2505) — Fix the contact-owner form 500 error for cars owned by the `noowner` account.
- WIP: [#1455](https://github.com/elan-registry/registry/issues/1455) — Keep typed Add/Edit Car data after navigation or CSRF expiry.
