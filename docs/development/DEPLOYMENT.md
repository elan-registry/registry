# Deployment Guide

This document provides comprehensive deployment procedures for the Lotus Elan
Registry application.

## 🚀 Production Environment

### Hosting Infrastructure

- **Hosting**: A2 Hosting with git deployment hooks
- **Remote**: `prod` remote configured for direct deployment to production server
- **Auto-deployment**: Master branch deploys automatically when pushed to prod remote
- **Version Display**: Uses VERSION file modification time for deployment timestamp

### 🚨 CRITICAL: Production Deployment Commands

**⚠️ IMPORTANT:** When someone says "push to prod", always use the `prod`
remote, NOT `origin`!

**Live Production Server:**

```bash
# Push code to PRODUCTION SERVER (live site)
git push prod main

# Push version tags to PRODUCTION SERVER  
git push prod --tags
```

**GitHub Repository (backup/development):**

```bash
# Push to GitHub for repository backup
git push origin main && git push origin --tags
```

### Remote Configuration Reference

```bash
origin git@github.com:unibrain1/elanregistry.git    # GitHub repository
test   [test-server-path]                            # Test/staging server
prod   a2hosting:/home/unibrain/git/elanregistry.git # LIVE PRODUCTION SERVER
```

**🔄 Deployment Rule:**

- `origin` = GitHub (development/backup)
- `test` = Test/staging server for validation
- `prod` = **LIVE WEBSITE** (elanregistry.org)

**Testing Feature Branches:**

```bash
# Deploy feature branch to test server
git push test feature/v2.9.1

# Deploy tag to test server
git push test v2.9.1
```

## 🤖 Automated Pull Request Checks

All pull requests to the `main` branch are automatically validated through a
comprehensive set of checks before merge is allowed. These checks ensure code
quality, security, and project management compliance.

### Quick Reference: PR Check Status

| Check Name | Purpose | Blocks | Runs When |
| ---------- | ------- | ------ | --------- |
| **CodeQL Analysis** | Security scanning | ✅ Yes | All PRs to main |
| **GitGuardian Security** | Secret detection | ✅ Yes | All commits/PRs |
| **Claude Code Review** | Coding standards | ✅ Yes | PHP/JS/CSS changes |
| **Issue Management** | Auto-label issues | ❌ No | Issue events |
| **PR Management** | Link PRs to issues | ❌ No | PR events |

### Security & Code Quality Checks

#### 1. **CodeQL Analysis**

- **What it does**: Static analysis for security vulnerabilities and code
  quality issues in JavaScript
- **When it runs**: On every pull request to main branch
- **Scope**: Analyzes JavaScript files for common vulnerabilities (XSS,
  injection attacks, etc.)
- **Pass criteria**: No critical security vulnerabilities detected
- **Failure impact**: Blocks merge until vulnerabilities are resolved

#### 2. **GitGuardian Security Checks**

- **What it does**: Scans for secrets, API keys, passwords, and credentials in code
- **When it runs**: On every commit and pull request
- **Scope**: All files in the repository for hardcoded secrets
- **Pass criteria**: No exposed credentials or API keys found
- **Failure impact**: Blocks merge and sends security alerts
- **Configuration**: External service, no local configuration files

#### 3. **Claude Code Review**

- **What it does**: Automated code review against Elan Registry coding standards
- **When it runs**: When PR contains PHP, JS, CSS files or documentation changes
- **Scope**: Enforces coding standards from `docs/development/CODING_STANDARDS.md`
- **Key checks**:
  - **PHP 8+ Type Safety**: Complete type declarations, `declare(strict_types=1)`
  - **Security**: CSRF tokens, parameterized queries, input validation
  - **Architecture**: Custom exceptions, proper error handling
  - **Documentation**: PHPDoc blocks for public methods
  - **Performance**: N+1 queries, caching opportunities
- **Pass criteria**: No blocking issues (❌), warnings (⚠️) acceptable
- **Review format**: Specific feedback with code examples and standard references

### Project Management Automation

#### 4. **Issue Management Automation**

- **What it does**: Automatically manages GitHub issues with labels,
  milestones, and status tracking
- **When it runs**: On issue creation, updates, and closure
- **Key functions**:
  - **Auto-labeling**: New issues get `status: needs-planning`
  - **Priority assignment**: Based on keywords (critical, bug, enhancement, etc.)
  - **Status transitions**: Removes conflicting status labels
  - **Milestone tracking**: Updates progress when issues close
- **Labels applied**: `priority: critical/high/medium/low`, `status: needs-planning/in-progress/needs-review`

#### 5. **PR Management Automation**

- **What it does**: Links PRs to issues and manages development workflow
- **When it runs**: On PR creation, updates, and merge
- **Key functions**:
  - **Issue linking**: Detects "fixes #123", "closes #456" patterns
  - **Status updates**: Updates linked issues based on PR state
  - **Auto-closure**: Closes linked issues when PR merges
  - **Draft handling**: Marks issues as "in-progress" for draft PRs
- **Status flow**: `status: in-progress` → `status: needs-review` → issue closed

