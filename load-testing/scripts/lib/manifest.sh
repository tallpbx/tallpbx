#!/usr/bin/env bash
# ==============================================================================
# TallPBX Load-Test Manifest Helpers
#
# Thin layer over `pbx:load-test:manifest` used by the matrix runner:
#   - write the manifest for the host under test
#   - read one value out of the JSON
#   - prove the host really is the tier the matrix says it is
#
# Command execution is delegated to `run_on_pbx` when the caller defines it,
# so the same helper works for a remote PBX (over SSH) and for a local
# single-host smoke run.
# ==============================================================================

set -euo pipefail

REMOTE_APP_DIR="${REMOTE_APP_DIR:-/var/www/tallpbx}"

# Run one shell command on the host under test.
#
# The matrix runner defines run_on_pbx to route the command over SSH; when
# the library is used standalone the command runs in this shell.
manifest_execute() {
    local command="$1"

    if declare -F run_on_pbx >/dev/null 2>&1; then
        run_on_pbx "$command"
        return
    fi

    bash -c "$command"
}

# Write manifest.json for the host under test.
manifest_write() {
    local output="$1"

    manifest_execute "mkdir -p $(printf '%q' "$(dirname "$output")") && cd $(printf '%q' "$REMOTE_APP_DIR") && php artisan pbx:load-test:manifest --output=$(printf '%q' "$output")"
}

# Read one dot-separated path out of a manifest file.
#
# Missing keys print an empty string so callers can test for absence without
# treating a malformed manifest as a crash.
manifest_field() {
    local file="$1" path="$2"

    php -r '
        if (!is_file($argv[1])) { exit(0); }
        $data = json_decode((string) file_get_contents($argv[1]), true);

        foreach (explode(".", $argv[2]) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) { exit(0); }
            $data = $data[$key];
        }

        if (is_bool($data)) { echo $data ? "true" : "false"; exit(0); }
        if (is_scalar($data)) { echo (string) $data; }
    ' "$file" "$path"
}

# Prove the host sizing matches the tier the matrix declares.
#
# Running a 2 vCPU cell on a 1 vCPU box silently produces numbers that look
# plausible and mean nothing, so the mismatch fails the tier before any
# measurement starts. RAM gets a 10% tolerance because hypervisors report a
# little less than the nominal size.
manifest_assert_tier() {
    local file="$1" vcpu="$2" ram_mb="$3"
    local host_vcpu host_ram ram_floor ram_ceiling

    host_vcpu="$(manifest_field "$file" host.cpu_cores)"
    host_ram="$(manifest_field "$file" host.memory_mb)"

    if [ -z "$host_vcpu" ]; then
        printf 'TIER-MISMATCH cpu_cores unreadable in %s\n' "$file" >&2
        return 1
    fi

    if [ "$host_vcpu" != "$vcpu" ]; then
        printf 'TIER-MISMATCH expected %s vCPU but host reports %s\n' "$vcpu" "$host_vcpu" >&2
        return 1
    fi

    if [ -z "$host_ram" ]; then
        printf 'TIER-MISMATCH memory_mb unreadable in %s\n' "$file" >&2
        return 1
    fi

    ram_floor=$((ram_mb * 90 / 100))
    ram_ceiling=$((ram_mb * 110 / 100))

    if [ "$host_ram" -lt "$ram_floor" ] || [ "$host_ram" -gt "$ram_ceiling" ]; then
        printf 'TIER-MISMATCH expected about %s MB RAM but host reports %s MB\n' "$ram_mb" "$host_ram" >&2
        return 1
    fi

    printf 'tier sizing verified: %s vCPU / %s MB RAM\n' "$host_vcpu" "$host_ram" >&2
}
