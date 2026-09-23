#!/bin/bash
# ==============================================================================
# TallPBX Installer
# ==============================================================================
# This script installs everything needed to run a complete PBX phone system:
#   - PHP, MariaDB (database), Nginx (web server)
#   - FreeSWITCH (the phone system engine)
#   - Laravel + Livewire + Tailwind CSS (the web app)
#
# You can run this script multiple times safely — it won't delete your data
# or re-do steps that have already finished.
#
# Usage:
#   ./install.sh              # prompt for demo data and development packages
#   ./install.sh --no-demo    # do not ask to add demo data
#   ./install.sh --no-development # do not install development tooling
# ==============================================================================

# Don't show apt prompts during install (use all defaults)
export DEBIAN_FRONTEND=noninteractive

# Track how long the installation takes so the summary can show elapsed time.
INSTALL_START_SECONDS=$SECONDS

# --- Parse flags ---
# Production defaults are the safe baseline for unattended installs. Interactive
# installs may opt into demo tenants, users, and extensions at the prompt below.
DEMO_MODE=false
DEMO_MODE_EXPLICIT=false
DEVELOPMENT_MODE=false
DEVELOPMENT_MODE_EXPLICIT=false
INSTALLER_OPTIONS_EXPLICIT=false
for arg in "$@"; do
    case $arg in
        --no-demo)
            DEMO_MODE=false
            DEMO_MODE_EXPLICIT=true
            INSTALLER_OPTIONS_EXPLICIT=true
            ;;
        --no-development)
            DEVELOPMENT_MODE=false
            DEVELOPMENT_MODE_EXPLICIT=true
            INSTALLER_OPTIONS_EXPLICIT=true
            ;;
        *)
            echo "Unknown installer option: $arg" >&2
            echo "Supported options: --no-demo, --no-development" >&2
            exit 1
            ;;
    esac
done

# Move into the scripts folder so we can load the helper files
cd "$(dirname "$0")"

# Load settings (database name, passwords, etc.) and helper functions
. ./resources/config.sh
. ./resources/colors.sh
. ./resources/environment.sh

# --- Installer logging ---
# Save everything that appears on screen to a log file as well,
# so you can check what happened later.
LOG_FILE="/var/log/pbx-install.log"
exec > >(tee -a "$LOG_FILE") 2>&1
echo ""
verbose "Log file: $LOG_FILE"
echo ""

verbose "TallPBX Installer"
verbose "========================"

# Ask an interactive operator for a boolean installer choice while treating the
# current value as the default. Empty input therefore preserves an existing
# installation instead of changing its mode during an idempotent re-run.
prompt_boolean_choice () {
    local output_variable="$1"
    local label="$2"
    local current_value="$3"
    local choice
    local default_hint

    if [ "$current_value" = true ]; then
        default_hint='Y/n'
    else
        default_hint='y/N'
    fi

    while true; do
        read -rp "$label [$default_hint] " choice

        case "${choice,,}" in
            y|yes)
                printf -v "$output_variable" '%s' true
                return
                ;;
            n|no)
                printf -v "$output_variable" '%s' false
                return
                ;;
            '')
                printf -v "$output_variable" '%s' "$current_value"
                return
                ;;
            *)
                warning "Please enter y or n."
                ;;
        esac
    done
}

# Select the FreeSWITCH delivery method every interactive run. Defaults to
# packages if Enter is pressed without typing a choice.
prompt_freeswitch_install_method () {
    local current_method="$1"
    local default_method="${current_method:-packages}"
    local hint="Packages/source"
    local choice

    if [ "$default_method" = "source" ]; then
        hint="packages/Source"
    fi

    echo ""
    verbose "How should FreeSWITCH be installed?"
    echo ""
    echo "  1) Packages — faster install, easy updates (needs a free SignalWire token)"
    echo "  2) Source  — no token needed, but slower to install and update"
    echo ""
    echo "  Most servers should use packages (option 1)."
    echo ""

    while true; do
        read -rp "Install method [${hint}]: " choice

        case "${choice,,}" in
            1|package|packages)
                FREESWITCH_INSTALL_METHOD=packages
                return
                ;;
            2|source)
                FREESWITCH_INSTALL_METHOD=source
                return
                ;;
            '')
                FREESWITCH_INSTALL_METHOD="$default_method"
                return
                ;;
            *)
                warning "Choose packages or source (or enter 1 or 2)."
                ;;
        esac
    done
}

