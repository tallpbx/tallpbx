#!/bin/bash
# ==============================================================================
# PHP-FPM POOL SHAPE
# ==============================================================================
# Writes the documented PHP-FPM worker-pool shape into every installed PHP
# version's pool file, validates the result, then reloads only the versions it
# changed.
#
# This file is the single implementation of the memory -> worker-ceiling table.
# It has two callers so the values cannot drift apart:
#   1. scripts/resources/php.sh    - installer step (reload suppressed, because
#                                    the installer restarts PHP-FPM afterwards)
#   2. appliance:sync-host         - after a snapshot restore or an IP change,
#                                    where the pool file arrives with the
#                                    snapshot and may describe other hardware
#
# Benchmark cells are deliberately not a caller either:
# load-testing/scripts/lib/switch.sh writes disposable per-cell shapes,
# including `static` pools this script never writes, and relies on its own
# snapshot/restore instead of a config test. Do not merge the two - the harness
# runs against staged remote checkouts that may predate this file.
#
# Safe to re-run: a pool file whose values already match is left byte-identical
# and reported as unchanged.
#
# The six directives it owns are the ones the worker sweep measured:
#   pm = dynamic, pm.start_servers = 1, pm.min_spare_servers = 1,
#   pm.max_spare_servers = 2, pm.max_requests = 10000, and
#   pm.max_children = 3 (1 GB) / 6 (2 GB) / 12 (4 GB+).
#
# Environment overrides (all optional, used by tests and by the override key):
#   FPM_POOL_BASE                    directory holding <version>/fpm/pool.d/
#   FPM_POOL_MEMINFO                 file reporting MemTotal
#   FPM_POOL_MAX_CHILDREN_OVERRIDE   pins the ceiling instead of the RAM table
#   FPM_POOL_PHP_FPM_BIN             validator binary instead of php-fpm<version>
# ==============================================================================

# Print a diagnostic line on stderr. Results stay on stdout so a caller can
# parse them without filtering prose.
fpm_pool_note() {
    printf 'note: %s\n' "$1" >&2
}

# Print the host's physical memory in megabytes, rounded down as `free -m`
# does. An unreadable or malformed source falls back to the smallest tier,
# because guessing high would open more workers than the host can afford.
fpm_pool_total_mb() {
    local meminfo="${FPM_POOL_MEMINFO:-/proc/meminfo}"
    local kb=""

    if [ -r "$meminfo" ]; then
        kb=$(awk '/^MemTotal:/{print $2; exit}' "$meminfo" 2>/dev/null)
    fi

    case "$kb" in
        ''|*[!0-9]*)
            fpm_pool_note "could not read MemTotal from $meminfo; assuming 1024 MB"
            printf '1024'
            return 0
            ;;
    esac

    printf '%s' "$((kb / 1024))"
}

# Print the worker ceiling for a given amount of memory. An administrator may
# pin a host to a published tier with PHP_FPM_MAX_CHILDREN (see config/php-fpm.php)
# when its RAM does not match the hardware a benchmark used; anything that is
# not a positive integer is reported and ignored so a typo cannot take the pool
# to zero workers.
fpm_pool_target_max_children() {
    local total_mb="$1"
    local override="${FPM_POOL_MAX_CHILDREN_OVERRIDE:-}"

    if [ -n "$override" ]; then
        case "$override" in
            *[!0-9]*)
                fpm_pool_note "ignoring PHP_FPM_MAX_CHILDREN='$override' (not a whole number)"
                ;;
            *)
                if [ "$override" -ge 1 ]; then
                    printf '%s' "$override"
                    return 0
                fi
                fpm_pool_note "ignoring PHP_FPM_MAX_CHILDREN='$override' (must be 1 or more)"
                ;;
        esac
    fi

    if [ "$total_mb" -ge 3500 ]; then
        printf '12'
    elif [ "$total_mb" -ge 1800 ]; then
        printf '6'
    else
        printf '3'
    fi
}

# Extract the PHP version from a pool path such as
# /etc/php/8.5/fpm/pool.d/www.conf -> 8.5. Returns non-zero when the path does
# not carry a version, so the caller can report it instead of guessing a unit.
fpm_pool_version_from_path() {
    local pool_file="$1"
    local base="$2"
    local relative="${pool_file#"$base"/}"
    local version="${relative%%/*}"

    case "$version" in
        *[!0-9.]*|.*|*.)
            return 1
            ;;
        *.*)
            printf '%s' "$version"
            ;;
        *)
            return 1
            ;;
    esac
}

