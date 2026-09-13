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

# The Dusk user has access only to the disposable browser-test database. A
# mistaken Dusk database name therefore fails instead of touching TallPBX data.
dusk_database_username="${database_username}_dusk"

# Create the database and user accounts.
# "IF NOT EXISTS" and "ALTER USER" make this safe to re-run —
# existing databases and data are preserved, and the password
# is updated to match the current config. The separate _dusk database keeps
# browser-test schema resets away from the installed application's data.
mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS $database_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ${database_name}_dusk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$database_username'@'$database_host' IDENTIFIED BY '$password';
ALTER USER '$database_username'@'$database_host' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON $database_name.* TO '$database_username'@'$database_host';
CREATE USER IF NOT EXISTS '$dusk_database_username'@'$database_host' IDENTIFIED BY '$password';
ALTER USER '$dusk_database_username'@'$database_host' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON ${database_name}_dusk.* TO '$dusk_database_username'@'$database_host';
FLUSH PRIVILEGES;
EOF

# Show only non-secret connection details. The password stays in the root-only
# installer state file and is never printed into the installation log.
verbose "Database: $database_name"
verbose "Username: $database_username"
verbose "Password: stored securely in installer state"
