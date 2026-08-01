#!/usr/bin/env bash

# Builds the production frontend bundle plus a no-dev backend and assembles a ZIP
# that mirrors the hosting layout of docs/NASADENIE.md:
#
#   sova-<version>/public_html/   -> copy into the document root of the hosting
#   sova-<version>/sova/          -> copy next to it, OUTSIDE the document root
#
# Backend code, .env and attachments must never be reachable over HTTP, so the
# package keeps them in a sibling directory rather than under the web root.
#
# The repository is never modified: backend sources are copied into the output
# directory before composer runs there, so backend/vendor keeps its dev packages.

set -euo pipefail
umask 022

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/.." && pwd)"
frontend_dir="$repo_root/frontend"
backend_dir="$repo_root/backend"

output_dir="$repo_root/build"
package_version=""
docroot_name="public_html"
private_name="sova"
run_verify=0

usage() {
  printf '%s\n' \
    "Usage: $0 [options]" \
    "" \
    "  -o, --output DIR     Where the package and the ZIP are written (default: build/)" \
    "  -v, --version TAG    Package version (default: <UTC date>-<commit>)" \
    "      --docroot NAME   Document root folder name (default: public_html)" \
    "      --private NAME   Folder kept outside the web root (default: sova)" \
    "      --verify         Run composer check and npm run check before building" \
    "  -h, --help           Show this help" \
    "" \
    "The result is <output>/sova-<version>/ and <output>/sova-<version>.zip."
}

die() {
  local code="$1"
  shift
  printf '%s\n' "$@" >&2
  exit "$code"
}