# Select how the first TallPBX administrator will be created. The default keeps
# the current installer experience. Browser options delay only administrator
# creation; they do not delay database, PBX, or service installation.
prompt_initial_admin_mode () {
    local current_mode="$1"
    local choice

    echo ""
    verbose "Initial administrator setup"
    echo "  1) Create administrator during installation (default)"
    echo "     Enter the administrator email and password now. TallPBX creates the account before installation finishes."
    echo "  2) Create administrator in the web browser with a one-time activation code"
    echo "     TallPBX shows an activation code after installation. Enter it at /panel/setup to choose the administrator email and password."
    echo "  3) Create administrator in the web browser without an activation code — trusted network only"
    echo "     The first person who opens /panel/setup can create the administrator. Use this only on a private, trusted network."
    echo ""

    while true; do
        read -rp "Choose 1, 2, or 3 [${current_mode:-installer}]: " choice

        case "${choice,,}" in
            1|installer|'')
                FSPBX_INITIAL_ADMIN_MODE=installer
                return
                ;;
            2|activation-code|activation)
                FSPBX_INITIAL_ADMIN_MODE=activation-code
                return
                ;;
            3|trusted-network|trusted)
                FSPBX_INITIAL_ADMIN_MODE=trusted-network
                return
                ;;
            *)
                warning "Please enter 1, 2, or 3."
                ;;
        esac
    done
}

# Preserve the previously selected demo mode on installer re-runs. Explicit
# flags override it, while interactive prompts use it as the default.
if [ "$DEMO_MODE_EXPLICIT" = false ]; then
    if saved_demo_mode=$(resolve_boolean_env_value /var/www/tallpbx/.env FSPBX_DEMO_MODE); then
        DEMO_MODE="$saved_demo_mode"
        verbose "Reusing existing demo data mode: $DEMO_MODE"
    fi
fi

if [ "$INSTALLER_OPTIONS_EXPLICIT" = false ] && [ -t 0 ]; then
    prompt_boolean_choice DEMO_MODE "Install demo data?" "$DEMO_MODE"
fi

export FSPBX_DEMO_MODE="$DEMO_MODE"

# Preserve the selected development mode. Legacy installations used local mode
# with Boost installed, so detect that state until the explicit value is saved.
if [ "$DEVELOPMENT_MODE_EXPLICIT" = false ]; then
    if [ -f /var/www/tallpbx/vendor/laravel/boost/composer.json ]; then
        DEVELOPMENT_MODE=true
        verbose "Preserving installed Laravel Boost development tooling."
    elif saved_development_mode=$(resolve_boolean_env_value /var/www/tallpbx/.env FSPBX_DEVELOPMENT_MODE); then
        DEVELOPMENT_MODE="$saved_development_mode"
        verbose "Reusing existing development mode: $DEVELOPMENT_MODE"
    elif [ "$(get_env_value /var/www/tallpbx/.env APP_ENV)" = local ]; then
        DEVELOPMENT_MODE=true
        verbose "Preserving legacy development tooling; future runs will save this mode."
    fi
fi

# Development mode installs coding tools and agent configuration. Fresh,
# unattended installs remain production by default, while recorded installs do
# not switch mode unless an explicit flag overrides them.
if [ "$INSTALLER_OPTIONS_EXPLICIT" = false ] && [ -t 0 ]; then
    prompt_boolean_choice DEVELOPMENT_MODE "Install development packages and tooling?" "$DEVELOPMENT_MODE"
fi

export FSPBX_DEVELOPMENT_MODE="$DEVELOPMENT_MODE"

# Mark child resource scripts as part of this planned installer run. They use
# this marker to reject a missing preflight value instead of asking a surprise
# question after package or service work has already started.
export FSPBX_INSTALLER_EXECUTION=true