# Rewrite the six process-manager directives in a pool file. Each pattern also
# matches the commented-out Debian defaults, so a stock file is fully shaped
# rather than partially overridden.
fpm_pool_rewrite() {
    local file="$1"
    local max_children="$2"

    sed -i -E \
        -e 's/^[; ]*pm\s*=\s*.*/pm = dynamic/' \
        -e "s/^[; ]*pm\.max_children\s*=\s*.*/pm.max_children = ${max_children}/" \
        -e 's/^[; ]*pm\.start_servers\s*=\s*.*/pm.start_servers = 1/' \
        -e 's/^[; ]*pm\.min_spare_servers\s*=\s*.*/pm.min_spare_servers = 1/' \
        -e 's/^[; ]*pm\.max_spare_servers\s*=\s*.*/pm.max_spare_servers = 2/' \
        -e 's/^[; ]*pm\.max_requests\s*=\s*.*/pm.max_requests = 10000/' \
        "$file"
}

# Ask PHP itself whether the rewritten pool is acceptable. The main config test
# reads pool.d/, so this validates the file in place - which is why the caller
# restores the previous copy when it fails.
fpm_pool_validate() {
    local version="$1"
    local binary="${FPM_POOL_PHP_FPM_BIN:-}"
    local output=""

    if [ -z "$binary" ]; then
        binary=$(command -v "php-fpm${version}" 2>/dev/null || command -v php-fpm 2>/dev/null || true)
    fi

    if [ -z "$binary" ]; then
        fpm_pool_note "no php-fpm binary found for ${version}; refusing to reload unvalidated config"
        return 1
    fi

    if ! output=$("$binary" -t 2>&1); then
        fpm_pool_note "${binary} -t rejected the rewritten pool: ${output}"
        return 1
    fi
}

# Reload one version's PHP-FPM unit and confirm it came back. A reload is
# graceful; a restart is only used when the unit had no running master to
# signal, which is the same fallback the appliance CLI offers.
fpm_pool_reload() {
    local version="$1"
    local unit="php${version}-fpm"

    if [ "${FPM_POOL_RELOAD:-yes}" = "none" ]; then
        return 0
    fi

    if ! systemctl reload "$unit" >/dev/null 2>&1; then
        if ! systemctl restart "$unit" >/dev/null 2>&1; then
            fpm_pool_note "could not reload or restart $unit"
            return 1
        fi
    fi

    if ! systemctl is-active --quiet "$unit"; then
        fpm_pool_note "$unit is not active after reload"
        return 1
    fi
}

