#!/usr/bin/env bash
#
# provision-schema.sh
#
# Provisions a database schema from scratch, with no live source database:
#
#   1. DROP + CREATE the target schema
#   2. Apply the vendored stock UserSpice structure
#      (database/vendor/userspice-6.1.4-base.sql)
#   3. composer migrate  — the ElanRegistry baseline migration + deltas
#   4. phinx seed:run    — reference/config data
#
# Nothing here reads any source database, and no binary path is hardcoded —
# `mysql`/`mysqldump` resolve via $PATH (overridable, see below) — so the same
# script works on a developer machine and in CI.
#
# Usage:
#   scripts/provision-schema.sh [options]
#
# Options:
#   --env-file <path>  Env file holding the TARGET database credentials.
#                      Default: .env.test.local (override also via
#                      PROVISION_ENV_FILE).
#   --full             Run every seed, including ElanFactoryInfoSeed (9,762 rows
#                      of factory data). Use for a real install; the default
#                      test/CI provisioning skips it — tests don't need it and
#                      it dominates the run time.
#   --skip-seeds       Provision structure only (vendor base + migrations).
#   --force            Override the schema-name safety guards (see below).
#   -h, --help         Show this help.
#
# Environment overrides:
#   MYSQL_BIN          Path to the `mysql` client. Defaults to `mysql` resolved
#                      on $PATH. MAMP's client is not on $PATH by default, e.g.
#                      MYSQL_BIN=/Applications/MAMP/Library/bin/mysql80/bin/mysql
#   PROVISION_ENV_FILE Same as --env-file (the flag wins if both are given).
#
# Examples:
#   scripts/provision-schema.sh                    # test schema
#   scripts/provision-schema.sh --env-file .env --full --force
#                                                  # fresh dev/prod-shaped install
#                                                  # (--force: name isn't a test schema)
#
set -euo pipefail

# Resolve repo root (this script lives in <repo>/scripts/). Phinx and Composer
# both resolve their config relative to the working directory.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

VENDOR_SQL="${REPO_ROOT}/database/vendor/userspice-6.1.4-base.sql"
SEEDS_DIR="${REPO_ROOT}/database/seeds"

# Seed excluded from the default run — bulk display data, not needed by tests.
# Included only with --full.
BULK_SEED="ElanFactoryInfoSeed"

TARGET_ENV="${PROVISION_ENV_FILE:-${REPO_ROOT}/.env.test.local}"
RUN_FULL_SEEDS=0
SKIP_SEEDS=0
FORCE=0

usage() {
    cat <<'EOF'
Usage: scripts/provision-schema.sh [options]

Provisions a database schema from scratch: recreate it, apply the vendored
stock UserSpice structure, run migrations, run seeds.

Options:
  --env-file <path>  Env file holding the TARGET database credentials.
                     Default: .env.test.local (or $PROVISION_ENV_FILE).
  --full             Run every seed, including ElanFactoryInfoSeed (bulk
                     factory data — skipped by default, tests don't need it).
  --skip-seeds       Provision structure only (vendor base + migrations).
  --force            Override the schema-name safety guards.
  -h, --help         Show this help.

Environment overrides:
  MYSQL_BIN          Path to the `mysql` client (default: `mysql` on $PATH).
  PROVISION_ENV_FILE Same as --env-file; the flag wins if both are given.
EOF
}

# --- Parse arguments ---------------------------------------------------------

while [[ $# -gt 0 ]]; do
    case "$1" in
        --env-file)
            [[ $# -ge 2 ]] || { echo "ERROR: --env-file requires a path." >&2; exit 1; }
            TARGET_ENV="$2"
            shift 2
            ;;
        --full)       RUN_FULL_SEEDS=1; shift ;;
        --skip-seeds) SKIP_SEEDS=1; shift ;;
        --force)      FORCE=1; shift ;;
        -h|--help)    usage; exit 0 ;;
        *)
            echo "ERROR: unknown option '$1'. Run with --help for usage." >&2
            exit 1
            ;;
    esac
