#!/bin/bash

#
# Intelligent Release Management Script for Elan Registry
#
# This script automates the complete release workflow:
# - Analyzes commits and recommends version bump
# - Creates release notes from template
# - Manages VERSION file and git tags
# - Creates GitHub releases
# - Provides deployment instructions
#
# Usage: ./scripts/release.sh
#

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# Global variables
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSION_FILE="$PROJECT_ROOT/VERSION"
RELEASE_NOTES_DIR="$PROJECT_ROOT/docs/releases"
RELEASE_NOTES_TEMPLATE="$PROJECT_ROOT/docs/development/RELEASE_NOTES_TEMPLATE.md"

# Error tracking
CREATED_TAG=""
CREATED_COMMIT=""
CREATED_FILES=()

#═══════════════════════════════════════════════════════════════════════════════
# UTILITY FUNCTIONS
#═══════════════════════════════════════════════════════════════════════════════

print_header() {
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo -e "${BOLD}$1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
    echo ""
}

print_error() {
    echo -e "${RED}❌ ERROR: $1${NC}" >&2
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  WARNING: $1${NC}"
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${NC}"
}

# Cleanup function for error rollback
cleanup_on_error() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo ""
        print_header "ERROR DETECTED - Initiating Rollback"

        # Delete created tag if exists
        if [ -n "$CREATED_TAG" ]; then
            if git tag -l | grep -q "^${CREATED_TAG}$"; then
                print_warning "Deleting created tag: $CREATED_TAG"
                git tag -d "$CREATED_TAG" 2>/dev/null || true
            fi
        fi

        # Undo commit if exists
        if [ -n "$CREATED_COMMIT" ]; then
            print_warning "Undoing release commit"
            git reset --hard HEAD~1 2>/dev/null || true
        fi

        # Delete created files
        for file in "${CREATED_FILES[@]}"; do
            if [ -f "$file" ]; then
                print_warning "Deleting created file: $file"
                rm -f "$file"
            fi
        done

        print_success "Rollback completed - repository restored to original state"
        echo ""
        print_info "You can retry the release after fixing the issue"
    fi
}

# Set trap for pre-push errors only
trap cleanup_on_error EXIT

#═══════════════════════════════════════════════════════════════════════════════
# PRE-FLIGHT CHECKS
#═══════════════════════════════════════════════════════════════════════════════

print_header "Pre-Flight Checks"

# Check 1: Required tools
print_info "Checking required tools..."

if ! command -v git &> /dev/null; then
    print_error "git is not installed"
    echo "Install git: https://git-scm.com/downloads"
    exit 1
fi
print_success "git: $(git --version | head -n1)"

if ! command -v gh &> /dev/null; then
    print_error "GitHub CLI (gh) is not installed"
    echo ""
    echo "Install GitHub CLI:"
    echo "  macOS:  brew install gh"
    echo "  Linux:  https://github.com/cli/cli/blob/trunk/docs/install_linux.md"
    echo "  Windows: https://github.com/cli/cli/releases"
    exit 1
fi
print_success "gh: $(gh --version | head -n1)"

# Check if gh is authenticated
if ! gh auth status &> /dev/null; then
    print_error "GitHub CLI is not authenticated"
    echo ""
    echo "Authenticate with: gh auth login"
    exit 1
fi
print_success "GitHub CLI: authenticated"

if ! command -v code &> /dev/null; then
    print_error "VS Code (code) is not installed or not in PATH"
    echo ""
    echo "Install VS Code: https://code.visualstudio.com/"
    echo "Add to PATH: https://code.visualstudio.com/docs/setup/mac#_launching-from-the-command-line"
    exit 1
fi
print_success "VS Code: available"

# Check git configuration
if [ -z "$(git config user.name)" ] || [ -z "$(git config user.email)" ]; then
    print_error "Git user.name or user.email not configured"
    echo ""
    echo "Configure git:"
    echo "  git config --global user.name \"Your Name\""
    echo "  git config --global user.email \"your.email@example.com\""
    exit 1
fi
print_success "Git configured: $(git config user.name) <$(git config user.email)>"

# Check 2: On main branch
print_info "Checking current branch..."
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "main" ]; then
    print_error "Releases must be created from main branch"
    echo ""
    echo "Current branch: $CURRENT_BRANCH"
    echo ""
    echo "Switch to main: git checkout main"
    exit 1
fi
print_success "On main branch"

