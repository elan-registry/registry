# Migrating a Checkout from MAMP to Docker

Step-by-step switch of one checkout (`Registry2/`) from MAMP to the Docker
Compose dev stack, and back. For what the stack *is* — services, ports,
grants, Traefik — see
[ENVIRONMENT.md — Docker Dev Environment](ENVIRONMENT.md#docker-dev-environment-optional-experimental).
This page only covers the move.

**`Registry2/` only, as committed.** `docker-compose.yml` hard-codes that
checkout's host ports (8002, 8082, 8090), network and volume. Running
these steps unchanged in any other checkout collides with Registry2's
stack. Adapting it is covered by the checklist in `docker-compose.yml`'s
header comment, not here.

MAMP is not being retired: both can stay installed indefinitely. What
decides which one works at any moment is the database host in `.env` and
`.env.test.local` — `db` for Docker, `localhost`/`8889` for MAMP.

## What changes

| | MAMP | Docker |
| --- | --- | --- |
| Site URL | `http://localhost:9999/ElanRegistry/Registry2/` | `http://localhost:8002/` |
| Database | MAMP MySQL, `localhost:8889` | `db` service (MySQL 8.0), no host port |
| DB inspection | MAMP phpMyAdmin | `http://localhost:8082/` |
| `.env` `DB_HOST` / `DB_PORT` | `localhost` / `8889` | `db` / `3306` |
| Where `composer test:*`, `migrate`, `provision-schema.sh` run | Host shell | Inside the `app` container |
| Where `npm run build` / Playwright run | Host shell | Host shell (no Node in the container) |
| Email | Brevo / Mailtrap per `EMAIL_SYSTEM.md` | Same, plus optional `mock-brevo` on `:8090` |

Files under the checkout (code, `vendor/`, `node_modules/`, built assets,
`userimages/`) are bind-mounted, so nothing needs copying — only the
database has to move.

## Prerequisites

- Docker Desktop running
- MAMP running (only for step 2, to export the existing dev database)
- `composer install` and `npm install && npm run build` done at least once
  on the host (see `CLAUDE.md` Quick Start)

## 1. Back up the MAMP env files

```bash
cp .env .env.mamp.bak
cp .env.test.local .env.test.local.mamp.bak
```

Both names match `.gitignore`'s `.env*.bak` rule. These are what you
restore to switch back.

## 2. Export the MAMP dev database

Skip this if you'd rather start from an empty, freshly provisioned
database (step 5, option B).

```bash
/Applications/MAMP/Library/bin/mysql80/bin/mysqldump \
  -h 127.0.0.1 -P 8889 -u elanregi_spice -p \
  --single-transaction --routines --triggers \
  elanregi_spice2 \
  | sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' \
  > ~/Downloads/elanregi_spice2-mamp.sql
```

Substitute your own `DB_USER`/`DB_NAME` from `.env.mamp.bak` if they
differ. Keep the dump out of the repo.

The `sed` is required. MAMP's `cars_*` audit triggers are owned by
`elanregi_spice@localhost`, but Docker's user is `elanregi_spice@'%'`
without the `SET_USER_ID` privilege, so importing a trigger with the
original owner fails with `ERROR 1227` and leaves a half-loaded database.
Stripping the clause makes each trigger owned by whoever imports it
(`scripts/refresh-local-db.sh` does the same for production dumps). Don't
use `--skip-triggers` instead: `composer migrate` won't recreate triggers
for migrations the dump already records as applied.

## 3. Point `.env` at Docker

Edit `.env`:

```dotenv
DB_HOST=db
DB_PORT=3306
# DB_USER, DB_PASS, DB_NAME unchanged
```

`DB_USER`, `DB_PASS` and `DB_NAME` are also what Compose uses to create the
`db` service's user and database on first start, so they must be set
before step 4.

**`DB_USER` must be `elanregi_spice`** unless you also edit
`docker/mysql-init/01-grant-all-elanregi-schemas.sql` — that file
hard-codes the username (see its "KNOWN COUPLING" comment).

Keep `US_ENVIRONMENT=development` and any `SESSION_NAME` /
`TOKEN_NAME` / `REMEMBER_COOKIE_NAME` values. Browser cookies are not
port-scoped, so `localhost:8002` and `localhost:9999` still share a
session cookie and the isolation described in
[ENVIRONMENT.md — Multi-Clone Session Isolation](ENVIRONMENT.md#multi-clone-session-isolation)
still applies.

Optional: add `BREVO_API_HOST=http://mock-brevo:8080/v3` to route email to
the local mock — see [EMAIL_SYSTEM.md — Local Development](EMAIL_SYSTEM.md#local-development).

## 4. Point `.env.test.local` at Docker

```dotenv
DB_HOST=db
DB_PORT=3306
DB_USER=elanregi_spice   # same user and password as .env
DB_PASS=<.env's DB_PASS>
# DB_NAME stays your test schema
```

**`DB_USER`/`DB_PASS` must change.** MAMP typically has a separate test
user (e.g. `elanregi_dev_test`), but Docker creates only the one user
named in `.env`, so the MAMP test credentials can't connect.

The test schema name must still contain `test` (checked by
`provision-schema.sh`) and start with `elanregi_` (the only schemas the
Docker user is granted).

`DB_HOST` is a bare `db`, not `db:3306`. The file's own header says
`DB_HOST` must be `host:port`, but that applies to MAMP: its MySQL is on
the non-standard port 8889, and UserSpice (`users/classes/DB.php`) builds
its connection from `DB_HOST` alone, with no separate port. Docker's `db` listens on the standard 3306, so the
bare host is enough and avoids `DB_HOST` and `DB_PORT` disagreeing.

## 5. Start the stack and load the database

```bash
docker compose up -d
docker compose exec -u www-data app composer install
```

The first `up` builds the `app` image and initializes an empty `db`
volume, which runs the grant file once. Wait for `db` to report
`(healthy)` in `docker compose ps`.

**Option A — import the MAMP dump from step 2:**

```bash
docker compose exec -T db sh -c \
  'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  < ~/Downloads/elanregi_spice2-mamp.sql
docker compose exec -u www-data app composer migrate
```

This uses the `db` container's own client and the credentials Compose
already gave it, so no password goes on your command line. `migrate`
applies anything newer than the dump.

**Option B — provision from scratch (no real data):**

```bash
docker compose exec -u www-data app \
  scripts/provision-schema.sh --env-file .env --full --force
```

`--force` is required because the dev database name has no `test` in it.
This drops and recreates the schema named in `.env` — only use it against
a database you're willing to lose.

## 6. Provision the test schema

```bash
docker compose exec -u www-data app scripts/provision-schema.sh
```

Rerun this whenever a new migration lands. Don't rely on the test suite
to tell you: the integration bootstrap aborts only for missing seed data
and one specific column (`cars.vericode`), not for every pending
migration.

## 7. Verify

```bash
docker compose ps                                   # 4 services up, db healthy
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8002/   # 200
docker compose exec -u www-data app composer migrate:status        # nothing "down"
docker compose exec -u www-data app composer test:full             # unit + integration green
```

Then log in at `http://localhost:8002/` and open a car detail page with
images to confirm uploads resolve.

**Always pass `-u www-data` to `exec`** — see
[ENVIRONMENT.md](ENVIRONMENT.md#docker-dev-environment-optional-experimental)
for why.

## 8. Update the host-side tooling

These still run on the host and still assume MAMP until changed.

### Playwright

Add or change in `.env.local` (read by `playwright.config.js` and
`playwright.config.dev.js` via `dotenv`):

```dotenv
PLAYWRIGHT_BASE_URL=http://localhost:8002/
```

The trailing slash is required. `npm run playwright:test` and
`npm run test:e2e:dev` then target Docker. The `E2E_DEV_*` credentials must
be accounts that exist in the Docker database.

### Local cron trigger

If you use the `org.elanregistry.local-cron` launchd agent (see
[ENVIRONMENT.md — Development Setup, step 6](ENVIRONMENT.md#development-setup)),
change its URL to `http://localhost:8002/users/cron/cron.php` and reload
it:

```bash
launchctl unload ~/Library/LaunchAgents/org.elanregistry.local-cron.plist
launchctl load ~/Library/LaunchAgents/org.elanregistry.local-cron.plist
```

If `cron_ip` is set, expect it to need changing: requests forwarded into
the container arrive from Docker's network gateway, not `::1`/`127.0.0.1`.
Use the deliberately-wrong-`cron_ip` technique in that same step to read
the real source address from the logs.

### Git hooks

`.githooks/pre-push` runs `composer test:integration` **on the host**
when a push to `origin` changes PHP under `app/`, `usersc/classes/` or
`tests/integration/`. With `.env.test.local` pointed at `db`, the host
can't resolve `db`, so the hook fails and blocks those pushes. Until the
hook learns about Docker, run
`docker compose exec -u www-data app composer test:integration` first, and
push with `git push --no-verify` only when it passes. `--no-verify` skips
the only automated integration-test gate; CI doesn't run that suite.

### Scripts that don't support Docker yet

`scripts/refresh-local-db.sh` hard-codes MAMP's `mysql`/`mysqldump`
binaries and socket, so it cannot target the Docker database. To refresh
from production, run it against MAMP and repeat steps 2 and 5A.

## Switching back to MAMP

```bash
docker compose stop                       # or `down`; keep the volume (no -v)
cp .env.mamp.bak .env
cp .env.test.local.mamp.bak .env.test.local
```

Revert `PLAYWRIGHT_BASE_URL` and the launchd URL if you changed them. The
Docker database stays in its named volume for next time (Compose prefixes
the project name, so `docker volume ls` shows
`registry2_registry2_db_data`); `docker compose down -v` deletes it.

The two databases are independent — changes in one never appear in the
other. Run `composer migrate` in whichever you switch to.
