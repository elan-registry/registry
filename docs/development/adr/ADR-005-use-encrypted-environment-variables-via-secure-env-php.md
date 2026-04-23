# ADR-005: Use Encrypted Environment Variables via SecureEnvPHP

> **Status: Superseded by [ADR-014](ADR-014-replace-secure-env-php-with-phpdotenv.md)** — April 2026
>
> This decision is no longer in effect. The library was replaced due to abandonment.
> See ADR-014 for the replacement decision.

---

## Status

**Superseded** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

Prior to the SecureEnvPHP integration, database credentials needed to be available at application boot time existed in an insecure state:

- **Plaintext in version control**: Credentials hardcoded in `users/init.php`or a committed`config.php`, exposing credentials to anyone with repository access
- **Plaintext on disk**: If not committed, credentials still existed as plaintext `.env` files, vulnerable to file disclosure attacks (LFI, misconfigured web
  server, backup exposure)
- **No bootstrap solution for shared hosting**: The application is hosted on A2 Hosting (shared hosting) without OS-level environment variable injection
  (PHP-FPM pool configuration or Apache SetEnv directives not available)

The application required database credentials to be available at Phase 1.6 of the page initialization sequence—before any framework or database infrastructure
was initialized. This bootstrap problem meant credentials had to come from some external source, not the database itself.

### Problem Statement

A PHP application on shared hosting needs:

1. Database credentials available before database connection (chicken-and-egg bootstrap problem)
2. Credentials not committed to Git (GitGuardian CI enforcement)
3. Credentials not exposed as plaintext on disk (defense-in-depth)
4. Simple operational model (encrypt once, deploy two files, done)
5. Integration with UserSpice authentication framework and the `/users/`vs`/usersc/` separation (ADR-001)

## Decision

Use `johnathanmiller/secure-env-php`v2.0.1 to encrypt database credentials into an`.env.enc`file with a separate`.env.key`file. Both files are deployed
separately from Git (never committed), loaded during Phase 1.6 of page initialization, and consumed via PHP's`getenv()` function.

### Package Details

- **Library**: `johnathanmiller/secure-env-php`v2.0.1 (locked in`usersc/composer.lock`)
- **Encryption**: AES-256-CBC via PHP's `openssl_encrypt()`and`openssl_decrypt()`
- **IV Handling**: Initialization vector prepended to ciphertext in `.env.enc`; key stored as raw binary in `.env.key`
- **Installation Location**: `usersc/vendor/`(runtime application dependency, separate from root`vendor/` development tools)

### Loading Sequence

SecureEnvPHP is integrated into the four-phase page loading sequence (Phase 1.5-1.7):

**Phase 1.5.6 — Autoloader Registration** (`users/helpers/helpers.php`, lines 55-57):

```php
if (file_exists($abs_us_root . $us_url_root . 'usersc/vendor/autoload.php')) {
    require_once $abs_us_root . $us_url_root . 'usersc/vendor/autoload.php';
}
```

**Phase 1.6 — Parse Encrypted Credentials** (`users/init.php`, lines 50-52):

```php
use SecureEnvPHP\SecureEnvPHP;
(new SecureEnvPHP())->parse(
    $abs_us_root . $us_url_root . '.env.enc',
    $abs_us_root . $us_url_root . '.env.key'
);
```

**Phase 1.7 — Consume via getenv()** (`users/init.php`, lines 58-61):

```php
$GLOBALS['config'] = [
    'mysql' => [
        'host'     => getenv('DB_HOST'),
        'username' => getenv('DB_USER'),
        'password' => getenv('DB_PASS'),
        'db'       => getenv('DB_NAME'),
    ],
];
```

These credentials are then passed to `DB::getInstance()` to establish the MySQL connection.

### Managed Variables

| Variable | Purpose | Example |
| --- | --- | --- |
| `DB_HOST` | MySQL server hostname | `localhost`or`db.example.com` |
| `DB_USER` | MySQL username | `elan_user` |
| `DB_PASS` | MySQL password | (encrypted, never visible) |
| `DB_NAME` | MySQL database name | `elan_registry` |

**Historical Variables** (removed in v2.11.0):

- `MAPS_KEY`and`GEO_ENCODE_KEY` (Google Maps/Geocoding API keys) were managed via SecureEnvPHP until LocationGeocoder was deprecated in v2.11.0.

### Encryption Workflow

1. Developer creates a plaintext `.env` file with credentials:

   ```text
   DB_HOST=localhost
   DB_USER=elan_user
   DB_PASS=mysecretpassword
   DB_NAME=elan_registry
   ```

2. Developer runs the SecureEnvPHP encryption CLI tool:

   ```bash
   vendor/bin/encrypt-env
   ```

   (The tool is provided in `vendor/johnathanmiller/secure-env-php/bin/encrypt-env`)

