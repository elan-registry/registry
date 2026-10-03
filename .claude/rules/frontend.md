---
paths:
  - "package.json"
  - "app/assets/**"
  - "app/admin/assets/**"
  - "usersc/js/**"
  - "usersc/css/**"
  - "usersc/templates/**"
  - "eslint*"
---

# Frontend and template architecture

- The active template is `/usersc/templates/customizer/`. It uses the
  `elanregistry` child theme and Bootstrap 5.3.8. The theme uses UserSpice's
  copies in `users/css` and `users/js`. Do not add another Bootstrap copy to
  `usersc/`. The repository vendors source maps on each `git pull` and deploy
  with `scripts/vendor-bootstrap-maps.php`. See ADR-015.
- UserSpice 6 requires jQuery from `users/js/jquery.php`. Keep it.
- `usersc/js` and `usersc/css` (DataTables, Chart.js, MapLibre GL, FilePond)
  are gitignored build output (ADR-018). Run `npm run build` after install and
  after you edit first-party JS or CSS.
- Architecture decisions are in `docs/development/adr/`. Update ADR-018 when
  you change frontend dependencies. ADR-018 supersedes ADR-017, which
  supersedes ADR-015. ADR-015 still covers Bootstrap source-map vendoring.
  Update ADR-016 for navigation changes and ADR-007 for CSP changes.
- Read `docs/development/UI_STANDARDS.md` before any UI change.
- **Cloudflare Rocket Loader and Email Obfuscation must remain disabled.**
  Both inject inline scripts that break the strict CSP nonce policy.
