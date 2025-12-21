# Environment Variables Documentation

This document covers environment variables and environments used in the Elan Registry application.

## Environment URLs

The Elan Registry operates across three environments:

- **Development:** `http://localhost:9999/elan_registry`
- **Test:** `https://test.elanregistry.org`
- **Production:** `https://elanregistry.org`

Environment detection is performed using URL/hostname pattern matching for environment-specific configurations and behaviors.

## Database Access

### Local Development (MAMP)

MySQL binaries are located in MAMP installation:

- **MySQL 8.0:** `/Applications/MAMP/Library/bin/mysql80/bin/mysql`
- **MySQL 5.7:** `/Applications/MAMP/Library/bin/mysql57/bin/mysql`

Access development database:

```bash
/Applications/MAMP/Library/bin/mysql57/bin/mysql -u mysql57 -p
```

Access test database (remote):

```bash
# Test environment now uses https://test.elanregistry.org
# Database access requires SSH tunnel or direct connection to A2 Hosting server
# See deployment documentation for database connection details
```

## Overview

The Elan Registry uses **SecureEnvPHP** for encrypted environment variable management, providing enhanced security for database credentials and API keys.

### Encryption System

- **Encrypted Storage**: Variables stored in `.env.enc` (encrypted file)
- **Decryption Key**: Security key stored in `.env.key`
- **Library**: `johnathanmiller/secure-env-php`
- **Loading**: Variables loaded in `usersc/includes/custom_functions.php:29-31`

## Environment Variables

### Database Configuration

**Usage**: `users/init.php:40-46`

- `DB_HOST` - Database server hostname/IP (e.g., `localhost`)
- `DB_USER` - Database username (e.g., `elan_registry_user`)
- `DB_PASS` - Database password
- `DB_NAME` - Database name (e.g., `elanregi_spice`)

## Setup & Configuration

### Development Setup

1. **Install Dependencies**:

   ```bash
   composer require johnathanmiller/secure-env-php
   ```

2. **MySQL Access** (MAMP Development):

   ```bash
   # MySQL CLI path for MAMP
   /Applications/MAMP/Library/bin/mysql57/bin/mysql -h 127.0.0.1 -P 8889 -u claude -pclaude -D elanregi_spice
   ```

3. **Create Environment Variables**:

   ```bash
   # Create temporary plaintext .env file
   echo "DB_HOST=localhost" > .env
   echo "DB_USER=your_username" >> .env
   echo "DB_PASS=your_password" >> .env
   echo "DB_NAME=your_database" >> .env
   ```

4. **Encrypt and Cleanup**:
   ```bash
   # Use SecureEnvPHP to encrypt (creates .env.enc and .env.key)
   cd www
   vendor/johnathanmiller/secure-env-php/bin/encrypt-env
   # Select Y when prompted to create key file

   # Remove plaintext file
   rm .env
   ```

### Production Deployment

```bash
# Set secure file permissions
chmod 600 .env.enc .env.key
chown www-data:www-data .env.enc .env.key

# Ensure web server cannot serve .env* files directly
# Configure .htaccess or nginx appropriately
```

## Code Usage

Environment variables are loaded during application bootstrap and accessed via PHP's `getenv()`:

```php
// Loading (in usersc/includes/custom_functions.php)
use SecureEnvPHP\SecureEnvPHP;
(new SecureEnvPHP())->parse($abs_us_root . $us_url_root . '.env.enc',
                            $abs_us_root . $us_url_root . '.env.key');

// Usage throughout application
$host = getenv('DB_HOST');
```

## Security Requirements

### File Security

- **Never commit** `.env.enc` or `.env.key` to version control
- **Store `.env.key` separately** from application code in production
- **Backup encryption key** securely and separately from application
- **Restrict file permissions** to web server user only

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

- Verify `.env.key` file exists and is readable by web server
- Check file permissions (600) and ownership
- Ensure files aren't corrupted during deployment

**Database Connection Issues**:

- Verify credentials in encrypted environment
- Check database server accessibility and user permissions

**Google Maps Issues**:

- Verify API keys are correctly set in environment
- Check Google Cloud Console for domain/API restrictions
- Ensure billing is enabled for Google Cloud project

**Debug Environment Loading**:

```php
// Check if variables loaded
if (getenv('DB_HOST') === false) {
    error_log('Environment variables not loaded');
}
```

## References

- [SecureEnvPHP Documentation](https://github.com/johnathanmiller/secure-env-php)
- [Google Maps API Documentation](https://developers.google.com/maps/documentation)
- [Google Geocoding API Documentation](https://developers.google.com/maps/documentation/geocoding)
