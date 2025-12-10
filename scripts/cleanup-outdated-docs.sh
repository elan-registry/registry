#!/bin/bash
# cleanup-outdated-docs.sh
# Removes outdated markdown files that have been moved or are no longer needed
#
# This script identifies files that exist on production but are outdated based on
# the new documentation structure where release notes live in docs/releases/
#
# Usage:
#   ./cleanup-outdated-docs.sh              # Run from project root
#   ./cleanup-outdated-docs.sh --dry-run    # Preview without removing
#   ./cleanup-outdated-docs.sh /path/to/project --dry-run

set -e

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Parse arguments
DRY_RUN=false
CUSTOM_ROOT=""

for arg in "$@"; do
    case $arg in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [PROJECT_ROOT] [--dry-run]"
            echo ""
            echo "Arguments:"
            echo "  PROJECT_ROOT    Path to project root (default: auto-detect)"
            echo "  --dry-run       Preview changes without removing files"
            echo "  --help, -h      Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                                    # Auto-detect, remove files"
            echo "  $0 --dry-run                          # Auto-detect, preview only"
            echo "  $0 /home/unibrain/elanregistry.org    # Specify path"
            echo "  $0 /home/unibrain/elanregistry.org --dry-run  # Safe preview"
            exit 0
            ;;
        *)
            if [[ -d "$arg" ]]; then
                CUSTOM_ROOT="$arg"
            fi
            shift
            ;;
    esac
done

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Determine project root
if [ -n "$CUSTOM_ROOT" ]; then
    PROJECT_ROOT="$CUSTOM_ROOT"
else
    PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
fi

echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Outdated Documentation Cleanup Script${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${BLUE}Project Root:${NC} $PROJECT_ROOT"
if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}Mode:${NC} DRY RUN (preview only, no files will be removed)"
else
    echo -e "${YELLOW}Mode:${NC} LIVE (files will be removed)"
fi
echo ""

# Define outdated files that should be removed
# These files have been moved to new locations or are no longer needed
OUTDATED_FILES=(
    # Release notes moved from root to docs/releases/
    "./RELEASE_NOTES_V2.8.1.md"
    "./RELEASE_NOTES_V2.8.6.md"

    # Old release plan files (superseded by per-version plans in docs/technical/)
    "./docs/development/V2.8.1_RELEASE_PLAN.md"
    "./docs/development/RELEASE_PLAN.md"
)

# Files to keep (even if they might look outdated)
KEEP_FILES=(
    "./CLAUDE.md"  # Intentional redirect file for Claude Code
    "./README.md"  # Main project README
)

# Track results
REMOVED_COUNT=0
MISSING_COUNT=0
KEPT_COUNT=0

echo -e "${YELLOW}Checking for outdated files...${NC}"
echo ""

# Function to check if file should be kept
should_keep_file() {
    local file="$1"
    for keep_file in "${KEEP_FILES[@]}"; do
        if [[ "$file" == "$keep_file" ]]; then
            return 0
        fi
    done
    return 1
}

# Change to project root
cd "$PROJECT_ROOT"

# Check and remove outdated files
for file in "${OUTDATED_FILES[@]}"; do
    if should_keep_file "$file"; then
        echo -e "${GREEN}✓${NC} KEEPING: $file (intentional)"
        ((KEPT_COUNT++))
        continue
    fi

    if [ -f "$file" ]; then
        echo -e "${YELLOW}Found outdated file:${NC} $file"

        # Check if it was moved to new location
        if [[ "$file" == ./RELEASE_NOTES_V*.md ]]; then
            basename=$(basename "$file")
            new_location="./docs/releases/$basename"
            if [ -f "$new_location" ]; then
                echo -e "  → Moved to: $new_location"
            fi
        fi

        # Remove the file (or preview in dry-run mode)
        if [ "$DRY_RUN" = true ]; then
            echo -e "${YELLOW}⚠${NC}  WOULD REMOVE: $file"
            ((REMOVED_COUNT++))
        else
            rm "$file"
            echo -e "${RED}✗${NC} REMOVED: $file"
            ((REMOVED_COUNT++))
        fi
        echo ""
    else
        echo -e "${GREEN}✓${NC} Already removed: $file"
        ((MISSING_COUNT++))
    fi
done

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}  Cleanup Summary${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════════${NC}"
if [ "$DRY_RUN" = true ]; then
    echo -e "${YELLOW}Would remove:${NC}    $REMOVED_COUNT files"
else
    echo -e "${GREEN}Removed:${NC}         $REMOVED_COUNT files"
fi
echo -e "${YELLOW}Already missing:${NC} $MISSING_COUNT files"
echo -e "${BLUE}Kept:${NC}            $KEPT_COUNT files"
echo ""

if [ $REMOVED_COUNT -gt 0 ]; then
    echo -e "${YELLOW}Note:${NC} Removed files have been deleted. If this was a mistake,"
    echo "      you can recover them using: git checkout HEAD -- <filename>"
    echo ""
    echo -e "${GREEN}Documentation Structure:${NC}"
    echo "  - Release notes: docs/releases/RELEASE_NOTES_V*.md"
    echo "  - Test plans: docs/technical/V*_TEST_PLAN.md"
    echo "  - Templates: docs/development/RELEASE_NOTES_TEMPLATE.md"
    echo ""
fi

# Check for any other root-level markdown files that might be outdated
echo -e "${YELLOW}Checking for other potential outdated files in root...${NC}"
ROOT_MD_FILES=$(find . -maxdepth 1 -name "*.md" -type f ! -name "README.md" ! -name "CLAUDE.md" 2>/dev/null || true)

if [ -n "$ROOT_MD_FILES" ]; then
    echo ""
    echo -e "${YELLOW}Warning: Found other markdown files in root directory:${NC}"
    echo "$ROOT_MD_FILES" | while read -r file; do
        echo "  - $file"
    done
    echo ""
    echo "Review these files and consider moving them to appropriate docs/ subdirectories."
else
    echo -e "${GREEN}✓${NC} No other markdown files found in root (except README.md and CLAUDE.md)"
fi

echo ""
if [ "$DRY_RUN" = true ]; then
    echo -e "${GREEN}✓ Dry-run complete!${NC}"
    if [ $REMOVED_COUNT -gt 0 ]; then
        echo ""
        echo -e "${YELLOW}To actually remove these files, run:${NC}"
        if [ -n "$CUSTOM_ROOT" ]; then
            echo "  $0 $CUSTOM_ROOT"
        else
            echo "  $0"
        fi
    fi
else
    echo -e "${GREEN}✓ Cleanup complete!${NC}"
fi