3. The tool prompts for a passphrase and generates two files:
   - `.env.enc` — IV (16 bytes) prepended to AES-256-CBC ciphertext
   - `.env.key` — Raw key material (binary, not base64-encoded)

4. Developer deletes the plaintext `.env` file

5. Developer deploys both `.env.enc`and`.env.key` to the server via SFTP/SSH (never via Git)

### File Security

**Git Exclusions:**

All sensitive files are listed in `.gitignore`:

```text
.env
.env.enc
.env.key
.env.local
.env.testing
```

These files are never committed to Git under any circumstances.

**Production File Permissions:**

```bash
chmod 600 .env.enc .env.key
chown www-data:www-data .env.enc .env.key
```

Only the web server process (www-data) can read these files; they are not world-readable.

**Layered Protection:**

1. **Application-level**: Files encrypted at rest with AES-256
2. **OS-level**: File permissions restrict reading to web server process only
3. **VCS-level**: GitGuardian CI check prevents plaintext secrets from being committed
4. **Static analysis**: CodeQL scans in CI provide additional protection against hardcoded credentials

### Environment-Specific Behavior

| Environment | File | Mechanism | Notes |
| --- | --- | --- | --- |
| Production | `.env.enc`+`.env.key` | AES-256 encrypted, deployed via SFTP/SSH | Separate key pair for production |
| Staging | `.env.enc`+`.env.key` | AES-256 encrypted, deployed via SFTP/SSH | Separate key pair with test database credentials |
| Development | `.env.local`(plaintext fallback) | Manual parsing via`putenv()` | For local integration tests only |

**Development: Plaintext `.env.local` Fallback:**

For local development and integration testing, SecureEnvPHP supports a fallback to plaintext `.env.local` parsing. The integration test bootstrap
(`tests/bootstrap-integration.php`) implements a two-path strategy:

1. **Try SecureEnvPHP**: Attempt to load `.env.enc`+`.env.key` (if they exist)
2. **Fall back to plaintext**: If encrypted files don't exist, parse `.env.local`manually with`putenv()`

This allows developers to test without managing encrypted files locally, while production is always protected.

**Variable Remapping for Tests:**

The plaintext `.env.local`uses a`ELAN_DEV_DB_*` prefix to distinguish development variables from production ones:

```text
ELAN_DEV_DB_HOST=localhost
ELAN_DEV_DB_USER=root
ELAN_DEV_DB_PASS=password
ELAN_DEV_DB_NAME=elan_registry_test
```

The test bootstrap remaps these to production variable names:

```php
if (!getenv('DB_HOST')) {
    putenv('DB_HOST=' . getenv('ELAN_DEV_DB_HOST'));
    putenv('DB_USER=' . getenv('ELAN_DEV_DB_USER'));
    // etc.
}
```

A sample `.env.local.sample` documents the expected format for new developers.

### Dependency Chain

The following sequence shows how SecureEnvPHP integrates into the application boot:

```text
app_page.php
  → users/init.php (Phase 1.3, initial setup)
  → users/helpers/helpers.php (Phase 1.5.6, autoloader registration)
  → usersc/vendor/autoload.php (auto-generated by Composer)
  → SecureEnvPHP\SecureEnvPHP (Phase 1.6, decrypt and parse)
  → .env.enc + .env.key (read from disk, decrypt in memory)
  → putenv() (set environment variables from decrypted contents)
  → getenv() (Phase 1.7, retrieve into $GLOBALS['config'])
  → DB::getInstance() (Phase 1.8, establish MySQL connection with credentials)
```

SecureEnvPHP is the first third-party library loaded in the application's boot sequence. Any failure (missing `.env.enc`or`.env.key`, bad key material,
corrupted ciphertext, decryption error) results in empty `$config` values and immediate database connection failure with a descriptive error.

### v2.11.0 Reorganization

