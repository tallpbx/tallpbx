#!/bin/bash
# ==============================================================================
# Installer Configuration
# ==============================================================================
# This file contains all the settings the installer needs. You can edit it
# before running the installer to customize how things are set up. The main
# installer asks for any intentionally blank secret and then saves it outside
# the repository in a root-only state file.
# ==============================================================================

# General Settings
php_version=8.5                              # PHP version installed for the web application
node_version=22                              # Node.js version used to build the browser assets
domain_name=ip_address                       # Server address until a real public domain is configured
system_username=admin@tallpbx.local          # Default name offered for the first web administrator

# Database Settings
database_name=tallpbx                        # Name of the application's main database
database_username=tallpbx                    # MariaDB account used by the application
database_password=random                     # Ask once, then save the chosen password securely
database_host=127.0.0.1                      # MariaDB server address on this same machine
db_version=11.8                              # MariaDB version the installer configures

# FreeSWITCH Settings
switch_branch=stable                         # FreeSWITCH Git branch when source installation is selected
switch_tls=true                              # Keep TLS support enabled for FreeSWITCH
switch_token=                                # Optional SignalWire token; otherwise the preflight asks once
                                             # Get a token from https://signalwire.com

# LEGACY — DO NOT EDIT. These are kept for backward compatibility with older
# direct-script invocations. The main installer's preflight questionnaire
# controls the FreeSWITCH installation method instead.
switch_source=false                          # Older direct-script source-install fallback
switch_package=true                          # Older direct-script package-install fallback
switch_version=1.11                          # FreeSWITCH version hint used by legacy configuration

# Browser Testing (Optional)
# Install Chromium for Laravel Dusk browser tests:
#   apt-get install -y chromium
#   cd /var/www/tallpbx && php artisan dusk:chrome-driver
#   php artisan dusk
