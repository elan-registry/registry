---
paths:
  - "**/*.php"
---

# PHP code

These files are the source of truth for PHP rules:

- `docs/development/CODING_STANDARDS.md`: strict types, type hints, PHPDoc,
  typed exceptions, database value casting.
- `docs/development/ERROR_HANDLING.md`: exception hierarchy, `ApiResponse`,
  Pattern A responses, logging.
- `docs/development/CLASSES.md`: project classes in `usersc/classes/`.
- `docs/development/DATABASE.md`: schema and `*_hist` audit tables.

## When you write code

- Add `declare(strict_types=1)` to new PHP files. Add type hints and PHPDoc.
- Replace raw `$_SERVER` with the server globals: `$scheme`, `$host`,
  `$is_https`, `$method`, `$request_uri`, `$current_url`, `$current_origin`,
  `$php_self`, `$remote_addr`, `$referer`, and `$user_agent`.
- Wrap database operations in `try` and `catch`.
- Do not call `getUserWithProfile()`. It was removed in v2.26.2. Use
  `(new Owner($userId))->data()`.
- For schema changes, write a migration with the FIX script pattern. See
  `FIX_SCRIPTS.md`.

## When you simplify code

- Use `match` instead of nested ternaries.
- Use constructor promotion where it shortens a class.
- Use readonly properties for value objects. See `CLASSES.md`.
- Do not change files under `/users/` or `vendor/`. See `upstream-userspice.md`.
- Do not cross the Users and Owners terminology boundary. See `CLAUDE.md`.
- Verify the change. See `testing.md`.

## When you review code

- CI already runs PHPStan, ESLint, CodeQL, and Semgrep. Do not repeat their
  findings.
- Report each issue as `path/to/file.php:42`.
- Architecture reviews apply to Large-tier issues only.

## When you review error handling

- Flag each `catch` of `\Throwable` or bare `\Exception`. Exception: the
  last-resort fallback pattern in `docs/development/ERROR_HANDLING.md`.
- Flag each use of the `@` error suppression operator.
- Check PDO calls for error handling. The project uses prepared statements.
  Verify each call anyway.
- Use `ApiResponse::error($message, $context)` for AJAX errors.
- Each log line needs enough context to debug it six months later, such as
  IDs and the operation.

## When you review comments

- Make PHPDoc types match the PHP type hints on the signature.
- Explain complex business logic. Examples are ownership transfers and auth
  flows.
- Each `TODO` and `FIXME` names a GitHub issue number.
- Do not copy `docs/development/*.md` into comments. Cross-reference them.
  These files are the source of truth for architecture.

## When you review classes

- Scope: `usersc/classes/`, such as `Car`, `Owner`, `ApiResponse`, and the
  exception classes.
- Use `readonly` promoted properties for value objects (PHP 8.2+).
- Use constructor promotion where it applies (PHP 8+).
- Use `enum` for closed sets of states.
- Use the typed exceptions in `ERROR_HANDLING.md`, not `\Exception`.
- Keep UserSpice framework types unchanged.
- Check invariants against ownership, registry state, and the audit trail.

## When you review architecture

- Find UserSpice functionality that the code duplicates.
- Find code that PHP or MySQL already provides.
- Do not flag typed exceptions, `ApiResponse`, `LogCategories`, validation,
  or tests as over-engineering.
- Follow existing patterns, such as the `Car` class and `Owner` class
  conventions.
- Design APIs with the Pattern A response format. See `ERROR_HANDLING.md`.
- New pages call `securePage()` and are registered in UserSpice. See
  `pages-permissions-cron.md`.
- Recommend schema changes with FIX script migrations. See `FIX_SCRIPTS.md`.