In v2.11.0 (Issue #427/#430), SecureEnvPHP was moved from root `vendor/`to`usersc/vendor/`, formalizing the architectural separation between:

- **Runtime application dependencies**: Needed by the application at execution time, located in `usersc/vendor/`
- **Development tools**: Build tools, linters, test runners, located in root `vendor/`

This separation is consistent with ADR-001 (UserSpice `/users/`vs`/usersc/`architecture). The`install-dependencies.sh`script validates
that`usersc/vendor/johnathanmiller/secure-env-php/` exists after installation.

## Consequences

### Positive

- **Credentials encrypted at rest.** Defense-in-depth against file disclosure vulnerabilities. If an attacker gains filesystem access via LFI or misconfigured
  web server, encrypted files reveal no plaintext credentials.

- **Simple operational model.** Developers encrypt once per environment, deploy two files, and never touch it again. No ongoing key rotation tooling or
  complexity (though rotation is supported).

- **Clean separation of concerns.** SecureEnvPHP is a pure cryptography utility; it has no opinions about configuration sources, formats, or semantics. The
  application controls when and how credentials are loaded via `getenv()`.

- **Integration with UserSpice architecture.** Installed in `usersc/vendor/`(application code) not root`vendor/` (dev tools), consistent with ADR-001
  separation. Loaded at Phase 1.6, before any framework infrastructure.

- **Credentials never as plaintext in deployed tree.** Files are encrypted on disk; credentials only become plaintext in memory during decryption and
  immediately afterwards are accessed via `getenv()` with no intermediate storage.

- **Layered CI protection.** GitGuardian + CodeQL checks provide automated prevention of accidental credential commits, supplementing file-based encryption.

- **Environment-specific isolation.** Each environment (prod, staging, dev) has its own `.env.key`, preventing cross-environment credential leakage if one key
  is compromised.

### Negative

- **AES-256-CBC without authentication.** The library uses AES-256-CBC, which does not provide authenticated encryption (no MAC/HMAC). A modified or corrupted
  ciphertext could decrypt to garbage without raising an error. Modern best practice prefers AES-256-GCM (which UserSpice's own `spiceEncrypt()` already uses).
  However, in practice, file corruption or tampering would be detected immediately when the database connection fails.

- **Both files on same server.** At-rest encryption only protects against offline attacks (stolen backup, disk image). If an attacker achieves full server
  compromise (SSH access, web shell), both `.env.enc`and`.env.key` are available, enabling decryption. Encryption does not protect against runtime compromise—it
  is one layer in a defense-in-depth strategy, not a sole protection.

- **No built-in key rotation tooling.** Rotating keys requires:
  1. Generating a new key
  2. Re-encrypting `.env` with the new key
  3. Deploying both new `.env.enc`and`.env.key` simultaneously

  There is no automated tooling; this is a manual process documented in deployment procedures.

- **Missing files cause hard boot failure.** If `.env.enc`or`.env.key`is missing (e.g., deployment error, accidental deletion), SecureEnvPHP raises an
  uncaught`RuntimeException`, terminating script execution at Phase 1.6. There is no graceful degradation. Error messages are descriptive but not user-friendly;
  the application fails to load at all.

- **Dev/production asymmetry.** Development uses plaintext `.env.local`via fallback, while production uses encrypted files. This introduces procedural
  complexity in integration test bootstrap with`ELAN_DEV_DB_*`prefix remapping. Developers might accidentally commit`.env.local`if not careful
  (though`.gitignore` prevents it).

- **No automated deployment mechanism.** Encrypted files must be manually deployed via SFTP/SSH after credential rotation. There is no CI/CD pipeline
  integration (e.g., GitHub Actions secret injection). Each environment requires manual SFTP upload after secret generation.

- **Two-file synchronization requirement.** `.env.enc`and`.env.key` must be generated together as a pair. If they drift (e.g., key is rotated but ciphertext is
  not re-encrypted with the new key), the site silently fails with "bad database password" and requires investigation to diagnose.

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| `.env.key`and`.env.enc` both on same server; full compromise reveals credentials | Low | High | OS-level file permissions (chmod 600); separation of key backup from server; defense-in-depth (not sole protection); regular security audits |
| Modified `.env.enc` silently decrypts to garbage (no MAC) | Very Low | Medium | Application fails immediately with database connection error; consider wrapping parse() in try-catch with descriptive error message for clarity |
| Missing `.env.enc`or`.env.key`causes uncaught RuntimeException at boot | Low | High | `install-dependencies.sh` validates presence after install; deployment checklist documents both files; consider adding explicit validation with user-friendly error in init.php |
| Key rotation causes mismatched `.env.enc`/`.env.key` pair | Low | High | Document rotation procedure explicitly (requires atomic re-encryption and deployment of both files); add checklist to deployment guide; consider SHA-256 hash validation of key pair |
| `usersc/composer.lock` accidentally deleted or not committed | Very Low | Medium | Pre-commit hooks validate lock file presence; CI validates dependency installation; Composer verifies lock integrity |
| SecureEnvPHP library abandoned or unmaintained | Medium | Low | Library is simple (~200 lines, well-documented); AES-256-CBC is a standard algorithm; could be forked or replaced with minimal effort; consider periodic review of maintenance status |
| Developer accidentally commits plaintext `.env`or`.env.local` | Low | High | GitGuardian CI check catches this;`.gitignore` prevents accidental commits; code review as secondary check; developer education |

## Alternatives Considered

### PHP Constants in Committed Config File

The original pattern before SecureEnvPHP: database credentials hardcoded as PHP constants in `users/init.php`or a separate`config.php` file, committed to Git.

**Rejected because:**

- Credentials in version control are a critical security risk. Any developer (or contractor, former employee, leaked GitHub account) with repository access can
  see production credentials.
- No separation between environments. All developers see all environments' credentials.
- GitGuardian CI enforces a no-hardcoded-secrets policy; this approach violates that policy and would block commits.

### Plaintext .env with vlucas/phpdotenv

The most common PHP `.env`library (used by Laravel, Symfony):`vlucas/phpdotenv`. Reads plaintext `.env`files with`.env.example` pattern, auto-loading, and
validation.

**Rejected because:**

- The `.env` file is plaintext on disk. While not committed to Git, a file disclosure vulnerability (LFI, misconfigured web server, backup exposure) leaks
  credentials in cleartext.
- SecureEnvPHP provides the same developer ergonomics (`getenv()` consumption) with encryption as an additional layer of defense.
- For a single file, SecureEnvPHP is simpler than phpdotenv (no environment variable detection, format parsing, or variable interpolation needed).

### Server Environment Variables (Apache SetEnv / PHP-FPM pool env[])

True OS-level environment variables set in web server configuration. The application would use `getenv()` directly with no library.

**Rejected because:**

- A2 Hosting (shared hosting) does not provide control over PHP-FPM pool configuration or Apache `SetEnv` directives. This approach requires server
  administration access that is unavailable.
- Environment variables would need to be configured manually on each server with no version-controlled artifact or documentation.
- Shared hosting platforms typically have restrictions on what can be modified in server configuration.

### Symfony Secrets Vault (symfony/dotenv + symfony/secrets)

Symfony's Dotenv component with secrets vault mechanism, conceptually similar to SecureEnvPHP.

**Rejected because:**

- The application does not use Symfony anywhere. Introducing a Symfony component would add a major framework dependency for a single feature.
- SecureEnvPHP is a focused, single-purpose library (~200 lines) with no framework coupling.
- Symfony components are heavier and bring transitive dependencies; SecureEnvPHP is completely standalone.

### External Secret Management (HashiCorp Vault, AWS Secrets Manager)

External secret stores (Vault, AWS Secrets Manager, Google Cloud Secret Manager) pulled at deploy time or runtime.

**Rejected because:**

- Entirely out of scope for a single-VPS shared-hosting application. These services require dedicated infrastructure, network access from the hosting
  environment, and account management.
- Operational complexity vastly exceeds application requirements. These tools are designed for organizations with dozens of microservices and strict secret
  governance.
- Runtime dependency on external service creates a new failure mode (secret store unavailable = application cannot boot).

### Config File Outside Web Root

Place a `config.php`one directory above`document_root` (outside web-accessible directory) with plaintext credentials.

**Rejected because:**

- While better than committing credentials, the file is still plaintext on disk.
- Does not protect against server-level file access (SSH, backup systems, hosting panel file managers, compromised web shell).
- Depends on specific filesystem layout of the hosting provider, reducing portability and increasing setup complexity.
- Sharing hosting typically has limited control over directory placement relative to web root.

## References

- **SecureEnvPHP Package**: [usersc/composer.json](../../usersc/composer.json)
- **Locked Version**: [usersc/composer.lock](../../usersc/composer.lock) (v2.0.1)
- **Loading Point**: [users/init.php](../../users/init.php) (lines 50-52, 58-61)
- **Autoloader Registration**: [users/helpers/helpers.php](../../users/helpers/helpers.php) (lines 55-57)
- **Environment Documentation**: [docs/development/ENVIRONMENT.md](../development/ENVIRONMENT.md)
- **Plaintext Sample**: [.env.local.sample](../../.env.local.sample)
- **Install Script**: [scripts/install-dependencies.sh](../../scripts/install-dependencies.sh)
- **Integration Test Bootstrap**: [tests/bootstrap-integration.php](../../tests/bootstrap-integration.php)
- **Crypto Implementation**: [usersc/vendor/johnathanmiller/secure-env-php/src/Crypto.php](../../usersc/vendor/johnathanmiller/secure-env-php/src/Crypto.php)
- **Page Loading Flow**: [docs/development/PAGE_LOADING_FLOW.md](../development/PAGE_LOADING_FLOW.md)
- **v2.11.0 Release Notes**: [docs/releases/RELEASE_NOTES_V2.11.0.md](../releases/RELEASE_NOTES_V2.11.0.md)
- **UserSpice Integration**: ADR-001 covers UserSpice `/users/`vs`/usersc/` separation
- **Nygard ADR Format**:
  [https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