# --- Config validation ---
# Make sure database settings only contain safe characters
# (letters, numbers, underscores, hyphens, dots). This prevents
# weird errors in SQL commands later.
validate_config_value () {
    local name="$1"
    local value="$2"
    if [[ ! "$value" =~ ^[a-zA-Z0-9_.-]+$ ]]; then
        error "Invalid config value for '$name': '$value'"
        error "Only letters, numbers, underscores, hyphens, and dots are allowed."
        exit 1
    fi
}

validate_config_value "database_name" "$database_name"
validate_config_value "database_username" "$database_username"
validate_config_value "database_host" "$database_host"

# --- Preflight questionnaire ---
# Ask every installer question before changing packages, services, or database
# data. If a later step fails, the selected values are already stored in the
# root-only state file, so the next run can continue without asking again.
INSTALLER_STATE_FILE="${PBX_INSTALLER_STATE_FILE:-/etc/pbx/installer.env}"

# Reuse the database password whenever it is already known. A new installation
# can either create a random password or accept a password typed by the person
# running the installer.
saved_database_password=$(resolve_database_password \
    "$database_password" \
    "$INSTALLER_STATE_FILE" \
    /var/www/tallpbx/.env)

if [ -n "$saved_database_password" ]; then
    database_password="$saved_database_password"
    verbose "Reusing the existing database password"
else
    echo ""
    verbose "Database password"
    echo "  The installer can generate a strong random password (recommended),"
    echo "  or you can choose your own."
    echo ""
    echo "  1) Generate a random password (recommended)"
    echo "  2) Enter my own password"
    echo ""
    read -rp "Enter 1 or 2 [1]: " password_choice

    if [ "$password_choice" = "2" ]; then
        while true; do
            read -rsp "Enter database password: " database_password
            echo ""
            read -rsp "Confirm database password: " database_password_confirm
            echo ""

            if [ -z "$database_password" ]; then
                warning "Password cannot be empty. Please try again."
            elif [ "$database_password" != "$database_password_confirm" ]; then
                warning "Passwords do not match. Please try again."
            else
                break
            fi
        done
    else
        # The generated value uses only characters accepted by MariaDB and the
        # installer validation rules, so it is safe to pass to later commands.
        database_password=$(od -An -N10 -tx1 /dev/urandom | tr -d ' \n')
    fi
fi

validate_config_value "database_password" "$database_password"
set_secure_env_value "$INSTALLER_STATE_FILE" DB_PASSWORD "$database_password"
export FSPBX_DB_PASSWORD="$database_password"

# Decide how FreeSWITCH will be installed before any package work begins. The
# saved selection is used as the default on re-runs so pressing Enter preserves
# the previous working installation method.
FREESWITCH_INSTALL_METHOD=""
PREVIOUS_FREESWITCH_INSTALL_METHOD=""
PREVIOUS_FREESWITCH_INSTALL_METHOD=$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_FREESWITCH_INSTALLED_METHOD)
saved_freeswitch_install_method=$(get_env_value /var/www/tallpbx/.env FSPBX_FREESWITCH_INSTALL_METHOD)
if [ -z "$saved_freeswitch_install_method" ]; then
    saved_freeswitch_install_method=$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_FREESWITCH_INSTALL_METHOD)
fi

case "$saved_freeswitch_install_method" in
    packages|source)
        FREESWITCH_INSTALL_METHOD="$saved_freeswitch_install_method"
        verbose "Reusing existing FreeSWITCH install method: $FREESWITCH_INSTALL_METHOD"
        ;;
    *)
        # A headless run may supply the method directly as an environment value
        # when nothing was persisted yet. The persisted choice above still wins
        # so an unfinished installation cannot silently switch its method.
        requested_freeswitch_install_method="${FSPBX_FREESWITCH_INSTALL_METHOD:-}"
        case "$requested_freeswitch_install_method" in
            packages|source)
                FREESWITCH_INSTALL_METHOD="$requested_freeswitch_install_method"
                verbose "Using FreeSWITCH install method from the environment: $FREESWITCH_INSTALL_METHOD"
                ;;
        esac
        ;;
esac

if [ -t 0 ]; then
    prompt_freeswitch_install_method "$FREESWITCH_INSTALL_METHOD"
