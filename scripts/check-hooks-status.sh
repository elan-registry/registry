#!/bin/bash

#
# Git Hooks Status Check Script
#
# Verifies that git hooks are properly configured and all dependencies are available.
# Useful for troubleshooting hook issues or verifying setup on a new machine.
#
# Usage: ./scripts/check-hooks-status.sh
#

set -e

echo "════════════════════════════════════════════════════════"
echo "  Git Hooks Status Check - Elan Registry"
echo "════════════════════════════════════════════════════════"
echo ""

# Track overall status
ALL_CHECKS_PASSED=true

# Check if we're in a git repository
if [ ! -d ".git" ]; then
    echo "❌ Error: Not in a git repository root"
    echo "   Current directory: $(pwd)"
    echo "   Please run from the project root directory"
    exit 1
fi

# ============================================================================
# Hook Configuration Checks
# ============================================================================

echo "🔧 Hook Configuration:"
echo "────────────────────────────────────────────────────────"

# Check if hooks are configured
HOOKS_PATH=$(git config core.hooksPath)
if [ "$HOOKS_PATH" = ".githooks" ]; then
    echo "✅ Hooks configured: $HOOKS_PATH"
else
    echo "❌ Hooks NOT configured"
    echo "   Expected: .githooks"
    echo "   Current:  ${HOOKS_PATH:-default (.git/hooks)}"
    echo ""
    echo "   Fix with: ./scripts/setup-git-hooks.sh"
    ALL_CHECKS_PASSED=false
fi

# Check hook files exist and are executable
echo ""
echo "📂 Hook Files:"
echo "────────────────────────────────────────────────────────"

# Pre-commit hook
if [ -f ".githooks/pre-commit" ]; then
    if [ -x ".githooks/pre-commit" ]; then
        echo "✅ Pre-commit hook: exists and executable"
    else
        echo "⚠️  Pre-commit hook: exists but NOT executable"
        echo "   Fix with: chmod +x .githooks/pre-commit"
        ALL_CHECKS_PASSED=false
    fi
else
    echo "❌ Pre-commit hook: NOT found"
    echo "   Expected location: .githooks/pre-commit"
    ALL_CHECKS_PASSED=false
fi

# Commit-msg hook
if [ -f ".githooks/commit-msg" ]; then
    if [ -x ".githooks/commit-msg" ]; then
        echo "✅ Commit-msg hook: exists and executable"
    else
        echo "⚠️  Commit-msg hook: exists but NOT executable"
        echo "   Fix with: chmod +x .githooks/commit-msg"
        ALL_CHECKS_PASSED=false
    fi
else
    echo "ℹ️  Commit-msg hook: not installed (optional)"
fi

# ============================================================================
# Required Tools Checks
# ============================================================================

echo ""
echo "🔧 Required Tools:"
echo "────────────────────────────────────────────────────────"

# PHP
if command -v php >/dev/null 2>&1; then
    PHP_VERSION=$(php -v | head -n1)
    echo "✅ PHP: $PHP_VERSION"

    # Check PHP version (8.1+ required)
    PHP_VERSION_NUM=$(php -r 'echo PHP_VERSION;')
    if php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then
        echo "   ✓ Version check: PHP 8.1+ (OK)"
    else
        echo "   ⚠️  Version check: PHP $PHP_VERSION_NUM (8.1+ recommended)"
    fi
else
    echo "❌ PHP: not found"
    echo "   Required for: Pre-commit coding standards checks"
    ALL_CHECKS_PASSED=false
fi

# Composer
if command -v composer >/dev/null 2>&1; then
    COMPOSER_VERSION=$(composer --version 2>/dev/null | head -n1 || echo "installed")
    echo "✅ Composer: $COMPOSER_VERSION"
else
    echo "⚠️  Composer: not found"
    echo "   Required for: PHP dependency management and testing"
fi

# npx (for markdown linting)
if command -v npx >/dev/null 2>&1; then
    NPX_VERSION=$(npx --version 2>/dev/null || echo "available")
    echo "✅ npx: version $NPX_VERSION"
else
    echo "⚠️  npx: not found"
    echo "   Required for: Markdown linting (markdownlint-cli2)"
    echo "   Install Node.js to enable this feature"
fi

# ============================================================================
# Dependencies Checks
# ============================================================================