# Check 3: No uncommitted changes
print_info "Checking for uncommitted changes..."
if ! git diff-index --quiet HEAD --; then
    print_error "You have uncommitted changes"
    echo ""
    echo "Modified files:"
    git status --short
    echo ""
    echo "Commit or stash changes before creating a release:"
    echo "  git add ."
    echo "  git commit -m \"Your message\""
    echo ""
    echo "Or stash them:"
    echo "  git stash"
    exit 1
fi
print_success "No uncommitted changes"

# Check 4: In sync with origin/main
print_info "Checking sync with origin/main..."
git fetch origin main --quiet

LOCAL_HASH=$(git rev-parse HEAD)
REMOTE_HASH=$(git rev-parse origin/main)

if [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
    # Check if behind, ahead, or diverged
    BEHIND=$(git rev-list --count HEAD..origin/main)
    AHEAD=$(git rev-list --count origin/main..HEAD)

    if [ $BEHIND -gt 0 ] && [ $AHEAD -eq 0 ]; then
        print_error "Your main branch is $BEHIND commit(s) behind origin/main"
        echo ""
        echo "Pull latest changes: git pull origin main"
    elif [ $AHEAD -gt 0 ] && [ $BEHIND -eq 0 ]; then
        print_error "Your main branch is $AHEAD commit(s) ahead of origin/main"
        echo ""
        echo "Push your changes first: git push origin main"
    else
        print_error "Your main branch has diverged from origin/main"
        echo ""
        echo "Behind: $BEHIND commits"
        echo "Ahead: $AHEAD commits"
        echo ""
        echo "Resolve conflicts first"
    fi
    exit 1
fi
print_success "In sync with origin/main"

print_success "All pre-flight checks passed!"

#═══════════════════════════════════════════════════════════════════════════════
# VERSION ANALYSIS & RECOMMENDATION
#═══════════════════════════════════════════════════════════════════════════════

print_header "Version Analysis"

# Get current version
if [ ! -f "$VERSION_FILE" ]; then
    print_error "VERSION file not found: $VERSION_FILE"
    exit 1
fi

CURRENT_VERSION=$(cat "$VERSION_FILE" | tr -d '\n\r')
print_info "Current version: $CURRENT_VERSION"

# Get last release tag
LAST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "")
if [ -z "$LAST_TAG" ]; then
    print_warning "No previous tags found - this appears to be the first release"
    LAST_TAG="HEAD~10"  # Analyze last 10 commits
fi

print_info "Analyzing changes since: $LAST_TAG"
echo ""

# Analyze commits
COMMIT_COUNT=$(git rev-list --count ${LAST_TAG}..HEAD)
print_info "Total commits: $COMMIT_COUNT"

# Categorize commits
BREAKING_COUNT=$(git log ${LAST_TAG}..HEAD --oneline | grep -iE "breaking|BREAKING CHANGE" | wc -l | tr -d ' ')
FEAT_COUNT=$(git log ${LAST_TAG}..HEAD --oneline | grep -iE "^[a-f0-9]+ (feat|feature):" | wc -l | tr -d ' ')
FIX_COUNT=$(git log ${LAST_TAG}..HEAD --oneline | grep -iE "^[a-f0-9]+ (fix|hotfix|bug):" | wc -l | tr -d ' ')

echo ""
echo "Commit breakdown:"
echo "  Breaking changes: $BREAKING_COUNT"
echo "  Features:         $FEAT_COUNT"
echo "  Fixes:            $FIX_COUNT"
echo "  Other:            $((COMMIT_COUNT - BREAKING_COUNT - FEAT_COUNT - FIX_COUNT))"

# Recommend version bump
if [ $BREAKING_COUNT -gt 0 ]; then
    RECOMMENDED="major"
    REASON="Breaking changes detected"
elif [ $FEAT_COUNT -gt 0 ]; then
    RECOMMENDED="minor"
    REASON="New features added"
elif [ $FIX_COUNT -gt 0 ]; then
    RECOMMENDED="patch"
    REASON="Bug fixes only"
else
    RECOMMENDED="patch"
    REASON="Miscellaneous changes"
fi

echo ""
print_info "Recommendation: ${BOLD}${RECOMMENDED}${NC} ($REASON)"

# Show commit summary
echo ""
echo "Recent commits:"
git log ${LAST_TAG}..HEAD --oneline --pretty=format:"  %C(yellow)%h%C(reset) %s" | head -n 10
if [ $COMMIT_COUNT -gt 10 ]; then
    echo "  ... and $((COMMIT_COUNT - 10)) more"
fi
echo ""

