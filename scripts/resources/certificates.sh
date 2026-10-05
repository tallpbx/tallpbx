#!/bin/bash
# ==============================================================================
# TallPBX Certificate Manager & TLS Helper Setup
# ==============================================================================
# This installer step configures host-level directories and installs the
# bounded host helper script and sudoers drop-in for TLS certificate lifecycle
# management, Let's Encrypt automation, and Nginx/FreeSWITCH deployments.
#
# What this step changes:
# 1. Creates storage directories for certificates:
#    - /etc/tallpbx/certs (mode 0750, root:www-data)
#    - /etc/tallpbx/certs/active (mode 0750, root:www-data)
# 2. Installs the bounded host helper '/usr/local/sbin/tallpbx-certificate'
#    (mode 0750, root:www-data) allowing the web application to perform
#    cryptographic validation, Certbot ACME operations, and service reloads
#    without broad root access.
# 3. Installs the sudoers drop-in '/etc/sudoers.d/tallpbx-certificate'
#    (mode 0440, root:root) permitting 'www-data' to invoke only that
#    single executable with NOPASSWD.
# 4. Ensures /etc/freeswitch/tls exists with proper telephony permissions.
#
# Safe to re-run:
# - All file and directory operations are idempotent.
# - Existing certificates and keys are untouched.
# ==============================================================================

set -e

cd "$(dirname "$0")"

. ./config.sh 2>/dev/null || true
. ./colors.sh 2>/dev/null || true
. ./environment.sh 2>/dev/null || true

verbose "Setting up TLS certificate directories"
install -d -m 0750 -o root -g www-data /etc/tallpbx/certs
install -d -m 0750 -o root -g www-data /etc/tallpbx/certs/active

if id freeswitch >/dev/null 2>&1; then
    install -d -m 0750 -o freeswitch -g freeswitch /etc/freeswitch/tls
fi

verbose "Installing bounded host certificate helper to /usr/local/sbin/tallpbx-certificate"
install -m 0750 -o root -g www-data ./tallpbx-certificate /usr/local/sbin/tallpbx-certificate

verbose "Installing sudoers rule for certificate helper"
install -d -m 0750 -o root -g root /etc/sudoers.d
install -m 0440 -o root -g root ./tallpbx-certificate.sudoers /etc/sudoers.d/tallpbx-certificate

if ! visudo -c -f /etc/sudoers.d/tallpbx-certificate >/dev/null 2>&1; then
    error "Sudoers syntax verification failed for /etc/sudoers.d/tallpbx-certificate"
    rm -f /etc/sudoers.d/tallpbx-certificate
    exit 1
fi

verbose "TLS certificate manager helper and sudoers rule installed successfully"
