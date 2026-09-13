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
# Enable and start it here so a fresh install matches the default .env.
systemctl enable redis-server
systemctl restart redis-server

# Tell systemd about any newly installed service files, then restart PHP-FPM so
# the web server can immediately use the installed PHP extensions.
systemctl daemon-reload
systemctl restart "php$php_version-fpm"
