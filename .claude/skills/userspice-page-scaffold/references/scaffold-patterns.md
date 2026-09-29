# UserSpice page scaffold patterns

Read this file completely after gathering the page requirements. Verify every referenced helper against the installed UserSpice source before using a pattern.

**In a repository with an overrides file** (see the skill's Canonical
context section), the templates below need these substitutions. Apply all
that fit the page being scaffolded:

- `Server::get('PHP_SELF')` → the project's validated global (for example,
  `$php_self`), in both the `securePage()` call and the form's `action`
  attribute.
- `Input::get('field')` used to store a value in the database → the
  project's raw-input class (for example, `ElanRegistry\Input::raw('field')`).
  Keep `Input::get()` only for a value rendered as HTML with no further
  escaping.
- A bare `echo json_encode([...])` in the AJAX template → the project's
  response class (for example, `ApiResponse::success()->send()` /
  `ApiResponse::error(...)->send()`), if the overrides file requires one.
- The AJAX template's path → the project's endpoint directory (for example,
  `app/api/<domain>/`) rather than `parsers/`, if the overrides file says so.
  Match the project's `securePage()`/`$path` convention for that directory —
  some projects check authentication inline instead and keep the endpoint out
  of `$path`.
- A page-type target (not `ajax`) → add the project's page-metadata
  variables (for example, `$pageTitle` and `$pageDescription`) as hardcoded
  string literals, set before the bootstrap `require_once`, if the overrides
  file describes this convention.

## Contents

- Placeholders
- Authentication and permission blocks
- Simple, form, and AJAX templates
- Field controls
- Validation rule map

## Placeholders

| Placeholder | Meaning |
|---|---|
| `<INIT_REL>` | Relative path from the generated file to `users/init.php` |
| `<PAGE_TITLE_LITERAL>` | Safely encoded PHP string literal for the user-provided or filename-derived title |
| `<AUTH_CHECK_PAGE>` | Empty for public pages; `securePage(...)` block otherwise |
| `<AUTH_CHECK_AJAX>` | Empty for public endpoints; logged-in check otherwise |
| `<PERMISSION_CHECK_PAGE>` | Optional page permission block |
| `<PERMISSION_CHECK_AJAX>` | Optional JSON permission block |
| `<VALIDATION_RULES>` | Rules derived from form fields |
| `<FORM_FIELDS_HTML>` | Rendered form controls |
| `<FIELD_READS>` | `Input::get(...)` assignments |
| `<DB_WRITE>` | Insert block or TODO for a form page |

## Authentication and permission blocks

Use this page authentication block for `login` and `permission N` modes:

```php
if (!securePage(Server::get('PHP_SELF'))) {
    die();
}
```

Use this page permission block only for `permission N` mode:

```php
if (!$user->hasPermission(<N>)) {
    usError('You do not have permission to view this page.');
    Redirect::to($us_url_root);
}
```

Use this endpoint authentication block for `login` and `permission N` modes:

```php
if (!isset($user) || !$user->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    die();
}
```

Use this endpoint permission block only for `permission N` mode:

```php
if (!$user->hasPermission(<N>)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    die();
}
```

## Simple page

```php
<?php
require_once '<INIT_REL>';
<AUTH_CHECK_PAGE>
<PERMISSION_CHECK_PAGE>
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
?>

<div class="container py-4">
    <h1><?= safeReturn(<PAGE_TITLE_LITERAL>) ?></h1>

    <p>Replace this content with your own.</p>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
```

## Form page that posts to itself

```php
<?php
require_once '<INIT_REL>';
<AUTH_CHECK_PAGE>
<PERMISSION_CHECK_PAGE>

$errors = [];

if (Input::exists('post')) {
    if (!Token::check(Input::get('csrf'))) {
        usError('Invalid security token. Please refresh and try again.');
        Redirect::to(Server::get('PHP_SELF'));
    }

    $validate = new Validate();
    $validate->check($_POST, [
        <VALIDATION_RULES>
    ]);

    if ($validate->passed()) {
        <FIELD_READS>

        <DB_WRITE>

        usSuccess('Saved successfully.');
        Redirect::to(Server::get('PHP_SELF'));
    } else {
        $errors = $validate->errors();
    }
}

require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
?>

<div class="container py-4">
    <h1><?= safeReturn(<PAGE_TITLE_LITERAL>) ?></h1>

    <?= display_errors($errors) ?>

    <form method="post" action="<?= safeReturn(Server::get('PHP_SELF')) ?>">
        <?= tokenHere() ?>

        <FORM_FIELDS_HTML>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
```

