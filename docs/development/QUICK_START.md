# Quick Start Guide

This document provides essential setup and testing information for developers
working on the Lotus Elan Registry.

## System Requirements

- PHP 8.1+ required (8.2+ recommended for full PHPUnit 12 compatibility)
- MySQL 8.0+
- Uses `johnathanmiller/secure-env-php` for encrypted environment variable handling

## Installation

For detailed setup instructions, see [INSTALLATION.md](INSTALLATION.md).

## Quick Start Commands

```bash
# Install PHP dependencies
composer install

# Install Node dependencies (for testing)
npm install

# Setup enhanced pre-commit quality checks (RECOMMENDED)
./scripts/setup-git-hooks.sh

# PHP test commands (core infrastructure)
composer test:quick        # Unit tests only (<30s)
composer test:medium       # Unit + Integration (<2min)
composer test:full         # All PHP tests
composer test:coverage     # Generate coverage report

# UI testing (requires setup)
npm test                   # Shows setup requirements
npm run playwright:install # Install Playwright browsers
npm run playwright:test    # Run UI tests (after setup)
```

## Testing

### PHP Test Suites

```bash
# Fast unit tests
composer test:unit

# Database integration tests
composer test:integration

# Issue-specific regression tests
composer test:regression
```

### UI Test Suites

**Note:** Requires Playwright setup (`npm run playwright:install`)

```bash
# Security-focused tests
npm run playwright:security

# UI consistency tests
npm run playwright:ui

# Navigation and redirects
npm run playwright:navigation

# Core functionality
npm run playwright:functionality

# Maps and charts
npm run playwright:maps

# CSP validation tests
npm run playwright:csp
```

## Pre-commit Quality Checks (HIGHLY RECOMMENDED)

**Setup once per developer:**

```bash
./scripts/setup-git-hooks.sh
```

**What it does:**

- **Step 1**: PHP coding standards validation (security, types, documentation)
- **Step 2**: Markdown linting for documentation files
- **Step 3**: Fast unit tests when critical files are modified
- **Blocks commits** with violations and provides fix guidance
- **No installation required** - uses existing tools and npx

**Benefits:**

- Prevents PR failures by catching issues locally
- Maintains consistent code quality across the team
- Provides immediate feedback with actionable fix suggestions

**Bypass (emergency only):** `git commit --no-verify`

## Environment Setup

See [ENVIRONMENT.md](ENVIRONMENT.md) for complete environment variable
configuration and security setup.

## Development Workflow

See [DEVELOPMENT_WORKFLOW.md](DEVELOPMENT_WORKFLOW.md) for detailed development
processes and patterns.

## Quick Deployment Reference

**🚨 CRITICAL:** When deploying, use the correct remote for each environment!

```bash
# Push to GitHub for repository backup
git push origin main && git push origin --tags

# Deploy to test server for validation
git push test feature/v2.9.1
git push test v2.9.1

# Push code to PRODUCTION SERVER (live site)
git push prod main
git push prod --tags
```

**📋 See [DEPLOYMENT.md](DEPLOYMENT.md) for complete release and deployment procedures**
