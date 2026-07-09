# CSS and Frontend Assets

## CSS File Structure

Registry-specific CSS lives in the Customizer child theme:

```text
usersc/templates/customizer/assets/child_themes/elanregistry/
├── consolidated.css          # Source (edit this)
└── consolidated.min.css      # Production (generated — do not edit directly)
```

App-specific JS/CSS (DataTables, Dropzone, Chart.js) is vendored at `usersc/js/` and `usersc/css/`.
First-party JS/CSS for pages lives at `app/assets/js/` and `app/assets/css/`.

## Build Process

Run after editing any first-party JS or CSS source files:

```bash
npm run build    # Minifies app/assets/js/, app/assets/css/, app/admin/assets/
```

The child theme CSS (`consolidated.css`) is also minified via the build script.

## Frontend Library Loading

Bootstrap 5.3.3 is self-hosted via the Customizer `header.php`.
jQuery is CDN-managed by UserSpice (`users/js/jquery.php`).

Frontend dependencies are vendored under `usersc/js/` and `usersc/css/` per
[ADR-015](adr/ADR-015-self-host-frontend-libraries.md). Update ADR-015 when
changing any dependency.

## Common CSS Tasks

**Add new styles:**

1. Edit `consolidated.css`
2. Run `npm run build` to regenerate `consolidated.min.css`
3. Hard-refresh browser (`Cmd+Shift+R`) to verify

**Remove unused CSS:**

1. Search codebase for class usage
2. Remove from `consolidated.css`
3. Run `npm run build` and test

## CSS Maintenance Checklist

- [ ] Edit source `consolidated.css` (not the `.min.css`)
- [ ] Run `npm run build` to regenerate minified file
- [ ] Test in browser with hard refresh
- [ ] Commit both `.css` and `.min.css` files

## Related

- [ADR-015](adr/ADR-015-self-host-frontend-libraries.md) — Self-hosting frontend libraries
- [UI_STANDARDS.md](UI_STANDARDS.md) — Color tokens and component patterns
