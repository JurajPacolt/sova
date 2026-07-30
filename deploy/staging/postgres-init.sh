#!/usr/bin/env bash

set -euo pipefail

: "${SOVA_DATABASE_USER:?SOVA_DATABASE_USER is required}"
: "${SOVA_DATABASE_PASSWORD:?SOVA_DATABASE_PASSWORD is required}"

psql \
  --set=ON_ERROR_STOP=1 \
  --username "$POSTGRES_USER" \
  --dbname "$POSTGRES_DB" \
  --set=app_user="$SOVA_DATABASE_USER" \
  --set=app_password="$SOVA_DATABASE_PASSWORD" \
  --set=database_name="$POSTGRES_DB" <<'SQL'
CREATE ROLE :"app_user"
    LOGIN
    PASSWORD :'app_password'
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOINHERIT
    NOBYPASSRLS;

ALTER DATABASE :"database_name" OWNER TO :"app_user";
ALTER SCHEMA public OWNER TO :"app_user";
GRANT ALL ON SCHEMA public TO :"app_user";
SQL

