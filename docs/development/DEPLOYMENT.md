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

| Check Name               | Purpose            | Blocks | Runs When          |
| ------------------------ | ------------------ | ------ | ------------------ |
| **CodeQL Analysis**      | Security scanning  | ✅ Yes | All PRs to main    |
| **GitGuardian Security** | Secret detection   | ✅ Yes | All commits/PRs    |
| **Claude Code Review**   | Coding standards   | ✅ Yes | PHP/JS/CSS changes |
| **Issue Management**     | Auto-label issues  | ❌ No  | Issue events       |
| **PR Management**        | Link PRs to issues | ❌ No  | PR events          |

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

### Pre-Commit Quality Checks

Pre-commit hooks validate PHP coding standards, markdown formatting, and run fast unit tests before each commit.

```bash
./scripts/setup-git-hooks.sh    # One-time setup
git commit --no-verify           # Bypass (emergency only)
```

**See [DEVELOPMENT_WORKFLOW.md](DEVELOPMENT_WORKFLOW.md#-git-hooks--quality-gates)** for hook details and `scripts/README.md` for troubleshooting.

## 📋 Complete Production Deployment Process

### Step-by-Step Deployment

1. **Create git tag** using `./scripts/bump-version.sh [patch|minor|major]`
2. **Commit changes** (if any) before creating tag
3. **Push to remotes** - deployment hooks automatically update VERSION file:
   - GitHub: `git push origin main && git push origin --tags`
   - Test: `git push test main && git push test --tags` (hook updates VERSION)
   - Production: `git push prod main && git push prod --tags` (hook updates VERSION)
4. **Verify deployment** by checking version display matches git tag on
   production site
5. **Complete post-deployment verification** (see checklist below)

### Git & Version Control

#### Branch Management Strategy

- `main` branch always contains production-ready code
- All development work happens on feature/phase branches
- Direct commits to main are discouraged

#### Branch Naming Convention

- Feature branches: `feature/issue-{number}-brief-description`
- Phase branches: `phase-{number}-{name}`
- Hotfix branches: `hotfix/issue-{number}-brief-description`

#### Version Management & Git Tag-Based Versioning

**Automated VERSION File Generation:**

- VERSION file is **auto-generated during deployment** (not manually edited)
- Git post-receive hooks run `git describe --tags > VERSION` on push
- VERSION file added to `.gitignore` (not tracked in git)
- Each environment generates its own VERSION file from its git repository
- Format: `vX.Y.Z` or `vX.Y.Z-N-gHASH` (semantic versioning via git describe)

**Deployment Hooks:**

Test and production servers have post-receive hooks that automatically:

1. Checkout latest code
2. Run `git describe --tags`
3. Write output to VERSION file

**Development:**

Run `./scripts/update-version.sh` to generate VERSION file locally after creating tags.

**Version Display:**

- `ApplicationVersion::get()` reads VERSION file (unchanged)
- Deployment timestamp shows VERSION file modification time
- Example output: `v2.9.1-rc1 (2025-12-14 10:30:00)`

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
- [ ] VERSION file exists on server (created by deployment hook)
- [ ] Deployment hooks executed successfully (check server logs)
- [ ] Test critical user workflows (car registration, editing, contact forms)
- [ ] Database connectivity and functionality
- [ ] Email delivery system functioning
- [ ] Image upload and display working
- [ ] Search and filtering functionality
- [ ] Mobile responsiveness maintained

### Database Access

- **Configuration**: Use credentials from `.env.local` file (see
  DEV*DB*\* variables)
- **Connection**: MAMP MySQL server on port 8889
- **MAMP MySQL Path**: `/Applications/MAMP/Library/bin/mysql`
- **Direct Command**:

  ```bash
  /Applications/MAMP/Library/bin/mysql -h localhost -P 8889 \
    -u claude -p"claude" elanregi_spice
  ```

## 🛠️ Environment Variables

See [ENVIRONMENT.md](ENVIRONMENT.md) for complete environment configuration (database credentials, API keys, SecureEnvPHP encryption).

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
