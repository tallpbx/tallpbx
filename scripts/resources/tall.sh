#!/bin/bash
# ==============================================================================
# TALL Stack (Laravel, Livewire, Tailwind CSS, DaisyUI)
# ==============================================================================
# This step installs Composer (PHP package manager), deploys the cloned
# FreeSwitchPBX application, and sets up its frontend dependencies.
#
# Safe to re-run: application source is deployed only when the FreeSwitchPBX
# marker is missing, and the .env file is only created when absent.
# ==============================================================================

# Stop this application setup as soon as a command fails. Continuing after a
# migration or seed failure can leave an incomplete installation looking ready.
set -e

# Work from known absolute paths so this step behaves the same whether it is
# started by the main installer or run directly by an administrator.
script_directory=$(cd "$(dirname "$0")" && pwd)
source_root=$(cd "$script_directory/../.." && pwd)
application_root=/var/www/tallpbx

cd "$script_directory"

. ./config.sh
. ./colors.sh
. ./environment.sh

# Use the database password from the main installer if available
if [ -n "$FSPBX_DB_PASSWORD" ]; then
    database_password="$FSPBX_DB_PASSWORD"
fi

# Development hosts retain Composer dev packages, including Laravel Boost.
# Production hosts only need runtime dependencies and compiled frontend assets.
development_mode="${FSPBX_DEVELOPMENT_MODE:-false}"

# Install Composer before Laravel dependencies are installed. Its published
# checksum is checked first so a damaged or substituted download is rejected.
verbose "Installing Composer"

# Download the Composer installer and verify its checksum for security
EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
    >&2 echo 'ERROR: Invalid composer installer checksum'
    rm composer-setup.php
    exit 1
fi

# Put Composer in a system-wide location, then remove the one-time installer
# file so it is not left in the script directory after a successful run.
php composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php

