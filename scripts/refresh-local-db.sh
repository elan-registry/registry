#!/usr/bin/env bash
#
# scripts/refresh-local-db.sh
#
# Refresh the local development database from production: fetch a fresh dump
# over SSH, upsert the registry tables, mask every email address, and rsync
# car images into a persistent local cache.
#
# Usage:
#   ./scripts/refresh-local-db.sh [OPTIONS] [DUMP_FILE]
#
# Options:
#   --fetch         Dump production over SSH before importing (recommended).
#                   Reads DB credentials from the prod docroot .env on the
#                   remote host, so nothing is stored locally.
#   --db NAME       Import into NAME instead of the DB_NAME from the env file.
#   --env-file P    Read the TARGET database credentials from P instead of
#                   .env. Use --env-file .env.test.local to rehearse a refresh
#                   against the scratch test schema before touching your
#                   working dev database. Connection details come from that
#                   file too: the client connects over TCP when DB_PORT is set
#                   or DB_HOST carries a `host:port` value, and otherwise
#                   falls back to the MAMP socket.
#   --skip-images   Skip image rsync (DB refresh only)
#   --images-only   Skip DB refresh, only rsync images
#   -h, --help      Show this help and exit
#
# Arguments:
#   DUMP_FILE       Path to an existing mysqldump SQL file to import. Cannot
#                   be combined with --fetch, which writes its own dump to
#                   ~/Downloads/unibrain_registry.sql and would otherwise
#                   overwrite the file named here.
#                   (default: ~/Downloads/unibrain_registry.sql)
#
# Tables upserted (new rows added, existing rows updated by primary key):
#   cars, cars_hist, car_models, car_transfer_requests, elan_factory_info,
#   users, profiles, user_permission_matches, country, audit
#
#   user_permission_matches gives imported production users their real roles
#   locally; country and audit are reference/audit-trail data the UI renders.
#   Deliberately NOT imported: logs and crons_logs (noise), users_online and
#   users_session (prod session state), settings and email (would overwrite
#   local dev config / hold SMTP credentials), and phinxlog, fix_script_runs,
#   updates, pages, menus, permissions (locally owned by migrations).
#
# Email masking:
#   All user emails are replaced with dev.owner.{id}@elanregistry.local.
#   User id=1 (admin) is preserved unchanged. Masking UPDATEs run inside the
#   same transaction as the INSERTs, so real addresses are never committed.
#   A verification pass then re-checks every email column; if any unmasked
#   address survives, the script exits non-zero and leaves the DB as-is for
#   inspection (restore manually from db-backups/ if needed).
#
#   City and IP columns are intentionally left intact -- they are coarse
#   grained and needed to exercise the location and map features.
#
# Image sync:
#   Two hops, so re-syncs stay fast and survive git clean / repo moves:
#     1. a2hosting:~/elanregistry.org/userimages/  ->  IMAGE_CACHE
#        (persistent, outside the repo -- the incremental cache)
#     2. IMAGE_CACHE  ->  ./userimages/   (so the local app renders images)
#
set -euo pipefail

# ── Paths ─────────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

MYSQL_BIN="/Applications/MAMP/Library/bin/mysql57/bin/mysql"
MYSQLDUMP_BIN="/Applications/MAMP/Library/bin/mysql57/bin/mysqldump"
MYSQL_SOCK="/Applications/MAMP/tmp/mysql/mysql.sock"

SSH_ALIAS="a2hosting"
REMOTE_DOCROOT="/home/unibrain/elanregistry.org"
REMOTE_IMAGES="$REMOTE_DOCROOT/userimages/"

# Persistent image cache, deliberately outside the repo so that `git clean`,
# branch switches, and repo moves never force a full re-download.
IMAGE_CACHE="${ELAN_IMAGE_CACHE:-$HOME/Developer/Web/ElanRegistry/.local-userimages}"
LOCAL_IMAGES="$PROJECT_ROOT/userimages/"

# car_user / car_user_hist were listed here historically but do not exist in
# the schema; ownership lives in cars.user_id.
TABLES=(
    cars
    cars_hist
    car_models
    car_transfer_requests
    elan_factory_info
    users
    profiles
    user_permission_matches
    country
    audit
)

# ── Argument parsing ──────────────────────────────────────────────────────────
SKIP_IMAGES=false
IMAGES_ONLY=false
FETCH_DUMP=false
DB_NAME_OVERRIDE=""
ENV_FILE_OVERRIDE=""
DUMP_FILE="$HOME/Downloads/unibrain_registry.sql"
DUMP_FILE_ARG=""

