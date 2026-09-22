#!/bin/bash

os_name=$(lsb_release -is)
os_codename=$(lsb_release -cs)
cpu_architecture=$(uname -m)

# Run a required APT operation while Debian finishes any unattended updates.
# A new server can briefly lock its package database after first boot. Waiting
# keeps resource scripts from continuing with missing software; a real package
# failure stops the current installer run so a re-run can recover safely.
apt_get_with_lock_wait () {
    local lock_timeout_seconds="${FSPBX_APT_LOCK_TIMEOUT_SECONDS:-300}"

    if ! apt-get -o "DPkg::Lock::Timeout=${lock_timeout_seconds}" "$@"; then
        error "Unable to complete the required APT command after waiting for the package manager lock."
        error "Wait for unattended upgrades to finish, fix any reported package error, then re-run the installer."
        exit 1
    fi
}

# Run an optional APT operation with the same lock wait. The caller may handle
# a missing optional package, but a transient package lock is never mistaken
# for a normal absence because APT waits before returning its result.
apt_get_optional_with_lock_wait () {
    local lock_timeout_seconds="${FSPBX_APT_LOCK_TIMEOUT_SECONDS:-300}"

    apt-get -o "DPkg::Lock::Timeout=${lock_timeout_seconds}" "$@"
}

# Write one environment key exactly once, replacing active or commented forms.
# A temporary file prevents a partially written configuration if this process
# stops while the value is being updated.
set_env_value () {
    local env_file="$1"
    local key="$2"
    local value="$3"
    local temp_file

    temp_file=$(mktemp "${env_file}.tmp.XXXXXX")

    awk -v key="$key" -v value="$value" '
        BEGIN { found = 0 }
        {
            candidate = $0
            sub(/^[[:space:]]*#[[:space:]]*/, "", candidate)
            sub(/^[[:space:]]*/, "", candidate)

            if (index(candidate, key "=") == 1) {
                if (! found) {
                    print key "=" value
                    found = 1
                }

                next
            }

            print
        }
        END {
            if (! found) {
                print key "=" value
            }
        }
    ' "$env_file" > "$temp_file"

    chmod --reference="$env_file" "$temp_file"
    mv "$temp_file" "$env_file"
}

# Read an active environment value without evaluating the file as shell code.
# This prevents values in a configuration file from running commands here.
get_env_value () {
    local env_file="$1"
    local key="$2"

    if [ ! -f "$env_file" ]; then
        return
    fi

    awk -v key="$key" '
        index($0, key "=") == 1 {
            print substr($0, length(key) + 2)
            exit
        }
    ' "$env_file"
}

# Return a persisted installer boolean only when it has an explicit value.
resolve_boolean_env_value () {
    local env_file="$1"
    local key="$2"
    local value

    value=$(get_env_value "$env_file" "$key")

    if [ "$value" = true ] || [ "$value" = false ]; then
        printf '%s\n' "$value"
        return 0
    fi

    return 1
}

# Store an installer secret in a root-only environment file. The directory and
# file permissions stop ordinary users and the web server from reading secrets.
set_secure_env_value () {
    local env_file="$1"
    local key="$2"
    local value="$3"
    local env_directory

    env_directory=$(dirname "$env_file")
    install -d -m 700 "$env_directory"

    if [ ! -f "$env_file" ]; then
        install -m 600 /dev/null "$env_file"
    fi

    if [ "$(get_env_value "$env_file" "$key")" != "$value" ]; then
        set_env_value "$env_file" "$key" "$value"
    fi

    chmod 600 "$env_file"
}

# Resolve a configured password from durable state or the deployed application.
# This preserves a working database login when the installer is run again.
resolve_database_password () {
    local configured_password="$1"
    local state_file="$2"
    local application_environment_file="$3"
    local saved_password

    if [ "$configured_password" != "random" ]; then
        printf '%s\n' "$configured_password"
        return
    fi

    saved_password=$(get_env_value "$state_file" DB_PASSWORD)

    if [ -z "$saved_password" ]; then
        saved_password=$(get_env_value "$application_environment_file" DB_PASSWORD)
    fi

    printf '%s\n' "$saved_password"
}

# Read the SignalWire password from an existing APT authentication file. This
# supports older installations that saved the token only for apt access.
get_apt_auth_password () {
    local auth_file="$1"

    if [ ! -f "$auth_file" ]; then
        return
    fi

    awk '
        $1 == "machine" && $2 == "freeswitch.signalwire.com" {
            for (field = 3; field <= NF; field++) {
                if ($field == "password" && field < NF) {
                    print $(field + 1)
                    exit
                }
            }
        }
    ' "$auth_file"
}

# Resolve a SignalWire token from config, durable state, or existing APT auth.
# The order lets an explicit current value override older saved values.
resolve_signalwire_token () {
    local configured_token="$1"
    local state_file="$2"
    local apt_auth_file="$3"
    local saved_token

    if [ -n "$configured_token" ]; then
        printf '%s\n' "$configured_token"
        return
    fi

    saved_token=$(get_env_value "$state_file" SWITCH_TOKEN)

    if [ -z "$saved_token" ]; then
        saved_token=$(get_apt_auth_password "$apt_auth_file")
    fi

    printf '%s\n' "$saved_token"
}

# Report whether this host has a default IPv6 route. The result drives the
# installer's IPv4 preference fix:
#   present - the kernel routes IPv6 traffic somewhere; leave the system alone
#   absent  - there is no default IPv6 route; PHP would keep trying dead IPv6
#             addresses first when downloading files
#   unknown - the check could not run (for example the ip command is missing),
#             so callers must leave the system alone
ipv6_default_route_state () {
    if ! command -v ip >/dev/null 2>&1; then
        printf 'unknown\n'
        return
    fi

    if [ -n "$(ip -6 route show default 2>/dev/null)" ]; then
        printf 'present\n'
    else
        printf 'absent\n'
    fi
}

# Make the system prefer IPv4 addresses when a hostname offers both IPv4 and
# IPv6. This activates the IPv4 precedence line in the name-resolution policy
# file (/etc/gai.conf by default). Running it again changes nothing. Tests can
# point FSPBX_GAI_CONF at a temporary file.
ensure_ipv4_precedence () {
    local gai_file="${FSPBX_GAI_CONF:-/etc/gai.conf}"
    local precedence_rule='precedence ::ffff:0:0/96  100'

    # Already active: nothing to do.
    if grep -Eq '^[[:space:]]*precedence[[:space:]]+::ffff:0:0/96[[:space:]]+100[[:space:]]*$' "$gai_file" 2>/dev/null; then
        return
    fi

    if [ -f "$gai_file" ]; then
        # Debian ships the rule commented out; activate that exact line.
        sed -i -E 's|^#[[:space:]]*precedence[[:space:]]+::ffff:0:0/96[[:space:]]+100[[:space:]]*$|precedence ::ffff:0:0/96  100|' "$gai_file"
    fi

    # Either the file did not exist or it carried no precedence line: append
    # the rule so the IPv4 preference is definitely in place.
    if ! grep -Eq '^[[:space:]]*precedence[[:space:]]+::ffff:0:0/96[[:space:]]+100[[:space:]]*$' "$gai_file" 2>/dev/null; then
        printf '%s\n' "$precedence_rule" >> "$gai_file"
    fi
}
