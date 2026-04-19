#!/bin/bash
set -e

# ==============================================================================
# Elan Registry Dependency Installation Script
# ==============================================================================
#
# Purpose: Installs or updates application dependencies (root and usersc/vendor)
#
# Features:
#   - Idempotent: Safe to run multiple times
#   - Validates Composer availability
#   - Verifies successful installation
#   - Supports both fresh install and updates
#   - Automatically runs `composer update` if lock file is out of sync
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
# Helper: run composer install, update lock file if stale
# Usage: run_composer_install <directory> <install_cmd> <update_cmd>
# ==============================================================================

run_composer_install() {
    local dir="$1"
    local install_cmd="$2"
    local update_cmd="$3"

    cd "$dir"

    # Capture output so we can detect stale lock file warning
    local output
    if ! output=$(eval "$install_cmd" 2>&1); then
        echo "$output"
        cd - > /dev/null
        return 1
    fi

    echo "$output"

    if echo "$output" | grep -q "lock file is not up to date"; then
        print_warning "Lock file is out of sync with composer.json — running update..."
        print_info "Running: ${update_cmd}"
        if eval "$update_cmd"; then
            print_success "Lock file updated"
        else
            print_warning "composer update failed — continuing with existing install"
        fi
    fi

    cd - > /dev/null
    return 0
}

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

# Determine Composer commands based on mode
if [ "$MODE" = "prod" ]; then
    COMPOSER_INSTALL="composer install --no-dev --optimize-autoloader"
    COMPOSER_UPDATE="composer update --no-dev --optimize-autoloader"
    print_info "Production mode: Installing without dev dependencies"
else
    COMPOSER_INSTALL="composer install"
    COMPOSER_UPDATE="composer update"
    print_info "Development mode: Installing with dev dependencies"
fi

print_info "Running: cd usersc && ${COMPOSER_INSTALL}"

if run_composer_install "usersc" "$COMPOSER_INSTALL" "$COMPOSER_UPDATE"; then
    print_success "Dependencies installed successfully"
else
    print_error "Composer installation failed"
    exit 2
fi

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

if [ -f "composer.json" ]; then
    if [ "$MODE" = "prod" ]; then
        print_header "Installing Root Dependencies (Production)"
        ROOT_INSTALL="composer install --no-dev"
        ROOT_UPDATE="composer update --no-dev"
    else
        print_header "Installing Root Dependencies"
        ROOT_INSTALL="composer install"
        ROOT_UPDATE="composer update"
    fi

    print_info "Running: ${ROOT_INSTALL}"
    if run_composer_install "." "$ROOT_INSTALL" "$ROOT_UPDATE"; then
        print_success "Root dependencies installed successfully"
    else
        print_warning "Root dependency installation failed (not critical)"
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
