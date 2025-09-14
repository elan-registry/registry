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
echo "📋 What was installed:"
echo "• Pre-commit hook: Enforces coding standards before commits"
echo ""
echo "🔍 How it works:"
echo "• Automatically runs on every 'git commit'"
echo "• Checks only staged PHP files for coding standard violations"
echo "• Blocks commits that have errors (allows warnings)"
echo "• Shows detailed error messages with fix suggestions"
echo ""
echo "🚨 To bypass the hook (NOT recommended):"
echo "   git commit --no-verify"
echo ""
echo "🧪 To test the checker manually:"
echo "   php scripts/check-coding-standards.php [directory]"
echo ""
echo "🎉 You're all set! Your commits will now be checked automatically."