# Ask for approval
echo ""
read -p "$(echo -e ${CYAN}Approve recommended ${BOLD}${RECOMMENDED}${NC}${CYAN} version bump? \(y/n\): ${NC})" -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo ""
    echo "Which version bump do you want?"
    echo "  1) patch (bug fixes)"
    echo "  2) minor (new features)"
    echo "  3) major (breaking changes)"
    echo ""
    read -p "Choice (1-3): " -n 1 -r BUMP_CHOICE
    echo ""

    case $BUMP_CHOICE in
        1) BUMP_TYPE="patch" ;;
        2) BUMP_TYPE="minor" ;;
        3) BUMP_TYPE="major" ;;
        *)
            print_error "Invalid choice"
            exit 1
            ;;
    esac
else
    BUMP_TYPE="$RECOMMENDED"
fi

print_success "Version bump type: $BUMP_TYPE"

#═══════════════════════════════════════════════════════════════════════════════
# CALCULATE NEW VERSION
#═══════════════════════════════════════════════════════════════════════════════

# Extract version components (remove 'v' prefix)
version_core=$(echo "$CURRENT_VERSION" | sed 's/^v//' | sed 's/-.*$//')
IFS='.' read -r major minor patch <<< "$version_core"

# Calculate new version
case "$BUMP_TYPE" in
    "patch")
        new_patch=$((patch + 1))
        NEW_VERSION="v${major}.${minor}.${new_patch}"
        RELEASE_TYPE="Patch"
        ;;
    "minor")
        new_minor=$((minor + 1))
        NEW_VERSION="v${major}.${new_minor}.0"
        RELEASE_TYPE="Minor"
        ;;
    "major")
        new_major=$((major + 1))
        NEW_VERSION="v${new_major}.0.0"
        RELEASE_TYPE="Major"
        ;;
esac

print_info "New version: ${BOLD}$NEW_VERSION${NC}"

# Check 5: Tag doesn't already exist
if git tag -l | grep -q "^${NEW_VERSION}$"; then
    print_error "Tag $NEW_VERSION already exists"
    echo ""
    echo "Possible causes:"
    echo "  1. Release already completed - check: git tag -l $NEW_VERSION"
    echo "  2. Previous release attempt failed - safe to delete local tag"
    echo "  3. Tag exists on remote - dangerous to recreate"
    echo ""
    echo "Check remote: git ls-remote --tags origin $NEW_VERSION"
    echo ""
    echo "To delete local tag only:"
    echo "  git tag -d $NEW_VERSION"
    echo ""
    echo "To delete from remote (CAUTION!):"
    echo "  git push origin :refs/tags/$NEW_VERSION"
    exit 1
fi

#═══════════════════════════════════════════════════════════════════════════════
# CREATE RELEASE NOTES
#═══════════════════════════════════════════════════════════════════════════════

print_header "Creating Release Notes"

RELEASE_NOTES_FILE="$RELEASE_NOTES_DIR/RELEASE_NOTES_${NEW_VERSION}.md"
mkdir -p "$RELEASE_NOTES_DIR"

print_info "Generating release notes from template..."

# Read template
if [ ! -f "$RELEASE_NOTES_TEMPLATE" ]; then
    print_error "Release notes template not found: $RELEASE_NOTES_TEMPLATE"
    exit 1
fi

# Create release notes from template
cat > "$RELEASE_NOTES_FILE" << EOF
# Elan Registry $NEW_VERSION Release Notes

**Release Date:** $(date +"%B %d, %Y")
**Type:** $RELEASE_TYPE Release

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

<!-- TODO: Add any required manual actions after deployment -->
<!-- If none required, replace this section with: -->
<!-- No manual actions required for this release. -->

## 👤 User-Facing Changes

<!-- TODO: Describe visible changes for end users -->
<!-- Or state: No visible changes for end users. This is an internal release. -->

## 🔧 Admin-Facing Changes

<!-- TODO: Describe changes for administrators -->
<!-- Or remove this section if not applicable -->

## 📋 Issues Resolved in This Release

EOF

# Add issues from commits
echo "" >> "$RELEASE_NOTES_FILE"
git log ${LAST_TAG}..HEAD --oneline | grep -oE "#[0-9]+" | sort -u | while read issue; do
    issue_num=$(echo $issue | tr -d '#')
    # Try to get issue title from GitHub
    issue_title=$(gh issue view $issue_num --json title --jq '.title' 2>/dev/null || echo "Issue $issue")
    echo "[$issue](https://github.com/$(gh repo view --json nameWithOwner -q .nameWithOwner)/issues/$issue_num) - $issue_title" >> "$RELEASE_NOTES_FILE"