done

if [[ ${RUN_FULL_SEEDS} -eq 1 && ${SKIP_SEEDS} -eq 1 ]]; then
    echo "ERROR: --full and --skip-seeds are mutually exclusive." >&2
    exit 1
fi

# --- Validate prerequisites --------------------------------------------------

# Resolve the mysql client: explicit override first, otherwise plain `mysql` off
# $PATH. Never fall back to a hardcoded install path — that's what makes this
# CI-portable. `command -v` validates absolute overrides too, so a stale
# MYSQL_BIN fails here rather than mid-provision.
MYSQL_BIN="${MYSQL_BIN:-mysql}"
if ! command -v "${MYSQL_BIN}" >/dev/null 2>&1; then
    echo "ERROR: mysql client not found: '${MYSQL_BIN}'" >&2
    echo "       Install the MySQL client, or point MYSQL_BIN at it, e.g.:" >&2
    echo "       MYSQL_BIN=/Applications/MAMP/Library/bin/mysql80/bin/mysql $0" >&2
    exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "ERROR: composer not found on \$PATH — required to run migrations." >&2
    exit 1
fi

if [[ ! -f "${TARGET_ENV}" ]]; then
    echo "ERROR: env file not found: ${TARGET_ENV}" >&2
    echo "       For the test schema: cp .env.test.local.sample .env.test.local," >&2
    echo "       then fill in DB_PASS. See docs/development/ENVIRONMENT.md." >&2
    exit 1
fi

# Normalize to an absolute path so the "is this the app's own env file?" check
# below compares like with like when --env-file was given a relative path.
TARGET_ENV="$(cd "$(dirname "${TARGET_ENV}")" && pwd)/$(basename "${TARGET_ENV}")"

if [[ ! -f "${VENDOR_SQL}" ]]; then
    echo "ERROR: vendored stock schema not found: ${VENDOR_SQL}" >&2
    exit 1
fi

# --- Parse credentials -------------------------------------------------------
# Env files are simple KEY=value lines with no quoting. Read a single key from a
# given file. Fails loudly on a missing/empty key rather than letting `set -e`
# kill the script silently — grep exits 1 on no match, and pipefail propagates
# that through `| head | cut` even though both of those succeed, so an unguarded
# call site would die with no output and no indication which key or file was the
# problem.
read_env() {
    local file="$1" key="$2" value
    value="$(grep -E "^${key}=" "${file}" | head -n1 | cut -d= -f2-)" || true
    if [[ -z "${value}" ]]; then
        echo "ERROR: ${key} not found (or empty) in ${file}" >&2
        exit 1
    fi
    printf '%s' "${value}"
}

# As read_env, but returns a default instead of aborting — for keys a CI env
# file may reasonably omit.
read_env_default() {
    local file="$1" key="$2" fallback="$3" value
    value="$(grep -E "^${key}=" "${file}" | head -n1 | cut -d= -f2-)" || true
    printf '%s' "${value:-${fallback}}"
}

DB_NAME="$(read_env "${TARGET_ENV}" DB_NAME)"
DB_USER="$(read_env "${TARGET_ENV}" DB_USER)"
DB_PASS="$(read_env "${TARGET_ENV}" DB_PASS)"
DB_HOST="$(read_env_default "${TARGET_ENV}" DB_HOST 127.0.0.1)"
DB_PORT="$(read_env_default "${TARGET_ENV}" DB_PORT 3306)"

# DB_HOST may carry an embedded port ("127.0.0.1:8889") — the application's own
# DB class (users/classes/DB.php) has no separate port parameter, so its env
# files write the port into DB_HOST directly. The mysql CLI's -h flag doesn't
# accept that combined form (unlike PDO, which does), so strip it here; DB_PORT
# above already carries the real port for -P. Phinx tolerates a bare host fine.
DB_HOST="${DB_HOST%%:*}"

