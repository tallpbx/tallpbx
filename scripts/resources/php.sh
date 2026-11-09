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

# Tune PHP-FPM worker pool sizing based on available physical memory.
# FreeSWITCH invokes HTTP XML handlers for call lookups (extensions, routes, voicemail).
# During simultaneous call bursts, a spare worker must be ready immediately to prevent
# 502/504 gateway timeouts and failed calls, so the pool grows on demand and is kept
# small: on minimal hardware more concurrent workers measurably LOSE calls (see below).
#
# Sizing logic based on physical RAM (ceiling of the dynamic range):
# - Standard server (>= 3500 MB RAM): 12 workers. The previous 4GB+ capacity envelope,
#   leaving ~2.9GB RAM free for FreeSWITCH and MariaDB.
# - Small server (>= 1800 MB RAM): 6 workers. Balanced for 2GB instances.
# - Minimal server (< 1800 MB RAM): 3 workers, measured (see below).
#
# Why dynamic workers with a low ceiling instead of a static pool:
# The 2026-11-08 worker sweep (load-testing/results/worker-sweep-*) measured
# every pool size from 5 down to 1 on the 1 vCPU / 1 GB reference host. At the
# supported rate (5 calls/sec) every size completed 300/300 calls; at the
# failure boundary (6 calls/sec) completion rose monotonically as workers were
# REMOVED: 38% at 5 workers to 84% at 1. On a saturated single core extra
# workers only deepen the request backlog, so more lookups miss FreeSWITCH's
# 5 second mod_xml_curl deadline. The pool therefore sits at one resident
# worker (Debian's dynamic mode) and grows only for burst overlap and
# single-stuck-request tolerance, capped at 3 - the last ceiling before the
# boundary curve collapses (4 workers drop to 51%, 5 to 38%). Fork latency on
# burst growth is the residual cost of dynamic mode; the tight ceiling bounds
# it. Full series and method: docs/load-testing-results.md.
configure_php_fpm_pool() {
    local pool_conf="/etc/php/$php_version/fpm/pool.d/www.conf"
    if [ ! -f "$pool_conf" ]; then
        return 0
    fi

    # Read total physical system RAM in megabytes
    local total_ram_mb
    total_ram_mb=$(free -m | awk '/^Mem:/{print $2}')
    total_ram_mb="${total_ram_mb:-1024}"

    # Dynamic mode on every tier with one low idle floor: one resident worker
    # at idle, growing only while requests queue behind it.
    local target_pm="dynamic"
    local target_start_servers=1
    local target_min_spare_servers=1
    local target_max_spare_servers=2
    local target_max_requests=10000

    # The ceiling is the only per-tier value: 3 on minimal hardware (measured),
    # the previous capacity envelope on larger hosts (6 on 2GB, 12 on 4GB+).
    local target_max_children=3

    if [ "$total_ram_mb" -ge 3500 ]; then
        target_max_children=12
        verbose "Configuring PHP-FPM dynamic 1-12 workers (detected ${total_ram_mb}MB RAM, 4GB+ tier)"
    elif [ "$total_ram_mb" -ge 1800 ]; then
        target_max_children=6
        verbose "Configuring PHP-FPM dynamic 1-6 workers (detected ${total_ram_mb}MB RAM, 2GB tier)"
    else
        verbose "Configuring PHP-FPM dynamic 1-3 workers (detected ${total_ram_mb}MB RAM, minimal tier)"
    fi

    # Update the process manager mode and the full dynamic knob set. The
    # sed patterns also rewrite commented-out Debian defaults. pm.max_requests
    # recycles long-lived workers so resident memory cannot creep over weeks
    # of uptime (the same bound the Octane worker uses).
    sed -i -E "s/^[; ]*pm\s*=\s*.*/pm = $target_pm/" "$pool_conf"
    sed -i -E "s/^[; ]*pm\.max_children\s*=\s*.*/pm.max_children = $target_max_children/" "$pool_conf"
    sed -i -E "s/^[; ]*pm\.start_servers\s*=\s*.*/pm.start_servers = $target_start_servers/" "$pool_conf"
    sed -i -E "s/^[; ]*pm\.min_spare_servers\s*=\s*.*/pm.min_spare_servers = $target_min_spare_servers/" "$pool_conf"
    sed -i -E "s/^[; ]*pm\.max_spare_servers\s*=\s*.*/pm.max_spare_servers = $target_max_spare_servers/" "$pool_conf"
    sed -i -E "s/^[; ]*pm\.max_requests\s*=\s*.*/pm.max_requests = $target_max_requests/" "$pool_conf"
}

configure_php_fpm_pool

# Tell systemd about any newly installed service files, then restart PHP-FPM so
# the web server can immediately use the installed PHP extensions and tuned pool settings.
systemctl daemon-reload
systemctl restart "php$php_version-fpm"