usage() {
    grep '^#' "$0" | grep -v '^#!/' | sed 's/^# \{0,1\}//'
    exit 0
}

while [[ $# -gt 0 ]]; do
    case $1 in
        --fetch)       FETCH_DUMP=true; shift ;;
        --db)          DB_NAME_OVERRIDE="${2:-}"; shift 2 ;;
        --env-file)    ENV_FILE_OVERRIDE="${2:-}"; shift 2 ;;
        --skip-images) SKIP_IMAGES=true; shift ;;
        --images-only) IMAGES_ONLY=true; shift ;;
        -h|--help)     usage ;;
        -*)            echo "Error: unknown option: $1" >&2; exit 1 ;;
        *)             DUMP_FILE_ARG="$1"; shift ;;
    esac
done

# ── Validation ────────────────────────────────────────────────────────────────
if [[ "$IMAGES_ONLY" == true ]] && [[ "$SKIP_IMAGES" == true ]]; then
    echo "Error: --images-only and --skip-images are mutually exclusive" >&2
    exit 1
fi

if [[ "$IMAGES_ONLY" == true ]] && [[ "$FETCH_DUMP" == true ]]; then
    echo "Error: --images-only and --fetch are mutually exclusive" >&2
    exit 1
fi

# --fetch writes the production dump to DUMP_FILE. Accepting a positional path
# alongside it would silently overwrite that file rather than "ignore" it, so
# refuse the combination instead of destroying whatever was there.
if [[ "$FETCH_DUMP" == true ]] && [[ -n "$DUMP_FILE_ARG" ]]; then
    echo "Error: --fetch dumps production to $DUMP_FILE; it cannot also take" >&2
    echo "       a DUMP_FILE argument. Drop --fetch to import $DUMP_FILE_ARG," >&2
    echo "       or drop the argument to fetch a fresh dump." >&2
    exit 1
fi

if [[ -n "$DUMP_FILE_ARG" ]]; then
    DUMP_FILE="$DUMP_FILE_ARG"
fi

if [[ "$IMAGES_ONLY" == false ]]; then
    if [[ "$FETCH_DUMP" == false ]] && [[ ! -f "$DUMP_FILE" ]]; then
        echo "Error: SQL dump not found: $DUMP_FILE" >&2
        echo "       Pass --fetch to dump production over SSH instead." >&2
        exit 1
    fi

    if [[ ! -x "$MYSQL_BIN" ]]; then
        echo "Error: MySQL binary not found at $MYSQL_BIN" >&2
        exit 1
    fi

    if [[ ! -x "$MYSQLDUMP_BIN" ]]; then
        echo "Error: mysqldump binary not found at $MYSQLDUMP_BIN" >&2
        exit 1
    fi
fi

# ── DB credentials ────────────────────────────────────────────────────────────
load_env() {
    local env_file="${ENV_FILE_OVERRIDE:-$PROJECT_ROOT/.env}"
    [[ -f "$env_file" ]] || { echo "Error: env file not found at $env_file" >&2; exit 1; }
    echo "==> Reading target credentials from $(basename "$env_file")"
    DB_USER=$(grep -E '^DB_USER=' "$env_file" | cut -d= -f2-)
    DB_PASS=$(grep -E '^DB_PASS=' "$env_file" | cut -d= -f2-)
    DB_NAME=$(grep -E '^DB_NAME=' "$env_file" | cut -d= -f2-)
    DB_HOST=$(grep -E '^DB_HOST=' "$env_file" | cut -d= -f2-)
    DB_PORT=$(grep -E '^DB_PORT=' "$env_file" | cut -d= -f2-)

    # DB_HOST may carry the port as "host:port" rather than using DB_PORT.
    # Either shape works: a combined value is split here, and a separate
    # DB_PORT is read directly. Both end up selecting the TCP branch in
    # setup_mysql_cnf.
    if [[ "$DB_HOST" == *:* ]]; then
        DB_PORT="${DB_PORT:-${DB_HOST##*:}}"
        DB_HOST="${DB_HOST%%:*}"
    fi

    if [[ -n "$DB_NAME_OVERRIDE" ]]; then
        DB_NAME="$DB_NAME_OVERRIDE"
    fi
    echo "==> Target database: $DB_NAME"
}

