#!/bin/bash

# Version bump helper script for ElanRegistry
# Usage: ./scripts/bump-version.sh [patch|minor|major] [--dry-run] [--tag]

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default options
DRY_RUN=false
CREATE_TAG=true  # Default to creating tags (can be disabled with --no-tag)
BUMP_TYPE=""

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        patch|minor|major)
            BUMP_TYPE="$1"
            shift
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --tag)
            CREATE_TAG=true
            shift
            ;;
        --no-tag)
            CREATE_TAG=false
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [patch|minor|major] [--dry-run] [--no-tag]"
            echo ""
            echo "Arguments:"
            echo "  patch     Increment patch version (X.Y.Z -> X.Y.Z+1)"
            echo "  minor     Increment minor version (X.Y.Z -> X.Y+1.0)"
            echo "  major     Increment major version (X.Y.Z -> X+1.0.0)"
            echo ""
            echo "Options:"
            echo "  --dry-run Show what would be done without making changes"
            echo "  --no-tag  Skip creating git tag (NOT recommended)"
            echo "  --help    Show this help message"
            echo ""
            echo "Note: Git tags are created by default and should match VERSION file"
            echo ""
            echo "Examples:"
            echo "  $0 patch              # v2.3.3 -> v2.3.4 (with git tag)"
            echo "  $0 minor --dry-run    # Preview minor version bump"
            echo "  $0 major --no-tag     # Version bump without tag (not recommended)"
            exit 0
            ;;
        *)
            echo -e "${RED}Error: Unknown option '$1'${NC}"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Validate bump type
if [ -z "$BUMP_TYPE" ]; then
    echo -e "${RED}Error: Must specify version bump type (patch|minor|major)${NC}"
    echo "Use --help for usage information"
    exit 1
fi

# Check if we're in git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo -e "${RED}Error: Not in a git repository${NC}"
    exit 1
fi

# Check if VERSION file exists
VERSION_FILE="VERSION"
if [ ! -f "$VERSION_FILE" ]; then
    echo -e "${RED}Error: VERSION file not found${NC}"
    exit 1
fi

# Read current version
current_version=$(cat "$VERSION_FILE" | tr -d '\n\r')

# Validate current version format
if ! echo "$current_version" | grep -qE "^v[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9\-]+)?$"; then
    echo -e "${RED}Error: Invalid version format in VERSION file: $current_version${NC}"
    echo "Expected format: vX.Y.Z (e.g., v2.3.4)"
    exit 1
fi

# Extract version components (remove 'v' prefix and any suffix)
version_core=$(echo "$current_version" | sed 's/^v//' | sed 's/-.*$//')
IFS='.' read -r major minor patch <<< "$version_core"

# Calculate new version
case "$BUMP_TYPE" in
    "patch")
        new_patch=$((patch + 1))
        new_version="v${major}.${minor}.${new_patch}"
        ;;
    "minor")
        new_minor=$((minor + 1))
        new_version="v${major}.${new_minor}.0"
        ;;
    "major")
        new_major=$((major + 1))
        new_version="${new_major}.0.0"
        new_version="v${new_version}"
        ;;
esac

# Display version change
echo -e "${BLUE}Version Bump: $BUMP_TYPE${NC}"
echo "  Current: $current_version"
echo "  New:     $new_version"

if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}[DRY RUN] No changes will be made${NC}"
    if [ "$CREATE_TAG" = true ]; then
        echo -e "${YELLOW}[DRY RUN] Would create git tag: $new_version${NC}"
    else
        echo -e "${YELLOW}[DRY RUN] No git tag would be created (--no-tag specified)${NC}"
    fi
    exit 0
fi

# Check for uncommitted changes
if ! git diff-index --quiet HEAD --; then
    echo -e "${YELLOW}Warning: You have uncommitted changes${NC}"
    read -p "Continue anyway? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Aborted"
        exit 1
    fi
fi

# Update VERSION file
echo "$new_version" > "$VERSION_FILE"
echo -e "${GREEN}✓ Updated $VERSION_FILE${NC}"

# Stage VERSION file
git add "$VERSION_FILE"
echo -e "${GREEN}✓ Staged $VERSION_FILE${NC}"

# Create git tag if requested
if [ "$CREATE_TAG" = true ]; then
    if git tag -a "$new_version" -m "Release $new_version"; then
        echo -e "${GREEN}✓ Created git tag: $new_version${NC}"
    else
        echo -e "${YELLOW}Warning: Failed to create git tag (may already exist)${NC}"
    fi
fi

echo ""
echo -e "${GREEN}Version bump completed!${NC}"
echo ""
echo "Next steps:"
echo "  1. Review your changes: git diff --cached"
echo "  2. Commit your changes: git commit -m 'VERSION: Bump to $new_version'"
if [ "$CREATE_TAG" = false ]; then
    echo -e "  ${YELLOW}3. REQUIRED: Create matching tag: git tag -a $new_version -m 'Release $new_version'${NC}"
fi
echo ""
if [ "$CREATE_TAG" = true ]; then
    echo -e "${GREEN}✓ Git tag $new_version created (matches VERSION file)${NC}"
else
    echo -e "${YELLOW}⚠ WARNING: No git tag created. Tag must match VERSION for deployment!${NC}"
fi
echo ""
echo "Or undo changes with:"
echo "  git reset HEAD $VERSION_FILE && git checkout $VERSION_FILE"
if [ "$CREATE_TAG" = true ]; then
    echo "  git tag -d $new_version  # Remove tag if created"
fi