echo ""
echo "📦 Dependencies:"
echo "────────────────────────────────────────────────────────"

# PHP dependencies (vendor/)
if [ -d "vendor" ]; then
    echo "✅ PHP dependencies: installed (vendor/ exists)"

    # Check if PHPUnit is available
    if [ -f "vendor/bin/phpunit" ]; then
        echo "   ✓ PHPUnit: available for pre-commit tests"
    else
        echo "   ⚠️  PHPUnit: not found in vendor/bin/"
    fi
else
    echo "⚠️  PHP dependencies: NOT installed"
    echo "   Fix with: composer install"
    echo "   Impact: Unit tests will be skipped in pre-commit hook"
fi

# Node dependencies (node_modules/)
if [ -d "node_modules" ]; then
    echo "✅ Node dependencies: installed (node_modules/ exists)"
else
    echo "⚠️  Node dependencies: NOT installed"
    echo "   Fix with: npm install"
    echo "   Impact: Markdown linting may use npx fallback"
fi

# ============================================================================
# Supporting Scripts Checks
# ============================================================================

echo ""
echo "📜 Supporting Scripts:"
echo "────────────────────────────────────────────────────────"

# Coding standards checker
if [ -f "scripts/check-coding-standards.php" ]; then
    echo "✅ Coding standards checker: scripts/check-coding-standards.php"
else
    echo "❌ Coding standards checker: NOT found"
    echo "   Expected: scripts/check-coding-standards.php"
    ALL_CHECKS_PASSED=false
fi

# Setup script
if [ -f "scripts/setup-git-hooks.sh" ]; then
    if [ -x "scripts/setup-git-hooks.sh" ]; then
        echo "✅ Setup script: scripts/setup-git-hooks.sh (executable)"
    else
        echo "⚠️  Setup script: exists but not executable"
        echo "   Fix with: chmod +x scripts/setup-git-hooks.sh"
    fi
else
    echo "❌ Setup script: NOT found"
    ALL_CHECKS_PASSED=false
fi

# Configuration files
if [ -f ".markdownlint.json" ]; then
    echo "✅ Markdown lint config: .markdownlint.json"
else
    echo "⚠️  Markdown lint config: NOT found (using defaults)"
fi

# ============================================================================
# Test Execution Check
# ============================================================================

echo ""
echo "🧪 Quick Hook Test:"
echo "────────────────────────────────────────────────────────"

if [ "$HOOKS_PATH" = ".githooks" ] && [ -x ".githooks/pre-commit" ]; then
    echo "Testing pre-commit hook (dry run)..."

    # Create a temporary test scenario
    TEST_PASSED=true

    # Check if there are any staged files
    STAGED_FILES=$(git diff --cached --name-only | wc -l)
    if [ "$STAGED_FILES" -gt 0 ]; then
        echo "⚠️  You have $STAGED_FILES staged file(s)"
        echo "   Skipping hook test to avoid affecting your work"
        echo "   To test: git commit --allow-empty -m 'test: verify hooks'"
    else
        echo "✓ No staged files (safe to test)"
        echo "   Suggestion: Run 'git commit --allow-empty -m \"test: verify hooks\"'"
    fi
else
    echo "⚠️  Cannot test hook (not configured or not executable)"
fi

# ============================================================================
# Summary
# ============================================================================

echo ""
echo "════════════════════════════════════════════════════════"
echo "  Summary"
echo "════════════════════════════════════════════════════════"
echo ""

if [ "$ALL_CHECKS_PASSED" = true ]; then
    echo "✅ All critical checks passed!"
    echo ""
    echo "Your git hooks are properly configured and ready to use."
    echo ""
    echo "💡 Next steps:"
    echo "   • Make a test commit: git commit --allow-empty -m 'test: verify hooks'"
    echo "   • Review hook behavior during your next real commit"
    echo "   • See scripts/README.md for troubleshooting help"
else
    echo "⚠️  Some checks failed (see details above)"
    echo ""
    echo "🔧 Recommended actions:"
    echo "   1. Run setup script: ./scripts/setup-git-hooks.sh"
    echo "   2. Install dependencies: composer install && npm install"
    echo "   3. Run this check again: ./scripts/check-hooks-status.sh"
    echo ""
    echo "📖 For help, see: scripts/README.md"
    exit 1
fi

echo ""
echo "════════════════════════════════════════════════════════"
