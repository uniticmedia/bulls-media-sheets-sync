#!/usr/bin/env bash
#
# Sets up Sheets Sync on a fresh Ubuntu 22.04 / 24.04 server.
#
#   sudo ./scripts/setup-ubuntu.sh [--with-demo] [--with-nginx] [--production]
#
# What it does (safe to run again):
#   1. installs PHP 8.3 + extensions, MySQL, Git and Composer
#   2. creates the MySQL database and user
#   3. installs PHP dependencies, writes .env, generates the application key
#   4. runs the database migrations (and optionally seeds the demo mapping)
#   5. optionally installs nginx + PHP-FPM and serves the admin UI on port 80
#   6. installs the cron entry that runs Laravel's scheduler (= the daily sync)
#
# Optional environment variables: DB_NAME, DB_USER, DB_PASS (hex/alphanumeric only).

set -euo pipefail

usage() {
    sed -n '3,15p' "$0" | sed 's/^# \{0,1\}//'
}

WITH_DEMO=0
WITH_NGINX=0
PRODUCTION=0

for arg in "$@"; do
    case "$arg" in
        --with-demo) WITH_DEMO=1 ;;
        --with-nginx) WITH_NGINX=1 ;;
        --production) PRODUCTION=1 ;;
        -h | --help) usage; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; usage; exit 1 ;;
    esac
done

if [[ $EUID -ne 0 ]]; then
    echo "Please run as root: sudo $0 $*" >&2
    exit 1
fi

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$APP_DIR/.env"

if [[ ! -f "$APP_DIR/artisan" ]]; then
    echo "artisan not found in $APP_DIR - run this script from inside the project (scripts/setup-ubuntu.sh)." >&2
    exit 1
fi

# The unprivileged user that owns the code and runs composer and artisan.
APP_USER="${SUDO_USER:-$(stat -c '%U' "$APP_DIR")}"
if [[ "$APP_USER" == "root" ]]; then
    APP_USER="www-data"
fi
# The user that runs the scheduler (cron) and, with --with-nginx, PHP-FPM. Becomes www-data in nginx mode.
RUN_USER="$APP_USER"

PHP_VERSION="8.3"
DB_NAME="${DB_NAME:-sheets_sync}"
DB_USER="${DB_USER:-sheets_sync}"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