step() {
  printf '\n==> %s\n' "$1"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -o | --output)
      [[ $# -ge 2 && -n "$2" ]] || die 64 "Option $1 needs a value."
      output_dir="$2"
      shift 2
      ;;
    -v | --version)
      [[ $# -ge 2 && -n "$2" ]] || die 64 "Option $1 needs a value."
      package_version="$2"
      shift 2
      ;;
    --docroot)
      [[ $# -ge 2 && -n "$2" ]] || die 64 "Option $1 needs a value."
      docroot_name="$2"
      shift 2
      ;;
    --private)
      [[ $# -ge 2 && -n "$2" ]] || die 64 "Option $1 needs a value."
      private_name="$2"
      shift 2
      ;;
    --verify)
      run_verify=1
      shift
      ;;
    -h | --help)
      usage
      exit 0
      ;;
    *)
      usage >&2
      die 64 "" "Unknown argument: $1"
      ;;
  esac
done

for name in "$docroot_name" "$private_name"; do
  if [[ ! "$name" =~ ^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$ ]]; then
    die 64 "Directory name must be a plain relative name: $name"
  fi
done

if [[ "$docroot_name" == "$private_name" ]]; then
  die 64 "The document root and the private directory must differ."
fi

# ---------------------------------------------------------------------------
# Toolchain
# ---------------------------------------------------------------------------

for tool in php composer npm zip; do
  command -v "$tool" >/dev/null 2>&1 || die 69 "Required tool is missing: $tool"
done

version_at_least() {
  local IFS=.
  local -a have want
  read -r -a have <<<"$1"
  read -r -a want <<<"$2"

  local index
  for index in 0 1 2; do
    local left="${have[index]:-0}" right="${want[index]:-0}"
    [[ "$left" =~ ^[0-9]+$ ]] || left=0
    ((10#$left > 10#$right)) && return 0
    ((10#$left < 10#$right)) && return 1
  done

  return 0
}

# frontend/package.json engines: ^22.22.3 || ^24.15.0 || >=26.0.0
node_version_supported() {
  local version="${1#v}"
  local major="${version%%.*}"

  [[ "$major" =~ ^[0-9]+$ ]] || return 1

  case "$major" in
    22) version_at_least "$version" 22.22.3 ;;
    24) version_at_least "$version" 24.15.0 ;;
    *) ((major >= 26)) ;;
  esac
}

ensure_node() {
  local current=""

  if command -v node >/dev/null 2>&1; then
    current="$(node --version 2>/dev/null || true)"
  fi

  if [[ -n "$current" ]] && node_version_supported "$current"; then
    return 0
  fi

  # The Angular CLI aborts on an unsupported Node, so try the version pinned in
  # frontend/.nvmrc before giving up.
  local nvm_script="${NVM_DIR:-$HOME/.nvm}/nvm.sh"
  if [[ -s "$nvm_script" ]]; then
    printf 'Node %s is outside the engines range; switching through nvm.\n' "${current:-<missing>}"
    set +u
    # shellcheck disable=SC1090
    . "$nvm_script" >/dev/null 2>&1 || true
    (cd "$frontend_dir" && nvm install >/dev/null 2>&1) || true
    cd "$frontend_dir" && nvm use >/dev/null 2>&1 || true
    cd "$repo_root"
    set -u

    current="$(node --version 2>/dev/null || true)"
    if [[ -n "$current" ]] && node_version_supported "$current"; then
      return 0
    fi
  fi

  die 69 \
    "Node ${current:-<missing>} does not satisfy the frontend engines range." \
    "Install 22.22.3+, 24.15.0+ or 26+ (frontend/.nvmrc pins 24.15.0) and retry."
}

ensure_node

php_version="$(php -n -r 'echo PHP_VERSION;' 2>/dev/null | grep -oE '^[0-9]+\.[0-9]+\.[0-9]+' || true)"
[[ -n "$php_version" ]] || die 69 "Could not determine the PHP version."
version_at_least "$php_version" 8.3.0 || die 69 "PHP 8.3 or newer is required, found $php_version."

# Missing extensions do not change the package: composer installs the versions
# pinned in composer.lock. They only have to exist on the hosting (NASADENIE.md,
# krok 0), so report them and continue.
missing_extensions=()
for extension in json mbstring fileinfo pdo pdo_pgsql sodium zip; do
  php -r "exit(extension_loaded('$extension') ? 0 : 1);" >/dev/null 2>&1 ||
    missing_extensions+=("$extension")
done

# ---------------------------------------------------------------------------
# Package identity and output paths
# ---------------------------------------------------------------------------

git_commit="unknown"
git_state="unknown"
if git -C "$repo_root" rev-parse --git-dir >/dev/null 2>&1; then
  git_commit="$(git -C "$repo_root" rev-parse --short=7 HEAD 2>/dev/null || echo unknown)"
  if [[ -n "$(git -C "$repo_root" status --porcelain 2>/dev/null)" ]]; then
    git_state="modified"
  else
    git_state="clean"
  fi
fi

if [[ -z "$package_version" ]]; then
  package_version="$(date -u +%Y%m%d)-$git_commit"
  if [[ "$git_state" == "modified" ]]; then
    package_version="$package_version-dirty"
  fi
fi

if [[ ! "$package_version" =~ ^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$ ]]; then
  die 64 "Version may contain only letters, numbers, dots, underscores and dashes."
fi

mkdir -p -- "$output_dir"
output_dir="$(cd -- "$output_dir" && pwd)"
package_name="sova-$package_version"
package_dir="$output_dir/$package_name"
zip_path="$output_dir/$package_name.zip"

[[ "$package_dir" == "$output_dir/sova-"* ]] || die 73 "Refusing to write outside $output_dir."
rm -rf -- "$package_dir"
rm -f -- "$zip_path" "$zip_path.sha256"

docroot_out="$package_dir/$docroot_name"
private_out="$package_dir/$private_name"
backend_out="$private_out/backend"

printf 'SOVA hosting package\n'
printf '  version    : %s\n' "$package_version"
printf '  commit     : %s (%s)\n' "$git_commit" "$git_state"
printf '  output     : %s\n' "$package_dir"
printf '  layout     : %s/ (document root) + %s/ (outside the web root)\n' \
  "$docroot_name" "$private_name"
printf '  node / php : %s / %s\n' "$(node --version)" "$php_version"
if ((${#missing_extensions[@]} > 0)); then
  printf '  note       : PHP extensions missing locally: %s\n' "${missing_extensions[*]}"
  printf '               The package is unaffected; the hosting must provide them.\n'
fi

# ---------------------------------------------------------------------------
# Frontend
# ---------------------------------------------------------------------------

step "Frontend: installing dependencies (npm ci)"
(cd "$frontend_dir" && npm ci --no-audit --no-fund)

if ((run_verify)); then
  step "Frontend: npm run check (format, typecheck, contrast, tests, build)"
  (cd "$frontend_dir" && npm run check)
else
  step "Frontend: npm run build (production configuration)"
  (cd "$frontend_dir" && npm run build)
fi

browser_dir="$frontend_dir/dist/sova-frontend/browser"
if [[ ! -f "$browser_dir/index.html" ]]; then
  browser_dir="$(find "$frontend_dir/dist" -mindepth 2 -maxdepth 2 -type d -name browser -print -quit 2>/dev/null || true)"
fi
[[ -n "$browser_dir" && -f "$browser_dir/index.html" ]] ||
  die 70 "The frontend build produced no index.html under frontend/dist."

# ---------------------------------------------------------------------------
# Backend
# ---------------------------------------------------------------------------

if ((run_verify)); then
  step "Backend: composer check (validate, cs, phpstan, phpunit)"
  (cd "$backend_dir" && composer check)
fi

step "Backend: copying sources without tests, .env and var/"
mkdir -p -- "$backend_out"
for item in bin config migrations public src cli-config.php composer.json composer.lock .env.example; do
  [[ -e "$backend_dir/$item" ]] || die 66 "Backend is missing $item."
  cp -a -- "$backend_dir/$item" "$backend_out/"
done

step "Backend: composer install --no-dev"
# --ignore-platform-reqs: the target is the hosting PHP, not this machine. The
# lock file pins exact versions, so the installed tree is identical either way.
(
  cd "$backend_out" &&
    composer install \
      --no-dev \
      --optimize-autoloader \
      --no-interaction \
      --no-progress \
      --no-scripts \
      --prefer-dist \
      --ignore-platform-reqs
)

# ---------------------------------------------------------------------------
# Package layout
# ---------------------------------------------------------------------------

step "Assembling the package"

mkdir -p -- "$docroot_out/api" "$backend_out/var" "$private_out/attachments"
cp -a -- "$browser_dir/." "$docroot_out/"

# FTP clients drop empty directories; the application needs both to exist.
printf '%s\n' "Runtime directory for logs. Do not delete." >"$backend_out/var/.keep"
printf '%s\n' "Attachment storage. Do not delete, do not publish over HTTP." \
  >"$private_out/attachments/.keep"

cat >"$docroot_out/api/index.php" <<'PHP'
<?php

declare(strict_types=1);

use Slim\App;

// Jediný vstupný bod API. $backendRoot musí ukazovať na priečinok backend/
// mimo document rootu; pri inej štruktúre upravte iba tento riadok.
$backendRoot = dirname(__DIR__, 2) . '/__PRIVATE_DIR__/backend';

/** @var App $app */
$app = require $backendRoot . '/config/bootstrap.php';
$app->run();
PHP
sed -i "s|__PRIVATE_DIR__|$private_name|" "$docroot_out/api/index.php"

cat >"$docroot_out/api/.htaccess" <<'APACHE'
# Každá požiadavka na /api/... ide do front controllera.
Options -Indexes

DirectoryIndex index.php

RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
APACHE

cat >"$docroot_out/.htaccess" <<'APACHE'
# SOVA – document root. Generované scripts/build-hosting-package.sh.
# Ak hosting vráti 500, zakomentujte riadok Options (nemá povolený AllowOverride).
Options -Indexes -MultiViews

DirectoryIndex index.html

<FilesMatch "^\.">
    Require all denied
</FilesMatch>

RewriteEngine On

# Vynútené HTTPS. Obe podmienky musia platiť naraz: za reverznou proxy je
# %{HTTPS} prázdne a samotná prvá podmienka by presmerovanie zacyklila.
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !=https
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

# /api si obsluhuje vlastný .htaccess.
RewriteRule ^api/ - [L]

# Existujúce súbory a priečinky sa servírujú priamo.
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Všetko ostatné obslúži Angular.
RewriteRule ^ index.html [L]

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), geolocation=(), microphone=()"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

    # Súbory s hashom v názve sa nikdy nemenia, index.html sa meniť musí.
    <FilesMatch "-[A-Za-z0-9_-]{8,}\.(?:js|css)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
    <FilesMatch "^index\.html$">
        Header set Cache-Control "no-cache"
    </FilesMatch>
    <FilesMatch "^(?:theme-init\.js|favicon\.ico)$">
        Header set Cache-Control "public, max-age=3600"
    </FilesMatch>
</IfModule>

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/javascript application/json application/problem+json text/css text/html text/plain
</IfModule>
APACHE

# --- .env template -----------------------------------------------------------

env_template="$backend_out/.env.production.template"

set_env_key() {
  local file="$1" key="$2" value="$3"

  awk -v key="$key" -v value="$value" '
    index($0, key "=") == 1 { print key "=" value; found = 1; next }
    { print }
    END { if (!found) print key "=" value }
  ' "$file" >"$file.tmp"
  mv -- "$file.tmp" "$file"
}

cp -- "$backend_out/.env.example" "$env_template"
set_env_key "$env_template" APP_ENV production
set_env_key "$env_template" APP_DEBUG false
set_env_key "$env_template" APP_VERSION "$package_version"
set_env_key "$env_template" APP_PUBLIC_URL https://CHANGE_ME.example.sk
set_env_key "$env_template" LOG_LEVEL warning
set_env_key "$env_template" LOG_PATH "/home/CHANGE_ME/$private_name/backend/var/app.log"
set_env_key "$env_template" DATABASE_HOST CHANGE_ME
set_env_key "$env_template" DATABASE_PORT 5432
set_env_key "$env_template" DATABASE_NAME CHANGE_ME
set_env_key "$env_template" DATABASE_USER CHANGE_ME
set_env_key "$env_template" DATABASE_PASSWORD CHANGE_ME
set_env_key "$env_template" DATABASE_SERVER_VERSION CHANGE_ME
set_env_key "$env_template" DATABASE_SSL_MODE prefer
set_env_key "$env_template" AUTH_COOKIE_SECURE true
set_env_key "$env_template" AUTH_RATE_LIMIT_SECRET CHANGE_ME
set_env_key "$env_template" SENSITIVE_PAYLOAD_KEY_ID prod-v1
set_env_key "$env_template" SENSITIVE_PAYLOAD_KEY CHANGE_ME
set_env_key "$env_template" ATTACHMENT_STORAGE_PATH "/home/CHANGE_ME/$private_name/attachments"
set_env_key "$env_template" MAILER_DSN "smtp://CHANGE_ME:CHANGE_ME@smtp.example.sk:587"
set_env_key "$env_template" MAILER_FROM sova@CHANGE_ME.example.sk
set_env_key "$env_template" CORS_ALLOWED_ORIGINS https://CHANGE_ME.example.sk

cat >"$env_template.header" <<'ENV'
# SOVA – produkčná konfigurácia. Skopírujte tento súbor ako .env a nahraďte
# každé CHANGE_ME. Podrobnosti sú v NASADENIE.md, krok 5.
#
#   cp .env.production.template .env
#   chmod 600 .env
#
# Tajomstvá vygenerujte a uložte do správcu hesiel:
#   openssl rand -hex 32      -> AUTH_RATE_LIMIT_SECRET
#   openssl rand -base64 32   -> SENSITIVE_PAYLOAD_KEY
#
# DATABASE_SERVER_VERSION zadajte podľa skutočnej verzie servera (napr. 16).
# Ak sú v hesle v MAILER_DSN znaky @ : / #, zakódujte ich percentom (@ = %40).
# ATTACHMENT_SCANNER zostáva "none": prílohy budú hlásiť chybu, zvyšok aplikácie
# funguje. Prečítajte si kapitolu o obmedzeniach v NASADENIE.md.

ENV
cat -- "$env_template.header" "$env_template" >"$env_template.tmp"
mv -- "$env_template.tmp" "$env_template"
rm -f -- "$env_template.header"

# --- documentation shipped with the package ----------------------------------

cp -- "$repo_root/docs/NASADENIE.md" "$package_dir/NASADENIE.md"

cat >"$package_dir/crontab.example" <<'CRON'
# SOVA – úlohy na pozadí. Bez nich sa neodošle žiadny e-mail (NASADENIE.md, krok 8).
# Cestu k PHP 8.3 a domovský priečinok nahraďte skutočnými hodnotami; cron má
# takmer vždy inú verziu PHP než web, preto sa píše plná cesta.

* * * * * cd /home/CHANGE_ME/__PRIVATE__/backend && /usr/local/php83/bin/php bin/outbox-worker.php --once >/dev/null 2>&1
* * * * * cd /home/CHANGE_ME/__PRIVATE__/backend && /usr/local/php83/bin/php bin/email-worker.php --once >/dev/null 2>&1
CRON

cat >"$package_dir/CITAJ-MA.txt" <<'README'
SOVA – balík na nasadenie na webhosting
=======================================

Verzia balíka : __VERSION__
Zostavené     : __BUILT_AT__ z commitu __COMMIT__ (__STATE__)

Balík obsahuje dve časti a obe treba nahrať:

  __DOCROOT__/   -> obsah nakopírujte do document rootu hostingu
  __PRIVATE__/   -> nakopírujte VEDĽA document rootu, teda mimo webu

Prečo mimo webu: v __PRIVATE__/backend/.env sú heslá k databáze a k SMTP
a v __PRIVATE__/attachments/ ležia prílohy používateľov. Ak sa dostanú pod
document root, ktokoľvek si ich stiahne z prehliadača.

Cieľový stav na hostingu
------------------------

  /home/pouzivatel/
  ├── __PRIVATE__/
  │   ├── attachments/
  │   └── backend/
  │       ├── bin/ config/ migrations/ public/ src/ vendor/ var/
  │       ├── .env                      <- vytvoríte z .env.production.template
  │       └── .env.production.template
  └── __DOCROOT__/
      ├── index.html, main-*.js, styles-*.css, favicon.ico, theme-init.js
      ├── .htaccess
      └── api/
          ├── index.php
          └── .htaccess

Ak sa __PRIVATE__/ a __DOCROOT__/ nebudú na hostingu nachádzať vedľa seba,
upravte cestu v premennej $backendRoot v súbore __DOCROOT__/api/index.php.

Rýchly postup (plné znenie je v NASADENIE.md)
---------------------------------------------

 1. Krok 0 v NASADENIE.md: overte PHP 8.3+, rozšírenia pdo_pgsql a sodium,
    PostgreSQL 14+ s rozšírením pg_trgm a databázový účet BEZ superuser
    a BEZ bypassrls. Bez toho nemá zmysel pokračovať.
 2. Nahrajte obe časti balíka podľa stromu vyššie (FTP alebo SSH).
 3. Vytvorte priečinky na zápis, ak ich prenos nevytvoril:
      mkdir -p ~/__PRIVATE__/attachments ~/__PRIVATE__/backend/var
      chmod 750 ~/__PRIVATE__/attachments ~/__PRIVATE__/backend/var
 4. Konfigurácia:
      cd ~/__PRIVATE__/backend
      cp .env.production.template .env
      chmod 600 .env
      # nahraďte každé CHANGE_ME (návod je v hlavičke súboru)
 5. Databázová schéma:
      php vendor/bin/doctrine-migrations migrations:migrate --no-interaction
 6. Prvý administrátor (heslo aspoň 15 znakov, spustiteľné iba raz):
      read -rsp "Heslo: " SOVA_PWD; printf '\n'
      printf '%s\n' "$SOVA_PWD" | php bin/bootstrap-superadmin.php \
        --email=admin@example.sk --display-name="Administrator" --locale=sk
      unset SOVA_PWD
 7. Dve cron úlohy každú minútu podľa crontab.example. Bez nich neodíde
    žiadny e-mail.
 8. Overenie:
      curl https://vasa-domena.sk/api/v1/health/live
      curl https://vasa-domena.sk/api/v1/health/ready
    Druhá odpoveď musí obsahovať "status":"ready" aj
    "tenant_isolation":"enforced". Ak nie, databázový účet je príliš
    privilegovaný – vráťte sa ku kroku 0.

Aktualizácia existujúcej inštalácie
-----------------------------------

Nikdy neprepisujte .env, priečinok attachments/ ani databázu. Poradie:
zálohovať -> nahrať nový backend -> spustiť migrácie -> až potom vymeniť
obsah __DOCROOT__. Detaily sú v NASADENIE.md, kapitola "Aktualizácia".

Čo v balíku zámerne nie je
--------------------------

Súbor .env (obsahuje tajomstvá, vytvára sa na hostingu), testy, vývojárske
Composer balíky a node_modules. Prílohy sa na klasickom webhostingu nedajú
nahrávať, lebo produkčný režim vyžaduje antivírusový skener – vysvetlenie
a možnosti sú v NASADENIE.md.
README

# --- build info --------------------------------------------------------------

built_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

sed -i \
  -e "s|__VERSION__|$package_version|g" \
  -e "s|__BUILT_AT__|$built_at|g" \
  -e "s|__COMMIT__|$git_commit|g" \
  -e "s|__STATE__|$git_state|g" \
  -e "s|__DOCROOT__|$docroot_name|g" \
  -e "s|__PRIVATE__|$private_name|g" \
  "$package_dir/CITAJ-MA.txt" "$package_dir/crontab.example"

cat >"$package_dir/BUILD-INFO.txt" <<INFO
package_version=$package_version
built_at_utc=$built_at
git_commit=$git_commit
git_state=$git_state
document_root_dir=$docroot_name
private_dir=$private_name
node=$(node --version)
npm=$(npm --version)
php=$php_version
composer=$(composer --version --no-interaction 2>/dev/null | sed -n 1p)
quality_gates=$( ((run_verify)) && echo "composer check + npm run check" || echo "skipped (--verify not used)")
migrations=$(find "$backend_out/migrations" -name 'Version*.php' -type f | wc -l | tr -d ' ')
INFO

# ---------------------------------------------------------------------------
# Sanity checks – a wrong package is worse than no package
# ---------------------------------------------------------------------------

step "Verifying the package"

[[ -f "$backend_out/vendor/autoload.php" ]] || die 70 "vendor/autoload.php is missing."
[[ -f "$docroot_out/index.html" ]] || die 70 "index.html is missing from the document root."
[[ -f "$docroot_out/.htaccess" && -f "$docroot_out/api/.htaccess" ]] ||
  die 70 "An .htaccess file is missing."
[[ ! -d "$backend_out/tests" ]] || die 70 "Test sources leaked into the package."

if [[ -n "$(find "$package_dir" -name '.env' -print -quit)" ]]; then
  die 70 "A real .env file leaked into the package."
fi

for dev_package in phpunit phpstan friendsofphp; do
  [[ ! -d "$backend_out/vendor/$dev_package" ]] ||
    die 70 "Development dependency leaked into the package: $dev_package"
done

grep -q 'CHANGE_ME' "$env_template" || die 70 "The .env template lost its placeholders."
grep -q "/$private_name/backend" "$docroot_out/api/index.php" ||
  die 70 "The API entry point does not point at the backend."

php -l "$docroot_out/api/index.php" >/dev/null 2>&1 ||
  die 70 "The generated API entry point is not valid PHP."

# ---------------------------------------------------------------------------
# ZIP
# ---------------------------------------------------------------------------

step "Creating the ZIP archive"
(cd "$output_dir" && zip --quiet --recurse-paths -X -9 "$package_name.zip" "$package_name")
(cd "$output_dir" && sha256sum "$package_name.zip" >"$package_name.zip.sha256")

# Read the listing into a variable: piping into grep -q closes the pipe early,
# which pipefail would report as a failing archive.
if command -v unzip >/dev/null 2>&1; then
  archive_listing="$(unzip -Z1 "$zip_path")"
  for required in \
    "$package_name/CITAJ-MA.txt" \
    "$package_name/$docroot_name/.htaccess" \
    "$package_name/$docroot_name/index.html" \
    "$package_name/$docroot_name/api/.htaccess" \
    "$package_name/$docroot_name/api/index.php" \
    "$package_name/$private_name/backend/vendor/autoload.php" \
    "$package_name/$private_name/backend/.env.production.template"; do
    grep -Fqx -- "$required" <<<"$archive_listing" ||
      die 70 "The archive does not contain: $required"
  done
fi

docroot_files="$(find "$docroot_out" -type f | wc -l | tr -d ' ')"
backend_files="$(find "$backend_out" -type f | wc -l | tr -d ' ')"

printf '\n'
printf 'Package : %s (%s)\n' "$package_dir" "$(du -sh -- "$package_dir" | cut -f1)"
printf 'Archive : %s (%s)\n' "$zip_path" "$(du -sh -- "$zip_path" | cut -f1)"
printf 'SHA-256 : %s\n' "$(cut -d' ' -f1 <"$zip_path.sha256")"
printf 'Contents: %s files in %s/, %s files in %s/backend/\n' \
  "$docroot_files" "$docroot_name" "$backend_files" "$private_name"
printf '\n'
printf 'Next: unpack the archive, upload %s/ into the document root and\n' "$docroot_name"
printf '%s/ next to it, outside the web root. Follow CITAJ-MA.txt.\n' "$private_name"

if ((run_verify == 0)); then
  printf '\nQuality gates were skipped. Re-run with --verify to build through\n'
  printf 'composer check and npm run check.\n'
fi
