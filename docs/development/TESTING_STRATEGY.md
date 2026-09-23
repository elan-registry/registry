# Testing Strategy

This document covers **why** the test suite is organized the way it is and
the UserSpice-specific behaviors that any new test needs to account for.
For **how** to run or write tests — commands, directory layout, fixture
helpers, troubleshooting — see [`tests/README.md`](../../tests/README.md)
and [`docs/testing/TESTING.md`](../testing/TESTING.md) (Playwright specifics:
[`docs/testing/PLAYWRIGHT_E2E.md`](../testing/PLAYWRIGHT_E2E.md)). This doc
doesn't repeat that material — it's the single place that documents the
*rules*, not the commands.

## Tier Architecture

Three tiers, each with a distinct purpose and a hard boundary:

- **Unit (`tests/unit/`)** — fast, no database. Test real application
  classes with real logic; never write a hand-rolled mock/reimplementation of
  your own project code (a mock `CarModel`, mock `CarRepository`, etc. tests
  the mock, not the application). Mocking third-party/framework boundaries
  (like UserSpice's `DB` class itself) is fine when the goal is isolating
  your own code's logic from a real database — the anti-pattern is mocking
  *your own* classes (e.g. `CarRepository`). Construct the real class against
  a mocked `DB` instead, so the class's own translation logic (DB response →
  return value/exception) actually executes.

  Every class that needs a database collaborator type-hints
  `ElanRegistry\DatabaseInterface` (`usersc/classes/DatabaseInterface.php`), a
  narrow interface covering exactly the methods production code calls on
  UserSpice's real `\DB` class, matching `\DB`'s actual runtime behavior
  (fluent self-returning `query()`/`get()`, `[]` — never `null` — on empty
  `first()`/`results()`). The real `\DB` is wrapped by
  `ElanRegistry\Database\DbAdapter` (`usersc/classes/Database/DbAdapter.php`)
  for production use; unit tests build a per-test double of the interface
  directly — `createMock(DatabaseInterface::class)`/
  `createStub(DatabaseInterface::class)` for interaction-style expectations
  (per the convention in `tests/unit/cars/services/CarRepositoryTest.php`'s
  `makeDbMock()`), or a concrete `tests/Support/FakeDatabase.php` subclass
  when a test needs mutable tracked state (hand-tracked call counts, etc.) —
  see `tests/unit/admin/ArchiveAccountsTest.php` for that pattern. Integration
  tests that need a real connection but must sabotage one call extend
  `tests/Support/PassThroughDatabase.php` as an anonymous class, overriding
  only that method (returning `simulateFailure()` to report a database error
  without reaching MySQL) — see
  `tests/integration/OwnerSyncOwnerFieldsToCarsTest.php`'s `db*()` factories.
  There is no shared global `DB` mock shell — a regression guardrail
  (`tests/unit/regression/DatabaseInterfaceUsageRegressionTest.php`) fails CI
  if one, or a concrete `\DB` type-hint in production code, or a call to a
  `\DB` method that isn't on `DatabaseInterface`, ever reappears (#1585).

  Model-combination existence is proven against the real `car_models` table in
  `tests/integration/cars/services/CarValidatorModelTest.php` (#1446) — that
  remains the place to assert which combinations are actually valid. A unit test
  that needs to *reach* `CarValidator`'s model branch must inject its own
  `CarModel`: `new CarValidator($carModel)`, where `$carModel` is a real
  `CarModel` constructed against a `DatabaseInterface` double
  (`new CarModel($db)`). Without injection, `CarValidator` lazily builds a
  default `CarModel` backed by `dbi()`, which fatals in the unit tier — `dbi()`
  itself is undefined there (it lives in `custom_functions.php`, which the
  unit bootstrap never loads).

  UserSpice's own bare (non-namespaced) classes under `users/classes/` are a
  different case, and the rule there is absolute: they can **never** be loaded
  for real in the unit tier, no matter how dependency-free they are. The entire
  `users/` tree is `.gitignore`'d (`users/**`) — it is a manually installed
  upstream checkout, absent from every CI checkout and from `composer install`.
  A `require_once $projectRoot . '/users/classes/Token.php'` in
  `tests/bootstrap-unit.php` works on a developer machine and fatals in CI with
  "Failed to open stream: No such file or directory" (#1554). This applies even
  to `Token`, `Input`, `Config` and `Session`, which touch nothing but
  superglobals and `$GLOBALS['config']` — the constraint is file availability,
  not runtime dependencies. So `tests/bootstrap-unit.php` declares raw stubs for
  `Token` and `Input`, and unit tests may only assert those stubs' own contract,
  never real CSRF crypto or real `htmlspecialchars()` encoding. Real behavior for
  these classes is proven in the integration tier, where `users/init.php` loads
  the genuine framework — see
  `tests/integration/TokenAndInputSecurityTest.php`. That coverage is
  developer-local, not CI-enforced: `tests.yml` never runs
  `phpunit-integration.xml` (unit and regression only), so this proof runs via
  `composer test:integration`/`test:full` before merge, not automatically on
  every push (#1591).

  `tests/unit/uploads/_is_uploaded_file_namespace_overrides.php` relaxes
  `ElanRegistry\Car\is_uploaded_file()` (via PHP's namespace-scoped function
  resolution) so `CarImageProcessor::validateFileUpload()` is testable
  without a real HTTP upload. Because PHP has no per-file function scoping,
  once `FileUploadSecurityTest.php` requires it, the relaxed check is
  declared for the rest of that PHPUnit process (`processIsolation="false"`).
  If you add a new test elsewhere that exercises `validateFileUpload()`'s
  `is_uploaded_file()` branch, be aware a file merely on disk
  (`file_exists()`) will pass that check too — it isn't isolated to
  `FileUploadSecurityTest.php`.

  When a test needs a bare global-namespace double for a real UserSpice class
  (not a namespaced project class) to satisfy a type hint the production code
  can't be changed to relax, declare it in its own `_`-prefixed file and add
  that file to `phpstan.neon`'s `excludePaths`, rather than inline in the
  test class — e.g. `tests/unit/auth/_User_test_double.php` standing in for
  `\User`. PHP has no per-file scoping for global classes: once `tests/`
  entered PHPStan's scan path
  (#1555), an inline double becomes *the* definition PHPStan uses for that
  class everywhere in the codebase, not just within the test that declares it
  (#1566). Excluding the file keeps the class genuinely unanalyzable to
  PHPStan instead, which the project's existing `ignoreErrors` patterns for
  UserSpice runtime classes already account for.
- **Integration (`tests/integration/`)** — real database, real UserSpice
  framework. Every test creates the fixtures it depends on
  (`IntegrationTestCase::createTestUser()` / `createTestCar()`) and cleans
  them up in `tearDown()`. Never assume ambient data exists — see
  `tests/README.md`'s "Test Data Isolation" section for the do/don't
  examples. One exception: `RobotsTxtAsServedTest.php` (#1542) needs neither
  a database nor `IntegrationTestCase` — it lives in this tier solely because
  it makes a live outbound HTTPS request (to catch edge-injected robots.txt
  content Cloudflare or similar could add ahead of the repo's own file) and
  must stay out of `composer test:quick`/`test:medium`, which directory
  placement achieves without any per-test setup.
- **Browser/E2E (`tests/playwright/`)** — golden paths and security-critical
  flows only (CSRF, auth, XSS). Not a substitute for unit/integration
  coverage of business logic — browser tests are expensive and should stay
  narrow.

`tests/unit/regression/` is **not** a fourth tier in the unit/integration/E2E
sense — it's orthogonal in intent, tracking "was this specific bug fixed"
rather than a class or flow. Since #1559 it's not a separate directory tier
either: regression tests live in `tests/unit/regression/` and run under the
same `tests/bootstrap-unit.php` as the rest of `tests/unit/` — mocks only,
no database. What distinguishes a regression test is the
`#[Group('regression')]` attribute plus the `Issue{N}RegressionTest.php` (or
a descriptive `{Name}RegressionTest.php` for cross-cutting concerns) naming
convention — not directory placement. `composer test:regression` now
group-filters the `Unit` testsuite (`--testsuite=Unit --group regression`
against `phpunit-unit.xml`). A regression test that needs a real database
still has to live in `tests/integration/` instead; it isn't a `composer
test:regression` test.

### Authenticated Sessions in Integration Tests

Code under test that calls `currentUserId()`, reads `global $user`, or checks
permissions needs an authenticated session. `IntegrationTestCase` provides the
only supported way to create one:

- `loginAsTestUser(int $userId): User` — marks a real `users` row (usually one
  from `createTestUser()`) as the ambient logged-in session, setting both
  `$GLOBALS['user']` and the `global $user` alias, and returns the `User`
  instance.
- `restoreGlobalUser(): void` — restores whatever session was ambient before
  the first `loginAsTestUser()` call of the current test. Idempotent.

Two usage patterns:

- **Whole class needs a session** — call `$this->loginAsTestUser($userId);` in
  `setUp()` and do nothing else. `IntegrationTestCase::tearDown()` calls
  `restoreGlobalUser()` automatically, so the fake session can't leak into the
  next test class.
- **Only specific test methods need a session** — leave `setUp()`
  unauthenticated. In the methods that need it, start a `try` block and call
  `$this->loginAsTestUser($userId)` as its first statement, then call
  `$this->restoreGlobalUser();` in a `finally` block so the session ends with
  that method rather than at `tearDown()`. Login must be the first statement
  inside `try` — not before it — so a throw during login can't skip the
  `finally` and leak the fake session.

**If your test class overrides `tearDown()`, it must call `parent::tearDown()`
unconditionally** — wrap its own cleanup in
`try { ...own cleanup... } finally { parent::tearDown(); }`. The automatic
restore is the *first* statement of `IntegrationTestCase::tearDown()` precisely
so a later failure can't skip it, but that guarantee silently evaporates if a
subclass's own cleanup throws before reaching `parent::tearDown()`. Nothing
fails loudly when this happens; the fake session just leaks into the next test
class. See `tests/integration/database/CarsYearSmallintMigrationTest.php` for
the pattern.

Do **not** hand-roll the reflection-based login bypass (`new User()` → `find()`
→ set `_isLoggedIn` → assign `$GLOBALS['user']`) in a test again. Duplicating it
is what left authenticated sessions leaking between test classes (#1572); the
helper is the single place that idiom is allowed to live.

**Out of scope — the inverse idiom.** Temporarily *removing* `$GLOBALS['user']`
mid-test to verify "no ambient session" behaviour (rather than authenticating) is
deliberately **not** covered by these helpers, and remains hand-rolled in
`CarDeletionTest.php`, `CarMergeTest.php`, and `CarTransferTest.php` (one test
method each). That is a known, intentional boundary, not an oversight —
`loginAsTestUser()`/`restoreGlobalUser()` are session *creation* helpers, and
folding an "unauthenticate" mode into them would complicate the snapshot
bookkeeping for three call sites. If a fourth appears, extract a separate
helper for that narrower need rather than re-duplicating the idiom or
overloading these two.

## UserSpice `DB` Class Conventions

UserSpice ships no test suite and no testing conventions of its own — the
following is derived directly from reading `users/classes/DB.php` (upstream,
do not modify — see `CLAUDE.md`'s Template Customization Rules), not from
UserSpice documentation. This is the source of truth for how to write
assertions against it.

- **Connection failures kill the request.** `DB::__construct()` catches
  `PDOException` on connect and calls `die("Could not connect to database...")`
  (uncatchable, no exception propagates). There is no framework-native way to
  intercept or test the first-connection-fails path — don't write a test that
  expects an exception here.
- **`query()`/`action()` never throw for execute-time failures.** In
  `DB::query()`, `$pdo->prepare()` runs *outside* the try/catch, so a
  prepare-time failure (malformed SQL) throws an uncaught `PDOException`.
  But `$query->execute()` runs *inside* the try/catch, and a failure there is
  caught and converted into internal state (`$this->_error = true`) — it does
  **not** throw. Always check `->error()` / `->errorString()` after a query
  to assert failure; wrapping a call in `try/catch` only catches prepare-time
  syntax errors, not execution failures like constraint violations.
- **`first()` returns `[]`, never `null`.** `DB::first()` is
  `$this->count() > 0 ? $this->results($assoc)[0] : []`. Asserting
  `assertNotNull($result)` on a "no row found" case always passes — it's
  testing the wrong thing. Use `assertIsObject()` / `assertNotEmpty()` (or
  `assertSame([], $result)` for the not-found case) instead.
- **`sql_mode` is deliberately `''` (strict mode off).** `DB::__construct()`
  sets `PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = ''"` unless
  `Config::get('mysql/options')` overrides it. A raw diagnostic MySQL
  connection (e.g. a manual `mysql` CLI session) defaults to strict mode and
  will behave differently — don't validate fixture data against a connection
  that isn't going through `DB`.

## Provisioning

Test, dev, CI, and prod all provision from the same mechanism: the baseline
migration + `composer migrate` + `composer seed:run`
(`scripts/provision-schema.sh` wraps the full sequence for a fresh schema).
See `tests/README.md`'s "Provisioning a Test Schema" and "Database Fixtures"
sections for the full walkthrough, seed class list, and what
`tests/bootstrap-integration.php` verifies vs. seeds itself.

## Related Work

Framework-divergence documentation (which upstream UserSpice files this
project has customized, and why) is tracked separately in #1460
(`UPSTREAM_DIVERGENCES.md`) — not yet written as of this doc. Once it lands,
cross-reference it here rather than duplicating UserSpice-quirk content in
two places.
