# The Lotus Elan Registry

An online database for Lotus Elan and Lotus Elan +2 cars, hosted at
[elanregistry.org](https://elanregistry.org).

The registry covers Lotus Elan cars from 1963 to 1973 and Lotus Elan +2 cars
from 1967 to 1974. It records the history of these British sports cars and
helps owners around the world communicate.

## Tech Stack

PHP 8.2+ · MySQL 8.0+ · UserSpice 6 · Bootstrap 5.3 · Cloudflare

## Features

- **Car database** — Records include chassis numbers, specifications, and ownership history.
- **Maps** — Google Maps displays car locations.
- **User accounts** — Users can manage profiles and share car records.
- **Image gallery** — Users can upload photos. The system resizes each photo automatically.
- **Statistics** — Charts display registry data.
- **Owner messaging** — Owners can contact each other through the registry.

## Developer Setup

### Requirements

- PHP 8.2+, MySQL 8.0+, Composer, Node.js
- Google Maps API Key (map display + geocoding)
- Cloudflare Turnstile Keys (spam protection)
- Brevo API Key or SMTP config (email delivery)
- UserSpice 6 installed — [userspice.com](https://userspice.com)
- UserSpice AI - [userspice.ai](https://userspice.ai)

### Quick Start

```bash
git clone https://github.com/elan-registry/registry.git
composer install
npm install
./scripts/setup-git-hooks.sh   # installs pre-commit quality checks
cp .env.example .env            # then fill in credentials
composer test:quick             # verify environment
```

For full installation steps, see the [Registry Installation Guide](https://github.com/elan-registry/registry/wiki/Registry-Installation).

See [ENVIRONMENT.md](docs/development/ENVIRONMENT.md) for full `.env` configuration.

## Documentation

**New here?** Read [`docs/development/SYSTEM_OVERVIEW.md`](docs/development/SYSTEM_OVERVIEW.md)
first — what the registry does, who can do what, and what is deliberately not
built. Then [`CLAUDE.md`](CLAUDE.md) for conventions and workflow.

| Audience                                      | Where                                                                        |
| --------------------------------------------- | ---------------------------------------------------------------------------- |
| **What the system does, by role**             | [`docs/development/SYSTEM_OVERVIEW.md`](docs/development/SYSTEM_OVERVIEW.md) |
| Development conventions, workflow, AI context | [`CLAUDE.md`](CLAUDE.md)                                                     |
| Technical reference docs                      | [`docs/development/`](docs/development/)                                     |
| Concepts and onboarding narrative             | [GitHub Wiki](https://github.com/elan-registry/registry/wiki)                |
| End-user guides                               | [`docs/guides/`](docs/guides/)                                               |
| Reference pages (paint colors, chassis ID)    | [`docs/reference/`](docs/reference/)                                         |

Code changes can make some documentation incorrect. Keep that documentation in
this repository and update it in the same pull request as the code. The wiki
contains concepts and instructions for a new installation.

## History

The Lotus Elan Registry began in January 2003. A discussion on LotusElan.net
asked, "Does anybody know if there is a Lotus Elan register?" The registry
started with basic functions. It now serves the global Elan community.

**Special thanks** to Ross, Tim, Gary, Ed, Terry, Peter, Jeff, Nicholas, Alan,
Christian, Michael, Stan, Jason, and everyone else who contributed testing,
feedback, images, and suggestions over the years.

## Privacy & GDPR

Location data is intentionally imprecise. Users have full access, correction,
and deletion rights. See [`app/owner/privacy.php`](app/owner/privacy.php).

## License

This project uses the [GNU Affero General Public License v3.0](LICENSE).
You can use it freely. You must share any modifications.

---

_The registry preserves the history of Lotus Elan and Elan +2 cars for current
and future generations._