### Special Workflow Behaviors

#### Version Check Behavior

- **Feature branches**: Version check **skipped** (allows development work)
- **Main branch**: Full version validation runs (ensures production quality)
- **Why skipped on PR**: Prevents blocking development, validation happens on merge

#### Check Dependencies

- **Required for merge**: CodeQL, GitGuardian, Claude Review (if applicable)
- **Informational only**: Project management automation (doesn't block merge)
- **Manual override**: Repository administrators can override if needed

### Troubleshooting Common Check Failures

#### CodeQL Failures

- **Cause**: Security vulnerabilities in JavaScript code
- **Resolution**: Fix identified vulnerabilities, rerun analysis
- **Common issues**: XSS vulnerabilities, unsafe DOM manipulation

#### GitGuardian Failures

- **Cause**: Hardcoded secrets, API keys, or credentials detected
- **Resolution**: Remove secrets, use environment variables instead
- **Prevention**: Use `.env.enc` encrypted storage for sensitive data

#### Claude Review Failures

- **Cause**: Coding standard violations (missing types, CSRF, documentation)
- **Resolution**: Address specific issues mentioned in review comments
- **Reference**: Follow examples and standards in review feedback

## 🛠️ Local Development Tools

### Enhanced Pre-Commit Quality Checks

A **comprehensive pre-commit hook** automatically validates code quality and
runs fast tests before allowing commits.

#### Setup (One-time)

```bash
# Install enhanced git hooks
./scripts/setup-git-hooks.sh
```

#### How It Works

**Three-Step Process:**

1. **PHP Coding Standards Check** (runs for staged PHP files):
   - **Enhanced Security Validation**: CSRF protection, SQL injection
     prevention, input validation
   - **Type Safety**: Complete PHP 8+ type declarations, strict typing
   - **Documentation**: PHPDoc completeness with @param, @return, @throws
   - **Architecture**: Specific exception types, proper error handling
   - **Performance**: N+1 query detection, caching opportunities

2. **Markdown Lint Check** (runs for staged .md files):
   - **Formatting**: Header spacing, list indentation, line endings
   - **Standards**: Consistent markdown formatting across documentation
   - **Quality**: No trailing whitespace, proper blank line usage
   - **Tools**: Uses `markdownlint-cli2` via npx (no installation required)

3. **Fast Unit Tests** (runs when critical files modified):
   - **Automatic**: Triggered by PHP, JSON, or test file changes
   - **Fast**: Uses `composer test:quick` (unit tests with early failure)
   - **Smart**: Skips if composer dependencies not installed
   - **Comprehensive**: Validates core functionality before commit

#### Manual Testing

**PHP Coding Standards:**

```bash
# Test current directory
php scripts/check-coding-standards.php .

# Test specific directory
php scripts/check-coding-standards.php app/classes/

# Test staged files only (used by pre-commit hook)
php scripts/check-coding-standards.php /tmp/staged --staged
```

**Markdown Linting:**

```bash
# Test all markdown files
npx markdownlint-cli2 "**/*.md"

# Test specific files
npx markdownlint-cli2 README.md docs/**/*.md

# Test with fix suggestions
npx markdownlint-cli2 --fix "**/*.md"
```

#### Bypass Hook (Emergency Only)

```bash
# NOT recommended - use sparingly!
git commit --no-verify
```

### What the Enhanced Standards Checker Validates

#### ❌ **Blocking Issues**

**PHP Type Safety:**

- Missing `declare(strict_types=1)` in new PHP files
- Functions without return type declarations
- Function parameters without type hints
- Public methods missing PHPDoc blocks

**Security Violations:**

- Potential SQL injection patterns (string concatenation in queries)
- Direct output of user input (XSS vulnerability)
- Email functions with unvalidated user input
- Generic Exception usage (should use specific exception types)

**Documentation Requirements:**

- Missing @param tags in PHPDoc blocks
- Missing @return tags for non-void functions
- Incomplete PHPDoc documentation

#### ⚠️ **Warnings**

**Security Concerns:**

- Forms without CSRF protection
- Direct use of superglobals without validation
- File uploads without proper validation
- RuntimeException usage (consider more specific types)

**Architecture Issues:**

- Database operations without try-catch blocks
- File operations without error handling
- JSON operations without error checking
- Catching generic Exception (catch specific types when possible)

**Performance Issues:**

- Potential N+1 query patterns (database queries in loops)
- High number of database queries that could be optimized
- Missing caching for expensive operations (API calls, file scans, aggregations)
- Multiple complex calculations without caching

### Benefits

- **Prevents CI Failures**: Catches issues locally before PR submission
- **Faster Development**: No need to wait for Claude Code Review to find basic issues
- **Consistent Code Quality**: Enforces standards across all developers
- **Educational**: Shows examples of correct implementations

## 📋 Complete Production Deployment Process

### Step-by-Step Deployment

1. **Update VERSION file and create matching git tag** (tag must exactly match
   VERSION content)
2. **Commit changes** with version bump and tag
3. **Push to GitHub** for repository backup:
   `git push origin main && git push origin --tags`
4. **🎯 DEPLOY TO PRODUCTION** (the important step):
   `git push prod main && git push prod --tags`
5. **Verify deployment** by checking version display matches git tag on
   production site
6. **Complete post-deployment verification** (see checklist below)

### Git & Version Control

#### Branch Management Strategy

- `main` branch always contains production-ready code
- All development work happens on feature/phase branches
- Direct commits to main are discouraged

#### Branch Naming Convention

- Feature branches: `feature/issue-{number}-brief-description`
- Phase branches: `phase-{number}-{name}`
- Hotfix branches: `hotfix/issue-{number}-brief-description`

#### Version Management & Automated Release Process

**Version File Structure:**

- Version information stored in `/VERSION` file in project root
- `ApplicationVersion::get()` reads from this file (no git dependencies)
- Production deployment timestamp shows file modification time
- Format: `vX.Y.Z` (semantic versioning, e.g., `v2.3.4`)

**Automated Version Enforcement:**

- **Git Pre-Commit Hook**: Automatically enforces version updates on main branch
- **Location**: `.git/hooks/pre-commit` (installed automatically)
- **Rules**: VERSION file must be updated when committing code changes to main

**Version Bump Helper Script:**

- **Location**: `scripts/bump-version.sh`
- **Usage**: `./scripts/bump-version.sh [patch|minor|major] [--tag] [--dry-run]`
- **Features**: Automatic semantic version incrementing, optional git tag creation

## ✅ Post-Deployment Configuration Requirements

**CRITICAL:** After deploying code changes to production, always verify and update:

### Google Maps API Configuration

- **Problem:** File reorganization affects API referrer restrictions
- **Solution:** Update Google Cloud Console API restrictions to include new
  file paths
- **Check:** Verify maps display correctly on statistics and detail pages

### UserSpice Page Permissions

- **Problem:** New pages and redirects need proper access permissions configured
- **Solution:** Update page permissions in UserSpice admin panel
- **Required for:** Both redirect pages AND new destination pages

### Deployment Verification Checklist

After each deployment, verify:

- [ ] Google Maps display correctly on all pages
- [ ] All redirected pages work and maintain proper permissions
- [ ] New pages have appropriate UserSpice permission levels
- [ ] Contact forms send to correct email addresses
- [ ] Version information displays correctly in footer
- [ ] Test critical user workflows (car registration, editing, contact forms)
- [ ] Database connectivity and functionality
- [ ] Email delivery system functioning
- [ ] Image upload and display working
- [ ] Search and filtering functionality
- [ ] Mobile responsiveness maintained

### Database Access

- **Configuration**: Use credentials from `.env.local` file (see
  DEV_DB_* variables)
- **Connection**: MAMP MySQL server on port 8889
- **MAMP MySQL Path**: `/Applications/MAMP/Library/bin/mysql`
- **Direct Command**:

  ```bash
  /Applications/MAMP/Library/bin/mysql -h localhost -P 8889 \
    -u claude -p"claude" elanregi_spice
  ```

## 🛠️ Environment Variables

### Production Environment Setup

See comprehensive documentation in `docs/development/ENVIRONMENT.md`:

- **Database credentials** (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`)
- **Google API keys** - **Production**: Stored in database settings table;
  **Testing only**: Environment variables (`MAPS_KEY`, `GEO_ENCODE_KEY`)
- All variables encrypted at rest using SecureEnvPHP

### UserSpice Plugins

**Active Plugins:**

- `Auto Assign Usernames` - Hides username field and auto-assigns usernames
  on registration
- `getSettings Function` - Provides global settings access via getSettings()
  function
- `hooker` - Custom hooks system for code injection points
- `reCAPTCHA` - Google reCAPTCHA v2/v3 integration for spam protection
- `Brevo Sendinblue` - API-based email delivery replacing phpmailer
  (300 emails/day free)

## 🚨 Troubleshooting

### Common Deployment Issues

1. **Version mismatch**: Ensure VERSION file content matches git tag exactly
2. **Permission errors**: Check UserSpice admin panel for new page permissions
3. **API failures**: Verify Google Cloud Console API restrictions are updated
4. **Email not working**: Check Brevo/Sendinblue API configuration
5. **Database connection**: Verify production database credentials

### Rollback Procedure

If deployment fails:

1. **Immediate rollback**: `git push prod previous-working-tag`
2. **Verify rollback**: Check version display and core functionality
3. **Investigate issue**: Review error logs and deployment differences
4. **Fix and redeploy**: Address issues and follow deployment process again

### Emergency Contacts

- **Hosting Support**: A2 Hosting technical support
- **Domain Management**: Check domain registrar for DNS issues
- **Database Issues**: Contact hosting provider database support

---

**📖 Related Documentation:**

- [CLAUDE.md](../../CLAUDE.md) - Essential development guidance
- [DEVELOPMENT_WORKFLOW.md](DEVELOPMENT_WORKFLOW.md) - Detailed development processes
- [ENVIRONMENT.md](ENVIRONMENT.md) - Environment setup and configuration