## AJAX endpoint inside a parsers folder

```php
<?php
require_once '<INIT_REL>';

header('Content-Type: application/json');

if (!Token::check(Input::get('csrf'))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token']);
    die();
}

<AUTH_CHECK_AJAX>
<PERMISSION_CHECK_AJAX>

<FIELD_READS>

// TODO: replace with the real handler logic.

echo json_encode(['ok' => true]);
```

## Field controls

Generate identifiers from validated field names containing only letters, digits, and underscores. Escape generated labels and fixed option text in the resulting PHP when appropriate.

### Text, email, and number

```html
<div class="mb-3">
    <label for="<NAME>" class="form-label"><LABEL></label>
    <input type="<TYPE>" id="<NAME>" name="<NAME>" class="form-control"
           value="<?= safeReturn(Input::get('<NAME>')) ?>" required>
</div>
```

### Password

Do not repopulate password fields after validation failure.

```html
<div class="mb-3">
    <label for="<NAME>" class="form-label"><LABEL></label>
    <input type="password" id="<NAME>" name="<NAME>" class="form-control" required>
</div>
```

### Hidden

```html
<input type="hidden" id="<NAME>" name="<NAME>" value="<?= safeReturn(Input::get('<NAME>')) ?>">
```

### Textarea

```html
<div class="mb-3">
    <label for="<NAME>" class="form-label"><LABEL></label>
    <textarea id="<NAME>" name="<NAME>" class="form-control" rows="4" required><?= safeReturn(Input::get('<NAME>')) ?></textarea>
</div>
```

### Checkbox

```html
<div class="form-check mb-3">
    <input type="checkbox" id="<NAME>" name="<NAME>" value="1" class="form-check-input"
           <?= Input::get('<NAME>') ? 'checked' : '' ?>>
    <label for="<NAME>" class="form-check-label"><LABEL></label>
</div>
```

### Select

Generate one option per requested value:

```html
<div class="mb-3">
    <label for="<NAME>" class="form-label"><LABEL></label>
    <select id="<NAME>" name="<NAME>" class="form-select" required>
        <option value="opt1" <?= Input::get('<NAME>') === 'opt1' ? 'selected' : '' ?>>opt1</option>
    </select>
</div>
```

## Validation rule map

| Type | Rule entry |
|---|---|
| `text` | `'<NAME>' => ['display' => '<LABEL>', 'required' => true, 'min' => 1, 'max' => 255]` |
| `email` | `'<NAME>' => ['display' => '<LABEL>', 'required' => true, 'valid_email' => true]` |
| `password` | `'<NAME>' => ['display' => '<LABEL>', 'required' => true, 'min' => 8]` |
| `textarea` | `'<NAME>' => ['display' => '<LABEL>', 'required' => true, 'min' => 1, 'max' => 5000]` |
| `number` | `'<NAME>' => ['display' => '<LABEL>', 'required' => true, 'is_numeric' => true]` |
| `checkbox` | Omit; checkboxes are presence/absence |
| `select(...)` | `'<NAME>' => ['display' => '<LABEL>', 'required' => true]` |
| `hidden` | Omit unless explicitly requested |

The numeric rule is `is_numeric`, not `numeric`, in the targeted UserSpice version. Verify available rules in the installed class:

```bash
grep -nE "case '[^']+'" "$INSTALL_ROOT/users/classes/Validate.php" 2>/dev/null
```

`required` is handled outside the `case` blocks. If an expected rule is missing, use only rules confirmed in the installed class and disclose the drift in the post-write summary.
