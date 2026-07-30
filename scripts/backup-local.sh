#!/usr/bin/env bash

set -euo pipefail
umask 077

usage() {
  echo "Usage: SOVA_PG_BIN_DIR=/path/to/postgresql/17/bin $0 BACKUP_DIRECTORY" >&2
}

if [[ $# -ne 1 || -z "$1" || "$1" == "/" || "$1" == "." || "$1" == ".." ]]; then
  usage
  exit 64
fi

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/.." && pwd)"
env_file="${SOVA_ENV_FILE:-$repo_root/backend/.env}"
target="$1"
target_parent="$(dirname -- "$target")"
target_name="$(basename -- "$target")"

if [[ ! -d "$target_parent" ]]; then
  echo "Backup parent does not exist: $target_parent" >&2
  exit 66
fi

if [[ -e "$target" ]]; then
  echo "Backup target already exists and will not be overwritten: $target" >&2
  exit 73
fi

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
db_name="${DATABASE_NAME:-$(read_env_value DATABASE_NAME sova)}"
db_user="${DATABASE_USER:-$(read_env_value DATABASE_USER sova)}"
export PGPASSWORD="${DATABASE_PASSWORD:-$(read_env_value DATABASE_PASSWORD '')}"
attachment_path="${ATTACHMENT_STORAGE_PATH:-$(read_env_value ATTACHMENT_STORAGE_PATH "$repo_root/backend/var/attachments")}"

if [[ "$attachment_path" != /* ]]; then
  attachment_path="$repo_root/backend/$attachment_path"
fi

pg_bin_dir="${SOVA_PG_BIN_DIR:-$(dirname -- "$(command -v pg_dump)")}"
for tool in pg_dump psql; do
  if [[ ! -x "$pg_bin_dir/$tool" ]]; then
    echo "Required PostgreSQL tool is missing: $pg_bin_dir/$tool" >&2
    exit 69
  fi
done

pg_args=(-h "$db_host" -p "$db_port" -U "$db_user")
server_major="$("$pg_bin_dir/psql" "${pg_args[@]}" -d "$db_name" -Atc \
  "SELECT current_setting('server_version_num')::integer / 10000")"
client_major="$("$pg_bin_dir/pg_dump" --version | sed -E 's/.* ([0-9]+)\..*/\1/')"

if [[ "$client_major" -ne "$server_major" ]]; then
  echo "pg_dump $client_major must match PostgreSQL $server_major; set SOVA_PG_BIN_DIR." >&2
  exit 69
fi

staging="$(mktemp -d "$target_parent/.${target_name}.staging.XXXXXX")"
cleanup() {
  if [[ -n "${staging:-}" && -d "$staging" ]]; then
    rm -rf -- "$staging"
  fi
}
trap cleanup EXIT

"$pg_bin_dir/pg_dump" "${pg_args[@]}" -d "$db_name" \
  --format=custom --compress=9 --no-owner --no-privileges \
  --file="$staging/database.dump"

inventory_sql=$(printf '%s\n' \
  "SELECT 'data.issue_attachments', COUNT(*) FROM issue_attachments" \
  "UNION ALL SELECT 'data.issues', COUNT(*) FROM issues" \
  "UNION ALL SELECT 'data.tenants', COUNT(*) FROM tenants" \
  "UNION ALL SELECT 'data.users', COUNT(*) FROM users" \
  "UNION ALL SELECT 'schema.migrations', COUNT(*) FROM doctrine_migration_versions" \
  "UNION ALL SELECT 'schema.rls_forced', COUNT(*) FROM pg_class" \
  "  WHERE relrowsecurity AND relforcerowsecurity" \
  "ORDER BY 1")
"$pg_bin_dir/psql" "${pg_args[@]}" -d "$db_name" -At -F $'\t' \
  -c "$inventory_sql" > "$staging/database-inventory.tsv"

if [[ -d "$attachment_path" ]]; then
  if [[ -n "$(find "$attachment_path" -type l -print -quit)" ]]; then
    echo "Attachment storage contains a symbolic link and cannot be backed up safely." >&2
    exit 65
  fi

  tar -C "$attachment_path" -cf "$staging/attachments.tar" .
  attachment_count="$(find "$attachment_path" -type f -printf '.' | wc -c)"
  attachment_bytes="$(find "$attachment_path" -type f -printf '%s\n' \
    | awk '{ total += $1 } END { print total + 0 }')"
else
  mkdir "$staging/empty-attachments"
  tar -C "$staging/empty-attachments" -cf "$staging/attachments.tar" .
  rmdir "$staging/empty-attachments"
  attachment_count=0
  attachment_bytes=0
fi
printf 'files\t%s\nbytes\t%s\n' "$attachment_count" "$attachment_bytes" \
  > "$staging/attachments-inventory.tsv"

printf 'format_version\t1\ncreated_at_utc\t%s\npostgres_major\t%s\npostgres_client_major\t%s\n' \
  "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$server_major" "$client_major" \
  > "$staging/metadata.tsv"

(
  cd "$staging"
  sha256sum database.dump database-inventory.tsv attachments.tar \
    attachments-inventory.tsv metadata.tsv > SHA256SUMS
)

mv -- "$staging" "$target"
staging=""
trap - EXIT

echo "Backup created: $target"
