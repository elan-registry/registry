# Deployment Guide

This document provides comprehensive deployment procedures for the Lotus Elan Registry application.

## 🚀 Production Environment

### Hosting Infrastructure

- **Hosting**: A2 Hosting with git deployment hooks
- **Remote**: `prod` remote configured for direct deployment to production server
- **Auto-deployment**: Master branch deploys automatically when pushed to prod remote
- **Version Display**: Uses VERSION file modification time for deployment timestamp

### 🚨 CRITICAL: Production Deployment Commands

**⚠️ IMPORTANT:** When someone says "push to prod", always use the `prod` remote, NOT `origin`!

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
origin	git@github.com:unibrain1/elanregistry.git    # GitHub repository
prod	a2hosting:/home/unibrain/elanregistry.project  # LIVE PRODUCTION SERVER
```

**🔄 Deployment Rule:**

- `origin` = GitHub (development/backup)
- `prod` = **LIVE WEBSITE** (elanregistry.org)

## 📋 Complete Production Deployment Process

### Step-by-Step Deployment

1. **Update VERSION file and create matching git tag** (tag must exactly match VERSION content)
2. **Commit changes** with version bump and tag
3. **Push to GitHub** for repository backup: `git push origin main && git push origin --tags`
4. **🎯 DEPLOY TO PRODUCTION** (the important step): `git push prod main && git push prod --tags`
5. **Verify deployment** by checking version display matches git tag on production site
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
- **Solution:** Update Google Cloud Console API restrictions to include new file paths
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

- **Configuration**: Use credentials from `.env.local` file (see DEV_DB_* variables)
- **Connection**: MAMP MySQL server on port 8889
- **MAMP MySQL Path**: `/Applications/MAMP/Library/bin/mysql`
- **Direct Command**: `/Applications/MAMP/Library/bin/mysql -h localhost -P 8889 -u claude -p"claude" elanregi_spice`

## 🛠️ Environment Variables

### Production Environment Setup

See comprehensive documentation in `docs/development/ENVIRONMENT.md`:

- **Database credentials** (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`)
- **Google API keys** - **Production**: Stored in database settings table; **Testing only**: Environment variables (`MAPS_KEY`, `GEO_ENCODE_KEY`)
- All variables encrypted at rest using SecureEnvPHP

### UserSpice Plugins

**Active Plugins:**

- `Auto Assign Usernames` - Hides username field and auto-assigns usernames on registration
- `getSettings Function` - Provides global settings access via getSettings() function
- `hooker` - Custom hooks system for code injection points
- `reCAPTCHA` - Google reCAPTCHA v2/v3 integration for spam protection
- `Brevo Sendinblue` - API-based email delivery replacing phpmailer (300 emails/day free)

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
- [CLAUDE.md](CLAUDE.md) - Essential development guidance
- [DEVELOPMENT_WORKFLOW.md](DEVELOPMENT_WORKFLOW.md) - Detailed development processes
- [ENVIRONMENT.md](ENVIRONMENT.md) - Environment setup and configuration