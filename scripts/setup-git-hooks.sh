#!/bin/bash

#
# Git Hooks Setup Script
#
# Sets up local git hooks to enforce coding standards before commits.
# This helps prevent blocking issues in Claude Code Review and automated checks.
#
# Usage: ./scripts/setup-git-hooks.sh
#

set -e

echo "🔧 Setting up Git hooks for Elan Registry..."
echo ""

# Check if we're in a git repository
if [ ! -d ".git" ]; then
    echo "❌ Error: This must be run from the root of the git repository"
    exit 1
fi

# Configure git to use our custom hooks directory
echo "📂 Configuring Git to use .githooks directory..."
git config core.hooksPath .githooks

# Make sure hooks are executable
echo "🔐 Making hooks executable..."
chmod +x .githooks/*

echo ""
echo "✅ Git hooks setup complete!"
echo ""

# Verify installation
echo "🧪 Verifying hook installation..."
echo ""

VERIFICATION_PASSED=true

# Check if hooks are configured correctly
HOOKS_PATH=$(git config core.hooksPath)
if [ "$HOOKS_PATH" = ".githooks" ]; then
    echo "✅ Hooks configured correctly: $HOOKS_PATH"
else
    echo "❌ Hook configuration failed (expected: .githooks, got: ${HOOKS_PATH:-default})"
    VERIFICATION_PASSED=false
fi

# Test if hooks are executable
if [ -x ".githooks/pre-commit" ]; then
    echo "✅ Pre-commit hook is executable"
else
    echo "⚠️  Warning: Pre-commit hook not executable, fixing..."
    chmod +x .githooks/pre-commit
    if [ -x ".githooks/pre-commit" ]; then
        echo "✅ Fixed: Pre-commit hook is now executable"
    else
        echo "❌ Failed to make pre-commit hook executable"
        VERIFICATION_PASSED=false
    fi
fi

# Check if commit-msg hook exists and is executable
if [ -f ".githooks/commit-msg" ]; then
    if [ -x ".githooks/commit-msg" ]; then
        echo "✅ Commit-msg hook is executable"
    else
        echo "⚠️  Warning: Commit-msg hook not executable, fixing..."
        chmod +x .githooks/commit-msg
    fi
fi

# Check if pre-push hook exists and is executable
if [ -f ".githooks/pre-push" ]; then
    if [ -x ".githooks/pre-push" ]; then
        echo "✅ Pre-push hook is executable (prints /review-pr reminder)"
    else
        echo "⚠️  Warning: Pre-push hook not executable, fixing..."
        if chmod +x .githooks/pre-push; then
            echo "✅ Pre-push hook is now executable"
        else
            echo "❌ Error: Failed to make pre-push hook executable. Run: chmod +x .githooks/pre-push" >&2
            VERIFICATION_PASSED=false
        fi
    fi
fi

# Check for required tools
echo ""
echo "🔧 Checking required tools:"
if command -v php >/dev/null 2>&1; then
    echo "✅ PHP: $(php -v | head -n1 | cut -d' ' -f2)"
else
    echo "⚠️  PHP: not found (required for pre-commit checks)"
    VERIFICATION_PASSED=false
fi

if command -v composer >/dev/null 2>&1; then
    echo "✅ Composer: installed"
else
    echo "⚠️  Composer: not found (required for PHP tests)"
fi

if command -v npx >/dev/null 2>&1; then
    echo "✅ npx: available (for markdown linting)"
else
    echo "⚠️  npx: not found (markdown linting will be skipped)"
fi

# Check dependencies
echo ""
echo "📊 Checking dependencies:"
if [ -d "vendor" ]; then
    echo "✅ PHP dependencies: installed"
else
    echo "⚠️  PHP dependencies: NOT installed"
    echo "   Run 'composer install' to enable unit tests in pre-commit"
fi

if [ -d "node_modules" ]; then
    echo "✅ Node dependencies: installed"
else
    echo "⚠️  Node dependencies: NOT installed"
    echo "   Run 'npm install' for full markdown linting"
fi

echo ""
if [ "$VERIFICATION_PASSED" = true ]; then
    echo "🎉 Installation verified successfully!"
else
    echo "⚠️  Installation completed with warnings (see above)"
fi

echo ""
echo "📋 What was installed:"
echo "• Comprehensive pre-commit hook: PHP standards + Markdown lint + Regression validation + Unit tests"
if [ -f ".githooks/commit-msg" ]; then
    echo "• Commit message validation: Ensures proper formatting and issue linking"
fi
echo ""
echo "🔍 How it works:"
echo "• Automatically runs on every 'git commit'"
echo "• Step 1: Checks staged PHP files for coding standard violations"
echo "• Step 2: Lints staged Markdown (.md) files for formatting issues"
echo "• Step 3: Validates regression tests have proper GitHub issue linking"
echo "• Step 4: Runs fast unit tests if critical files are modified"
echo "• Blocks commits that have errors, lint issues, invalid tests, or test failures"
echo "• Shows detailed error messages with fix suggestions"
echo ""
echo "🚨 To bypass the hook (NOT recommended):"
echo "   git commit --no-verify"
echo ""
echo "🧪 To test the setup:"
echo "   ./scripts/check-hooks-status.sh    # Verify hook health"
echo "   php scripts/check-coding-standards.php [directory]  # Test standards checker"
echo "   git commit --allow-empty -m 'test: verify hooks'    # Test actual commit"
echo ""
echo "📖 For troubleshooting, see: scripts/README.md"