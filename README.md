# The Lotus Elan Registry

An online database for Lotus Elan and Lotus Elan +2 cars, hosted at
[elanregistry.org](https://elanregistry.org).

This registry covers the 1963-1973 Lotus Elan and 1967-1974 Lotus Elan +2,
serving to preserve automotive history, trace the evolution of these British
sports cars, and facilitate communication between owners worldwide.

## History

The Lotus Elan Registry began in January 2003 following a discussion on
LotusElan.net asking "Does anybody know if there is a Lotus Elan register?"
Starting with basic functionality, the registry has evolved into a platform
serving the global Elan community.

The registry represents the collaborative effort of many enthusiasts from the
Elan mailing list and forums, who contributed testing, feedback, images, and
suggestions that shaped the platform into what it is today.

**Special thanks** to Ross, Tim, Gary, Ed, Terry, Peter, Jeff, Nicholas, Alan,
Christian, Michael, Stan, Jason, and everyone else who has contributed to
making this registry a place for enthusiasts to celebrate these remarkable
British sports cars.

## Features

- **Car Database**: Detailed records of Elan and +2 vehicles with chassis
  numbers, specifications, and ownership history
- **Interactive Maps**: Geographic visualization of car locations worldwide
  using Google Maps
- **User Management**: Secure user accounts with profile management and car
  sharing capabilities
- **Image Gallery**: Photo uploads and management for each vehicle
- **Statistical Analysis**: Registry statistics with charts and data visualization
- **Owner Communication**: Secure messaging system between car owners
- **Mobile Responsive**: Optimized for desktop and mobile devices

## Quick Start

### Requirements

- PHP 8.1+ (8.2+ recommended)
- MySQL 8.0+
- Composer for dependency management
- Google Maps JavaScript API, Google Geocoding API
- Environment variables via phpdotenv (plaintext `.env` with `chmod 600`)

**Required API Keys:**

- Google Maps API Key (for map displays)
- Google Geocoding API Key (for location lookup)
- Cloudflare Turnstile Keys (for spam protection)
- Brevo/Sendinblue API Key or SMTP configuration (for email delivery)

### Installation

0. Install UserSpice - <https://userspice.com>
1. Clone the repository
2. Install dependencies: `composer install`
3. Configure environment variables (see `ENVIRONMENT.md`)
4. Import database schema

For detailed setup instructions including API key configuration, see the
complete installation guide in `docs/development/INSTALLATION.md`.

### Developer Setup Checklist

New developers should complete these steps to ensure a proper development
environment:

- [ ] Clone repository: `git clone https://github.com/unibrain1/elanregistry.git`
- [ ] Install PHP dependencies: `composer install`
- [ ] Install Node dependencies: `npm install`
- [ ] **Setup git hooks** (CRITICAL): `./scripts/setup-git-hooks.sh`
- [ ] Verify hooks are working: `./scripts/check-hooks-status.sh`
- [ ] Run initial tests: `composer test:quick`
- [ ] Configure local environment (`.env` file - see `ENVIRONMENT.md`)
- [ ] Review coding standards: `docs/development/CODING_STANDARDS.md`
- [ ] Read [Development Workflow](https://github.com/jimboone/elan-registry/wiki/Development-Workflow) (wiki)

**Important:** The git hooks setup is essential for maintaining code quality.
It catches issues before they reach GitHub, saving time and preventing failed
CI checks.

## Development

### Documentation

- **User Documentation** (`docs/faq/`) - End-user guides and FAQ
  - Car transfer guides, privacy policy, and user help
- **Admin Documentation** (`docs/faq/admin/`) - Administrative procedures
  - Car transfer admin guides, troubleshooting, and system maintenance
- **Development Docs** (`docs/development/`) - Documentation for
  developers
  - See root `CLAUDE.md` for AI assistant instructions and quick reference
  - `QUICK_REFERENCE.md` - Quick lookup for common tasks and commands
  - `INSTALLATION.md` - Setup, installation, and testing commands
  - **GitHub Wiki**: [Architecture Guide](https://github.com/jimboone/elan-registry/wiki/Architecture) - System architecture and class patterns
  - `DATABASE.md` - Complete database schema and table relationships
  - **GitHub Wiki**: [UserSpice Integration Guide](https://github.com/jimboone/elan-registry/wiki/Integration) - UserSpice integration and custom functions
  - `CODING_STANDARDS.md` - Coding standards and conventions
  - `ENVIRONMENT.md` - Environment configuration and security setup
  - `FIX_SCRIPTS.md` - Database maintenance script guidelines
- **Testing Docs** (`docs/testing/`) - Testing strategy and test execution
- **Product Requirements** (`docs/PRD.md`) - Feature specifications and
  roadmap

## Privacy & GDPR Compliance

The registry maintains strict privacy standards and GDPR compliance:

- **Privacy by Design**: Location data is intentionally imprecise for user privacy
- **Data Protection**: Privacy controls and user data management
- **Transparent Policies**: Clear privacy policy available at `/app/privacy.php`
- **User Rights**: Full data access, correction, and deletion capabilities

For complete privacy details, see `docs/faq/PRIVACY.md`.

## Contributing

We welcome contributions from the Elan community! Before contributing:

1. Read the development guidelines in `CLAUDE.md`
2. Check existing GitHub issues for current development priorities
3. Ensure all tests pass before submitting changes
4. Follow the established coding standards and security practices

## License

This project is open source. See the LICENSE file for details.

## Support

For registry support or questions:

- Visit [elanregistry.org](https://elanregistry.org)
- Review documentation in this repository
- Check GitHub issues for known problems and solutions

---

*Preserving the legacy of Lotus Elan and Elan +2 sports cars for current and
future generations.*
