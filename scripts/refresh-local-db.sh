#!/usr/bin/env bash
#
# scripts/refresh-local-db.sh
#
# Refresh the local database with car and owner data from a production SQL dump,
# mask all email addresses for dev safety, and optionally rsync car images.
#
# Usage:
#   ./scripts/refresh-local-db.sh [OPTIONS] [DUMP_FILE]
#
# Options:
#   --skip-images   Skip image rsync (DB refresh only)
#   --images-only   Skip DB refresh, only rsync images
#   -h, --help      Show this help and exit
#
# Arguments:
#   DUMP_FILE       Path to a mysqldump SQL file
#                   (default: ~/Downloads/unibrain_registry.sql)
#
# Tables upserted (new rows added, existing rows updated by primary key):
#   cars, cars_hist, car_models, car_user, car_user_hist,
#   car_transfer_requests, elan_factory_info, users, profiles
#
# Email masking:
#   All user emails are replaced with dev.owner.{id}@elanregistry.local.
#   User id=1 (admin) is preserved unchanged.
#
# Image sync:
#   rsync from a2hosting:/home/unibrain/elanregistry.org/userimages/
#         to ./userimages/ in the project root

set -euo pipefail

# ── Paths ─────────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

MYSQL_BIN="/Applications/MAMP/Library/bin/mysql57/bin/mysql"
MYSQLDUMP_BIN="/Applications/MAMP/Library/bin/mysql57/bin/mysqldump"
MYSQL_SOCK="/Applications/MAMP/tmp/mysql/mysql.sock"

SSH_ALIAS="a2hosting"
REMOTE_IMAGES="/home/unibrain/elanregistry.org/userimages/"
LOCAL_IMAGES="$PROJECT_ROOT/userimages/"

TABLES=(
    cars
    cars_hist
    car_models
    car_user
    car_user_hist
    car_transfer_requests
    elan_factory_info
    users
    profiles
)

# ── Argument parsing ──────────────────────────────────────────────────────────
SKIP_IMAGES=false
IMAGES_ONLY=false
DUMP_FILE="$HOME/Downloads/unibrain_registry.sql"

usage() {
    grep '^#' "$0" | grep -v '^#!/' | sed 's/^# \{0,1\}//'
    exit 0
}

while [[ $# -gt 0 ]]; do
    case $1 in
        --skip-images) SKIP_IMAGES=true; shift ;;
        --images-only) IMAGES_ONLY=true; shift ;;
        -h|--help)     usage ;;
        -*)            echo "Error: unknown option: $1" >&2; exit 1 ;;
        *)             DUMP_FILE="$1"; shift ;;
    esac
done

# ── Validation ────────────────────────────────────────────────────────────────
if [[ "$IMAGES_ONLY" == true ]] && [[ "$SKIP_IMAGES" == true ]]; then
    echo "Error: --images-only and --skip-images are mutually exclusive" >&2
    exit 1
fi

if [[ "$IMAGES_ONLY" == false ]]; then
    if [[ ! -f "$DUMP_FILE" ]]; then
        echo "Error: SQL dump not found: $DUMP_FILE" >&2
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
    local env_file="$PROJECT_ROOT/.env"
    [[ -f "$env_file" ]] || { echo "Error: .env not found at $env_file" >&2; exit 1; }
    DB_USER=$(grep -E '^DB_USER=' "$env_file" | cut -d= -f2-)
    DB_PASS=$(grep -E '^DB_PASS=' "$env_file" | cut -d= -f2-)
    DB_NAME=$(grep -E '^DB_NAME=' "$env_file" | cut -d= -f2-)
}

# Write credentials to a temp file so the password never appears in the process list
setup_mysql_cnf() {
    MYSQL_CNF=$(mktemp /tmp/elan_mysql_XXXXXX.cnf)
    chmod 600 "$MYSQL_CNF"
    cat > "$MYSQL_CNF" <<EOF
[client]
socket=$MYSQL_SOCK
user=$DB_USER
password=$DB_PASS
EOF
}