done

# Add commit summary
cat >> "$RELEASE_NOTES_FILE" << EOF

---

## 📝 Summary of Changes

### Features Added
EOF

git log ${LAST_TAG}..HEAD --oneline | grep -iE "^[a-f0-9]+ (feat|feature):" | sed 's/^[a-f0-9]* /- /' >> "$RELEASE_NOTES_FILE" || echo "- None" >> "$RELEASE_NOTES_FILE"

cat >> "$RELEASE_NOTES_FILE" << EOF

### Bug Fixes
EOF

git log ${LAST_TAG}..HEAD --oneline | grep -iE "^[a-f0-9]+ (fix|hotfix|bug):" | sed 's/^[a-f0-9]* /- /' >> "$RELEASE_NOTES_FILE" || echo "- None" >> "$RELEASE_NOTES_FILE"

cat >> "$RELEASE_NOTES_FILE" << EOF

### Other Changes
EOF

git log ${LAST_TAG}..HEAD --oneline | grep -ivE "^[a-f0-9]+ (feat|feature|fix|hotfix|bug):" | sed 's/^[a-f0-9]* /- /' | head -n 20 >> "$RELEASE_NOTES_FILE"

cat >> "$RELEASE_NOTES_FILE" << EOF

---

**Summary:** $RELEASE_TYPE release with $COMMIT_COUNT commits since $LAST_TAG.
EOF

CREATED_FILES+=("$RELEASE_NOTES_FILE")
print_success "Release notes draft created: $RELEASE_NOTES_FILE"

# Open in VS Code for editing
echo ""
print_info "Opening release notes in VS Code for review and completion..."
print_warning "Complete the TODO sections, then save and close the file"
echo ""
read -p "Press Enter when ready to open VS Code..."

code --wait "$RELEASE_NOTES_FILE"

print_success "Release notes completed"

#═══════════════════════════════════════════════════════════════════════════════
# UPDATE VERSION FILE
#═══════════════════════════════════════════════════════════════════════════════

print_header "Updating VERSION File"

echo "$NEW_VERSION" > "$VERSION_FILE"
print_success "VERSION file updated to $NEW_VERSION"

#═══════════════════════════════════════════════════════════════════════════════
# CREATE COMMIT AND TAG
#═══════════════════════════════════════════════════════════════════════════════

print_header "Creating Release Commit"

# Stage files
git add "$VERSION_FILE"
git add "$RELEASE_NOTES_FILE"

# Extract brief description from release notes (the Type line)
BRIEF_DESC=$(grep "^**Type:**" "$RELEASE_NOTES_FILE" | sed 's/\*\*Type:\*\* //' || echo "$RELEASE_TYPE Release")

# Create commit
COMMIT_MSG="RELEASE: $NEW_VERSION - $BRIEF_DESC

Auto-generated release commit including:
- VERSION file updated to $NEW_VERSION
- Release notes: $RELEASE_NOTES_FILE

Changes in this release:
- $COMMIT_COUNT commits since $LAST_TAG
- $FEAT_COUNT features
- $FIX_COUNT fixes
- $BREAKING_COUNT breaking changes

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"

git commit -m "$COMMIT_MSG"
CREATED_COMMIT="HEAD"
print_success "Release commit created"

# Create annotated tag
print_info "Creating git tag..."
TAG_MSG="Release $NEW_VERSION: $BRIEF_DESC

$(cat $RELEASE_NOTES_FILE | head -n 50)

Full release notes: $RELEASE_NOTES_FILE"

git tag -a "$NEW_VERSION" -m "$TAG_MSG"
CREATED_TAG="$NEW_VERSION"
print_success "Git tag created: $NEW_VERSION"

#═══════════════════════════════════════════════════════════════════════════════
# PRE-PUSH CONFIRMATION
#═══════════════════════════════════════════════════════════════════════════════

print_header "Ready to Push"

echo "Summary of changes:"
echo "  Version:      $CURRENT_VERSION → $NEW_VERSION"
echo "  Commit:       $(git rev-parse --short HEAD)"
echo "  Tag:          $NEW_VERSION"
echo "  Release notes: $RELEASE_NOTES_FILE"
echo ""
echo "Will push to:"
echo "  • origin/main (GitHub repository)"
echo "  • origin/$NEW_VERSION (tag)"
echo ""
echo "Will create:"
echo "  • GitHub release with auto-generated notes"
echo ""