elif [ -z "$FREESWITCH_INSTALL_METHOD" ]; then
    error "A non-interactive install must set FSPBX_FREESWITCH_INSTALL_METHOD to packages or source."
    exit 1
fi

set_secure_env_value "$INSTALLER_STATE_FILE" FSPBX_FREESWITCH_INSTALL_METHOD "$FREESWITCH_INSTALL_METHOD"
export FSPBX_FREESWITCH_INSTALL_METHOD="$FREESWITCH_INSTALL_METHOD"
export FSPBX_PREVIOUS_FREESWITCH_INSTALL_METHOD="$PREVIOUS_FREESWITCH_INSTALL_METHOD"

# Package installs need a SignalWire token. Resolve it now so the FreeSWITCH
# resource script never stops midway through installation to ask for a secret.
if [ "$FREESWITCH_INSTALL_METHOD" = packages ]; then
    switch_token=$(resolve_signalwire_token \
        "$switch_token" \
        "$INSTALLER_STATE_FILE" \
        /etc/apt/auth.conf.d/freeswitch.conf)

    # Sanitize token: trim whitespace and surrounding quotes
    switch_token=$(printf '%s' "$switch_token" | sed -e 's/^[[:space:]"'"'"']*//' -e 's/[[:space:]"'"'"']*$//')

    if [ -z "$switch_token" ]; then
        echo ""
        warning "A SignalWire Personal Access Token is required for packages."
        read -rsp "Enter your Personal Access Token: " switch_token
        echo ""

        switch_token=$(printf '%s' "$switch_token" | sed -e 's/^[[:space:]"'"'"']*//' -e 's/[[:space:]"'"'"']*$//')
        if [ -z "$switch_token" ]; then
            error "A token is required to install FreeSWITCH packages."
            exit 1
        fi
    else
        verbose "Reusing the existing SignalWire Personal Access Token"
    fi

    set_secure_env_value "$INSTALLER_STATE_FILE" SWITCH_TOKEN "$switch_token"
    export FSPBX_SWITCH_TOKEN="$switch_token"
fi

# A source build already on the server can be preserved or rebuilt. Asking now
# ensures the later FreeSWITCH step runs without an unexpected pause.
if [ "$FREESWITCH_INSTALL_METHOD" = source ] \
    && [ -x /usr/bin/freeswitch ] \
    && [ -f /usr/src/freeswitch/Makefile ]; then
    recompile_source=false

    if [ -t 0 ]; then
        read -rp "Recompile FreeSWITCH from source? [y/N] " recompile_choice
        case "${recompile_choice,,}" in
            y|yes) recompile_source=true ;;
        esac
    fi

    export FSPBX_RECOMPILE_SOURCE="$recompile_source"
fi

# Gather the initial administrator mode before execution starts.
# When an administrator has already been created, setup is marked complete.
# Otherwise, interactive installs always prompt the operator with the active or
# default mode, while non-interactive installs require an explicit or saved mode.
saved_initial_admin_mode=$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_INITIAL_ADMIN_MODE)
requested_initial_admin_mode="${FSPBX_INITIAL_ADMIN_MODE:-}"
case "$saved_initial_admin_mode" in
    installer|activation-code|trusted-network)
        FSPBX_INITIAL_ADMIN_MODE="$saved_initial_admin_mode"
        ;;
    *)
        case "$requested_initial_admin_mode" in
            installer|activation-code|trusted-network)
                FSPBX_INITIAL_ADMIN_MODE="$requested_initial_admin_mode"
                ;;
            *)
                FSPBX_INITIAL_ADMIN_MODE=installer
                ;;
        esac
        ;;
esac

if [ "$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_ADMIN_INITIALIZED)" = true ]; then
    verbose "Administrator setup already completed"
elif [ -t 0 ]; then
    prompt_initial_admin_mode "$FSPBX_INITIAL_ADMIN_MODE"
elif [ -z "$saved_initial_admin_mode" ] && [ -z "$requested_initial_admin_mode" ]; then
    error "A non-interactive install must set FSPBX_INITIAL_ADMIN_MODE to installer, activation-code, or trusted-network."
    exit 1
fi