env_get() {
    # Prints the value of KEY from .env (empty if missing), without surrounding quotes.
    local key="$1"
    [[ -f "$ENV_FILE" ]] || return 0
    grep -E "^${key}=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

env_set() {
    local key="$1" value="$2" escaped
    if grep -qE "^#?[[:space:]]*${key}=" "$ENV_FILE"; then
        # Escape the characters that are special in a sed replacement.
        escaped="${value//\\/\\\\}"
        escaped="${escaped//&/\\&}"
        escaped="${escaped//|/\\|}"
        sed -i -E "s|^#?[[:space:]]*${key}=.*|${key}=${escaped}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

as_app() {
    sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && $*"
}

# ---------------------------------------------------------------------------
step "1/6 Installing system packages (PHP ${PHP_VERSION}, MySQL, Composer)"
# ---------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common ca-certificates curl unzip git openssl cron

if ! apt-cache policy "php${PHP_VERSION}-cli" | grep -q 'Candidate: [0-9]'; then
    # Ubuntu 22.04 ships PHP 8.1 only; Laravel needs 8.2+, so use the maintained PPA.
    add-apt-repository -y ppa:ondrej/php
    apt-get update -y
fi

apt-get install -y \
    "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" \
    mysql-server

update-alternatives --set php "/usr/bin/php${PHP_VERSION}" >/dev/null 2>&1 || true

systemctl enable --now mysql
systemctl enable --now cron

if ! command -v composer >/dev/null 2>&1; then
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    if [[ "$expected" != "$actual" ]]; then
        rm -f /tmp/composer-setup.php
        echo "Composer installer checksum mismatch - aborting." >&2
        exit 1
    fi
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

# ---------------------------------------------------------------------------
step "2/6 Creating the MySQL database and user"
# ---------------------------------------------------------------------------
chown -R "$APP_USER": "$APP_DIR"
[[ -f "$ENV_FILE" ]] || as_app "cp .env.example .env"

DB_PASS="${DB_PASS:-$(env_get DB_PASSWORD)}"
if [[ -z "$DB_PASS" ]]; then
    DB_PASS="$(openssl rand -hex 16)"
fi

mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------------------
step "3/6 Configuring the application (.env, dependencies, key)"
# ---------------------------------------------------------------------------
ADMIN_PASSWORD="$(env_get SHEETS_ADMIN_PASSWORD)"
ADMIN_PASSWORD_IS_NEW=0
if [[ -z "$ADMIN_PASSWORD" ]]; then
    ADMIN_PASSWORD="$(openssl rand -hex 12)"
    ADMIN_PASSWORD_IS_NEW=1
fi

if [[ $PRODUCTION -eq 1 ]]; then
    env_set APP_ENV production
    env_set APP_DEBUG false
else
    env_set APP_ENV local
    env_set APP_DEBUG true
fi

env_set DB_CONNECTION mysql
env_set DB_HOST 127.0.0.1
env_set DB_PORT 3306
env_set DB_DATABASE "$DB_NAME"
env_set DB_USERNAME "$DB_USER"
env_set DB_PASSWORD "$DB_PASS"
env_set GOOGLE_SHEETS_CREDENTIALS "$APP_DIR/storage/app/google/service-account.json"
[[ -n "$(env_get SHEETS_SYNC_TIME)" ]] || env_set SHEETS_SYNC_TIME "02:00"
[[ -n "$(env_get SHEETS_ADMIN_USER)" ]] || env_set SHEETS_ADMIN_USER "admin"
env_set SHEETS_ADMIN_PASSWORD "$ADMIN_PASSWORD"

chown "$APP_USER": "$ENV_FILE"
chmod 640 "$ENV_FILE"

mkdir -p "$APP_DIR/storage/app/google"

if [[ $PRODUCTION -eq 1 ]]; then
    as_app "composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader"
else
    as_app "composer install --no-interaction --prefer-dist"
fi

if [[ -z "$(env_get APP_KEY)" ]]; then
    as_app "php artisan key:generate --force"
fi

# ---------------------------------------------------------------------------
step "4/6 Running database migrations"
# ---------------------------------------------------------------------------
as_app "php artisan migrate --force"

if [[ $WITH_DEMO -eq 1 ]]; then
    as_app "php artisan db:seed --force"
fi

if [[ $PRODUCTION -eq 1 ]]; then
    as_app "php artisan optimize"
fi

# ---------------------------------------------------------------------------
step "5/6 Web server"
# ---------------------------------------------------------------------------
if [[ $WITH_NGINX -eq 1 ]]; then
    apt-get install -y nginx "php${PHP_VERSION}-fpm"

    if [[ "$APP_DIR" == /home/* ]]; then
        echo "WARNING: $APP_DIR is under /home, which nginx usually cannot read. Move the project to /var/www/ and run this script again." >&2
    fi

    # PHP-FPM runs as www-data. It (and the scheduler, which then also runs as www-data) must be able to
    # write to storage/ and bootstrap/cache/ and to read .env and the Google key. The code itself stays
    # owned by ${APP_USER} and is only readable by www-data.
    RUN_USER="www-data"
    chown -R "$RUN_USER": "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    chgrp "$RUN_USER" "$ENV_FILE"
    chmod 640 "$ENV_FILE"

    cat > /etc/nginx/sites-available/sheets-sync <<NGINX
server {
    listen 80 default_server;
    server_name _;
    root ${APP_DIR}/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
    ln -sf /etc/nginx/sites-available/sheets-sync /etc/nginx/sites-enabled/sheets-sync
    rm -f /etc/nginx/sites-enabled/default
    nginx -t
    systemctl enable --now "php${PHP_VERSION}-fpm"
    systemctl reload nginx
else
    chown -R "$RUN_USER": "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    echo "Skipped (use --with-nginx, or run: php artisan serve --host=0.0.0.0 --port=8000)."
fi

chmod 700 "$APP_DIR/storage/app/google"

# ---------------------------------------------------------------------------
step "6/6 Installing the cron entry for the Laravel scheduler"
# ---------------------------------------------------------------------------
PHP_BIN="$(command -v php)"
cat > /etc/cron.d/sheets-sync <<CRON
# Laravel scheduler for Sheets Sync. It runs the daily Google Sheets import (see routes/console.php).
* * * * * ${RUN_USER} cd ${APP_DIR} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/sheets-sync

# ---------------------------------------------------------------------------
cat <<DONE

Sheets Sync is installed in ${APP_DIR}

  Admin UI user      : $(env_get SHEETS_ADMIN_USER)
  Admin UI password  : ${ADMIN_PASSWORD}$([[ $ADMIN_PASSWORD_IS_NEW -eq 1 ]] || echo "  (unchanged, already in .env)")
  Database           : ${DB_NAME} (user ${DB_USER}; password stored in .env)
  Daily run          : $(env_get SHEETS_SYNC_TIME) server time, as user ${RUN_USER} via /etc/cron.d/sheets-sync

Next steps
  1. Create a Google service account and download its JSON key (README, "Google API credentials").
  2. Install the key so the user that runs the sync (${RUN_USER}) can read it:
       sudo cp key.json ${APP_DIR}/storage/app/google/service-account.json
       sudo chown ${RUN_USER}: ${APP_DIR}/storage/app/google/service-account.json
       sudo chmod 600 ${APP_DIR}/storage/app/google/service-account.json
  3. Share your spreadsheets with the service account's e-mail address (Viewer).
  4. Open the UI, add the files and mappings, then click "Run all now" (or: php artisan sheets:sync).
DONE
