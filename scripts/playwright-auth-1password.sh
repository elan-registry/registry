#!/bin/bash

# Script to set up authentication with credentials from 1Password
# This only needs to be run once (or when the session expires)

echo "🔐 Setting up authentication with 1Password credentials..."

# Load credentials from 1Password
export ELAN_USERNAME=$(op read "op://ElanRegistry/elanregistry - test account/username")
export ELAN_PASSWORD=$(op read "op://ElanRegistry/elanregistry - test account/password")

# Run the setup script
node scripts/playwright-auth-setup.js
