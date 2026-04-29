# ADR-016: File-Based Navigation for Customizer Template

## Status

Accepted

## Date

2026-04-27

## Context

As part of the Bootstrap 5 template migration (#618), the ElanRegistry switched from its custom `ElanRegistry`
template (which used `nav.php`, a file-based approach) to the UserSpice Customizer template.
The Customizer template supports two navigation strategies:

1. **`file_nav.php`** — Navigation defined in a PHP source file (source-controlled)
2. **`dbnav.php`** — Navigation driven by database records (admin-panel configurable)

The choice between these two approaches affects how navigation changes are managed: as code changes
with Git history and code review, or as runtime database state.

### Navigation Requirements

The ElanRegistry navigation includes conditional logic that is not easily expressible in a
database-driven model:

- **Role-based visibility** — Admin items must check user role via `checkMenu(2, ...)` before rendering
- **Login-state conditionals** — "Add Car", "Account" dropdown, and "Register/Login" buttons must
  conditionally render based on authentication state
- **Notification badge with count** — An unread notification badge must display a dynamic count
  fetched via `$notifications->getUnreadCount()`
- **Feature flag checks** — Navigation visibility depends on `$settings->notifications` to gate
  features in beta or staged rollout

These requirements demand runtime PHP logic that cannot be declaratively expressed in database records.

## Decision

Use **`file_nav.php`** (file-based navigation). Migrate ElanRegistry navigation items from the old
`nav.php` to the Customizer's `file_nav.php` using the Customizer's `us_menu`, `sub-toggle`, and
`us_sub-menu` CSS class system for styling and interactivity.

### Implementation

Navigation is defined as a standard HTML menu structure in `/usersc/templates/customizer/file_nav.php`:

```php
<nav class="us_menu">
  <a href="<?=$us_url_root?>index.php">Home</a>

  <?php if (loggedIn()) { ?>
    <div class="sub-toggle">
      <a href="<?=$us_url_root?>app/account">Account</a>
      <ul class="us_sub-menu">
        <li><a href="<?=$us_url_root?>app/profile">Profile</a></li>
        <li><a href="<?=$us_url_root?>app/settings">Settings</a></li>
        <?php if ($settings->notifications) { ?>
          <li><a href="<?=$us_url_root?>app/notifications">
            Notifications
            <?php if ($unread_count = $notifications->getUnreadCount()) { ?>
              <span class="badge"><?=$unread_count?></span>
            <?php } ?>
          </a></li>
        <?php } ?>
      </ul>
    </div>

    <?php if (checkMenu(2, "admin")) { ?>
      <a href="<?=$us_url_root?>users/admin">Admin</a>
    <?php } ?>
  <?php } else { ?>
    <a href="<?=$us_url_root?>users/login.php">Login</a>
    <a href="<?=$us_url_root?>users/join.php">Register</a>
  <?php } ?>
</nav>
```

The navigation uses the Customizer template's built-in CSS classes (`us_menu`, `sub-toggle`,
`us_sub-menu`) for responsive design and dropdown behavior, without requiring database configuration.

## Consequences

### Positive

- **Version-controlled changes.** Navigation structure and logic appear in Git history with full
  diffs and commit messages. Rollback is a `git revert` away.

- **Code review on nav changes.** Any modification to navigation (adding items, changing links,
  adjusting visibility logic) goes through the standard PR review process before deployment.

- **Conditional logic is natural.** Role checks, login-state branching, feature flags, and dynamic
  content (notification counts) are written in standard PHP without abstraction layers.

- **No database state to sync.** Navigation is deployed with the code. No risk of environment drift
  (e.g., test database has stale nav records while prod has current ones).

- **Consistent with self-hosted asset strategy.** Aligns with ADR-015's principle that code and
  deployable assets should be source-controlled, not database-driven.

### Negative

- **Navigation changes require code deploy.** Admins cannot edit navigation via a web interface.
  Any nav change (adding a menu item, updating a link, changing visibility rules) requires a
  developer to edit `file_nav.php`, commit, and deploy.

- **No WYSIWYG editor.** Unlike `dbnav.php` which could support a drag-and-drop nav builder,
  `file_nav.php` requires understanding PHP syntax and the Customizer CSS classes.

## Alternatives Considered

### Use `dbnav.php` (Database-Driven Navigation)

Store navigation items as database records and render them via the Customizer's `dbnav.php` logic.

**Rejected because:**

- Database records cannot express conditional PHP logic (role checks, login state, feature flags,
  dynamic counts). The conditional blocks would have to be moved into `dbnav.php` as special-case
  logic, creating a split between code-controlled visibility (in the template) and
  database-controlled structure (in the nav table). This coupling is fragile.

- The Customizer's default `dbnav.php` assumes a flat structure of menu items with simple links,
  no dynamic content. Extending it to support conditionals would require custom PHP in `dbnav.php`
  to evaluate database records — essentially reimplementing the logic that `file_nav.php` already
  supports.

- Database state drift between environments (test, prod) is a known risk. Navigation records in
  one database may not match another, leading to feature inconsistency across deployments.

### Hybrid: `file_nav.php` with Some Database-Driven Items

Use `file_nav.php` for conditional items (Account dropdown, Admin link, Login/Register) and query
the database for secondary navigation items.

**Rejected because:**

- Adds complexity without clear benefit. If all conditional logic is in code, keeping *any*
  database-driven nav creates a hybrid that is harder to reason about than a single approach.

- The ElanRegistry nav is not large enough to justify splitting the definition across two storage
  mechanisms. All nav items fit comfortably in a single PHP file.

## References

- **Issue #618:** Bootstrap 5 template migration (changed template from ElanRegistry custom to Customizer)
- **Issue #617:** Original navigation acceptance criterion in the Bootstrap 5 milestone
- **Source file:** `/usersc/templates/customizer/file_nav.php`
- **Customizer template:** `/usersc/templates/customizer/` (UserSpice reference template)
- **Related ADR:** [ADR-015](ADR-015-self-host-frontend-libraries.md) (source-controlled assets)
- **Nygard ADR Format:** <https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions>
