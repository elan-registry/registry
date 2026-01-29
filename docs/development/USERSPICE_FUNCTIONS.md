# UserSpice Functions & Methods Reference

> **This is a reference copy** from <https://userspice.com/kb/> and is **NOT the
> source of truth**. The authoritative documentation lives on the UserSpice
> website. This file exists so AI coding assistants understand UserSpice
> capabilities, leverage existing framework functions, and avoid duplicating
> functionality.
>
> **Generated:** 2026-01-28

---

## Table of Contents

- [DB Class](#db-class)
- [User Class](#user-class)
- [Validate Class](#validate-class)
- [Session Class](#session-class)
- [Cookie Class](#cookie-class)
- [Token Class](#token-class)
- [Hash Class](#hash-class)
- [Input Class](#input-class)
- [Config Class](#config-class)
- [Redirect Class](#redirect-class)
- [Menu Class](#menu-class)
- [Permission & Access Control](#permission--access-control)
- [User Helper Functions](#user-helper-functions)
- [Page Management](#page-management)
- [Display & Output Helpers](#display--output-helpers)
- [Utility Functions](#utility-functions)
- [Plugin & Hook System](#plugin--hook-system)
- [Form Manager](#form-manager)
- [Session & Message Helpers](#session--message-helpers)
- [Menu Helper Functions](#menu-helper-functions)
- [Deprecated Functions](#deprecated-functions)
- [Quick Lookup Table](#quick-lookup-table)

---

## DB Class

File: `users/classes/DB.php`

### DB::getInstance()

Returns singleton DB instance.

```php
$db = DB::getInstance();
```

### DB::getDB($config)

Returns a DB connection for alternate databases.

```php
$db2 = DB::getDB(['otherDBname']);
$db2 = DB::getDB(["host", "dbname", "username", "password"]);
```

### DB::query($sql, $params = [])

Executes SQL with prepared statements. Chain with `results()`, `first()`,
`count()`, etc.

```php
$db->query("SELECT * FROM users WHERE id = ?", [1]);
```

### DB::get($table, $where)

Retrieves records matching conditions.

```php
$db->get('users', ['id', '=', 5]);
```

### DB::insert($table, $fields, $update = false)

Inserts a record. Pass `$update = true` for duplicate key handling.

```php
$db->insert('users', ['username' => 'john', 'email' => 'john@example.com']);
```

### DB::update($table, $id, $fields)

Updates a record by ID.

```php
$db->update('users', 5, ['email' => 'new@example.com']);
```

### DB::delete($table, $where)

Deletes records matching conditions. Supports `=`, `<`, `>`, `LIKE`, `IN`,
`BETWEEN`, `IS NULL`, `REGEXP`, and more.

```php
$db->delete('users', ['id', '=', 5]);
```

### DB::deleteById($table, $id)

Shorthand to delete by ID.

```php
$db->deleteById('users', 5);
```

### DB::findAll($table)

Returns all records from a table.

### DB::findById($id, $table)

Returns a single record by ID.

### DB::cell($tableColumn, $where)

Returns a single value using dot syntax.

```php
$email = $db->cell('users.email', ['id', '=', 123]);
```

### Result Methods

| Method | Returns |
| --- | --- |
| `results($assoc = false)` | All result rows (objects or arrays) |
| `first($assoc = false)` | First result row |
| `count()` | Affected row count |
| `lastId()` | Last inserted ID |
| `error()` | `bool` - whether query failed |
| `errorInfo()` | Error codes and messages |
| `errorString()` | Error description string |
| `getQueryCount()` | Total queries executed |
| `getColCount()` | Column count from result |
| `getColMeta($col)` | Column metadata |

### DB::action($action, $table, $where = [])

Internal method for SQL processing. Generally no advantage to using directly.

---

## User Class

File: `users/classes/User.php`

### user->create($fields = [])

Creates a new user account. Returns new user ID. Does not auto-assign
permissions or validate uniqueness.

```php
$newId = $user->create([
    'username' => Input::get('username'),
    'email'    => Input::get('email'),
    'password' => password_hash(Input::get('password', true), PASSWORD_BCRYPT, ['cost' => 12]),
    'permissions' => 1,
    'join_date' => date('Y-m-d H:i:s'),
]);
```

### user->find($identifier)

Finds a user by ID (int), email, or username (string). Returns `bool`.

```php
$user->find(2);
$user->find('bob@aol.com');
$user->find('admin');
```

### user->login($username, $password)

Authenticates with username and password.

### user->loginEmail($email, $password)

Authenticates with email and password.

### user->logout()

Logs out the user. Prefer redirecting to `users/logout.php` instead.

### user->isLoggedIn()

Returns `bool` - whether user has active session.

```php
if (isset($user) && $user->isLoggedIn()) { /* ... */ }
```

### user->data()

Returns object with all user table columns as properties.

```php
echo $user->data()->username;
echo $user->data()->email;
```

### user->exists()

Returns `bool`. Prefer `isLoggedIn()` instead.

### user->update($fields)

Updates the logged-in user's data.

```php
$user->update(['username' => 'bob']);
```

### user->notLoggedInRedirect($page)

Redirects unauthenticated users. Prefer `securePage()` or `isLoggedIn()`.

---

## Validate Class

File: `users/classes/Validate.php`

### Validate::check($source, $items = [], $sanitize = false)

Validates input against rules. Returns `$this` for chaining. Supported rules:
`min`, `max`, `matches`, `unique`, `unique_update`, `is_numeric`,
`valid_email`, `is_not_email`, `is_integer`, `is_timezone`, `in`,
`is_datetime`, `is_in_array`, `is_in_database`,
`is_valid_north_american_phone`, and comparison operators.

### Validate::addError($error)

Adds an error message to the validation errors array.

### Validate::errors()

Returns the accumulated errors array.

### Validate::passed()

Returns whether validation passed (no errors).

### Validate::display_errors()

Deprecated. Use JavaScript-based solutions instead.

---

## Session Class

File: `users/classes/Session.php`

| Method | Description |
| --- | --- |
| `Session::put($name, $value)` | Store a session value |
| `Session::get($name)` | Retrieve a session value |
| `Session::exists($name)` | Check if session key exists (`bool`) |
| `Session::delete($name)` | Remove a session key |
| `Session::flash($name)` | Refresh/flash a session |
| `Session::uagent_no_version()` | Get browser/OS info without version |

```php
Session::put('us_lang', 'en-US');
$lang = Session::get('us_lang');
if (Session::exists('user')) { /* ... */ }
Session::delete('user');
```

---

## Cookie Class

File: `users/classes/Cookie.php`

| Method | Description |
| --- | --- |
| `Cookie::put($name, $value, $expiry)` | Set a cookie |
| `Cookie::get($name)` | Get cookie value |
| `Cookie::exists($name)` | Check if cookie exists (`bool`) |
| `Cookie::delete($name)` | Delete a cookie |

```php
Cookie::put('UserSpice', 'data', Config::get('remember/cookie_expiry'));
```

---

## Token Class

File: `users/classes/Token.php`

### Token::generate()

Creates a CSRF token and stores it in the session.

### Token::check($token)

Verifies submitted token matches session token. Returns `bool`.

```php
$token = Input::get('csrf');
if (!Token::check($token)) {
    include($abs_us_root.$us_url_root.'usersc/scripts/token_error.php');
}
```

---

## Hash Class

File: `users/classes/Hash.php`

### Hash::make($string, $salt = '')

Returns SHA-256 hash. Not for passwords; use for integrity checks.

### Hash::unique()

Returns a time-based unique hash via `uniqid()`.

---

## Input Class

File: `users/classes/Input.php`

### Input::exists($type = 'post')

Checks if POST or GET data exists. Returns `bool`.

### Input::get($item)

Retrieves and sanitizes a form input value.

```php
$username = Input::get('username');
```

### Input::sanitize($item)

Sanitizes non-form data for safe storage.

### Input::json($json, $associative = false, $encode = false)

Processes JSON data - decodes, cleans recursively, optionally re-encodes.

### Input::recursive($object)

Recursively processes/sanitizes arrays or objects.

---

## Config Class

File: `users/classes/Config.php`

### Config::get($path)

Retrieves config value using slash-separated path. Returns value or `false`.

```php
$dbUser = Config::get('mysql/username');
```

---

## Redirect Class

File: `users/classes/Redirect.php`

### Redirect::to($location, $args)

Redirects to a specified location.

### Redirect::safe($location, $args = '')

Safely redirects with path validation. Falls back to JavaScript/meta refresh.

---

## Menu Class

File: `users/classes/Menu.php`

### new Menu($id)

Constructor - instantiates a menu by ID.

### Menu::display($override = [])

Renders menu HTML. Override options: `layout` (horizontal/vertical/accordion),
`branding_html`, `show_branding`, `show_active`, `theme` (dark/light).

```php
$menu = new Menu(1);
$menu->display(["layout" => "vertical", "theme" => "dark"]);
```

### Menu::hasPerms($item)

Internal - checks user permission for a menu item.

### Menu::recursivelyDeleteMenuItem($itemId)

Recursively deletes a menu item and its children.

---

## Permission & Access Control

File: `users/helpers/permissions.php`

### securePage($uri)

**Core function.** Determines if someone is allowed to visit a page. Checks
master account, bans, admin status, public/private access. Logs unauthorized
attempts.

```php
if (!securePage($_SERVER['PHP_SELF'])) { die(); }
```

### hasPerm($permissions, $id = null, $masterCheck = true)

Checks if user has any of the specified permission levels. Returns `bool`.

```php
if (hasPerm([2, 3], $user->data()->id)) { /* ... */ }
```

### isAdmin()

Returns `bool` - whether current user has permission level 2. Works when
cloaked.

### isStandardUser($user_id)

Returns `bool` - whether user has only the standard permission level.

### checkAccess($key, $value)

Validates user access via `us_management` table. Auto-grants for master/admin.

### checkMenu($permission, $id = 0)

Legacy (UserCake). Checks single permission level. Use `hasPerm()` instead.

### addPermission($permission_ids, $members)

Assigns permissions to users via `user_permission_matches`.

```php
addPermission([2, 3], [36, 38]);
```

### removePermission($permissions, $members)

Removes permission assignments.

### deletePermission($permissions)

Deletes permission levels from the database.

### fetchAllPermissions()

Returns all permission levels (objects with `id`, `name`).

### fetchPermissionDetails($id)

Returns permission/page details by ID.

### fetchPermissionUsers($permission_id)

Returns all users with a specific permission.

### fetchPermissionPages($permission_id)

Returns pages associated with a permission.

### fetchUserPermissions($user_id)

Returns all permissions for a user.

### updatePermissionName($id, $name)

Renames a permission level.

### permissionIdExists($id)

Returns `bool`.

### permissionNameExists($permission)

Returns `bool`.

### cleanupPermissionPageMatches()

Removes orphaned permission-page matches.

---

## User Helper Functions

Files: `users/helpers/users.php`, `users/helpers/us_helpers.php`

### fetchAllUsers($orderBy, $desc = false, $disabled = true)

Returns array of user objects. Pass `$disabled = false` to exclude disabled.

```php
$users = fetchAllUsers('lname DESC, fname', true, false);
```

### fetchUser($id)

Returns all user data for a given ID.

### fetchUserDetails($column, $term, $id)

Legacy. Retrieves user by ID, column/term, or username/email. **Security**: do
not pass user input to `$column`.

### fetchUserName($username, $token, $id)

Returns first + last name concatenated.

### echouser($id, $echoType, $return = false)

Displays user name in configurable formats.

### echousername($id)

Returns username string.

### userIdExists($id)

Returns `bool`.

### usernameExists($username)

Returns array of matching records or `null`.

### emailExists($email)

Returns `bool`.

### isValidEmail($email)

Returns `bool`.

### isUserLoggedIn()

Returns `bool` - whether session is active.

### deleteUsers($users)

Deletes multiple users by ID array.

### updateUser($column, $id, $value)

Legacy. Updates a single column. **Security**: whitelist `$column` values.

### updateEmail($id, $email)

Updates a user's email address.

### username_helper($fname, $lname, $email)

Generates a unique username from name/email.

### fetchProfilePicture($userid)

Returns profile image URL (falls back to Gravatar).

### get_gravatar($email, $s = 120, $d = 'mm', $r = 'pg', $img = false, $atts = [])

Generates Gravatar URL or HTML img tag.

### name_from_id($id)

Legacy (UserCake). Returns capitalized username or "-".

---

## Page Management

File: `users/helpers/permissions.php`

### createPages($pages)

Inserts page entries with default privacy settings.

### deletePages($pages)

Removes pages by comma-separated IDs. Triggers cleanup.

### fetchAllPages()

Returns all pages ordered by ID descending.

### fetchPageDetails($id)

Returns page object (id, page, title, private, re_auth).

### fetchPagePermissions($page_id)

Returns permissions for a page.

### addPage($page, $permission)

Associates permissions with pages.

### removePage($pages, $permissions)

Removes page-permission associations.

### updatePrivate($id, $private)

Sets page public (0) or private (1).

### pageIdExists($id)

Returns `bool`.

### stripPagePermissions($id)

Removes all permissions for a page.

### getPageFiles()

Scans parent directory for PHP files.

### getUSPageFiles()

Returns PHP files in `users/` directory.

### getPathPhpFiles($absRoot, $urlRoot, $fullPath)

Finds PHP files in specified directory.

### currentPage()

Returns current page filename.

### currentFolder()

Returns current folder name (one level).

### currentFile()

Returns current file via `$_SERVER['PHP_SELF']`.

### currentPageId($uri)

Deprecated. Returns page ID from database.

### currentPageStrict($uri)

Returns page ID only if page is active (private=0, status=1).

---

## Display & Output Helpers

File: `users/helpers/helpers.php`, `users/helpers/us_helpers.php`

### dump($var, $adminOnly = false, $localhostOnly = false)

Formatted `var_dump()` in `<pre>` tags. Conditional display options.

### dnd($var, $adminOnly = false, $localhostOnly = false)

Dump and die - outputs variable then exits.

### bold($text)

Echoes bold, centered heading with white background.

### err($text)

Displays red error message (auto-fades after 15s).

### bin($number)

Converts 0/1 to colored "Yes"/"No"/"Other" HTML.

### money($ugly)

Formats number as US currency (`$20.50`).

### size($path)

Returns human-readable file size (e.g., "102 KB").

### echoId($id, $table, $column)

Echoes a database value by ID, table, and column.

### echopage($id)

Echoes page name by ID.

---

## Utility Functions

Files: `users/helpers/helpers.php`, `users/helpers/us_helpers.php`

### redirect($location)

HTTP header redirect.

### sanitize($string)

Sanitizes string/array/object via Input class.

### clean($string)

Removes special chars, replaces spaces with hyphens (URL slugs).

### encodeURIComponent($str)

PHP equivalent of JavaScript's `encodeURIComponent()`.

### email($to, $subject, $body, $opts = [], $attachment = null)

Sends email. Supports sender details, CC, BCC, attachments.

```php
email("bob@aol.com", "Subject", "Body", ['email' => 'from@example.com']);
```

### email_body($template, $options = [])

Generates HTML email from template file in `usersc/views` or `users/views`.

### logger($user_id, $logtype, $lognote, $metadata = null)

Logs user activity to database.

### lognote($logid)

Retrieves and customizes a log entry by ID.

### UserSpice_getLogs($opts = [])

Retrieves log entries. Options: `preset` ("diag", "debug"), `limit` (default
5000).

### echodatetime($ts)

Converts timestamp to human-friendly format (relative for recent dates).

### time2str($ts)

Converts timestamp to relative time ("7 months ago").

### offsetDate($number, $datestring, $unit = null)

Returns date offset by N days/weeks/months/years.

```php
$date = offsetDate(3, 'day');
$date = offsetDate(1, 'month', '2023-01-15');
```

### randomstring($len)

Generates random alphanumeric string.

### random_password($length = 16)

Generates random password with mixed character types.

### validateJson($string)

Returns `bool` - whether string is valid JSON.

### oxfordList($data, $opts = [])

Converts array to Oxford comma list.

```php
echo oxfordList(["apple", "banana", "orange"], ["final" => "or"]);
// "apple, banana, or orange"
```

### lang($key, $markers = [])

Returns localized text with dynamic marker replacement (`%m1%`, `%m2%`).

### isLocalhost()

Returns `bool`.

### isDebugModeActive()

Returns `bool`.

### importSQL($file)

Executes SQL file queries sequentially. Back up first.

### ipCheck()

Returns visitor's IP address.

### checkBan($ip)

Returns `bool` - whether IP is banned.

### ipCheckBan()

Checks if current IP is blacklisted (not whitelisted).

### ipReason($reason)

Returns text description of ban/whitelist reason.

### getIP()

Returns IP address (audit logging, `users/helpers/audit.php`).

### requestCheck($expectedAr)

Validates expected `$_GET`/`$_POST` vars exist, returns associative array.

### returnError($errorMsg)

Outputs JSON error `{success: true, error: true, errorMsg: "..."}` and exits.

### updateFields2($post, $skip = [])

Processes/sanitizes POST fields, skipping specified fields.

### safefilerewrite($fileName, $dataToSave)

File-locked write with collision avoidance.

### write_php_ini($array, $file)

Writes associative array to INI file format.

### parse_ini_file

Internal use only. Do not modify.

### tokenHere()

Outputs CSRF hidden input field in forms.

```php
tokenHere();
// Outputs: <input type="hidden" name="csrf" value="...">
```

### sanitizedDest($varname = 'dest')

Validates/sanitizes destination URL parameter against database pages.

### verifyadmin($page)

Checks admin status, redirects to verification if needed.

### isSelected($one, $two, $output = "selected='selected'")

Returns output string when values match. For HTML select options.

### fetchFolderFiles($folder, $extension = "php")

Scans directory for files by extension. Returns array with files/direct/links.

### fetchAdminSessions($all = false)

Returns user session data. `$all = false` for active sessions only.

### fetchUserSessions($all)

Returns sessions for authenticated user.

### UserSessionCount()

Returns count of active sessions for current user.

### killSessions($sessions, $admin = false)

Terminates specified sessions.

### passwordResetKillSessions($uid = null)

Ends all sessions except current on password reset.

---

## Plugin & Hook System

Files: `users/helpers/us_helpers.php`

### pluginActive($plugin, $checkOnly = false)

Checks if plugin is active. Redirects to Plugin Manager unless `$checkOnly`.

### registerHooks($hooks, $plugin_name)

Registers plugin hooks into `us_plugin_hooks` table.

```php
$hooks = ['dashboard' => ['position1' => 'hook1']];
registerHooks($hooks, 'my_plugin');
```

### deRegisterHooks($plugin_name)

Disables all hooks for a plugin.

### includeHook($hooks, $position)

Includes plugin code at designated page positions (pre, post, body, bottom).

### getMyHooks($opts = [])

Returns hooks for current or specified page.

### shakerIsInstalled($type, $reserved)

Checks if a Spice Shaker component is installed. Returns `bool`.

### languageSwitcher()

Renders language selection form. Persists choice to session/database.

---

## Form Manager

Files: `users/helpers/` (form manager system)

### displayForm($formName, $options = [])

Renders an HTML form. Options: `update` (row ID), `skip` (fields to exclude),
`noclose` (don't close form tag).

### generateAddForm($table, $skip = [])

Auto-generates add form from database table schema.

### generateForm($table, $id, $skip = [])

Generates edit form for existing record.

### processForm()

Processes form submissions with validation and CSRF. Place at top of page.

```php
if (!empty($_POST)) { processForm(); }
```

### preProcessForm()

Returns array: `form_valid`, `validation`, `token`, `fields`, `name`.

### postProcessForm($response)

Processes validated data (insert/update).

### displayView($viewNumber)

Displays a custom subset view of an existing form.

---

## Session & Message Helpers

### sessionValMessages($valErr, $valSuc, $genMsg)

Passes message arrays to `$_SESSION` for cross-page display.

### parseSessionMessages()

Returns array with keys: `genMsg`, `valErr`, `valSuc`.

### usSuccess($msg)

Stores success message in session.

### usMessage($msg)

Stores general message in session.

### usError($msg)

Stores error message in session.

### output_message($message)

Deprecated.

---

## Menu Helper Functions

File: `users/helpers/menus.php`

### prepareMenuTree($menuResults)

Builds hierarchical menu structure from flat data.

### prepareIndentedMenuTree($menuResults)

Organizes menu data with indentation hierarchy.

### prepareItemString($menuItem, $user_id)

Generates HTML for a single menu item with permission checks.

### prepareDropdownString($menuItem, $user_id)

Generates HTML dropdown menu with permission-checked children.

### parseMenuLabel($string)

Replaces template placeholders (`{{WELCOME_MESSAGE}}`,
`{{LOGGED_IN_USERNAME}}`) with dynamic values.

### parse_menu_hook($find, $replace, $string)

Find-and-replace on menu text.

### migrateUSMainMenu($truncate = false)

Migrates menu items from older UserSpice versions.

### updateGroupsMenus($group_ids, $menu_ids)

Manages group-menu associations.

### fetchGroupsByMenu($menu_id)

Returns group associations for a menu.

---

## Deprecated Functions

These exist in `users/helpers/deprecated.php`. Avoid using in new code.

| Function | Replacement |
| --- | --- |
| `checkPermission()` | `hasPerm()` |
| `checkMenu()` | `hasPerm()` |
| `userHasPermission()` | `hasPerm()` |
| `display_errors()` | JavaScript-based solutions |
| `display_successes()` | JavaScript-based solutions |
| `output_message()` | `usSuccess()` / `usError()` |
| `inputBlock()` | Form Manager |
| `currentPageId()` | `currentPageStrict()` |
| `stripPagePermissions()` (deprecated ver.) | Current version in `us_helpers.php` |
| `updateEmail()` (deprecated ver.) | Current version in `us_helpers.php` |
| `usernameExists()` (deprecated ver.) | Current version in `us_helpers.php` |

---

## Quick Lookup Table

| Function | Category | Description |
| --- | --- | --- |
| `addPage` | Page Mgmt | Associate permissions with pages |
| `addPermission` | Permissions | Assign permissions to users |
| `bin` | Display | Binary to colored Yes/No HTML |
| `bold` | Display | Echo bold centered heading |
| `cell` | DB | Get single value by table.column |
| `checkAccess` | Permissions | Check user access via us_management |
| `checkBan` | Utility | Check if IP is banned |
| `clean` | Utility | Sanitize string for URL slugs |
| `Config::get` | Config | Get config value by path |
| `Cookie::*` | Cookie | Cookie CRUD operations |
| `createPages` | Page Mgmt | Insert pages into database |
| `currentFile` | Page Mgmt | Current filename from PHP_SELF |
| `currentFolder` | Page Mgmt | Current folder name |
| `currentPage` | Page Mgmt | Current page filename |
| `DB::delete` | DB | Delete records by condition |
| `DB::get` | DB | Select records by condition |
| `DB::getInstance` | DB | Get singleton DB instance |
| `DB::insert` | DB | Insert a record |
| `DB::query` | DB | Execute prepared SQL |
| `DB::update` | DB | Update records by ID |
| `deletePages` | Page Mgmt | Remove pages from database |
| `deletePermission` | Permissions | Delete permission levels |
| `deleteUsers` | User Helpers | Delete users by ID array |
| `displayForm` | Form Manager | Render an HTML form |
| `dnd` | Display | Dump variable and die |
| `dump` | Display | Formatted var_dump |
| `echodatetime` | Utility | Human-friendly timestamp |
| `echouser` | User Helpers | Display user name (configurable) |
| `email` | Utility | Send email with attachments |
| `email_body` | Utility | Generate email from template |
| `emailExists` | User Helpers | Check if email is registered |
| `err` | Display | Display red error message |
| `fetchAllPages` | Page Mgmt | Get all pages |
| `fetchAllPermissions` | Permissions | Get all permission levels |
| `fetchAllUsers` | User Helpers | Get all users (filterable) |
| `fetchUser` | User Helpers | Get user data by ID |
| `fetchUserDetails` | User Helpers | Legacy user lookup |
| `fetchUserPermissions` | Permissions | Get user's permissions |
| `get_gravatar` | User Helpers | Gravatar URL generator |
| `hasPerm` | Permissions | Check user permission levels |
| `Hash::make` | Hash | SHA-256 hash |
| `Hash::unique` | Hash | Time-based unique hash |
| `importSQL` | Utility | Execute SQL file |
| `Input::exists` | Input | Check for POST/GET data |
| `Input::get` | Input | Get sanitized form input |
| `Input::sanitize` | Input | Sanitize non-form data |
| `isAdmin` | Permissions | Check if user is admin |
| `isLocalhost` | Utility | Check if running locally |
| `isStandardUser` | Permissions | Check standard user only |
| `isUserLoggedIn` | User Helpers | Check active session |
| `killSessions` | Utility | Terminate user sessions |
| `lang` | Utility | Get localized text string |
| `logger` | Utility | Log user activity |
| `money` | Display | Format as US currency |
| `offsetDate` | Utility | Calculate date offset |
| `oxfordList` | Utility | Array to Oxford comma list |
| `pluginActive` | Plugins | Check if plugin is active |
| `randomstring` | Utility | Random alphanumeric string |
| `random_password` | Utility | Random secure password |
| `redirect` | Redirect | HTTP redirect |
| `Redirect::safe` | Redirect | Safe redirect with validation |
| `registerHooks` | Plugins | Register plugin hooks |
| `removePage` | Page Mgmt | Remove page-permission links |
| `removePermission` | Permissions | Remove user permissions |
| `requestCheck` | Utility | Validate expected request vars |
| `returnError` | Utility | JSON error response and exit |
| `sanitize` | Utility | Sanitize string/array/object |
| `securePage` | Permissions | **Core** page access control |
| `Session::*` | Session | Session CRUD operations |
| `size` | Display | Human-readable file size |
| `time2str` | Utility | Relative time string |
| `Token::check` | Token | Verify CSRF token |
| `Token::generate` | Token | Generate CSRF token |
| `tokenHere` | Utility | Output CSRF hidden input |
| `updateEmail` | User Helpers | Update user email |
| `updateUser` | User Helpers | Legacy single-column update |
| `user->create` | User | Create new user account |
| `user->data` | User | Get logged-in user data |
| `user->find` | User | Find user by ID/email/name |
| `user->isLoggedIn` | User | Check login status |
| `user->login` | User | Authenticate user |
| `user->logout` | User | End user session |
| `user->update` | User | Update user fields |
| `userIdExists` | User Helpers | Check if user ID exists |
| `usernameExists` | User Helpers | Check if username exists |
| `usMessage` | Messages | Store session message |
| `usSuccess` | Messages | Store success message |
| `validateJson` | Utility | Validate JSON string |
| `verifyadmin` | Permissions | Verify admin credentials |
