#!/bin/bash
# ==============================================================================
# MariaDB Database Server
# ==============================================================================
# This step installs MariaDB (a MySQL-compatible database server) and creates
# the database and user that the Laravel application will use.
#
# Safe to re-run: it won't delete existing databases or data.
# ==============================================================================

cd "$(dirname "$0")"

. ./config.sh
. ./colors.sh
. ./environment.sh

verbose "Installing MariaDB"

# Install the tools that add and verify MariaDB's official package repository.
# Re-running these commands only refreshes the same repository configuration.
apt_get_with_lock_wait install -y apt-transport-https curl wget gnupg2
# Store MariaDB's signing key where apt expects protected repository keys.
mkdir -p /etc/apt/keyrings
# Download the signing key and write the one repository entry for this server.
curl -fsSL https://mariadb.org/mariadb_release_signing_key.asc | gpg --dearmor > /etc/apt/keyrings/mariadb.gpg
chmod 644 /etc/apt/keyrings/mariadb.gpg
sh -c "echo \"deb [signed-by=/etc/apt/keyrings/mariadb.gpg] https://mirror.mariadb.org/repo/$db_version/debian \$(lsb_release -sc) main\" > /etc/apt/sources.list.d/mariadb.list"
apt_get_with_lock_wait update -y

# Install both the server and command-line client used by the rest of the
# installer to create the application databases and accounts.
apt_get_with_lock_wait install -y mariadb-server mariadb-client

# Enable MariaDB for future boots and start or restart it now before issuing
# database commands below. This does not remove or recreate existing data.
systemctl daemon-reload
systemctl enable mariadb
systemctl restart mariadb

# Use the database password from the main installer if available,
# otherwise generate a random one as a fallback
if [ -n "$FSPBX_DB_PASSWORD" ]; then
    password="$FSPBX_DB_PASSWORD"
else
    password=$(od -An -N10 -tx1 /dev/urandom | tr -d ' \n')
fi

# Create the database and user account.
# "IF NOT EXISTS" and "ALTER USER" make this safe to re-run —
# existing databases and data are preserved, and the password
# is updated to match the current config. Browser tests run against an
# in-memory SQLite database, so no separate test database is created.
mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS $database_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$database_username'@'$database_host' IDENTIFIED BY '$password';
ALTER USER '$database_username'@'$database_host' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON $database_name.* TO '$database_username'@'$database_host';
FLUSH PRIVILEGES;
EOF

# Show only non-secret connection details. The password stays in the root-only
# installer state file and is never printed into the installation log.
verbose "Database: $database_name"
verbose "Username: $database_username"
verbose "Password: stored securely in installer state"

# Provision the separate FreeSWITCH runtime schema only when MariaDB is opted in.
# Standard installations keep SQLite (FS_DB_DRIVER=sqlite) and touch only $database_name.
INSTALLER_STATE_FILE="${PBX_INSTALLER_STATE_FILE:-/etc/default/tallpbx}"
fs_db_driver="${FSPBX_FREESWITCH_DB_DRIVER:-$(get_env_value /var/www/tallpbx/.env FS_DB_DRIVER)}"
fs_db_driver="${fs_db_driver:-${freeswitch_db_driver:-sqlite}}"

if [ "$fs_db_driver" = "mariadb" ]; then
    fs_db_name="${FSPBX_FREESWITCH_DB_NAME:-${freeswitch_db_name:-freeswitch}}"
    fs_db_user="${FSPBX_FREESWITCH_DB_USERNAME:-${freeswitch_db_username:-freeswitch}}"
    fs_db_password="${FSPBX_FREESWITCH_DB_PASSWORD:-}"

    if [ -z "$fs_db_password" ]; then
        fs_db_password=$(resolve_database_password \
            "${freeswitch_db_password:-random}" \
            "$INSTALLER_STATE_FILE" \
            /var/www/tallpbx/.env \
            FS_DB_PASSWORD)
    fi

    if [ -z "$fs_db_password" ]; then
        fs_db_password=$(od -An -N10 -tx1 /dev/urandom | tr -d ' \n')
        set_secure_env_value "$INSTALLER_STATE_FILE" FS_DB_PASSWORD "$fs_db_password"
    fi

    for pair in "fs_db_name:$fs_db_name" "fs_db_user:$fs_db_user" "fs_db_password:$fs_db_password"; do
        key="${pair%%:*}"
        value="${pair#*:}"
        if [[ ! "$value" =~ ^[a-zA-Z0-9_.-]+$ ]]; then
            error "Invalid FreeSWITCH database config value for '$key'"
            exit 1
        fi
    done

    mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS $fs_db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$fs_db_user'@'$database_host' IDENTIFIED BY '$fs_db_password';
ALTER USER '$fs_db_user'@'$database_host' IDENTIFIED BY '$fs_db_password';
GRANT ALL PRIVILEGES ON $fs_db_name.* TO '$fs_db_user'@'$database_host';
FLUSH PRIVILEGES;
EOF

    verbose "FreeSWITCH runtime database: $fs_db_name (user: $fs_db_user)"
fi
