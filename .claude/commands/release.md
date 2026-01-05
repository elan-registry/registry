---
description: Release a new version with comprehensive workflow
---

# Release Command

**Intelligent automated release workflow with commit analysis, version
recommendation, and deployment guidance.**

## Quick Start

```bash
# Run the intelligent release script
./scripts/release.sh
```

The script will guide you through the complete release process:

## What the Script Does

### 1. **Pre-Flight Checks** (Automatic)

- ✅ Verifies required tools (git, gh CLI, VS Code)
- ✅ Confirms you're on main branch
- ✅ Checks for uncommitted changes
- ✅ Verifies sync with origin/main
- ✅ Validates tag doesn't already exist

### 2. **Version Analysis** (Intelligent)

- 📊 Analyzes commits since last release
- 🔍 Categorizes: features, fixes, breaking changes
- 💡 Recommends version bump (patch/minor/major)
- 👤 Asks for your approval or manual override

### 3. **Release Notes** (Hybrid)

- 📝 Auto-generates from template with populated sections
- ✏️  Opens in VS Code for you to complete TODO sections
- 📋 Includes: issues resolved, commit summaries, categorized changes

### 4. **Commit & Tag** (Automatic)

- 📄 Updates VERSION file
- 💾 Creates release commit with auto-generated message
- 🏷️  Creates annotated git tag

### 5. **Push Confirmation** (Safety Check)

- 📊 Shows summary of what will be pushed
- ❓ Asks: "Push to origin and create GitHub release?"
- 🛡️  Gives you chance to review before pushing

### 6. **Push & Release** (Automatic if approved)

- ⬆️  Pushes to origin/main
- 🏷️  Pushes tag to origin
- 🎉 Creates GitHub release with auto-generated notes

### 7. **Deployment Instructions** (Copy-Paste Ready)

- 🧪 Shows test deployment commands
- ⚠️  Shows production deployment commands with warnings
- 🔗 Provides links to GitHub release and release notes

## Error Handling

**Pre-Push Errors:** Automatic rollback

- Deletes created tag
- Undos commit
- Removes created files
- Returns to clean state

**Post-Push Errors:** Recovery instructions

- Keeps what was successfully pushed
- Shows manual commands to complete/fix

## Example Session

```bash
$ ./scripts/release.sh

═══════════════════════════════════════════════════════════
Pre-Flight Checks
═══════════════════════════════════════════════════════════

✅ git: git version 2.39.0
✅ gh: gh version 2.40.0
✅ GitHub CLI: authenticated
✅ VS Code: available
✅ On main branch
✅ No uncommitted changes
✅ In sync with origin/main

═══════════════════════════════════════════════════════════
Version Analysis
═══════════════════════════════════════════════════════════

ℹ️  Current version: v2.9.4
ℹ️  Analyzing changes since: v2.9.4

Commit breakdown:
  Breaking changes: 0
  Features:         3
  Fixes:            2
  Other:            5

ℹ️  Recommendation: minor (New features added)

Approve recommended minor version bump? (y/n): y

✅ Version bump type: minor
ℹ️  New version: v2.9.5

═══════════════════════════════════════════════════════════
Creating Release Notes
═══════════════════════════════════════════════════════════

✅ Release notes draft created
ℹ️  Opening in VS Code for review...
[You complete TODO sections and save]
✅ Release notes completed

═══════════════════════════════════════════════════════════
Ready to Push
═══════════════════════════════════════════════════════════

Summary:
  Version: v2.9.4 → v2.9.5

Push to origin and create GitHub release? (y/n): y

✅ Pushed to origin/main
✅ Pushed tag to origin
✅ GitHub release created

═══════════════════════════════════════════════════════════
Release v2.9.5 Created Successfully!
═══════════════════════════════════════════════════════════

[Deployment instructions shown...]
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
# Set the version to rollback (REQUIRED)
read -p "Enter version to rollback (e.g., v2.9.5): " VERSION
echo "Rolling back version: $VERSION"

# Delete tag from all remotes
git tag -d "$VERSION"
git push origin :refs/tags/"$VERSION"
git push test :refs/tags/"$VERSION"
# 🚨 CAREFUL: Only delete from prod if already pushed there
git push prod :refs/tags/"$VERSION"

# Emergency production rollback to previous version
read -p "Enter previous stable version (e.g., v2.9.4): " PREVIOUS_TAG
echo "Rolling back to: $PREVIOUS_TAG"
git push prod $PREVIOUS_TAG
git push prod $PREVIOUS_TAG:refs/heads/main
```

---

**📖 Full Documentation:**

- [DEPLOYMENT.md](../../docs/development/DEPLOYMENT.md) -
  Complete release and deployment procedures
- [RELEASE_NOTES_TEMPLATE.md](../../docs/development/RELEASE_NOTES_TEMPLATE.md)
  \- Release notes template
- [CLAUDE.md](../../docs/development/CLAUDE.md) - Development
  guidelines