# --- Idempotent application deployment ---
# A failed legacy install may have left a generic Laravel skeleton in place.
# The FreeSwitchPBX support class is the durable marker for the real app.
if [ ! -f "$application_root/app/Support/ModuleServiceProvider.php" ]; then
    verbose "Installing FreeSwitchPBX application source"

    if [ -d "$application_root" ] && [ -n "$(find "$application_root" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
        error "$application_root already exists but does not contain FreeSwitchPBX. Move it aside before installing."
        exit 1
    fi

    # Deploy as a real Git working tree so production servers can inspect
    # commits, pull updates, and report exact repository state. The initial
    # clone comes from the checked-out installer source in /usr/src, then the
    # app clone's origin is pointed back at the upstream repository URL.
    source_origin_url="$(git -C "$source_root" remote get-url origin 2>/dev/null || true)"
    git clone "$source_root" "$application_root"

    if [ -n "$source_origin_url" ]; then
        git -C "$application_root" remote set-url origin "$source_origin_url"
    fi
else
    verbose "FreeSwitchPBX application source already installed"
fi

if [ ! -f "$application_root/artisan" ] || [ ! -f "$application_root/app/Support/ModuleServiceProvider.php" ] || [ ! -d "$application_root/.git" ]; then
    error "FreeSwitchPBX application source was not deployed correctly"
    exit 1
fi

cd "$application_root"

# Start from Laravel's example configuration only on a new application. An
# existing .env holds installation-specific secrets and is never replaced.
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Write installer mode and method into .env early so the values are available
# if Composer or Artisan boots Laravel before the full configure step below.
# Database credentials are written once in the "Configure environment" section.
set_env_value .env FSPBX_DEMO_MODE "${FSPBX_DEMO_MODE:-false}"
set_env_value .env FSPBX_DEVELOPMENT_MODE "${FSPBX_DEVELOPMENT_MODE:-false}"
set_env_value .env FSPBX_FREESWITCH_INSTALL_METHOD "${FSPBX_FREESWITCH_INSTALL_METHOD}"

# The installer still provisions the isolated Dusk database and user, but it
# never changes the tracked .env.dusk.example test fixture in the application source.

# Keep caller-created recordings and voicemail outside the Git worktree. Read
# an existing choice first so an installer re-run never moves stored media.
media_root="$(get_env_value .env TALLPBX_MEDIA_ROOT)"
if [ -z "$media_root" ]; then
    media_root="/var/lib/tallpbx/media"
    set_env_value .env TALLPBX_MEDIA_ROOT "$media_root"
fi

# Keep complete backup archives outside the Git worktree and separate from
# call media. Preserve an existing administrator choice on re-runs so archives
# are never moved unexpectedly.
backup_root="$(get_env_value .env TALLPBX_BACKUP_ROOT)"
if [ -z "$backup_root" ]; then
    backup_root="/var/lib/tallpbx/backups"
    set_env_value .env TALLPBX_BACKUP_ROOT "$backup_root"
fi

# Let service accounts reach their protected TallPBX subdirectories without
# letting them list the parent directory. The backup directory is writable by
# PHP-FPM, while root retains ownership for safe maintenance and restore work.
# Re-running this only restores the intended access policy; it never removes
# stored media or backup archives.
install -d -m 0711 -o root -g root /var/lib/tallpbx
install -d -m 2770 -o root -g www-data "$backup_root"

# Install only runtime PHP dependencies on production systems. Development
# systems install the locked dev toolchain, including Laravel Boost and tests.
# Composer removes packages that are not needed in production when --no-dev is
# used, so a deliberate production re-run also removes old test/dev packages.
if [ "$development_mode" = true ]; then
    composer install --no-interaction --prefer-dist
else
    composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
fi

# Artisan is available after Composer has installed the framework. Preserve an
# existing APP_KEY because encrypted database columns, sessions, and other
# signed/encrypted payloads can only be read with the key that created them.
current_app_key="$(grep -E '^APP_KEY=' .env 2>/dev/null | tail -1 | cut -d= -f2- | sed 's/^"//; s/"$//')"
if [ -z "$current_app_key" ]; then
    php artisan key:generate --force
else
    verbose "Preserving existing Laravel APP_KEY"
fi

if [ "$development_mode" = true ]; then
    verbose "Installing Laravel Boost development tooling"
    php artisan boost:install --guidelines --skills --mcp --no-interaction
else
    verbose "Skipping Laravel Boost and development tooling for production"
fi

# Install the exact frontend dependency versions recorded by the project. This
# avoids unexpected JavaScript or CSS changes during an installer re-run.
verbose "Installing locked frontend dependencies"
npm ci --no-audit --no-fund

# --- Configure environment BEFORE build and migrate ---
# The .env file must exist with valid DB credentials before npm run build
# (which may reference env vars) and artisan migrate (which needs DB access).
#
# Idempotency: only create .env from scratch if it doesn't exist.
# This preserves any customizations (e.g. APP_ENV) on re-runs.
verbose "Configuring environment"

if [ "$development_mode" = true ]; then
    set_env_value .env APP_ENV local
    set_env_value .env APP_DEBUG true
else
    set_env_value .env APP_ENV production
    set_env_value .env APP_DEBUG false
fi
set_env_value .env APP_URL "http://$(hostname -I | awk '{print $1}')"
set_env_value .env DB_CONNECTION mysql
set_env_value .env DB_HOST "$database_host"
set_env_value .env DB_PORT 3306
set_env_value .env DB_DATABASE "$database_name"
set_env_value .env DB_USERNAME "$database_username"
set_env_value .env DB_PASSWORD "$database_password"

# Set the application name shown in Laravel notifications and page titles.
set_env_value .env APP_NAME TallPBX

# Add FreeSWITCH connection and XML-handler defaults. Existing explicit values
# are preserved; only old loopback placeholders are replaced with this server.
if grep -q "^FREESWITCH_SERVER=http://127.0.0.1" .env 2>/dev/null; then
    sed -i "s#^FREESWITCH_SERVER=.*#FREESWITCH_SERVER=http://$(hostname -I | awk '{print $1}')#" .env
elif ! grep -q "^FREESWITCH_SERVER=" .env 2>/dev/null; then
    echo "FREESWITCH_SERVER=http://$(hostname -I | awk '{print $1}')" >> .env
fi

if grep -q "^FREESWITCH_DEFAULT_SIP_REALM=127.0.0.1" .env 2>/dev/null; then
    sed -i "s#^FREESWITCH_DEFAULT_SIP_REALM=.*#FREESWITCH_DEFAULT_SIP_REALM=$(hostname -I | awk '{print $1}')#" .env
elif ! grep -q "^FREESWITCH_DEFAULT_SIP_REALM=" .env 2>/dev/null; then
    echo "FREESWITCH_DEFAULT_SIP_REALM=$(hostname -I | awk '{print $1}')" >> .env
fi

grep -q "^FREESWITCH_ESL_HOST=" .env 2>/dev/null || echo "FREESWITCH_ESL_HOST=127.0.0.1" >> .env
grep -q "^FREESWITCH_ESL_PORT=" .env 2>/dev/null || echo "FREESWITCH_ESL_PORT=8021" >> .env
grep -q "^FREESWITCH_ESL_PASSWORD=" .env 2>/dev/null || echo "FREESWITCH_ESL_PASSWORD=ClueCon" >> .env
grep -q "^FREESWITCH_XML_HANDLER_AUTH=" .env 2>/dev/null || echo "FREESWITCH_XML_HANDLER_AUTH=true" >> .env
grep -q "^FREESWITCH_XML_HANDLER_PATH=" .env 2>/dev/null || echo "FREESWITCH_XML_HANDLER_PATH=/api/v1/xml-handler" >> .env
grep -q "^FREESWITCH_XML_HANDLER_LOG_REQUESTS=" .env 2>/dev/null || echo "FREESWITCH_XML_HANDLER_LOG_REQUESTS=false" >> .env
if ! grep -q "^FREESWITCH_XML_HANDLER_TOKEN=" .env 2>/dev/null; then
    echo "FREESWITCH_XML_HANDLER_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')" >> .env
elif [ -z "$(grep -E '^FREESWITCH_XML_HANDLER_TOKEN=' .env | tail -1 | cut -d= -f2- | sed 's/^\"//; s/\"$//')" ]; then
    sed -i "s/^FREESWITCH_XML_HANDLER_TOKEN=.*/FREESWITCH_XML_HANDLER_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')/" .env
fi
if ! grep -q "^PBX_DEFAULT_SIP_PASSWORD=" .env 2>/dev/null; then
    echo "PBX_DEFAULT_SIP_PASSWORD=$(php -r 'echo bin2hex(random_bytes(12));')" >> .env
elif [ -z "$(grep -E '^PBX_DEFAULT_SIP_PASSWORD=' .env | tail -1 | cut -d= -f2- | sed 's/^\"//; s/\"$//')" ]; then
    sed -i "s/^PBX_DEFAULT_SIP_PASSWORD=.*/PBX_DEFAULT_SIP_PASSWORD=$(php -r 'echo bin2hex(random_bytes(12));')/" .env
fi

# Use Redis for cache and sessions. It avoids filesystem/database I/O on
# concurrent panel and XML-handler requests, and matches the project defaults.
grep -q "^CACHE_STORE=" .env 2>/dev/null \
    && sed -i "s/^CACHE_STORE=.*/CACHE_STORE=redis/" .env \
    || echo "CACHE_STORE=redis" >> .env
grep -q "^SESSION_DRIVER=" .env 2>/dev/null \
    && sed -i "s/^SESSION_DRIVER=.*/SESSION_DRIVER=redis/" .env \
    || echo "SESSION_DRIVER=redis" >> .env
grep -q "^SESSION_CONNECTION=" .env 2>/dev/null \
    && sed -i "s/^SESSION_CONNECTION=.*/SESSION_CONNECTION=cache/" .env \
    || echo "SESSION_CONNECTION=cache" >> .env
grep -q "^FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=" .env 2>/dev/null \
    && sed -i "s/^FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=.*/FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis/" .env \
    || echo "FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis" >> .env
grep -q "^FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED=" .env 2>/dev/null \
    || echo "FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED=false" >> .env
grep -q "^FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX=" .env 2>/dev/null \
    || echo "FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX=100000" >> .env
grep -q "^FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED=" .env 2>/dev/null \
    || echo "FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED=false" >> .env

# --- Build application assets and update the database ---
# Build browser assets after all Node packages are present. The build writes
# generated files only; it does not change application or customer data.
npm run build

# The production runtime does not need Node build tooling after assets exist.
if [ "$development_mode" != true ]; then
    npm prune --omit=dev
fi

# Clear stale optimized configuration so re-runs seed the mode selected by the
# current installer invocation before caching the new configuration below.
php artisan optimize:clear

# Apply only migrations that have not already been recorded. Forward migrations
# preserve existing data; this installer never calls schema-reset commands.
php artisan migrate --force

# Seed the Default tenant, permission groups, and system defaults. The first
# administrator is deliberately handled afterward by one Laravel command so
# installer and browser modes cannot drift into separate creation logic.
# Demo mode additionally creates customer tenant users and callable PBX fixtures.
php artisan db:seed --force

# Reconcile file ownership and modes after Composer, Vite, and Artisan created
# files. This keeps PHP-FPM able to read the application without giving it
# permission to modify deployed source files.
verbose "Setting permissions"

# Keep source readable to PHP-FPM without making the web user its owner.
# Only Laravel runtime paths remain writable by www-data.
php artisan permissions:repair --scope=full

# Make public storage files available through Laravel's intended symbolic link.
# Leave a real existing path untouched because it may contain administrator data.
if [ -L /var/www/tallpbx/public/storage ]; then
    verbose "Laravel public storage link already exists"
elif [ -e /var/www/tallpbx/public/storage ]; then
    warning "public/storage already exists and is not a symlink; leaving it unchanged"
else
    php artisan storage:link
fi

# Regenerate module autoload cache before framework optimization.
# Without this, modular routes are excluded from the route cache,
# causing PBX menu items to appear greyed out due to missing
# permissions and route resolution failures.
php artisan module:cache
php artisan optimize

# Configure FreeSWITCH dynamic XML after .env and optimized config exist.
# The FreeSWITCH installer may run before Laravel exists; this re-run makes
# fresh installs and installer re-runs converge on the same working state.
if [ -f /var/www/tallpbx/scripts/resources/freeswitch.sh ]; then
    TALLPBX_MEDIA_ROOT="$media_root" bash /var/www/tallpbx/scripts/resources/freeswitch.sh --configure-only
fi

# Final source and generated-file permission pass after cache/build files are recreated.
php artisan permissions:repair --scope=full

# Install the narrow root-owned entry point for approved restore operations.
# The web application may only request an operation; systemd invokes this
# helper with the operation UUID after the queue runner has staged it.
# Install the restore service only when both its executable and service recipe
# are present. Keeping the executable root-only prevents the web user from
# restoring arbitrary files or databases.
if [ -f /var/www/tallpbx/scripts/resources/tallpbx-restore ] && [ -f /var/www/tallpbx/scripts/resources/tallpbx-restore.service ]; then
    install -m 0700 /var/www/tallpbx/scripts/resources/tallpbx-restore /usr/local/sbin/tallpbx-restore
    install -m 0644 /var/www/tallpbx/scripts/resources/tallpbx-restore.service /etc/systemd/system/tallpbx-restore@.service
fi

# Install the dispatcher that watches approved restore requests. Its directory
# is shared only with the web group so the application can request, not execute,
# a restore operation.
if [ -f /var/www/tallpbx/scripts/resources/tallpbx-restore-dispatch ]; then
    install -d -m 0770 -o root -g www-data /var/lib/tallpbx/restore-requests
    install -m 0700 /var/www/tallpbx/scripts/resources/tallpbx-restore-dispatch /usr/local/sbin/tallpbx-restore-dispatch
    install -m 0644 /var/www/tallpbx/scripts/resources/tallpbx-restore-dispatch.service /etc/systemd/system/
    install -m 0644 /var/www/tallpbx/scripts/resources/tallpbx-restore-dispatch.path /etc/systemd/system/
fi

# Install systemd service units for the background workers that TallPBX needs.
# All unit files are copied first, then systemd is reloaded once, and finally
# each service is enabled and started. This avoids redundant daemon-reload calls.
if [ -f /var/www/tallpbx/scripts/freeswitch-listener.service ]; then
    cp /var/www/tallpbx/scripts/freeswitch-listener.service /etc/systemd/system/
fi
if [ -f /var/www/tallpbx/scripts/tallpbx-queue.service ]; then
    cp /var/www/tallpbx/scripts/tallpbx-queue.service /etc/systemd/system/
fi
if [ -f /var/www/tallpbx/scripts/tallpbx-scheduler.service ]; then
    cp /var/www/tallpbx/scripts/tallpbx-scheduler.service /etc/systemd/system/
fi
if [ -f /var/www/tallpbx/scripts/tallpbx-reverb.service ]; then
    cp /var/www/tallpbx/scripts/tallpbx-reverb.service /etc/systemd/system/
fi

# Reload systemd once after all unit files are installed, then enable and start
# each service. The single daemon-reload avoids repeated reloads.
systemctl daemon-reload

if [ -f /etc/systemd/system/tallpbx-restore-dispatch.path ]; then
    systemctl enable --now tallpbx-restore-dispatch.path
fi
systemctl enable freeswitch-listener 2>/dev/null || true
systemctl restart freeswitch-listener 2>/dev/null || true
systemctl enable tallpbx-queue 2>/dev/null || true
systemctl restart tallpbx-queue 2>/dev/null || true
systemctl enable tallpbx-scheduler 2>/dev/null || true
systemctl restart tallpbx-scheduler 2>/dev/null || true
systemctl enable tallpbx-reverb 2>/dev/null || true
systemctl restart tallpbx-reverb 2>/dev/null || true

