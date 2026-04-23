# ADR-014: Replace johnathanmiller/secure-env-php with vlucas/phpdotenv

## Status

**Accepted** (April 2026)

## Date

April 2026

## Supersedes

[ADR-005](ADR-005-use-encrypted-environment-variables-via-secure-env-php.md) — Use Encrypted Environment Variables via SecureEnvPHP

## Context

The Elan Registry currently uses `johnathanmiller/secure-env-php` (v2.0.1) for encrypted environment
variable management. This library has become unmaintained and poses a first-boot dependency risk.

### Library Status

`johnathanmiller/secure-env-php` has been abandoned:

- **Last commit**: 2019 (7 years ago)
- **Open PHP 8 issues**: Multiple unresolved issues with PHP 8+ compatibility
- **No active maintenance**: No response to bug reports or pull requests
- **First-boot dependency risk**: A single dependency failure at bootstrap prevents the entire application from loading

### Encryption Security Analysis

ADR-005's own risk table acknowledged the fundamental limitation of the encryption approach:
**both `.env.enc` and `.env.key` reside on the same server**. This negates the protection against
filesystem read access:

- If an attacker gains filesystem access (LFI, misconfigured web server, shell injection), both files are available, enabling decryption.
- Encryption does not protect against runtime compromise (the primary threat vector for shared hosting).
- Offline attacks (stolen backups, disk images) are rare in cloud/shared hosting environments.

### Industry Standard: plaintext .env with chmod 600

The industry-standard approach (Laravel, Symfony, Django, Node.js projects) for shared hosting and single-VPS deployments:

1. Store plaintext credentials in `.env`
2. Restrict file permissions: `chmod 600 .env` (web server user only)
3. Exclude from version control: `.gitignore`
4. Add automated CI scanning: GitGuardian detects commits of plaintext secrets
5. Use environment detection: separate `.env` per environment

This approach provides equivalent security without encryption complexity:

- **Filesystem protection** via OS-level permissions (chmod 600) — same as encrypted files
- **VCS protection** via gitignore + GitGuardian CI scanning
- **Industry validation** — proven in millions of deployments
- **Simplified operations** — no encryption/decryption overhead, no key management

## Decision

Replace `johnathanmiller/secure-env-php` with `vlucas/phpdotenv` v5.

### Implementation Details

**Credentials Storage**:

- Plaintext `.env` file (never committed, `chmod 600` on server)
- `.env.example` committed as public template
- `.env.local` for local dev overrides (plaintext, with `DB_*` variables)

**Loading Mechanism**:

Phase 1.6 of `users/init.php` changes from:

```php
use SecureEnvPHP\SecureEnvPHP;
(new SecureEnvPHP())->parse(
    $abs_us_root . $us_url_root . '.env.enc',
    $abs_us_root . $us_url_root . '.env.key'
);
```

To:

```php
\Dotenv\Dotenv::createImmutable($abs_us_root . $us_url_root)->safeLoad();
```

**Consumption** (Phase 1.7):