# Write credentials to a temp file so the password never appears in the process list
setup_mysql_cnf() {
    MYSQL_CNF=$(mktemp /tmp/elan_mysql_XXXXXX)
    chmod 600 "$MYSQL_CNF"
    {
        echo "[client]"
        # A non-localhost host or an explicit port means TCP; otherwise use
        # MAMP's Unix socket, which "localhost" alone would not resolve to.
        if [[ -n "$DB_PORT" ]] || [[ -n "$DB_HOST" && "$DB_HOST" != "localhost" ]]; then
            echo "host=${DB_HOST:-127.0.0.1}"
            echo "port=${DB_PORT:-3306}"
            echo "protocol=TCP"
        else
            echo "socket=$MYSQL_SOCK"
        fi
        echo "user=$DB_USER"
        echo "password=$DB_PASS"
    } > "$MYSQL_CNF"
}

# ── Production dump fetch ─────────────────────────────────────────────────────
# Dump production over SSH. Credentials are read from the prod docroot .env on
# the remote host and written to a 0600 defaults-file there, so the password
# never appears in a process list, in this repo, or on this machine.
fetch_dump() {
    local dest="$1"

    echo "==> Dumping production database on $SSH_ALIAS ..."

    ssh -o ConnectTimeout=30 "$SSH_ALIAS" \
        "REMOTE_DOCROOT='$REMOTE_DOCROOT' DUMP_TABLES='${TABLES[*]}' bash -s" \
        <<'REMOTE' > "$dest.gz"
set -euo pipefail

env_file="$REMOTE_DOCROOT/.env"
[ -f "$env_file" ] || { echo "Remote .env not found: $env_file" >&2; exit 1; }

# Strip surrounding quotes -- .env values may be quoted (matches the handling
# in the Monitoring project's fetch.sh, which reads this same file).
# `|| true` matters: DB_PORT is absent from the production .env, and under
# `set -e` a failing grep inside a command substitution aborts this script
# silently (grep prints nothing on no-match). Quote stripping matches the
# handling in the Monitoring project's fetch.sh, which reads this same file.
get() { grep -E "^$1=" "$env_file" | head -1 | cut -d= -f2- | sed "s/['\"]//g" || true ; }

db_host=$(get DB_HOST); db_user=$(get DB_USER)
db_pass=$(get DB_PASS); db_name=$(get DB_NAME); db_port=$(get DB_PORT)
[ -n "$db_name" ] || { echo "DB_NAME missing from remote .env" >&2; exit 1; }

cnf=$(mktemp); chmod 600 "$cnf"
trap 'rm -f "$cnf"' EXIT
{
  echo "[client]"
  echo "host=${db_host:-localhost}"
  echo "port=${db_port:-3306}"
  echo "user=$db_user"
  echo "password=$db_pass"
} > "$cnf"

# Dump only the tables we import. Naming them explicitly also skips
# `users_carsview`, a deprecated view whose definer/privileges are broken
# (MySQL error 1356) -- mysqldump cannot read its structure and aborts with a
# nonzero exit, which would kill this script under `set -e`. The Monitoring
# project's fetch.sh hits the same two issues against this database.
#
# --no-tablespaces: the shared-hosting DB user has no PROCESS privilege, and
#   without this flag mysqldump errors out trying to dump tablespaces.
# --single-transaction: consistent read that keeps the live site unblocked.
# --routines is deliberately omitted -- it is schema-wide (not per-table) and
#   we only ever import data rows plus each table's own triggers.
# --complete-insert names every column in each INSERT instead of relying on
# positional values. Production still carries legacy `users` columns
# (`company`, `last_confirm`) that no migration creates, so a freshly
# provisioned local schema has fewer columns than production and a positional
# insert fails with "Column count doesn't match value count" (MySQL 1136).
# Naming the columns makes the import tolerate that drift in either direction.
mysqldump --defaults-file="$cnf" \
    --single-transaction --quick --triggers --no-tablespaces \
    --complete-insert --default-character-set=utf8mb4 \
    "$db_name" $DUMP_TABLES | gzip -c
REMOTE

    if [[ ! -s "$dest.gz" ]]; then
        echo "Error: production dump came back empty" >&2
        rm -f "$dest.gz"
        exit 1
    fi

    echo "==> Decompressing to $dest ..."
    gunzip -f "$dest.gz"

    # A truncated dump silently imports partial data, so require the trailer
    # mysqldump writes only after a successful run.
    if ! tail -5 "$dest" | grep -q 'Dump completed'; then
        echo "Error: dump is incomplete (no 'Dump completed' trailer)" >&2
        exit 1
    fi

    echo "    Dump saved: $dest ($(du -h "$dest" | cut -f1))"
}

