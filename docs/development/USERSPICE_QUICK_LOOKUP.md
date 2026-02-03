# UserSpice Quick Lookup Tables

**Quick method reference for UserSpice classes and functions.**

For detailed explanations and code examples, see:
- Full reference: [USERSPICE_FUNCTIONS.md](USERSPICE_FUNCTIONS.md)
- Wiki guide: [UserSpice Integration Guide](https://github.com/jimboone/elan-registry/wiki/Integration)

---

## DB Class Methods

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| getInstance() | `DB::getInstance()` | DB | Get singleton DB instance |
| getDB() | `DB::getDB($config)` | DB | Get alternate database connection |
| query() | `query($sql, $params = [])` | QueryResult | Execute prepared SQL statement |
| get() | `get($table, $where)` | QueryResult | Get records by condition |
| insert() | `insert($table, $fields, $update = false)` | QueryResult | Insert new record |
| update() | `update($table, $id, $fields)` | QueryResult | Update record by ID |
| delete() | `delete($table, $where)` | QueryResult | Delete records by condition |
| deleteById() | `deleteById($table, $id)` | QueryResult | Delete by ID (shorthand) |
| findAll() | `findAll($table)` | QueryResult | Get all records from table |
| findById() | `findById($id, $table)` | QueryResult | Get single record by ID |
| cell() | `cell($tableColumn, $where)` | mixed | Get single value using dot syntax |

---

## DB QueryResult Methods

Chain these methods on DB query results.

| Method | Returns | Purpose |
|--------|---------|---------|
| results($assoc = false) | array | Get all result rows |
| first($assoc = false) | object/array | Get first result row |
| count() | int | Number of affected rows |
| lastId() | int | Last inserted row ID |
| error() | bool | Check if query failed |
| errorInfo() | array | Error codes and messages |
| errorString() | string | Error description |
| getQueryCount() | int | Total queries executed |
| getColCount() | int | Column count in result |
| getColMeta($col) | array | Column metadata |

---

## User Class Methods

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| create() | `create($fields = [])` | bool | Create new user account |
| find() | `find($identifier)` | bool | Find user by ID/email/username |
| login() | `login($username, $password)` | bool | Log in by username |
| loginEmail() | `loginEmail($email, $password)` | bool | Log in by email |
| logout() | `logout()` | void | Destroy user session |
| isLoggedIn() | `isLoggedIn()` | bool | Check if user logged in |
| data() | `data()` | object | Get user data object |
| exists() | `exists()` | bool | Check if user exists |
| update() | `update($fields)` | bool | Update user data |
| notLoggedInRedirect() | `notLoggedInRedirect($page)` | void | Redirect if not logged in |

---

## Validation & Input Classes

### Validate Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| check() | `check($source, $items = [], $sanitize = false)` | Validate | Validate input fields |
| addError() | `addError($error)` | void | Add validation error |
| errors() | `errors()` | array | Get all validation errors |
| passed() | `passed()` | bool | Check if validation passed |
| display_errors() | `display_errors()` | void | Display errors on page |

### Input Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| exists() | `exists($type = 'post')` | bool | Check if input exists (GET/POST) |
| get() | `get($item)` | mixed | Get input value |
| sanitize() | `sanitize($item)` | string | Sanitize input for XSS |
| json() | `json($json, $associative = false, $encode = false)` | mixed | Parse/encode JSON |
| recursive() | `recursive($object)` | mixed | Recursively process object |

---

## Security & Token Classes

### Token Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| generate() | `Token::generate()` | string | Generate CSRF token |
| check() | `check($token)` | bool | Verify CSRF token |

### Hash Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| make() | `make($string, $salt = '')` | string | Hash password (bcrypt) |
| unique() | `unique()` | string | Generate unique hash |

### Session Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| put() | `put($name, $value)` | void | Set session value |
| get() | `get($name)` | mixed | Get session value |
| has() | `has($name)` | bool | Check if session exists |
| delete() | `delete($name)` | void | Delete session value |
| uagent_no_parse() | `uagent_no_parse()` | string | Get user agent |

### Cookie Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| put() | `put($name, $value, $expiry)` | void | Set cookie |
| get() | `get($name)` | mixed | Get cookie value |
| has() | `has($name)` | bool | Check if cookie exists |
| delete() | `delete($name)` | void | Delete cookie |

---

## Permission & Access Control

| Function | Signature | Returns | Purpose |
|----------|-----------|---------|---------|
| hasPerm() | `hasPerm($permissions, $id = null)` | bool | Check user permission(s) |
| fetchUserPermissions() | `fetchUserPermissions($user_id)` | array | Get all user permissions |
| fetchPagePermissions() | `fetchPagePermissions($page_id)` | array | Get page requirements |
| addPermission() | `addPermission($permission_ids, $members)` | bool | Add permission to user(s) |
| removePermission() | `removePermission($permissions, $members)` | bool | Remove permission from user(s) |
| isAdmin() | `isAdmin()` | bool | Check if user is admin (perm 2) |
| ipCheck() | `ipCheck()` | string | Get client IP address |

---

## Configuration & Redirect

### Config Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| get() | `get($path)` | mixed | Get config value by dot path |
| set() | `set($path, $value)` | void | Set config value |

### Redirect Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| to() | `to($location, $args = [])` | void | Safe redirect to URL |
| sanitized() | `sanitized($location, $args = [], $code = 302, $options = [])` | void | Redirect with validation |

### Server Class

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| get() | `get($key, $default = '')` | string | Get validated $_SERVER value |

---

## Menu System

| Method | Signature | Returns | Purpose |
|--------|-----------|---------|---------|
| __construct() | `new Menu($id_or_name)` | Menu | Create menu instance |
| display() | `display($override = [])` | void | Render menu HTML |
| items() | `items()` | array | Get menu items |

---

## User Helper Functions

| Function | Signature | Returns | Purpose |
|----------|-----------|---------|---------|
| getUser() | `getUser($user_id)` | object | Get user by ID |
| getUserId() | `getUserId()` | int | Get current user ID |
| getUserUsername() | `getUserUsername($user_id)` | string | Get username |
| getUserEmail() | `getUserEmail($user_id)` | string | Get user email |
| createUser() | `createUser($username, $email, $password)` | int | Create new user |
| deleteUser() | `deleteUser($user_id)` | bool | Delete user account |
| updateUser() | `updateUser($user_id, $fields)` | bool | Update user data |
| fetchUserTags() | `fetchUserTags($user_id)` | array | Get user tags |
| tagUser() | `tagUser($user_id, $tags)` | bool | Add tags to user |
| untagUser() | `untagUser($user_id, $tags)` | bool | Remove tags from user |

---

## Page Management Functions

| Function | Signature | Returns | Purpose |
|----------|-----------|---------|---------|
| getPages() | `getPages()` | array | Get all pages |
| getPage() | `getPage($page_id)` | object | Get page by ID |
| createPage() | `createPage($path, $title, $private = 0)` | int | Create new page |
| updatePage() | `updatePage($page_id, $fields)` | bool | Update page |
| deletePage() | `deletePage($page_id)` | bool | Delete page |

---

## Display & Output Helpers

| Function | Returns | Purpose |
|----------|---------|---------|
| display_errors() | void | Display validation errors |
| err() | void | Display error message |
| err_view() | string | Get error HTML |
| succ() | void | Display success message |
| succ_view() | string | Get success HTML |
| testForAjax() | bool | Check if AJAX request |
| getBytes() | string | Get file size in bytes |

---

## Email Functions

| Function | Signature | Purpose |
|----------|-----------|---------|
| email() | `email($to, $subject, $body, $headers = [])` | Send email via PHPMailer |
| emailHtmlTemplate() | `emailHtmlTemplate($template, $data)` | Render email template |

---

## Utility Functions

| Function | Signature | Returns | Purpose |
|----------|-----------|---------|---------|
| logger() | `logger($user_id, $log_type, $log_note, $metadata = [])` | bool | Log user action |
| protect() | `protect($str)` | string | Escape for HTML output |
| cleanStr() | `cleanStr($str)` | string | Remove special characters |
| slug() | `slug($text)` | string | Convert text to URL slug |
| moneyFormat() | `moneyFormat($amount)` | string | Format currency |
| jsonResponse() | `jsonResponse($data)` | void | Send JSON response |

---

## Plugin & Hook System

| Function | Signature | Purpose |
|----------|-----------|---------|
| loadPlugins() | `loadPlugins()` | Load all plugins |
| getMyHooks() | `getMyHooks($options)` | Get hooks for page |
| includeHook() | `includeHook($hooks, $location)` | Include hook file |
| installPlugin() | `installPlugin($plugin_name)` | Install plugin |
| activatePlugin() | `activatePlugin($plugin_name)` | Activate plugin |
| deactivatePlugin() | `deactivatePlugin($plugin_name)` | Deactivate plugin |

---

## Session & Message Helpers

| Function | Signature | Purpose |
|----------|-----------|---------|
| message() | `message($type, $msg)` | Set flash message |
| displayMessage() | `displayMessage()` | Display flash messages |
| clearMessages() | `clearMessages()` | Clear all messages |

---

## Menu Helper Functions

| Function | Signature | Returns | Purpose |
|----------|-----------|---------|---------|
| getMenus() | `getMenus()` | array | Get all menus |
| getMenu() | `getMenu($menu_id)` | object | Get menu by ID |
| createMenu() | `createMenu($menu_name, $type = 0)` | int | Create new menu |
| deleteMenu() | `deleteMenu($menu_id)` | bool | Delete menu |
| getMenuItems() | `getMenuItems($menu_id)` | array | Get menu items |
| addMenuItem() | `addMenuItem($menu_id, $label, $link, $parent_id = 0)` | int | Add menu item |
| deleteMenuItem() | `deleteMenuItem($item_id)` | bool | Delete menu item |

---

## Form Manager Functions

| Function | Signature | Purpose |
|----------|-----------|---------|
| loadFormManager() | `loadFormManager()` | Load form manager |
| createForm() | `createForm($form_name, $fields)` | Create custom form |
| renderForm() | `renderForm($form_name)` | Render form HTML |
| processForm() | `processForm($form_name)` | Process form submission |

---

## Common Patterns

### Database Query Pattern
```php
$db = DB::getInstance();
$result = $db->query("SELECT * FROM users WHERE id = ?", [$userId]);
if ($result->count() > 0) {
    $user = $result->first();
}
```

### User Login Pattern
```php
$user = new User();
if ($user->login($username, $password)) {
    // User logged in successfully
    $userData = $user->data();
}
```

### Permission Check Pattern
```php
if (hasPerm([2], $user->data()->id)) {
    // User is admin (permission 2)
}
```

### CSRF Token Pattern
```php
// Generate token in form
<?= Token::generate(); ?>

// Check token in processing
if (!Token::check($fields['csrf'])) {
    throw new Exception('Invalid CSRF token');
}
```

### Input Validation Pattern
```php
$validate = new Validate();
$validation = $validate->check($fields, [
    'email' => ['required' => true, 'email' => true],
    'password' => ['required' => true, 'min' => 8],
]);

if ($validation->passed()) {
    // Data is valid
} else {
    // Display errors
    $validate->display_errors();
}
```

---

**See [USERSPICE_FUNCTIONS.md](USERSPICE_FUNCTIONS.md) for detailed explanations and extended examples.**