set_secure_env_value "$INSTALLER_STATE_FILE" FSPBX_INITIAL_ADMIN_MODE "$FSPBX_INITIAL_ADMIN_MODE"
export FSPBX_INITIAL_ADMIN_MODE

# Installer mode is the only choice that needs credentials before application
# work starts. They are saved root-only for a failed-run retry and are passed to
# Laravel only for the one shared administrator-creation command.
if [ "$FSPBX_INITIAL_ADMIN_MODE" = installer ] \
    && [ "$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_ADMIN_INITIALIZED)" != true ]; then
    default_admin_username="${system_username:-admin@tallpbx.local}"
    admin_username=$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_ADMIN_USERNAME)
    admin_password=$(get_env_value "$INSTALLER_STATE_FILE" FSPBX_ADMIN_PASSWORD)

    if [ -z "$admin_username" ] || [ -z "$admin_password" ]; then
        # A run without a terminal cannot answer the questions below; the
        # password loop would wait at end-of-input forever. Stop with clear
        # guidance instead of hanging.
        if [ ! -t 0 ]; then
            error "A non-interactive install cannot ask for administrator credentials."
            error "Pre-seed /etc/pbx/installer.env with FSPBX_ADMIN_USERNAME and FSPBX_ADMIN_PASSWORD, or choose FSPBX_INITIAL_ADMIN_MODE=activation-code."
            exit 1
        fi

        echo ""
        verbose "Administrator account details"
        verbose "Default email: $default_admin_username"
        read -rp "Administrator email [$default_admin_username]: " admin_username_input

        admin_username="${admin_username_input:-$default_admin_username}"

        # A password is never printed or given a known fallback. Confirmation
        # prevents a typing mistake from locking out the first administrator.
        while true; do
            read -rsp "Admin password: " admin_password
            echo ""
            read -rsp "Confirm admin password: " admin_password_confirm
            echo ""

            if [ -z "$admin_password" ]; then
                warning "Admin password cannot be empty. Please try again."
            elif [ "$admin_password" != "$admin_password_confirm" ]; then
                warning "Admin passwords do not match. Please try again."
            else
                break
            fi
        done

        set_secure_env_value "$INSTALLER_STATE_FILE" FSPBX_ADMIN_USERNAME "$admin_username"
        set_secure_env_value "$INSTALLER_STATE_FILE" FSPBX_ADMIN_PASSWORD "$admin_password"
    fi

    export FSPBX_ADMIN_USERNAME="$admin_username"
    export FSPBX_ADMIN_PASSWORD="$admin_password"
fi

# --- Error tracking ---
# Keep a list of any failed prerequisite steps so the summary can name each
# one. The installer may continue through independent system setup, but exits
# unsuccessfully and can always be re-run after the reported issue is fixed.
FAILED_STEPS=()

# Total number of major installer steps, used for the progress counter.
INSTALLER_TOTAL_STEPS=6
INSTALLER_CURRENT_STEP=0

# Run an installer step and track whether it succeeded or failed.
# Shows a [1/6] progress counter so the operator can see how far along the
# install is.
run_step () {
    local step_name="$1"
    local step_script="$2"

    INSTALLER_CURRENT_STEP=$((INSTALLER_CURRENT_STEP + 1))

    echo ""
    verbose "[$INSTALLER_CURRENT_STEP/$INSTALLER_TOTAL_STEPS] --- $step_name ---"

    # Resource scripts are part of the checked-out source. Invoke them through
    # Bash so an installer rerun does not change their tracked file modes.
    if [[ ! -f "$step_script" ]]; then
        error "✗ $step_name failed: script '$step_script' not found"
        FAILED_STEPS+=("$step_name")
        return 1
    fi

    if bash "$step_script"; then
        verbose "✓ $step_name completed successfully"
    else
        local exit_code=$?
        error "✗ $step_name failed (exit code: $exit_code)"
        FAILED_STEPS+=("$step_name")
        return 1
    fi
}

# --- System prep ---
# Remove the CD-ROM from apt's sources (it's not needed on a server)
sed -i '/cdrom:/d' /etc/apt/sources.list