# ── Table extraction ──────────────────────────────────────────────────────────
# Single-pass Python extractor: reads the mysqldump and emits only the sections
# for the requested tables (structure + data + triggers), wrapped with the
# charset/mode preamble and epilogue needed for a clean import.

# Columns that exist in production but not in the local schema. Production
# carries legacy `users` columns (`company`, `last_confirm`) that no migration
# creates, so a provisioned schema legitimately has fewer columns. Rather than
# fail the import, the extractor drops those columns from each INSERT.
target_columns_csv() {
    # group_concat_max_len defaults to 1024 bytes; a wide table would silently
    # truncate its column list here and cause real columns to be dropped from
    # the import.
    "$MYSQL_BIN" --defaults-file="$MYSQL_CNF" -N -B "$DB_NAME" -e "
        SET SESSION group_concat_max_len = 1000000;
        SELECT CONCAT(TABLE_NAME, ':', GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION))
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         GROUP BY TABLE_NAME"
}

extract_tables() {
    local dump_file="$1"
    shift
    local schema_map
    schema_map=$(target_columns_csv)
    TARGET_SCHEMA="$schema_map" python3 - "$dump_file" "$@" <<'PYEOF'
import sys, re

import os

dump_file = sys.argv[1]
wanted    = set(sys.argv[2:])

# table -> ordered list of columns that actually exist in the target schema.
TARGET = {}
for entry in os.environ.get('TARGET_SCHEMA', '').splitlines():
    if ':' in entry:
        t, cols = entry.split(':', 1)
        TARGET[t] = cols.split(',')

COMPLETE_INSERT_RE = re.compile(
    r'^(REPLACE INTO `([^`]+)` )\(([^)]*)\) VALUES (.*)$', re.S
)


def split_tuples(values_sql):
    """Split a VALUES payload into its top-level (...) tuples.

    Cannot be done with a regex: values contain quoted strings that may hold
    parentheses, commas, and escaped quotes.
    """
    tuples, depth, buf = [], 0, []
    in_str = False
    esc = False
    for ch in values_sql:
        if in_str:
            buf.append(ch)
            if esc:
                esc = False
            elif ch == '\\':
                esc = True
            elif ch == "'":
                in_str = False
            continue
        if ch == "'":
            in_str = True
            buf.append(ch)
        elif ch == '(':
            depth += 1
            if depth == 1:
                buf = []
            else:
                buf.append(ch)
        elif ch == ')':
            depth -= 1
            if depth == 0:
                tuples.append(''.join(buf))
            else:
                buf.append(ch)
        elif depth:
            buf.append(ch)
    return tuples


def split_values(tup):
    """Split one tuple's comma-separated values, respecting quoted strings."""
    out, buf, in_str, esc = [], [], False, False
    for ch in tup:
        if in_str:
            buf.append(ch)
            if esc:
                esc = False
            elif ch == '\\':
                esc = True
            elif ch == "'":
                in_str = False
            continue
        if ch == "'":
            in_str = True
            buf.append(ch)
        elif ch == ',':
            out.append(''.join(buf))
            buf = []
        else:
            buf.append(ch)
    out.append(''.join(buf))
    return out


def drop_missing_columns(line):
    """Rewrite a --complete-insert REPLACE, removing columns the target lacks."""
    m = COMPLETE_INSERT_RE.match(line)
    if not m:
        return line
    head, table, collist, values_sql = m.groups()
    target_cols = TARGET.get(table)
    if not target_cols:
        return line

    cols = [c.strip().strip('`') for c in collist.split(',')]
    keep = [i for i, c in enumerate(cols) if c in target_cols]
    if len(keep) == len(cols):
        return line  # nothing to drop

    trailing = ';\n' if values_sql.rstrip().endswith(';') else '\n'
    payload = values_sql.rstrip().rstrip(';')

    new_tuples = []
    for tup in split_tuples(payload):
        vals = split_values(tup)
        if len(vals) != len(cols):
            return line  # shape not as expected; leave it alone
        new_tuples.append('(' + ','.join(vals[i] for i in keep) + ')')

    new_cols = '(' + ','.join('`' + cols[i] + '`' for i in keep) + ')'
    return head + new_cols + ' VALUES ' + ','.join(new_tuples) + trailing

# Section boundaries are the per-table header comments themselves, NOT the
# `-- ------` separator lines. A whole-schema dump emits a separator before
# every table, but a dump of explicitly named tables (what --fetch produces)
# emits only one, in the file header -- keying on separators there collapses
# every table into a single section and silently drops all but the last.
SECTION_RE = re.compile(r'^-- Table structure for table `([^`]+)`')
TABLE_RE   = re.compile(
    r'-- (?:Table structure|Dumping data|Triggers) for table `([^`]+)`'
)

# For each email-sensitive table: the masking UPDATE runs inside the same
# transaction as the INSERTs so real addresses are never the committed state.
EMAIL_MASKS = {
    # `noowner` is the GDPR reassignment target. Its address is deliberately
    # unroutable: `.invalid` (RFC 2606) cannot resolve, which is the only thing
    # closing the passwordless-login recovery path for that account. Masking it
    # to an @elanregistry.local address would re-open that gate, so preserve it.
    # Matched by username, never by id — the migration that creates the account
    # deliberately lets its id fall out of the insert rather than hardcoding it.
    'users': (
        "UPDATE `users` "
        "SET `email` = CONCAT('dev.owner.', `id`, '@elanregistry.local'), "
        "`email_new` = NULL "
        "WHERE `id` != 1 "
        "AND `username` <> 'noowner' "
        "AND `email` NOT LIKE '%@invalid';\n"
    ),
    'cars': (
        "UPDATE `cars` "
        "SET `email` = CONCAT('dev.owner.', COALESCE(`user_id`, 0), '@elanregistry.local');\n"
    ),
    'cars_hist': (
        "UPDATE `cars_hist` "
        "SET `email` = CONCAT('dev.owner.', COALESCE(`user_id`, 0), '@elanregistry.local');\n"
    ),
    'car_transfer_requests': (
        "UPDATE `car_transfer_requests` "
        "SET `submitted_email` = CONCAT('dev.owner.', COALESCE(`requested_by_user_id`, 0), '@elanregistry.local') "
        "WHERE `submitted_email` IS NOT NULL;\n"
    ),
}

# mysqldump does not emit a bare `CREATE TRIGGER`: it wraps the statement in
# version-gated comments, e.g.
#   /*!50003 CREATE*/ /*!50017 DEFINER=`u`@`h`*/ /*!50003 TRIGGER `name` ...
# Match the trigger name in either form.
CREATE_TRIGGER_RE = re.compile(r'^(?:/\*!\d+ )?CREATE.*?TRIGGER[ ]+`([^`]+)`')

# Production's trigger definer (`unibrain_registry`@`localhost`) does not exist
# on a developer machine, and MySQL rejects the CREATE with error 1449. Strip
# the clause so each trigger is created as whoever runs the import.
DEFINER_RE = re.compile(r'/\*!5\d{4} DEFINER=`[^`]*`@`[^`]*`\*/ ?')

def transform_buf(buf):
    """Skip DROP TABLE and CREATE TABLE; convert INSERT INTO → REPLACE INTO;
    prepend DROP TRIGGER IF EXISTS before each CREATE TRIGGER.

    The local schema already matches production, so we only need to
    upsert data rows — REPLACE INTO overwrites existing rows and inserts new ones.

    Dropping the `DROP TABLE IF EXISTS` lines is essential, not cosmetic: we
    also skip the CREATE TABLE that follows, so letting a DROP through would
    delete the local table and never recreate it, and the import would then
    fail on the next `LOCK TABLES` for that table.
    """
    out = []
    in_create = False
    for line in buf:
        if line.startswith('DROP TABLE '):
            continue
        if 'DEFINER=' in line:
            line = DEFINER_RE.sub('', line)
        if line.startswith('CREATE TABLE '):
            in_create = True
            continue
        if in_create:
            if line.rstrip().endswith(';'):
                in_create = False
            continue
        if line.startswith('INSERT INTO '):
            line = 'REPLACE INTO ' + line[len('INSERT INTO '):]
            line = drop_missing_columns(line)
        elif CREATE_TRIGGER_RE.match(line):
            m = CREATE_TRIGGER_RE.match(line)
            drop = f'DROP TRIGGER IF EXISTS `{m.group(1)}`;\n'
            # The CREATE is preceded by `DELIMITER ;;`, after which `;` no
            # longer terminates a statement — emit the DROP before that
            # switch, otherwise it is swallowed into the trigger body.
            for i in range(len(out) - 1, -1, -1):
                if out[i].startswith('DELIMITER '):
                    out.insert(i, drop)
                    break
            else:
                out.append(drop)
        out.append(line)
    return out

def flush(buf, table):
    if not buf or table is None:
        return
    buf = transform_buf(buf)
    mask = EMAIL_MASKS.get(table)
    if mask is None:
        sys.stdout.writelines(buf)
        return

    # Each REPLACE INTO is a multi-line statement; we need the index of the
    # first REPLACE INTO header and the last data row (ending with ');').
    #
    # Stop at the first DELIMITER line: everything past it is trigger bodies,
    # whose lines also end in ');'. Scanning into them would place the COMMIT
    # inside a trigger body, which MySQL rejects with error 1422 ("Explicit or
    # implicit commit is not allowed in stored function or trigger"). Match any
    # DELIMITER, not `DELIMITER $$` specifically — mysqldump emits `;;`.
    first_replace = None
    last_data = None
    for i, line in enumerate(buf):
        if line.startswith('DELIMITER '):
            break
        if line.startswith('REPLACE INTO') and first_replace is None:
            first_replace = i
        if line.rstrip().endswith(');'):
            last_data = i

    if first_replace is None:
        sys.stdout.writelines(buf)  # empty table, nothing to mask
        return

    sys.stdout.writelines(buf[:first_replace])
    sys.stdout.write('START TRANSACTION;\n')
    sys.stdout.writelines(buf[first_replace:last_data + 1])
    sys.stdout.write(mask)
    sys.stdout.write('COMMIT;\n')
    sys.stdout.writelines(buf[last_data + 1:])  # triggers etc.

sys.stdout.write(
    # Save every session variable that mysqldump's own epilogue restores from.
    # We emit our own preamble rather than the dump's, so without these saves
    # the passed-through `SET X=@OLD_X` lines at the end resolve to NULL and
    # error (MySQL 1231).
    "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE */;\n"
    "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n"
    "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS */;\n"
    "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS */;\n"
    "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES */;\n"
    "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
    "SET time_zone = '+00:00';\n"
    "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
    "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n"
    "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n"
    "/*!40101 SET NAMES utf8mb4 */;\n"
    "SET foreign_key_checks = 0;\n"
    "SET unique_checks = 0;\n\n"
)

buf       = []
cur_table = None

with open(dump_file, encoding="utf-8", errors="replace") as f:
    for line in f:
        m = SECTION_RE.match(line)
        if m:
            # A new table's section starts here: flush the previous one.
            flush(buf, cur_table)
            buf       = [line]
            cur_table = m.group(1) if m.group(1) in wanted else None
        else:
            # A --no-create-info dump has no `-- Table structure` headers, so
            # the only section boundary is a data/trigger header. Treat one for
            # a *different* table as a boundary too, otherwise every later
            # table is appended to the first one's buffer and silently dropped.
            m2 = TABLE_RE.search(line)
            if m2 and m2.group(1) != cur_table:
                name = m2.group(1)
                if cur_table is not None:
                    flush(buf, cur_table)
                    buf = []
                cur_table = name if name in wanted else None
            buf.append(line)

flush(buf, cur_table)  # last section

sys.stdout.write(
    "\nSET foreign_key_checks = 1;\n"
    "SET unique_checks = 1;\n"
    "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n"
    "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n"
    "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n"
)
PYEOF
}

