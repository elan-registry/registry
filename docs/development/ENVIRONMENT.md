# Environment Variables Documentation

This document covers environment variables and environments used in the Elan
Registry application.

## Environment URLs

The Elan Registry operates across three environments:

- **Development:** `http://localhost:9999/elan_registry`
- **Test:** `https://test.elanregistry.org`
- **Production:** `https://elanregistry.org`

Environment detection is performed using URL/hostname pattern matching for
environment-specific configurations and behaviors.

## Database Access

### Local Development (MAMP MySQL 8.0)

Access the development database using MAMP's MySQL 8.0:

```bash
# MySQL CLI access (credentials from .env.local file)
/Applications/MAMP/Library/bin/mysql80/bin/mysql -h 127.0.0.1 -P 8889 \
  -u [DB_USER from .env.local] -p \
  -D [DB_NAME from .env.local]
# Enter password from .env.local when prompted
```

### Remote Database Access (Test/Production)

Test and production databases require SSH tunnel or direct connection:

```bash
# Test environment: https://test.elanregistry.org
# Production environment: https://elanregistry.org
# Database credentials are in .env.local file
# See DEPLOYMENT.md for SSH tunnel setup and connection details
```

## Overview

The Elan Registry uses **vlucas/phpdotenv** v5 for environment variable loading from plaintext `.env` files with `chmod 600` filesystem permissions.

### Loading System

- **Plaintext Storage**: Variables stored in `.env` (plaintext file)
- **Permissions**: `chmod 600` restricts file to web server user only
- **Library**: `vlucas/phpdotenv` v5
- **Loading**: Variables loaded in Phase 1.6 of `users/init.php` via `Dotenv::createImmutable()->safeLoad()`

## Environment Variables

### Database Configuration

**Usage**: `users/init.php` (Phase 1.6–1.7)

- `DB_HOST` - Database server hostname/IP (e.g., `localhost`)
- `DB_USER` - Database username (e.g., `elan_registry_user`)
- `DB_PASS` - Database password
- `DB_NAME` - Database name (e.g., `elanregi_spice`)

## Setup & Configuration

### Development Setup

1. **Get Database Credentials**:

   Database credentials are stored in `.env.local` file (not committed to git).
   This file should be provided separately and contains local development database credentials.

   See "Database Access" section above for connecting to databases.

2. **Create `.env` from `.env.example`**:

   ```bash
   # Copy the public template
   cp .env.example .env
   
   # Edit with your local credentials
   # Example contents:
   # DB_HOST=127.0.0.1
   # DB_USER=root
   # DB_PASS=password
   # DB_NAME=elanregi_spice
   ```

3. **Set Secure Permissions**:

   ```bash
   # Restrict to web server user only
   chmod 600 .env
   ```

4. **Create `.env.local` for Integration Tests** (optional):

   ```bash
   # Copy the test template
   cp .env.local.sample .env.local
   
   # Edit with your test database credentials
   # Uses DB_* names directly (not ELAN_DEV_DB_* prefix)
   chmod 600 .env.local
   ```

### Production Deployment

```bash
# Create .env from current credentials
# (obtain credentials securely, via 1Password, secure email, etc.)
cat > .env << 'EOF'
DB_HOST=your_production_host
DB_USER=your_production_user
DB_PASS=your_production_password
DB_NAME=your_production_database
EOF

# Set secure file permissions (web server user only)
chmod 600 .env
chown www-data:www-data .env

# After verifying site boots correctly, remove old encrypted files
# (if migrating from SecureEnvPHP)
shred -vfz -n 3 .env.enc .env.key
```

## Code Usage

Environment variables are loaded during application bootstrap and accessed via
PHP's `$_ENV` superglobal:

```php
// Loading (in users/init.php, Phase 1.6)
$dotenv = \Dotenv\Dotenv::createImmutable($abs_us_root . $us_url_root);
$dotenv->safeLoad();
$dotenv->required(['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME']);

// Usage throughout application (phpdotenv populates $_ENV, not putenv)
$host = $_ENV['DB_HOST'];
```

## Credential Management

### .env File (Production/Staging)

The `.env` file contains database credentials for the running environment:

- **Location**: Root directory (not committed to git)
- **Permissions**: `chmod 600` (web server user only)
- **Format**: Plain text key-value pairs
- **Distribution**: Created on server via secure channel (SFTP, SSH, deployment automation)
- **Creation**: Copy from `.env.example` and fill in credentials

**Security**: File permissions (`chmod 600`) combined with `.gitignore` and GitGuardian CI scanning
provide industry-standard protection. See ADR-014 for security analysis.

### .env.local File (Local Development)

The `.env.local` file contains local development database credentials:

- **Location**: Root directory (not committed to git)
- **Permissions**: `chmod 600`
- **Format**: Plain text key-value pairs using `DB_*` variable names
- **Distribution**: Created locally or from `.env.local.sample` template
- **Usage**: For local integration testing with MAMP or remote databases

**Important**: Never commit `.env`, `.env.local`, or other environment files to version control. All are listed in `.gitignore`.

## Security Requirements

### File Security

- **Never commit** `.env`, `.env.local`, or other environment files to version control
- **Restrict file permissions** to web server user only: `chmod 600 .env`
- **Secure deletion** when replacing credentials: `shred -vfz .env.enc` (if migrating from SecureEnvPHP)
- **Backup security** — ensure backups are encrypted by hosting provider
- **CI scanning** — GitGuardian detects accidental plaintext secret commits

### API Key Security

Configure API keys in **Google Cloud Console**:

- **Domain Restrictions**: Restrict to your domains only
- **API Restrictions**: Enable only Maps JavaScript API and Geocoding API
- **Monitoring**: Set usage quotas and monitor for unusual activity
- **Separate Keys**: Use different keys for development/staging/production

### Database Security

- **Least Privilege**: Database user should have only necessary permissions
- **Network Security**: Restrict database access to application server
- **Connection Security**: Use SSL/TLS when possible

## Troubleshooting

**Environment Loading Issues**:

- Verify `.env` file exists and is readable by web server
- Check file permissions: `ls -la .env` should show `-rw-------` (600)
- Ensure `.env` file is not world-readable or group-readable
- Verify ownership: `chown www-data:www-data .env`

**Database Connection Issues**:

- Verify credentials in `.env` are correct
- Test database connection: use MySQL CLI to verify connectivity
- Check database server accessibility from application host
- Verify database user permissions (SELECT, INSERT, UPDATE, DELETE as needed)

**Google Maps Issues**:

- Verify API keys are correctly set in environment
- Check Google Cloud Console for domain/API restrictions
- Ensure billing is enabled for Google Cloud project

**Debug Environment Loading**:

```php
// Check if variables loaded
if (empty($_ENV['DB_HOST'])) {
    error_log('Environment variables not loaded');
}
```

## References

- [vlucas/phpdotenv Documentation](https://github.com/vlucas/phpdotenv)
- [ADR-014: Replace secure-env-php with phpdotenv](adr/ADR-014-replace-secure-env-php-with-phpdotenv.md)
- [Google Maps API Documentation](https://developers.google.com/maps/documentation)
- [Google Geocoding API Documentation](https://developers.google.com/maps/documentation/geocoding)