# Update all system packages to the latest versions. Use separate, checked
# commands so a failed upgrade cannot be hidden by an && list.
verbose "Updating system packages"
apt_get_with_lock_wait update
apt_get_with_lock_wait upgrade -y

# Install basic tools needed by the rest of the installer. This runs only
# after the lock-aware update and upgrade steps have completed successfully.
verbose "Installing core dependencies"
apt_get_with_lock_wait install -y \
    git \
    wget \
    curl \
    lsb-release \
    ca-certificates \
    gnupg2 \
    net-tools \
    unzip \
    sudo

# Some VPS providers hand out a global IPv6 address without a default IPv6
# route. PHP then tries the IPv6 address first when it downloads files (for
# example the Composer installer), waits for the connection to time out, and
# the installer fails. When that condition is detected, ask the system's
# name-resolution policy to prefer IPv4 addresses. Hosts with a working IPv6
# default route are left exactly as they are.
case "$(ipv6_default_route_state)" in
    absent)
        ensure_ipv4_precedence
        verbose "No IPv6 default route found; configured the system to prefer IPv4 so downloads do not time out."
        ;;
    unknown)
        warning "Could not inspect IPv6 routes; skipping the IPv4 preference check."
        ;;
esac

# The application clone is a fixed, installer-managed deployment path. Trust it
# system-wide so administrators can use Git even when runtime files are owned
# by www-data. Git cannot read repository-local configuration before this trust
# decision, so this setting must live outside the repository itself.
application_root=/var/www/tallpbx
if ! git config --system --get-all safe.directory 2>/dev/null | grep -Fxq "$application_root"; then
    git config --system --add safe.directory "$application_root"
fi

# Node.js via NodeSource (more up-to-date than Debian packages)
# First, remove any Node.js or npm that came from Debian's repositories,
# because having both installed can cause conflicts.
if dpkg -l nodejs npm 2>/dev/null | grep -q '^ii'; then
    verbose "Removing existing Debian nodejs/npm packages"
    apt_get_with_lock_wait remove -y nodejs npm
    apt_get_with_lock_wait autoremove -y
fi
verbose "Installing Node.js $node_version from NodeSource"
# Add the NodeSource package repository and install Node.js from it
curl -fsSL "https://deb.nodesource.com/setup_${node_version}.x" | bash -
apt_get_with_lock_wait install -y nodejs

# --- Run installer steps ---
run_step "PHP $php_version" resources/php.sh
run_step "MariaDB" resources/mariadb.sh

run_step "FreeSWITCH" resources/freeswitch.sh
run_step "Nginx" resources/nginx.sh

# The application cannot be considered installed when its migrations or seed
# data fail. Stop before permission reconciliation can obscure that failure.
if ! run_step "TALL Stack (Laravel, Livewire, Tailwind, DaisyUI)" resources/tall.sh; then
    error "Stopping because the application setup failed. Fix the reported error and re-run the installer."
    exit 1
fi

# Configure host-level packet filtering (nftables), the bounded security helper,
# and baseline rules for VoIP, Web, and SSH services. This protects the host from
# brute-force and port scanning attacks while ensuring all administrative and phone
# traffic remains fully operational.
run_step "Security & Firewall (nftables)" resources/security.sh

# Reconcile source and generated-file permissions after installer re-runs.
if [ -f /var/www/tallpbx/artisan ]; then
    (cd /var/www/tallpbx && php artisan permissions:repair --scope=full)
fi

# PHP-FPM begins during the package step, before Laravel writes its final .env
# and optimized configuration. Restart it only after those files and their
# permissions are complete so web requests use the finished installation. A
# restart is safe on re-runs and must succeed before the installer reports a
# working PBX or shows a browser activation code.
verbose "Restarting PHP-FPM with the completed TallPBX configuration"
if ! systemctl restart "php$php_version-fpm"; then
    error "PHP-FPM did not restart. Review its systemd status, fix the reported issue, and re-run the installer."
    exit 1
fi