# ── Table extraction ──────────────────────────────────────────────────────────
# Single-pass Python extractor: reads the mysqldump and emits only the sections
# for the requested tables (structure + data + triggers), wrapped with the
# charset/mode preamble and epilogue needed for a clean import.
extract_tables() {
    local dump_file="$1"
    shift
    python3 - "$dump_file" "$@" <<'PYEOF'
import sys, re

dump_file = sys.argv[1]
wanted    = set(sys.argv[2:])

SEPARATOR = re.compile(r'^-- -{50,}')
TABLE_RE  = re.compile(
    r'-- (?:Table structure|Dumping data|Triggers) for table `([^`]+)`'
)

# For each email-sensitive table: the masking UPDATE runs inside the same
# transaction as the INSERTs so real addresses are never the committed state.
EMAIL_MASKS = {
    'users': (
        "UPDATE `users` "
        "SET `email` = CONCAT('dev.owner.', `id`, '@elanregistry.local'), "
        "`email_new` = NULL "
        "WHERE `id` != 1;\n"
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

CREATE_TRIGGER_RE = re.compile(r'^CREATE TRIGGER `([^`]+)`')

def transform_buf(buf):
    """Skip CREATE TABLE blocks; convert INSERT INTO → REPLACE INTO;
    prepend DROP TRIGGER IF EXISTS before each CREATE TRIGGER.

    The local schema already matches production, so we only need to
    upsert data rows — REPLACE INTO overwrites existing rows and inserts new ones.
    """
    out = []
    in_create = False
    for line in buf:
        if line.startswith('CREATE TABLE '):
            in_create = True
            continue
        if in_create:
            if line.rstrip().endswith(';'):
                in_create = False
            continue
        if line.startswith('INSERT INTO '):
            line = 'REPLACE INTO ' + line[len('INSERT INTO '):]
        elif CREATE_TRIGGER_RE.match(line):
            m = CREATE_TRIGGER_RE.match(line)
            out.append(f'DROP TRIGGER IF EXISTS `{m.group(1)}`;\n')
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
    # Stop scanning at DELIMITER $$ so trigger bodies don't interfere.
    first_replace = None
    last_data = None
    for i, line in enumerate(buf):
        if line.rstrip() == 'DELIMITER $$':
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
    "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
    "SET time_zone = '+00:00';\n"
    "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
    "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n"
    "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n"
    "/*!40101 SET NAMES utf8mb4 */;\n"
    "SET foreign_key_checks = 0;\n"
    "SET unique_checks = 0;\n\n"
)

buf        = []
cur_table  = None
past_first = False

with open(dump_file, encoding="utf-8", errors="replace") as f:
    for line in f:
        if SEPARATOR.match(line):
            flush(buf, cur_table)
            buf       = [line]
            cur_table = None
            past_first = True
        else:
            buf.append(line)
            if cur_table is None and past_first:
                m = TABLE_RE.search(line)
                if m and m.group(1) in wanted:
                    cur_table = m.group(1)

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

# ── Main ──────────────────────────────────────────────────────────────────────
if [[ "$IMAGES_ONLY" == false ]]; then
    load_env
    setup_mysql_cnf
    trap 'rm -f "$MYSQL_CNF" "$EXTRACT_SQL"' EXIT

    backup_local_db

    echo "==> Extracting ${#TABLES[@]} tables from $(basename "$DUMP_FILE") ..."
    EXTRACT_SQL=$(mktemp /tmp/elan_refresh_XXXXXX.sql)

    extract_tables "$DUMP_FILE" "${TABLES[@]}" > "$EXTRACT_SQL"

    line_count=$(wc -l < "$EXTRACT_SQL" | xargs)
    echo "==> Upserting $line_count lines into $DB_NAME (emails masked in-transaction) ..."
    "$MYSQL_BIN" --defaults-file="$MYSQL_CNF" "$DB_NAME" < "$EXTRACT_SQL"

    echo "==> Database refresh complete."
fi

if [[ "$SKIP_IMAGES" == false ]]; then
    echo "==> Syncing images from $SSH_ALIAS:$REMOTE_IMAGES ..."
    rsync -avz --progress -e "ssh -o ConnectTimeout=15" "$SSH_ALIAS:$REMOTE_IMAGES" "$LOCAL_IMAGES"
    echo "==> Image sync complete."
fi
