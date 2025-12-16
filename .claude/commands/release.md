---
description: Release a new version with comprehensive workflow
---

# Release Command - Quick Reference

**📋 For complete release workflow, see [docs/development/DEPLOYMENT.md](../../docs/development/DEPLOYMENT.md)**

## Quick Release Workflow

```bash
# 1. Update VERSION file
echo "v2.9.1" > VERSION

# 2. Create release commit
git add VERSION
git commit -m "RELEASE: v2.9.1 - Brief description

Major Features:
- Feature 1
- Feature 2

Bug Fixes:
- Fix 1
- Fix 2

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"

# 3. Create annotated tag
git tag -a v2.9.1 -m "Release v2.9.1: Brief Description

Major Features:
- Feature 1 with details
- Feature 2 with details

Bug Fixes:
- Fix 1 with details
- Fix 2 with details

Documentation:
- Doc updates

Technical Changes:
- Technical changes"

# 4. Push to all remotes
git push origin feature/v2.9.1 && git push origin v2.9.1    # GitHub
git push test v2.9.1                                          # Test server
git push prod main && git push prod v2.9.1                    # Production

# 5. Create GitHub release (major/minor only)
gh release create "v2.9.1" \
  --title "Release v2.9.1: Brief Description" \
  --generate-notes \
  --verify-tag
```

## Remote Configuration

- **origin** - GitHub repository (backup/development)
- **test** - Test/staging server for validation
- **prod** - LIVE PRODUCTION SERVER (elanregistry.org)

## Release Requirements

**MANDATORY for major (x.0.0) and minor (x.y.0) releases:**

- Release notes using template at `docs/development/RELEASE_NOTES_TEMPLATE.md`
- GitHub release with `gh release create`
- Annotated git tags
- Documentation in `docs/releases/`

**Optional for patch releases (x.y.z):**

- Release notes for significant patches or security fixes

## Pre-Release Analysis

```bash
# Find last release tag
git tag --sort=-version:refname | head -10

# Analyze changes since last release
LAST_TAG=$(git describe --tags --abbrev=0)
git log $LAST_TAG..HEAD --oneline --stat
git log $LAST_TAG..HEAD --pretty=format:"- %s (%h)"
```

## Semantic Versioning

- **Major (X.0.0)**: Breaking changes, incompatible API changes
- **Minor (X.Y.0)**: New features, backwards compatible
- **Patch (X.Y.Z)**: Bug fixes, backwards compatible

## Rollback Commands

```bash
# Delete tag from all remotes
git tag -d "v2.9.1"
git push origin :refs/tags/"v2.9.1"
git push test :refs/tags/"v2.9.1"
git push prod :refs/tags/"v2.9.1"

# Emergency production rollback
PREVIOUS_TAG="v2.9.0"
git push prod $PREVIOUS_TAG
git push prod $PREVIOUS_TAG:refs/heads/main
```

---

**📖 Full Documentation:**

- [DEPLOYMENT.md](../../docs/development/DEPLOYMENT.md) - Complete release and deployment procedures
- [RELEASE_NOTES_TEMPLATE.md](../../docs/development/RELEASE_NOTES_TEMPLATE.md) - Release notes template
- [CLAUDE.md](../../docs/development/CLAUDE.md) - Development guidelines
