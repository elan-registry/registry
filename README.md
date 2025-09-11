# The Lotus Elan Registry

A comprehensive online database for Lotus Elan and Lotus Elan +2 cars, hosted at [elanregistry.org](https://elanregistry.org).

This registry covers the 1963-1973 Lotus Elan and 1967-1974 Lotus Elan +2, serving to preserve automotive history, trace the evolution of these iconic British sports cars, and facilitate communication between owners worldwide.

## History

The Lotus Elan Registry began in January 2003 following a discussion on LotusElan.net asking "Does anybody know if there is a Lotus Elan register?" Starting with basic functionality, the registry has evolved into a comprehensive platform serving the global Elan community.

The registry represents the collaborative effort of many enthusiasts from the Elan mailing list and forums, who contributed testing, feedback, images, and suggestions that shaped the platform into what it is today.

**Special thanks** to Ross, Tim, Gary, Ed, Terry, Peter, Jeff, Nicholas, Alan, Christian, Michael, Stan, Jason, and everyone else who has contributed to making this registry a place for enthusiasts to celebrate these remarkable British sports cars.


## Features

- **Comprehensive Car Database**: Detailed records of Elan and +2 vehicles with chassis numbers, specifications, and ownership history
- **Interactive Maps**: Geographic visualization of car locations worldwide using Google Maps
- **User Management**: Secure user accounts with profile management and car sharing capabilities
- **Image Gallery**: Photo uploads and management for each vehicle
- **Statistical Analysis**: Registry statistics with charts and data visualization
- **Owner Communication**: Secure messaging system between car owners
- **Mobile Responsive**: Optimized for desktop and mobile devices

## Technology Stack

- **Backend**: PHP 7.4+ with UserSpice framework for authentication
- **Database**: MySQL 8.0+ with comprehensive audit trails
- **Frontend**: Bootstrap 4/5 with responsive design
- **APIs**: Google Maps JavaScript API, Google Geocoding API
- **Environment**: Encrypted environment variables using SecureEnvPHP

## Quick Start

### Requirements
- PHP 7.4+
- MySQL 8.0+
- Composer for dependency management

**Required API Keys:**
- Google Maps API Key (for map displays)
- Google Geocoding API Key (for location lookup)
- reCAPTCHA Keys (for spam protection)
- Brevo/Sendinblue API Key or SMTP configuration (for email delivery)

### Installation
1. Clone the repository
2. Install dependencies: `composer install`
3. Configure environment variables (see `ENVIRONMENT.md`)
4. Import database schema
5. Configure web server to serve from project root

For detailed setup instructions including API key configuration, see the complete installation guide in `docs/development/INSTALLATION.md`.

## Development

### Documentation
- **`CLAUDE.md`** - Complete development guide, coding standards, and deployment procedures
- **`DATABASE.md`** - Database schema documentation and relationships
- **`ENVIRONMENT.md`** - Environment configuration and security setup
- **`PRIVACY.md`** - GDPR compliance and privacy policy details

### Testing
The project includes comprehensive automated testing:

```bash
# Run all tests
npm test

# Run specific test suites
npm run test:security      # Security validation tests
npm run test:functionality # Core functionality tests
npm run test:navigation    # Navigation and redirects
npm run test:csp           # Content Security Policy validation

# Run PHP security tests
vendor/bin/phpunit tests/
```

**Testing Coverage**:
- 35/35 Playwright browser tests (100% success rate)
- Comprehensive PHPUnit security test suite
- Automated CSP violation detection
- Cross-browser compatibility validation

### Development Workflow
1. Review development guidelines in `CLAUDE.md`
2. Create feature branch from main
3. Implement changes with appropriate tests
4. Run full test suite before committing
5. Update VERSION file for releases
6. Deploy using production workflow documented in `CLAUDE.md`


## Privacy & GDPR Compliance

The registry maintains strict privacy standards and GDPR compliance:

- **Privacy by Design**: Location data is intentionally imprecise for user privacy
- **Data Protection**: Comprehensive privacy controls and user data management
- **Transparent Policies**: Clear privacy policy available at `/app/privacy.php`
- **User Rights**: Full data access, correction, and deletion capabilities
- **Secure Processing**: All data handling follows privacy regulations

For complete privacy details, see `PRIVACY.md`.

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

*Preserving the legacy of Lotus Elan and Elan +2 sports cars for current and future generations.*