# Configure the first administrator only after every package, service, and
# application step has succeeded. This keeps the activation code at the end of
# a successful install, while the shared Laravel command still owns all account
# and activation-code rules. Safe re-runs preserve an existing administrator or
# pending activation code instead of replacing either one.
configure_initial_administrator () {
    local admin_exists

    admin_exists=$(cd /var/www/tallpbx && php artisan tinker --execute='echo \App\Models\Admin::query()->exists() ? "true" : "false";' --no-interaction --no-ansi | tr -d '\r\n')

    if [ "$admin_exists" != true ] && [ "$admin_exists" != false ]; then
        error "Unable to determine whether an administrator already exists"
        return 1
    fi

    if [ "$admin_exists" = false ]; then
        case "$FSPBX_INITIAL_ADMIN_MODE" in
            installer)
                if [ -z "${FSPBX_ADMIN_USERNAME:-}" ] || [ -z "${FSPBX_ADMIN_PASSWORD:-}" ]; then
                    error "Installer mode requires FSPBX_ADMIN_USERNAME and FSPBX_ADMIN_PASSWORD."
                    return 1
                fi

                (cd /var/www/tallpbx && php artisan initial-admin:configure installer) || return 1
                ;;
            activation-code)
                # Keep the one-time code in the installer output and log so an
                # administrator can recover it after the terminal closes.
                # Laravel creates it once and preserves it on a re-run until
                # the browser setup form has used it.
                (cd /var/www/tallpbx && php artisan initial-admin:configure activation-code) || return 1
                ;;
            trusted-network)
                (cd /var/www/tallpbx && php artisan initial-admin:configure trusted-network) || return 1
                ;;
            *)
                error "Initial administrator mode must be installer, activation-code, or trusted-network."
                return 1
                ;;
        esac

        admin_exists=$(cd /var/www/tallpbx && php artisan tinker --execute='echo \App\Models\Admin::query()->exists() ? "true" : "false";' --no-interaction --no-ansi | tr -d '\r\n')
    fi

    # Only a real account finishes administrator setup. Browser modes remain
    # pending until their first administrator submits the web setup form.
    if [ "$admin_exists" = true ]; then
        set_secure_env_value "$INSTALLER_STATE_FILE" FSPBX_ADMIN_INITIALIZED true
    fi
}

# The activation code is intentionally the final setup output, after all work
# that could still fail. Stop here if the shared Laravel setup command fails.
if ! configure_initial_administrator; then
    error "Stopping because initial administrator setup failed. Fix the reported error and re-run the installer."
    exit 1
fi

# --- Summary ---
elapsed=$(( SECONDS - INSTALL_START_SECONDS ))
elapsed_minutes=$(( elapsed / 60 ))
elapsed_seconds=$(( elapsed % 60 ))

echo ""

if [ ${#FAILED_STEPS[@]} -gt 0 ]; then
    error "Installation finished with ${#FAILED_STEPS[@]} failed step(s):"
    for step in "${FAILED_STEPS[@]}"; do
        error "  ✗ $step"
    done
    echo ""
    error "Review the log file for details: $LOG_FILE"
    echo ""
    warning "Fix the reported issue and re-run the installer; completed steps are designed to be safe to repeat."
    exit 1
else
    echo ""
    verbose "╔══════════════════════════════════════════════════════════╗"
    verbose "║            TallPBX Installation Complete!                ║"
    verbose "╠══════════════════════════════════════════════════════════╣"
    echo ""
    verbose "  Web Panel:    http://$(hostname -I | awk '{print $1}')/panel/login"
    verbose "  FreeSWITCH:   fs_cli -x 'status'"
    verbose "  Log file:     $LOG_FILE"
    echo ""
    if [ "$DEMO_MODE" = true ]; then
        verbose "  Demo data:    2 tenants (TallPBX, Acme Corp), 4 extensions"
        verbose "                4 users (1 shared across both tenants)"
        echo ""
    fi
    verbose "  Next steps:"
    verbose "    • Sign in and configure your first extensions"
    verbose "    • Add a SIP trunk for external calling"
    verbose "    • Set up HTTPS: see INSTALL.md Section 7"
    verbose "    • Configure email: Panel → Email Connector"
    echo ""
    verbose "  Completed in ${elapsed_minutes}m ${elapsed_seconds}s"
    echo ""
    verbose "╚══════════════════════════════════════════════════════════╝"
fi