read -p "$(echo -e ${CYAN}Push to origin and create GitHub release? \(y/n\): ${NC})" -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    print_warning "Release cancelled by user"
    echo ""
    print_info "Changes are committed locally but not pushed"
    echo ""
    echo "To push manually later:"
    echo "  git push origin main"
    echo "  git push origin $NEW_VERSION"
    echo "  gh release create $NEW_VERSION --generate-notes"
    echo ""
    echo "To undo the release commit and tag:"
    echo "  git tag -d $NEW_VERSION"
    echo "  git reset --hard HEAD~1"
    exit 0
fi

# Disable error rollback from this point - we're pushing
trap - EXIT

#═══════════════════════════════════════════════════════════════════════════════
# PUSH TO ORIGIN
#═══════════════════════════════════════════════════════════════════════════════

print_header "Pushing to Origin"

print_info "Pushing to origin/main..."
if ! git push origin main; then
    print_error "Failed to push to origin/main"
    echo ""
    echo "Recovery:"
    echo "  1. Fix the issue (check git output above)"
    echo "  2. Push again: git push origin main"
    echo "  3. Then push tag: git push origin $NEW_VERSION"
    echo "  4. Then create release: gh release create $NEW_VERSION --generate-notes"
    exit 1
fi
print_success "Pushed to origin/main"

print_info "Pushing tag to origin..."
if ! git push origin "$NEW_VERSION"; then
    print_error "Failed to push tag to origin"
    echo ""
    echo "Recovery:"
    echo "  1. Fix the issue (check git output above)"
    echo "  2. Push tag: git push origin $NEW_VERSION"
    echo "  3. Create release: gh release create $NEW_VERSION --generate-notes"
    exit 1
fi
print_success "Pushed tag to origin"

#═══════════════════════════════════════════════════════════════════════════════
# CREATE GITHUB RELEASE
#═══════════════════════════════════════════════════════════════════════════════

print_header "Creating GitHub Release"

print_info "Creating GitHub release with auto-generated notes..."
if ! gh release create "$NEW_VERSION" --title "Release $NEW_VERSION: $BRIEF_DESC" --generate-notes --verify-tag; then
    print_error "Failed to create GitHub release"
    echo ""
    echo "Recovery:"
    echo "  The commit and tag were pushed successfully."
    echo "  Create the release manually:"
    echo "    gh release create $NEW_VERSION --title \"Release $NEW_VERSION\" --generate-notes --verify-tag"
    echo ""
    echo "  Or create via GitHub web interface:"
    echo "    https://github.com/$(gh repo view --json nameWithOwner -q .nameWithOwner)/releases/new?tag=$NEW_VERSION"
    exit 1
fi

RELEASE_URL=$(gh release view "$NEW_VERSION" --json url -q .url)
print_success "GitHub release created: $RELEASE_URL"

#═══════════════════════════════════════════════════════════════════════════════
# SUCCESS - SHOW DEPLOYMENT INSTRUCTIONS
#═══════════════════════════════════════════════════════════════════════════════

print_header "Release $NEW_VERSION Created Successfully!"

cat << EOF

${GREEN}✅ Release completed successfully!${NC}

${BLUE}═══════════════════════════════════════════════════════════
🧪 TEST DEPLOYMENT (Do This First)
═══════════════════════════════════════════════════════════${NC}

Deploy to test server for validation:

    ${BOLD}git push test $NEW_VERSION${NC}

Required validation (24-48 hours):
  ✓ Version displays correctly on test site
  ✓ All features from release notes tested
  ✓ No errors in server logs
  ✓ Performance acceptable
  ✓ Required actions from release notes completed

${BLUE}═══════════════════════════════════════════════════════════
🚨 PRODUCTION DEPLOYMENT (After Test Success)
═══════════════════════════════════════════════════════════${NC}

${YELLOW}⚠️  WARNING: ONLY proceed after successful test validation!${NC}

Commands to deploy to LIVE production (elanregistry.org):

    ${BOLD}git push prod main${NC}
    ${BOLD}git push prod $NEW_VERSION${NC}

Then verify at: ${CYAN}https://elanregistry.org${NC}

${BLUE}═══════════════════════════════════════════════════════════${NC}

${CYAN}🔗 GitHub Release:${NC} $RELEASE_URL
${CYAN}📄 Release Notes:${NC} $RELEASE_NOTES_FILE

${GREEN}All done! Happy deploying! 🚀${NC}

EOF
