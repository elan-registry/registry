#!/bin/bash
set -e

# ==============================================================================
# Elan Registry Dependency Installation Script
# ==============================================================================
#
# Purpose: Installs or updates application runtime dependencies in usersc/vendor
#
# Features:
#   - Idempotent: Safe to run multiple times
#   - Validates Composer availability
#   - Verifies successful installation
#   - Supports both fresh install and updates
#
# Usage:
#   ./scripts/install-dependencies.sh [--dev|--prod]
#
# Options:
#   --dev   Install with development dependencies (default)
#   --prod  Install without development dependencies (production)
#
# Exit Codes:
#   0 - Success
#   1 - Composer not found
#   2 - Installation failed
#   3 - Verification failed
#
# ==============================================================================

# Color output for better UX
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default mode
MODE="dev"

# Parse arguments
if [ "$1" = "--prod" ] || [ "$1" = "-p" ]; then
    MODE="prod"
elif [ "$1" = "--dev" ] || [ "$1" = "-d" ]; then
    MODE="dev"
fi

# ==============================================================================
# Helper Functions
# ==============================================================================

print_header() {
    echo -e "\n${BLUE}===================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}===================================${NC}\n"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

# ==============================================================================
# Pre-flight Checks
# ==============================================================================

print_header "Elan Registry Dependency Installation"

print_info "Mode: ${MODE}"
print_info "Working directory: $(pwd)"

# Check if we're in the project root
if [ ! -f "z_us_root.php" ]; then
    print_error "Must be run from the Elan Registry root directory"
    exit 1
fi

# Check if Composer is installed
if ! command -v composer &> /dev/null; then
    print_error "Composer not found. Please install Composer first."
    print_info "Visit: https://getcomposer.org/download/"
    exit 1
fi

print_success "Composer found: $(composer --version | head -n1)"

# ==============================================================================
# Install/Update usersc Dependencies
# ==============================================================================

print_header "Installing usersc Dependencies"

# Check if usersc/composer.json exists
if [ ! -f "usersc/composer.json" ]; then
    print_error "usersc/composer.json not found"
    exit 1
fi

print_success "Found usersc/composer.json"

# Determine Composer command based on mode
if [ "$MODE" = "prod" ]; then
    COMPOSER_CMD="composer install --no-dev --optimize-autoloader"
    print_info "Production mode: Installing without dev dependencies"
else
    COMPOSER_CMD="composer install"
    print_info "Development mode: Installing with dev dependencies"
fi

# Install dependencies in usersc/
print_info "Running: cd usersc && ${COMPOSER_CMD}"

cd usersc

if $COMPOSER_CMD; then
    print_success "Dependencies installed successfully"
else
    print_error "Composer installation failed"
    exit 2
fi

cd ..

# ==============================================================================
# Verify Installation
# ==============================================================================

print_header "Verifying Installation"

# Check usersc/vendor directory exists
if [ -d "usersc/vendor" ]; then
    print_success "usersc/vendor/ directory created"
else
    print_error "usersc/vendor/ directory not found"
    exit 3
fi

# Check autoloader exists
if [ -f "usersc/vendor/autoload.php" ]; then
    print_success "Autoloader found: usersc/vendor/autoload.php"
else
    print_error "Autoloader not found: usersc/vendor/autoload.php"
    exit 3
fi

# Check SecureEnvPHP package exists
if [ -d "usersc/vendor/johnathanmiller/secure-env-php" ]; then
    print_success "SecureEnvPHP package installed"
else
    print_error "SecureEnvPHP package not found"
    print_warning "Expected: usersc/vendor/johnathanmiller/secure-env-php"
    exit 3
fi

# Check composer.lock exists
if [ -f "usersc/composer.lock" ]; then
    print_success "Dependency lock file: usersc/composer.lock"
else
    print_warning "composer.lock not found (not critical)"
fi

# ==============================================================================
# Optional: Update Root Dependencies
# ==============================================================================

if [ "$MODE" = "prod" ] && [ -f "composer.json" ]; then
    print_header "Updating Root Dependencies (Production)"

    print_info "Cleaning up development dependencies from root vendor/"

    if composer install --no-dev; then
        print_success "Root dependencies updated for production"
    else
        print_warning "Root dependency update failed (not critical)"
    fi
fi

# ==============================================================================
# Summary
# ==============================================================================

print_header "Installation Complete"

echo -e "${GREEN}✅ All dependencies installed successfully!${NC}\n"

print_info "Next steps:"
if [ "$MODE" = "prod" ]; then
    echo "  1. Restart web server/PHP-FPM if needed"
    echo "  2. Test site loads correctly"
    echo "  3. Check error logs for any issues"
else
    echo "  1. Test site loads correctly"
    echo "  2. Run test suite: composer test:quick"
fi

echo ""
print_success "Installation script completed successfully"

exit 0
