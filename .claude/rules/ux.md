---
paths:
  - "app/views/**"
  - "usersc/views/**"
  - "usersc/templates/**"
  - "app/assets/css/**"
  - "app/owner/**/*.php"
  - "app/admin/**/*.php"
---

# UX and UI copy

## Context

- Audience has two personas:
  - Owners: registered members who manage their cars.
  - Enthusiasts: logged-in or guest visitors who browse the registry.
- Page types:
  - Public car listing: `app/owner/cars/index.php`.
  - Public car detail: `app/owner/cars/details.php`.
  - Owner account: `usersc/account.php`.
  - Car edit form: `app/owner/cars/edit.php`.
  - Admin pages: `app/admin/`.
- Template: Bootstrap 5.3.8, self-hosted. jQuery is available. Font Awesome
  provides the icons.

## Copy

- Use a verb and an object for actions. Use "Update Car", not "Edit". Use
  "Contact Owner", not "Send Message".
- Use "Owner" in UI copy. Use "User" only in auth and system contexts. See
  `CLAUDE.md`, "Terminology".

## When you review UX

- Read `docs/development/UI_STANDARDS.md` before you recommend a component.
- Check colour use against `--er-accent` and Bootstrap semantic colours.
- Check the mobile layout against Bootstrap grid breakpoints.
- New screens use the card patterns in `UI_STANDARDS.md`.
- Name the Bootstrap variant in your output, such as `btn-primary` or
  `btn-outline-light`.

## Car hero card buttons

The hero card renders one of five contexts. `app/views/cars/_car_hero_actions.php`
picks the context in its `switch`. Read that file for the current button sets.

| Context | User | Primary goal | Actions rendered |
| --- | --- | --- | --- |
| `owner_detail` | Owner viewing their car | Verify public display | Update Car |
| `admin_detail` | Admin or editor viewing any car | Manage registry data | Admin Edit Car, Contact Owner |
| `visitor_detail` | Logged-in enthusiast | Learn about / contact | Contact Owner |
| `guest_detail` | Anonymous visitor | Browse | Log in to contact owner |
| `owner_account` | Owner on their own account page | Manage their car | Update Car, View Details |

- Partials take a `$context` string or an `$options` array. Use the form that
  the partial reads.
