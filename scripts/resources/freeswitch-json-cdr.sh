#!/bin/bash
# ==============================================================================
# FreeSWITCH JSON CDR Helpers
# ==============================================================================
# These functions deploy mod_json_cdr's configuration and its disk failure
# spool. The CDR pipeline depends on two guarantees:
#
#   1. The deployed json_cdr.conf.xml always carries a per-install token; a
#      file rendered without one would authenticate nobody and every call
#      record would be rejected, so rendering fails closed.
#   2. The spool directories exist with a shared group so FreeSWITCH (which
#      writes failures) and the drain command (which reads and removes them,
#      running as www-data) can both work on the same files.
#
# This file only defines functions. Sourcing it never changes the host, which
# keeps it safe to load from tests.
# ==============================================================================

# Render the versioned json_cdr template with the per-install token.
#
# The template keeps a placeholder instead of a literal token so the shared
# secret is never published in the repository.
render_json_cdr_config() {
    local template="$1"
    local target="$2"
    local token="$3"

    if [ -z "$token" ]; then
        printf 'FREESWITCH_CDR_TOKEN is empty; refusing to render json_cdr.conf.xml\n' >&2
        return 1
    fi

    if [ ! -f "$template" ]; then
        printf 'json_cdr template not found: %s\n' "$template" >&2
        return 1
    fi

    sed "s|__TALLPBX_CDR_TOKEN__|${token}|g" "$template" > "$target"
}

# Create the failure spool and quarantine directories.
#
# failed/ receives deliveries that exhausted their retries; quarantine/ holds
# payloads the application could not parse. The set-group-id bit keeps files
# created by FreeSWITCH readable and removable by the drain command.
ensure_json_cdr_spool_dirs() {
    local root="${1:-/var/log/freeswitch/json_cdr}"
    local group="${2:-tallpbx-media}"

    mkdir -p "$root/failed" "$root/quarantine" || return 1

    chown -R freeswitch:"$group" "$root" 2>/dev/null || true
    chmod 775 "$root" "$root/failed" "$root/quarantine" 2>/dev/null || true
    chmod g+s "$root/failed" "$root/quarantine" 2>/dev/null || true
}