# --- Safety guards -----------------------------------------------------------
# The one destructive operation here is DROP DATABASE on the target. The old
# script guarded it by refusing to run when source and target names matched;
# there is no source database any more, so that comparison is gone. What still
# needs protecting is the same thing it was protecting: a misconfigured env file
# pointing this script at a real dev/prod database.
#
# Two guards replace it, both overridable with --force because this script is
# also the supported way to provision a fresh dev or CI database (where a
# non-"test" name is the correct answer):
#
#   1. Refuse a target name that doesn't look like a test schema. Names are
#      case-folded — MAMP's MySQL runs with lower_case_table_names=2 on macOS's
#      case-insensitive filesystem, so a typo'd-case name would otherwise slip
#      past a naive comparison.
#   2. Refuse the database this checkout's application is configured to use
#      (DB_NAME in .env), which is the specific accident the old guard existed
#      to prevent. Only .env counts: it is the file the app (users/init.php)
#      loads. .env.local is read by Playwright alone, so a DB_NAME there can
#      be stale; trusting it would protect the wrong database (#2175).
DB_NAME_LOWER="$(tr '[:upper:]' '[:lower:]' <<< "${DB_NAME}")"

APP_ENV_FILE=""
if [[ -f "${REPO_ROOT}/.env" ]]; then
    APP_ENV_FILE="${REPO_ROOT}/.env"
fi

APP_DB_NAME=""
if [[ -n "${APP_ENV_FILE}" && "${APP_ENV_FILE}" != "${TARGET_ENV}" ]]; then
    APP_DB_NAME="$(read_env_default "${APP_ENV_FILE}" DB_NAME '')"
fi
APP_DB_NAME_LOWER="$(tr '[:upper:]' '[:lower:]' <<< "${APP_DB_NAME}")"

if [[ -n "${APP_DB_NAME_LOWER}" && "${APP_DB_NAME_LOWER}" == "${DB_NAME_LOWER}" && ${FORCE} -eq 0 ]]; then
    echo "ERROR: '${DB_NAME}' is the database this checkout's application uses (${APP_ENV_FILE})." >&2
    echo "       Refusing to DROP it. Check ${TARGET_ENV}, or pass --force if you really" >&2
    echo "       mean to reprovision the application database from scratch." >&2
    exit 1
fi

if [[ "${DB_NAME_LOWER}" != *test* && ${FORCE} -eq 0 ]]; then
    echo "ERROR: target schema '${DB_NAME}' does not look like a test schema (no 'test' in the name)." >&2
    echo "       This script DROPs the target. Pass --force to provision a non-test schema" >&2
    echo "       (fresh dev or CI database), after confirming the name is correct." >&2
    exit 1
fi

if [[ ${FORCE} -eq 1 ]]; then
    echo "WARNING: --force given — about to DROP and recreate '${DB_NAME}' on ${DB_HOST}:${DB_PORT}."
fi

# --- Helpers -----------------------------------------------------------------

# MYSQL_PWD keeps the password out of argv (and therefore out of `ps`).
mysql_target() {
    MYSQL_PWD="${DB_PASS}" "${MYSQL_BIN}" \
        -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "$@"
}

# Phinx reads DB_* from the process environment (see phinx.php — it defines a
# single 'default' environment, so there is no -e environment to switch to;
# injecting the variables is the mechanism). Exported values win over anything
# in .env: phpdotenv is loaded immutably, so it will not overwrite a variable
# that is already set in the environment.
#
# A subshell with `export` (not `env DB_PASS=... "$@"`) for the same reason
# mysql_target above uses MYSQL_PWD instead of a CLI flag: `env KEY=value cmd`
# puts the assignment in env's own argv, which is briefly visible to `ps`
# before exec replaces it. The subshell avoids that window entirely.
phinx_env() {
    ( export DB_HOST DB_PORT DB_NAME DB_USER DB_PASS; "$@" )
}

