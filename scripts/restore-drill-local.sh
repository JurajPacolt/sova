#!/usr/bin/env bash

set -euo pipefail
umask 077

usage() {
  echo "Usage: SOVA_PG_BIN_DIR=/path/to/postgresql/17/bin $0 BACKUP_DIRECTORY" >&2
}

if [[ $# -ne 1 || ! -d "$1" ]]; then
  usage
  exit 64
fi

backup_dir="$(cd -- "$1" && pwd)"
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/.." && pwd)"
env_file="${SOVA_ENV_FILE:-$repo_root/backend/.env}"

for file in database.dump database-inventory.tsv attachments.tar \
  attachments-inventory.tsv metadata.tsv SHA256SUMS; do
  if [[ ! -f "$backup_dir/$file" ]]; then
    echo "Backup is incomplete; missing $file." >&2
    exit 65
  fi
done

read_env_value() {
  local key="$1"
  local fallback="$2"
  local value=""

  if [[ -f "$env_file" ]]; then
    value="$(sed -n "s/^${key}=//p" "$env_file" | tail -n 1)"
    value="${value#\"}"
    value="${value%\"}"
  fi

  if [[ -z "$value" ]]; then
    value="$fallback"
  fi

  printf '%s' "$value"
}

db_host="${DATABASE_HOST:-$(read_env_value DATABASE_HOST 127.0.0.1)}"
db_port="${DATABASE_PORT:-$(read_env_value DATABASE_PORT 5432)}"
db_user="${DATABASE_USER:-$(read_env_value DATABASE_USER sova)}"
export PGPASSWORD="${DATABASE_PASSWORD:-$(read_env_value DATABASE_PASSWORD '')}"
pg_bin_dir="${SOVA_PG_BIN_DIR:-$(dirname -- "$(command -v pg_restore)")}"

for tool in createdb dropdb pg_restore psql; do
  if [[ ! -x "$pg_bin_dir/$tool" ]]; then
    echo "Required PostgreSQL tool is missing: $pg_bin_dir/$tool" >&2
    exit 69
  fi
done

(
  cd "$backup_dir"
  sha256sum --check --strict SHA256SUMS
)

if tar -tf "$backup_dir/attachments.tar" \
  | awk '/^\// || /(^|\/)\.\.(\/|$)/ { unsafe = 1 } END { exit unsafe }'; then
  :
else
  echo "Attachment archive contains an unsafe path." >&2
  exit 65
fi

if tar -tvf "$backup_dir/attachments.tar" \
  | awk 'substr($0, 1, 1) != "-" && substr($0, 1, 1) != "d" { unsafe = 1 } END { exit unsafe }'; then
  :
else
  echo "Attachment archive contains a link or unsupported entry type." >&2
  exit 65
fi

started_at="$(date +%s)"
restore_db="${SOVA_RESTORE_DATABASE_NAME:-sova_restore_drill_$(date -u +%Y%m%d%H%M%S)_$$}"

if [[ ! "$restore_db" =~ ^sova_restore_drill_[0-9A-Za-z_]+$ ]]; then
  echo "Restore database name must use the guarded sova_restore_drill_ prefix." >&2
  exit 64
fi

pg_args=(-h "$db_host" -p "$db_port" -U "$db_user")
server_major="$("$pg_bin_dir/psql" "${pg_args[@]}" -d postgres -Atc \
  "SELECT current_setting('server_version_num')::integer / 10000")"
client_major="$("$pg_bin_dir/pg_restore" --version | sed -E 's/.* ([0-9]+)\..*/\1/')"
backup_format="$(awk -F $'\t' '$1 == "format_version" { print $2 }' \
  "$backup_dir/metadata.tsv")"
backup_major="$(awk -F $'\t' '$1 == "postgres_major" { print $2 }' \
  "$backup_dir/metadata.tsv")"
backup_client_major="$(awk -F $'\t' '$1 == "postgres_client_major" { print $2 }' \
  "$backup_dir/metadata.tsv")"

if [[ "$backup_format" != "1" || -z "$backup_major" || -z "$backup_client_major" \
  || "$client_major" -ne "$server_major" \
  || "$backup_major" -ne "$server_major" \
  || "$backup_client_major" -ne "$server_major" ]]; then
  echo "Backup format must be 1, and all PostgreSQL major versions must match." >&2
  echo "Backup format: ${backup_format:-unknown}." >&2
  echo "Backup server: ${backup_major:-unknown}; backup client: ${backup_client_major:-unknown};" >&2
  echo "restore client: $client_major; target server: $server_major." >&2
  exit 69
fi

existing="$("$pg_bin_dir/psql" "${pg_args[@]}" -d postgres -Atc \
  "SELECT 1 FROM pg_database WHERE datname = '$restore_db'")"

if [[ -n "$existing" ]]; then
  echo "Restore database already exists and will not be touched: $restore_db" >&2
  exit 73
fi

restore_workdir="$(mktemp -d /tmp/sova-restore-drill.XXXXXX)"
attachment_restore="$restore_workdir/attachments"
mkdir "$attachment_restore"
created_database=false
cleanup() {
  if [[ "$created_database" == true ]]; then
    "$pg_bin_dir/dropdb" "${pg_args[@]}" --if-exists "$restore_db" >/dev/null
  fi

  if [[ -d "$restore_workdir" ]]; then
    rm -rf -- "$restore_workdir"
  fi
}
trap cleanup EXIT

"$pg_bin_dir/createdb" "${pg_args[@]}" "$restore_db"
created_database=true
"$pg_bin_dir/pg_restore" "${pg_args[@]}" -d "$restore_db" \
  --exit-on-error --no-owner --no-privileges "$backup_dir/database.dump"

inventory_sql=$(printf '%s\n' \
  "SELECT 'data.issue_attachments', COUNT(*) FROM issue_attachments" \
  "UNION ALL SELECT 'data.issues', COUNT(*) FROM issues" \
  "UNION ALL SELECT 'data.tenants', COUNT(*) FROM tenants" \
  "UNION ALL SELECT 'data.users', COUNT(*) FROM users" \
  "UNION ALL SELECT 'schema.migrations', COUNT(*) FROM doctrine_migration_versions" \
  "UNION ALL SELECT 'schema.rls_forced', COUNT(*) FROM pg_class" \
  "  WHERE relrowsecurity AND relforcerowsecurity" \
  "ORDER BY 1")
"$pg_bin_dir/psql" "${pg_args[@]}" -d "$restore_db" -At -F $'\t' \
  -c "$inventory_sql" > "$restore_workdir/database-inventory.tsv"
cmp --silent "$backup_dir/database-inventory.tsv" \
  "$restore_workdir/database-inventory.tsv"

tar -C "$attachment_restore" -xf "$backup_dir/attachments.tar"
attachment_count="$(find "$attachment_restore" -type f -printf '.' | wc -c)"
attachment_bytes="$(find "$attachment_restore" -type f -printf '%s\n' \
  | awk '{ total += $1 } END { print total + 0 }')"
printf 'files\t%s\nbytes\t%s\n' "$attachment_count" "$attachment_bytes" \
  > "$restore_workdir/attachments-inventory.tsv"
cmp --silent "$backup_dir/attachments-inventory.tsv" \
  "$restore_workdir/attachments-inventory.tsv"

"$pg_bin_dir/psql" "${pg_args[@]}" -d "$restore_db" -v ON_ERROR_STOP=1 -Atc \
  "SELECT 1 FROM tenants LIMIT 1" >/dev/null

duration="$(( $(date +%s) - started_at ))"
echo "Restore drill passed in ${duration}s."
echo "Database inventory, forced RLS count, and attachment bytes match the backup."