# Apply the shape to a single pool file and print one machine-readable result
# line. Returns non-zero when the file could not be brought to the target
# shape, so the caller can exit non-zero without stopping on the first failure.
fpm_pool_apply_one() {
    local pool_file="$1"
    local base="$2"
    local max_children="$3"
    local total_mb="$4"
    local version tmp backup changed reload_state

    if ! version=$(fpm_pool_version_from_path "$pool_file" "$base"); then
        fpm_pool_note "unrecognised PHP version in $pool_file"
        printf 'RESULT pool=%s total_mb=%s max_children=%s changed=0 config=skipped reload=skipped reason=unrecognised-version\n' \
            "$pool_file" "$total_mb" "$max_children"
        return 1
    fi

    # A pool file without the directive cannot be shaped; writing only the
    # other five would recreate the drift this script exists to remove.
    if ! grep -Eq '^[; ]*pm\.max_children[[:space:]]*=' "$pool_file"; then
        fpm_pool_note "$pool_file has no pm.max_children directive"
        printf 'RESULT pool=%s version=%s total_mb=%s max_children=%s changed=0 config=skipped reload=skipped reason=missing-pm-max-children\n' \
            "$pool_file" "$version" "$total_mb" "$max_children"
        return 1
    fi

    # Stage the rewrite beside the original so the final move is atomic, and
    # copy metadata first so the pool file keeps its owner, group and mode.
    tmp="${pool_file}.tallpbx-tmp"
    backup="${pool_file}.tallpbx-orig"

    if ! cp -p "$pool_file" "$tmp" 2>/dev/null; then
        fpm_pool_note "$pool_file is not writable"
        printf 'RESULT pool=%s version=%s total_mb=%s max_children=%s changed=0 config=skipped reload=skipped reason=not-writable\n' \
            "$pool_file" "$version" "$total_mb" "$max_children"
        return 1
    fi

    fpm_pool_rewrite "$tmp" "$max_children"

    if cmp -s "$pool_file" "$tmp"; then
        changed=0
    else
        changed=1
    fi

    # Dry run reports what would change and leaves the host untouched.
    if [ "${FPM_POOL_DRY_RUN:-no}" = "yes" ]; then
        rm -f "$tmp"
        printf 'RESULT pool=%s version=%s total_mb=%s max_children=%s changed=%s config=dry-run reload=skipped\n' \
            "$pool_file" "$version" "$total_mb" "$max_children" "$changed"
        return 0
    fi

    if [ "$changed" -eq 0 ]; then
        rm -f "$tmp"
        printf 'RESULT pool=%s version=%s total_mb=%s max_children=%s changed=0 config=ok reload=skipped\n' \
            "$pool_file" "$version" "$total_mb" "$max_children"
        return 0
    fi

    if ! cp -p "$pool_file" "$backup" 2>/dev/null; then
        rm -f "$tmp"
        fpm_pool_note "could not stage a backup of $pool_file"
        return 1
    fi

    if ! mv -f "$tmp" "$pool_file"; then
        rm -f "$tmp" "$backup"
        fpm_pool_note "could not replace $pool_file"
        return 1
    fi

    if ! fpm_pool_validate "$version"; then
        # Put the previous file back: a pool that fails the config test would
        # stop the unit from starting at all.
        mv -f "$backup" "$pool_file"
        rm -f "$backup"
        printf 'RESULT pool=%s version=%s total_mb=%s max_children=%s changed=0 config=fail reload=skipped\n' \
            "$pool_file" "$version" "$total_mb" "$max_children"
        return 1
    fi

    rm -f "$backup"

    if ! fpm_pool_reload "$version"; then
        printf 'RESULT pool=%s version=%s total_mb=%s max_children=%s changed=1 config=ok reload=fail\n' \
            "$pool_file" "$version" "$total_mb" "$max_children"
        return 1
    fi

    reload_state="ok"
    if [ "${FPM_POOL_RELOAD:-yes}" = "none" ]; then
        reload_state="skipped"
    fi

    printf 'RESULT pool=%s version=%s total_mb=%s max_children=%s changed=1 config=ok reload=%s\n' \
        "$pool_file" "$version" "$total_mb" "$max_children" "$reload_state"
    return 0
}

# Entry point shared by the installer step and appliance:sync-host. Accepts
# --dry-run and --reload=none so both callers use one code path, then walks
# every installed PHP version's pool file.
fpm_pool_main() {
    local base="${FPM_POOL_BASE:-/etc/php}"
    local total_mb max_children
    local pool_files=()
    local pool_file status=0
    local had_nullglob

    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dry-run)
                FPM_POOL_DRY_RUN=yes
                shift
                ;;
            --reload=*)
                FPM_POOL_RELOAD="${1#--reload=}"
                shift
                ;;
            *)
                fpm_pool_note "ignoring unknown argument: $1"
                shift
                ;;
        esac
    done

    total_mb=$(fpm_pool_total_mb)
    max_children=$(fpm_pool_target_max_children "$total_mb")

    # Collect every installed version without letting an empty glob reach the
    # loop as a literal path.
    had_nullglob=$(shopt -p nullglob)
    shopt -s nullglob
    pool_files=( "$base"/*/fpm/pool.d/www.conf )
    eval "$had_nullglob"

    if [ "${#pool_files[@]}" -eq 0 ]; then
        fpm_pool_note "no PHP-FPM pool files found under $base"
        printf 'RESULT pool=none total_mb=%s max_children=%s changed=0 config=skipped reload=skipped reason=no-pool-files\n' \
            "$total_mb" "$max_children"
        return 0
    fi

    for pool_file in "${pool_files[@]}"; do
        fpm_pool_apply_one "$pool_file" "$base" "$max_children" "$total_mb" || status=1
    done

    return "$status"
}

# Only run when executed directly. scripts/resources/php.sh sources this file
# and calls fpm_pool_main itself, with the reload suppressed.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    fpm_pool_main "$@"
fi