# ── Backup ───────────────────────────────────────────────────────────────────
backup_local_db() {
    local backup_dir="$PROJECT_ROOT/db-backups"
    mkdir -p "$backup_dir"
    local backup_file="$backup_dir/${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql.gz"
    echo "==> Backing up $DB_NAME to $(basename "$backup_file") ..."
    "$MYSQLDUMP_BIN" --defaults-file="$MYSQL_CNF" \
        --single-transaction --routines --triggers \
        "$DB_NAME" | gzip > "$backup_file"
    echo "    Backup saved: $backup_file"
}

# ── Masking verification ──────────────────────────────────────────────────────
# Re-read every email column after import and fail loudly if any real address
# survived. The DB is left as-is so the offending rows can be inspected; the
# pre-refresh backup in db-backups/ is the manual restore path.
verify_masking() {
    echo "==> Verifying email masking ..."

    local sql="
SELECT 'users.email', COUNT(*) FROM \`users\`
    WHERE \`id\` != 1
      AND \`username\` <> 'noowner'
      AND \`email\` NOT LIKE '%@invalid'
      AND \`email\` NOT LIKE '%@elanregistry.local'
UNION ALL
SELECT 'users.email_new', COUNT(*) FROM \`users\`
    WHERE \`email_new\` IS NOT NULL AND \`email_new\` != ''
UNION ALL
SELECT 'cars.email', COUNT(*) FROM \`cars\`
    WHERE \`email\` IS NOT NULL AND \`email\` != ''
      AND \`email\` NOT LIKE '%@elanregistry.local'
UNION ALL
SELECT 'cars_hist.email', COUNT(*) FROM \`cars_hist\`
    WHERE \`email\` IS NOT NULL AND \`email\` != ''
      AND \`email\` NOT LIKE '%@elanregistry.local'
UNION ALL
SELECT 'car_transfer_requests.submitted_email', COUNT(*) FROM \`car_transfer_requests\`
    WHERE \`submitted_email\` IS NOT NULL AND \`submitted_email\` != ''
      AND \`submitted_email\` NOT LIKE '%@elanregistry.local';
"

    local results
    results=$("$MYSQL_BIN" --defaults-file="$MYSQL_CNF" -N -B "$DB_NAME" -e "$sql")

    local failed=false
    while IFS=$'\t' read -r column count; do
        [[ -n "$column" ]] || continue
        if [[ "$count" -gt 0 ]]; then
            echo "    FAIL: $column has $count unmasked value(s)" >&2
            failed=true
        else
            echo "    ok: $column"
        fi
    done <<< "$results"

    if [[ "$failed" == true ]]; then
        echo "" >&2
        echo "Error: unmasked email addresses remain after import." >&2
        echo "       The database has NOT been rolled back — inspect the rows above." >&2
        echo "       To restore: gunzip < db-backups/<newest>.sql.gz | mysql $DB_NAME" >&2
        exit 1
    fi

    echo "    All email columns masked."
}

# ── Main ──────────────────────────────────────────────────────────────────────
if [[ "$IMAGES_ONLY" == false ]]; then
    load_env
    setup_mysql_cnf
    EXTRACT_SQL=""
    trap 'rm -f "$MYSQL_CNF" ${EXTRACT_SQL:+"$EXTRACT_SQL"}' EXIT

    backup_local_db

    if [[ "$FETCH_DUMP" == true ]]; then
        fetch_dump "$DUMP_FILE"
    fi

    echo "==> Extracting ${#TABLES[@]} tables from $(basename "$DUMP_FILE") ..."
    EXTRACT_SQL=$(mktemp /tmp/elan_refresh_XXXXXX)

    extract_tables "$DUMP_FILE" "${TABLES[@]}" > "$EXTRACT_SQL"

    line_count=$(wc -l < "$EXTRACT_SQL" | xargs)
    echo "==> Upserting $line_count lines into $DB_NAME (emails masked in-transaction) ..."
    "$MYSQL_BIN" --defaults-file="$MYSQL_CNF" "$DB_NAME" < "$EXTRACT_SQL"

    verify_masking

    echo "==> Database refresh complete."
fi

if [[ "$SKIP_IMAGES" == false ]]; then
    # Hop 1: production -> persistent cache outside the repo. This is the only
    # network transfer, and it is incremental across runs.
    mkdir -p "$IMAGE_CACHE"
    echo "==> Syncing images from $SSH_ALIAS:$REMOTE_IMAGES into cache ..."
    echo "    Cache: $IMAGE_CACHE"
    rsync -az --info=progress2 -e "ssh -o ConnectTimeout=15" \
        "$SSH_ALIAS:$REMOTE_IMAGES" "$IMAGE_CACHE/"

    # Hop 2: cache -> repo, so the local app actually renders the images.
    # Local copy only; --delete keeps the working tree an exact mirror of the
    # cache (userimages/ is gitignored apart from .htaccess, preserved below).
    mkdir -p "$LOCAL_IMAGES"
    echo "==> Copying cache into $LOCAL_IMAGES ..."
    rsync -a --delete --exclude '.htaccess' "$IMAGE_CACHE/" "$LOCAL_IMAGES"

    echo "==> Image sync complete ($(du -sh "$IMAGE_CACHE" | cut -f1) cached)."
fi
