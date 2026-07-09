# The Lotus Elan Registry

An online database for Lotus Elan and Lotus Elan +2 cars, hosted at
[elanregistry.org](https://elanregistry.org).

This registry covers the 1963–1973 Lotus Elan and 1967–1974 Lotus Elan +2,
serving to preserve automotive history, trace the evolution of these British
sports cars, and facilitate communication between owners worldwide.

## Tech Stack

PHP 8.2+ · MySQL 8.0+ · UserSpice 6 · Bootstrap 5.3 · Cloudflare

## Features

- **Car Database** — Detailed records with chassis numbers, specs, and ownership history
- **Interactive Maps** — Geographic visualization via Google Maps
- **User Management** — Secure accounts with profile and car-sharing
- **Image Gallery** — Photo uploads with automatic resizing
- **Statistics** — Registry stats with charts and data visualization
- **Owner Messaging** — Secure contact between car owners

## Developer Setup

### Requirements

- PHP 8.2+, MySQL 8.0+, Composer, Node.js
- Google Maps API Key (map display + geocoding)
- Cloudflare Turnstile Keys (spam protection)
- Brevo API Key or SMTP config (email delivery)
- UserSpice 6 installed — [userspice.com](https://userspice.com)

### Quick Start

```bash
git clone https://github.com/unibrain1/elanregistry.git
composer install
npm install
./scripts/setup-git-hooks.sh   # installs pre-commit quality checks
cp .env.example .env            # then fill in credentials
composer test:quick             # verify environment
```

For full installation steps, see the [Registry Installation Guide](https://github.com/unibrain1/elanregistry/wiki/Registry-Installation).

See [ENVIRONMENT.md](docs/development/ENVIRONMENT.md) for full `.env` configuration.

## Documentation

| Audience | Where |
| --- | --- |
| Development conventions, workflow, AI context | [`CLAUDE.md`](CLAUDE.md) |
| Architecture, database design, class patterns | [GitHub Wiki](https://github.com/unibrain1/elanregistry/wiki) |
| Technical reference docs | [`docs/development/`](docs/development/) |
| End-user guides | [`docs/guides/`](docs/guides/) |
| Reference pages (paint colors, chassis ID) | [`docs/reference/`](docs/reference/) |

## History

The Lotus Elan Registry began in January 2003 following a discussion on
LotusElan.net asking "Does anybody know if there is a Lotus Elan register?"
Starting with basic functionality, the registry has evolved into a platform
serving the global Elan community.

**Special thanks** to Ross, Tim, Gary, Ed, Terry, Peter, Jeff, Nicholas, Alan,
Christian, Michael, Stan, Jason, and everyone else who contributed testing,
feedback, images, and suggestions over the years.

## Privacy & GDPR

Location data is intentionally imprecise. Users have full access, correction,
and deletion rights. See [`app/owner/privacy.php`](app/owner/privacy.php).

## License

Open source. See the LICENSE file for details.

---

*Preserving the legacy of Lotus Elan and Elan +2 sports cars for current and future generations.*