# --- 1. Recreate the target schema -------------------------------------------

echo "Provisioning schema '${DB_NAME}' on ${DB_HOST}:${DB_PORT}..."
echo "  → Dropping and recreating '${DB_NAME}'..."

# Charset/collation must match phinx.php's adapter settings, otherwise migration
# DDL inherits the server default and drifts from dev/prod.
mysql_target -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;
                 CREATE DATABASE \`${DB_NAME}\`
                     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# --- 2. Apply the vendored stock UserSpice structure --------------------------

echo "  → Applying stock UserSpice structure (database/vendor/userspice-6.1.4-base.sql)..."
mysql_target "${DB_NAME}" < "${VENDOR_SQL}"

# --- 3. Migrations ------------------------------------------------------------

echo "  → Running migrations (composer migrate)..."
phinx_env composer migrate

# --- 4. Seeds -----------------------------------------------------------------

if [[ ${SKIP_SEEDS} -eq 1 ]]; then
    echo "  → Skipping seeds (--skip-seeds)."
else
    # Phinx 0.16.12 has no "run all except X" option: seed:run either runs every
    # seed or, with -s, only the ones named. So the default run enumerates the
    # seed classes on disk and names each one except the bulk seed. Discovering
    # them from the filesystem (Phinx requires class name == file name) keeps a
    # newly added seed from being silently left out of provisioning.
    #
    # Note that naming seeds with -s bypasses Phinx's dependency ordering, so
    # seeds must not rely on getDependencies(). The one real cross-seed
    # dependency — PageRegistrationSeed inserting permission_page_matches rows
    # that reference permissions.id=3 — is no longer a seed ordering concern:
    # #1679 moved those baseline permission rows into the
    # RegisterBaselinePermissions *migration*, and `composer migrate` runs
    # above, so migrations always complete before any seed starts. Any new
    # seed-to-seed ordering requirement would still have to sort correctly by
    # filename, since this loop has no explicit ordering mechanism and relies
    # on alphabetical glob order.
    SEED_ARGS=()
    SEED_NAMES=()
    for seed_file in "${SEEDS_DIR}"/*.php; do
        [[ -e "${seed_file}" ]] || continue
        seed_name="$(basename "${seed_file}" .php)"
        if [[ ${RUN_FULL_SEEDS} -eq 0 && "${seed_name}" == "${BULK_SEED}" ]]; then
            continue
        fi
        SEED_NAMES+=("${seed_name}")
        SEED_ARGS+=(-s "${seed_name}")
    done

    if [[ ${#SEED_NAMES[@]} -eq 0 ]]; then
        echo "ERROR: no seed classes found in ${SEEDS_DIR}." >&2
        echo "       Expected at least one <ClassName>.php seed. Pass --skip-seeds to" >&2
        echo "       provision structure only." >&2
        exit 1
    fi

    echo "  → Running seeds: ${SEED_NAMES[*]}"
    if [[ ${RUN_FULL_SEEDS} -eq 0 ]]; then
        echo "     (excluding ${BULK_SEED} — pass --full to include it)"
    fi
    phinx_env vendor/bin/phinx seed:run "${SEED_ARGS[@]}"
fi

# --- Summary ------------------------------------------------------------------

echo ""
echo "Done. Schema '${DB_NAME}' provisioned from:"
echo "  • database/vendor/userspice-6.1.4-base.sql (stock UserSpice 6.1.4)"
echo "  • database/migrations/ (composer migrate)"
if [[ ${SKIP_SEEDS} -eq 1 ]]; then
    echo "  • seeds skipped — run this script again without --skip-seeds to load them"
else
    echo "  • database/seeds/: ${SEED_NAMES[*]}"
fi
echo ""
echo "Next step: composer test:integration"
