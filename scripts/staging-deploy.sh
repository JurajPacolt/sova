#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/.." && pwd)"
compose_file="$repo_root/compose.staging.yml"
env_file="${SOVA_STAGING_ENV_FILE:-$repo_root/deploy/staging/.env}"
last_release_file="$repo_root/deploy/staging/.last-successful-release"
previous_release_file="$repo_root/deploy/staging/.previous-release"

usage() {
  printf '%s\n' \
    "Usage:" \
    "  $0 deploy RELEASE_TAG" \
    "  $0 rollback RELEASE_TAG" \
    "  $0 verify [RELEASE_TAG]" \
    "  $0 status [RELEASE_TAG]"
}

if [[ $# -lt 1 || $# -gt 2 ]]; then
  usage >&2
  exit 64
fi

action="$1"
release_tag="${2:-}"

if [[ ! -f "$env_file" ]]; then
  echo "Staging environment file is missing: $env_file" >&2
  echo "Copy deploy/staging/.env.example and replace every CHANGE_ME value." >&2
  exit 66
fi

if grep -q 'CHANGE_ME' "$env_file"; then
  echo "Staging environment still contains a CHANGE_ME placeholder." >&2
  exit 78
fi

read_env_value() {
  local key="$1"
  local fallback="$2"
  local value=""

  value="$(sed -n "s/^${key}=//p" "$env_file" | tail -n 1)"
  value="${value#\"}"
  value="${value%\"}"

  if [[ -z "$value" ]]; then
    value="$fallback"
  fi

  printf '%s' "$value"
}

if [[ -z "$release_tag" ]]; then
  release_tag="$(read_env_value SOVA_IMAGE_TAG '')"
fi

if [[ ! "$release_tag" =~ ^[0-9A-Za-z][0-9A-Za-z._-]{0,127}$ ]]; then
  echo "Release tag must contain only letters, numbers, dots, underscores, and dashes." >&2
  exit 64
fi

compose() {
  SOVA_IMAGE_TAG="$release_tag" docker compose \
    --env-file "$env_file" \
    --file "$compose_file" \
    "$@"
}

probe() {
  local port
  local response
  port="$(read_env_value SOVA_HTTP_PORT 8080)"

  curl --fail --silent --show-error \
    "http://127.0.0.1:${port}/api/v1/health/live" >/dev/null
  response="$(curl --fail --silent --show-error \
    "http://127.0.0.1:${port}/api/v1/health/ready")"

  if [[ "$response" != *'"status":"ready"'* \
    || "$response" != *'"tenant_isolation":"enforced"'* ]]; then
    echo "Readiness did not confirm database and tenant isolation." >&2
    echo "$response" >&2
    return 1
  fi

  curl --fail --silent --show-error \
    "http://127.0.0.1:${port}/" >/dev/null

  compose exec -T postgres sh -eu -c '
    missing_force="$(psql \
      --username "$POSTGRES_USER" \
      --dbname "$POSTGRES_DB" \
      --tuples-only \
      --no-align \
      --command "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = '\''public'\'' AND c.relrowsecurity AND NOT c.relforcerowsecurity")"
    forced="$(psql \
      --username "$POSTGRES_USER" \
      --dbname "$POSTGRES_DB" \
      --tuples-only \
      --no-align \
      --command "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = '\''public'\'' AND c.relforcerowsecurity")"
    test "$missing_force" = "0"
    test "$forced" -gt 0
  '

  compose ps
  echo "Staging verification passed for release $release_tag."
}

case "$action" in
  deploy)
    if docker image inspect "sova-app:$release_tag" >/dev/null 2>&1 \
      || docker image inspect "sova-test:$release_tag" >/dev/null 2>&1; then
      echo "Release image already exists and will not be overwritten: $release_tag" >&2
      exit 73
    fi

    compose config --quiet
    compose --profile test build --pull test
    compose build web
    compose --profile test run --rm test
    compose up --detach --wait
    probe

    if [[ -f "$last_release_file" ]]; then
      cp -- "$last_release_file" "$previous_release_file"
    fi
    printf '%s\n' "$release_tag" > "$last_release_file"
    ;;
  rollback)
    if ! docker image inspect "sova-app:$release_tag" >/dev/null 2>&1; then
      echo "Rollback image is not present locally: sova-app:$release_tag" >&2
      exit 66
    fi

    compose config --quiet
    compose up --detach --no-build --no-deps --wait \
      web outbox-worker email-worker
    probe
    printf '%s\n' "$release_tag" > "$last_release_file"
    ;;
  verify)
    probe
    ;;
  status)
    compose ps
    ;;
  *)
    usage >&2
    exit 64
    ;;
esac
