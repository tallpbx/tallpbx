#!/bin/bash
# ==============================================================================
# PHP
# ==============================================================================
# This step installs PHP and all the extensions Laravel needs to run.
# It adds the Sury PHP repository (which has up-to-date PHP packages
# for Debian) before installing.
#
# Safe to re-run: apt skips already-installed packages.
# ==============================================================================

cd "$(dirname "$0")"

. ./config.sh
. ./colors.sh
. ./environment.sh
# Durability helpers for the Redis instance that buffers CDR stream entries.
. ./redis-durability.sh
# PHP-FPM pool reconciler, shared with `appliance:sync-host` so the installer
# and a snapshot restore write the same six directives from one RAM table.
. ./php-fpm-pool.sh

verbose "Installing PHP $php_version"

# Install the few tools needed to trust and use the Sury repository. This does
# not replace PHP yet; it prepares apt to find the requested PHP version.
apt_get_with_lock_wait install -y apt-transport-https lsb-release ca-certificates curl wget gnupg2
# Keep repository signing keys in apt's standard protected keyring directory.
mkdir -p /etc/apt/keyrings
# Download the publisher's public key so apt can reject altered PHP packages.
wget -qO- https://packages.sury.org/php/apt.gpg | gpg --dearmor > /etc/apt/keyrings/sury-php.gpg
chmod 644 /etc/apt/keyrings/sury-php.gpg
# Register one repeatable repository entry for this Debian release, then refresh
# apt's package list so the following install can see the new PHP packages.
sh -c 'echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'
apt_get_with_lock_wait update -y

# Install PHP, Redis, and all the extensions Laravel and FreeSWITCH need.
# This list matches what is currently installed and verified on a
# running Debian 13 system with PHP 8.5 from the Sury repository.
apt_get_with_lock_wait install -y --no-install-recommends \
    redis-server \
    redis-tools \
    php$php_version \
    php$php_version-common \
    php$php_version-cli \
    php$php_version-dev \
    php$php_version-fpm \
    php$php_version-mysql \
    php$php_version-sqlite3 \
    php$php_version-curl \
    php$php_version-imap \
    php$php_version-xml \
    php$php_version-gd \
    php$php_version-mbstring \
    php$php_version-ldap \
    php$php_version-intl \
    php$php_version-bcmath \
    php$php_version-zip \
    php$php_version-soap \
    php$php_version-gmp \
    php$php_version-redis \
    php$php_version-igbinary \
    php$php_version-readline

# Redis backs Laravel cache, sessions, and XML handler hot-path caching.
# Configure a universal 256MB memory cap and volatile-lru eviction policy.
# By default, Debian packages configure Redis without a memory limit (maxmemory 0),
# which allows Redis to grow unchecked and risk out-of-memory lockouts.
# Capping Redis at 256MB provides 5x–10x more headroom than TallPBX needs
# while leaving ample physical memory for FreeSWITCH, MariaDB, and PHP-FPM.
# The volatile-lru eviction policy guarantees that if memory approaches the cap,
# Redis automatically purges the oldest expired keys (cache entries, attempt counters)
# rather than rejecting writes or crashing.
configure_redis() {
    local redis_conf="/etc/redis/redis.conf"
    if [ ! -f "$redis_conf" ]; then
        return 0
    fi

    verbose "Configuring Redis with universal 256mb memory cap and volatile-lru eviction"

    # Set maxmemory 256mb idempotently
    if grep -q -E '^[# ]*maxmemory\s+[0-9]' "$redis_conf"; then
        sed -i -E 's/^[# ]*maxmemory\s+[0-9a-zA-Z]+/maxmemory 256mb/' "$redis_conf"
    elif grep -q -E '^# maxmemory <bytes>' "$redis_conf"; then
        sed -i -E 's/^# maxmemory <bytes>/maxmemory 256mb/' "$redis_conf"
    else
        echo "maxmemory 256mb" >> "$redis_conf"
    fi

    # Set maxmemory-policy volatile-lru idempotently
    if grep -q -E '^[# ]*maxmemory-policy\s+' "$redis_conf"; then
        sed -i -E 's/^[# ]*maxmemory-policy\s+[a-zA-Z_-]+/maxmemory-policy volatile-lru/' "$redis_conf"
    else
        echo "maxmemory-policy volatile-lru" >> "$redis_conf"
    fi
}

configure_redis

# The CDR pipeline accepts a call record with HTTP 200 before it reaches
# MariaDB, so Redis holds the only copy until the worker commits it. Enable
# append-only persistence and the kernel setting its snapshot fork depends on.
configure_redis_durability /etc/redis/redis.conf
configure_redis_overcommit /etc/sysctl.d/99-tallpbx-redis.conf
apply_redis_overcommit /etc/sysctl.d/99-tallpbx-redis.conf

systemctl enable redis-server
systemctl restart redis-server

# Warn loudly when the running host disagrees with the configuration just
# written: without these settings a restart can discard undigested records.
if ! verify_redis_overcommit; then
    warning "vm.overcommit_memory is not 1; Redis may refuse writes after a failed snapshot"
fi

if ! verify_redis_appendonly; then
    warning "Redis append-only persistence is not active; undigested CDR records can be lost on restart"
fi

# Reconcile the PHP-FPM worker pool with the documented shape for this host's
# physical RAM. The tier table and the six directives it writes live in
# php-fpm-pool.sh, which `appliance:sync-host` runs too, so an installer run and
# a snapshot restore cannot disagree about the pool.
#
# The reload is suppressed here because the installer restarts PHP-FPM a few
# lines below; letting the reconciler reload as well would bounce the service
# twice and discard the first restart's warm workers.
configure_php_fpm_pool() {
    local override

    # Optional administrator override (PHP_FPM_MAX_CHILDREN in .env), used to pin
    # a host to a published tier when its RAM does not match the benchmark host.
    override=$(get_env_value "/var/www/tallpbx/.env" PHP_FPM_MAX_CHILDREN)

    if [ -n "$override" ]; then
        verbose "Configuring PHP-FPM: administrator override PHP_FPM_MAX_CHILDREN=$override"
    fi

    FPM_POOL_MAX_CHILDREN_OVERRIDE="$override" fpm_pool_main --reload=none
}

configure_php_fpm_pool

# Tell systemd about any newly installed service files, then restart PHP-FPM so
# the web server can immediately use the installed PHP extensions and tuned pool settings.
systemctl daemon-reload
systemctl restart "php$php_version-fpm"