Reads from `$_ENV` (phpdotenv's recommended approach — avoids `getenv()`/`putenv()`).
Required variables are validated at load time; a missing variable throws `RuntimeException`:

```php
$dotenv = \Dotenv\Dotenv::createImmutable($abs_us_root . $us_url_root);
$dotenv->safeLoad();
$dotenv->required(['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME']);

$GLOBALS['config'] = [
    'mysql' => [
        'host'     => $_ENV['DB_HOST'],
        'username' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASS'],
        'db'       => $_ENV['DB_NAME'],
    ],
];
```

**File Structure**:

```text
.
├── .env                 # Production credentials (plaintext, chmod 600, not committed)
├── .env.example         # Public template (committed)
├── .env.local           # Local dev overrides (plaintext, not committed)
└── .env.local.sample    # Local dev template (committed)
```

**phpdotenv Features Used**:

- `createImmutable()` — populates `$_ENV`/`$_SERVER` only; avoids `putenv()` per phpdotenv's own recommendation
- `safeLoad()` — load without raising exceptions if file absent (handles missing files gracefully)
- Variable interpolation — supports `${VAR}` references within values

## Rationale

### Why Replace SecureEnvPHP

1. **Abandonment risk**: No commits since 2019; incompatibility with PHP 8+ prevents upgrades to modern frameworks
2. **Operational burden**: Key management (storage, rotation, deployment) adds complexity without corresponding security benefit
3. **Industry convergence**: All major PHP frameworks (Laravel, Symfony) use plaintext `.env` with chmod 600
4. **Simpler debugging**: No decryption failures, no key synchronization issues

### Why phpdotenv

1. **Active maintenance**: 50K+ weekly downloads; core dependency of Laravel, Symfony, and hundreds of projects
2. **Zero framework coupling**: Works standalone; no Laravel/Symfony dependencies required
3. **PHP 8+ compatible**: Supports PHP 8.1+ (our minimum requirement); regular security updates
4. **Proven track record**: Millions of deployments across diverse hosting environments
5. **Graceful degradation**: `safeLoad()` continues if `.env` absent (useful for containerized deployments)

### Security Posture: Equivalent or Better

**Threats mitigated**:

| Threat | SecureEnvPHP | phpdotenv + chmod 600 | Notes |
| --- | --- | --- | --- |
| Filesystem read access (LFI, shell) | ❌ No (key + encrypted file same server) | ✅ Yes (OS-level perms) | Both fail against full server compromise |
| Plaintext in version control | ✅ Yes (encrypted) | ✅ Yes (gitignore + GitGuardian) | phpdotenv relies on process discipline, widely proven |
| Offline attacks (stolen backup) | ✅ Yes (encrypted at rest) | ❌ No (plaintext in backup) | Rare in cloud/shared hosting; backups already encrypted by hosting provider |
| Accidental exposure in logs | ❌ No (decrypts at startup) | ❌ No (plaintext in env) | Both have this weakness; mitigation is limiting log retention and access |
| Runtime compromise | ❌ No | ❌ No | Neither protects; encryption is no defense against active compromise |

**LayeredDefense**:

1. **OS-level**: `chmod 600` restricts to web server user only (same as encrypted files)
2. **VCS-level**: `.gitignore` + GitGuardian CI check prevents committed secrets
3. **Host-level**: Backup encryption (hosting provider responsibility)

## Consequences

### Positive

- **Resolves abandonment risk**. Active maintenance, regular security updates, PHP 8+ compatible.

- **Simpler operational model**. Copy `.env.example` to `.env`, fill credentials, `chmod 600`. No encryption tooling or key management.

- **Industry standard**. Used by Laravel, Symfony, Django, Node.js projects. Well-documented, widely understood by developers and AI code agents.

- **Zero first-boot dependency**. `safeLoad()` handles missing `.env` gracefully; application continues booting (e.g., containerized environments).

- **Easier debugging**. No decryption failures, no key synchronization issues, no "bad key" mysteries.

- **Single-file deployment**. `.env` file only; no `.env.key` synchronization requirement.

- **Local dev simplicity**. `.env.local` plaintext with `DB_*` names directly (no `ELAN_DEV_DB_*` remapping needed).

### Negative

- **Credentials in plaintext on disk**. No encryption at rest. Mitigated by `chmod 600` + `.gitignore`
  \+ GitGuardian CI, but credentials exist as plaintext in memory and on-disk backup systems.

- **Operator discipline required**. `.env` must be manually created and `chmod 600` applied.
  No warnings if permissions are incorrect or file is world-readable.

- **Shared hosting backup risk**. If hosting provider's backups leak, `.env` credentials are exposed
  in plaintext (backups are typically encrypted by hosting provider, mitigating this risk).

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| `.env` file permissions set incorrectly (644 instead of 600) | Medium | High | Deployment checklist documents `chmod 600`; automation can validate permissions on deploy; consider post-deploy verification script |
| `.env` accidentally committed to Git | Low | High | `.gitignore` provides primary defense; GitGuardian CI catches plaintext secrets in commits; developer education and pre-commit hooks |
| Hosting provider backup leaks plaintext `.env` | Very Low | High | Out of scope (backup encryption is hosting provider responsibility); enforce strict backup access controls in organizational security policy |
| Plaintext credentials in memory dumps during runtime | Very Low | Medium | Unavoidable (credentials must be in memory for database connection); encrypted files have same weakness; mitigation is limiting memory access and monitoring for unauthorized SSH/shell access |
| Developer accidentally exposes credentials in logs | Low | Medium | Implement log sanitization (filter `DB_*` variables from logs); document log retention and access policies; educate developers on not logging sensitive values |
| Migration procedure error leaves old `.env.enc`/`.env.key` in place | Low | Low | Migration checklist documents secure deletion of old files; `.env.key` particularly should be overwritten (`shred`) not just deleted |

## Migration Procedure

### Pre-Migration (on current SecureEnvPHP version)

1. Verify all environments have `.env.enc` + `.env.key` deployed
2. Document current database credentials (will be used to populate `.env` after migration)

### Migration Steps (per environment)

1. **Preparation**:

   ```bash
   # SSH to server
   ssh user@server
   cd /path/to/elan-registry
   ```

2. **Create `.env` from current credentials**:

   ```bash
   # Extract credentials from .env.enc using existing SecureEnvPHP setup
   # Create new .env file with plaintext credentials
   cat > .env << 'EOF'
   DB_HOST=localhost
   DB_USER=elan_user
   DB_PASS=mysecretpassword
   DB_NAME=elan_registry
   EOF
   ```

3. **Set secure permissions**:

   ```bash
   chmod 600 .env
   chown www-data:www-data .env
   ```

4. **Verify phpdotenv loading** (after code deployment):

   ```bash
   # Browser test or curl test to verify application boots
   curl -s https://example.com/elan_registry | head -20
   ```

5. **Remove old encrypted files** (after verification):

   ```bash
   # Securely delete old files
   shred -vfz -n 3 .env.enc .env.key
   ```

6. **Verify removal**:

   ```bash
   ls -la .env*  # Should show only .env
   ```

### GitHub Actions / CI (for test/prod auto-deployment)

If using automated deployments via git hooks or CI/CD:

1. Encrypted `.env.enc`/`.env.key` are deployed manually via SFTP before updating code
2. After code deployment, phpdotenv loads `.env` automatically (no changes needed in deployment script)
3. Post-deployment verification checks that database connection succeeds

## Alternatives Considered

### Keep SecureEnvPHP with Fork/Maintenance

Maintain a fork of SecureEnvPHP to add PHP 8+ support.

**Rejected because**:

- Adds ongoing maintenance burden for a solved problem (phpdotenv exists and is battle-tested)
- Creates a custom security-critical dependency with no external audit
- Risk of introducing bugs in cryptographic code
- Resource better spent on application features than dependency maintenance

### Vault/External Secret Management

Use HashiCorp Vault, AWS Secrets Manager, or similar.

**Rejected because**:

- Out of scope for single-VPS shared hosting application
- Adds external service dependency and operational complexity
- Overkill for current deployment model; industry standard for this scale is plaintext `.env` with gitignore

### Environment Variables Only (no .env file)

Use only PHP-FPM pool `env[]` or Apache `SetEnv` directives.

**Rejected because**:

- A2 Hosting (shared hosting) does not provide control over PHP-FPM pool configuration
- No version-controlled artifact for new server setup
- Breaks portability and reproducibility across environments

## References

- **vlucas/phpdotenv**: [GitHub](https://github.com/vlucas/phpdotenv), [Packagist](https://packagist.org/packages/vlucas/phpdotenv)
- **Issue #631**: Environment variable library replacement
- **ADR-005**: [Superseded decision on encrypted environment variables](ADR-005-use-encrypted-environment-variables-via-secure-env-php.md)
- **PAGE_LOADING_FLOW.md**: [Phase 1.6 environment loading](../PAGE_LOADING_FLOW.md#phase-16-load-environment-variables)
- **ENVIRONMENT.md**: [Environment configuration documentation](../ENVIRONMENT.md)
- **DEPLOYMENT.md**: [Deployment procedures with `.env` setup](../DEPLOYMENT.md)
- **Industry Practice**: [Laravel Environment Configuration](https://laravel.com/docs/configuration#environment-configuration), [Symfony .env Files](https://symfony.com/doc/current/configuration.html#configuring-environment-variables-in-env-files)
