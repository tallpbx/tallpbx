#!/usr/bin/env bash
# ==============================================================================
# TallPBX Load-Test Symmetric Switch Helpers
#
# Every measurement cell must apply a configuration, prove it is live, run the
# profile, and put the host back exactly as it was. This library owns the
# apply/assert/restore half of that contract and runs on the PBX under test.
#
# Design rules:
#   - Every apply_* has a matching assert_* that reads the value back after the
#     reload. A mismatch prints "SWITCH-FAILED <name>" and returns 1 so the
#     orchestrator can abort one cell instead of the whole series.
#   - Nothing here touches nftables. The firewall stays exactly as the
#     application installed it for the duration of every run.
#   - restore_baseline is idempotent and only touches settings this library
#     actually changed, so it is safe to call from a teardown trap.
#
# Usage (single shot, executed on the PBX under test):
#   bash switch.sh apply_fpm_profile static 5
#   bash switch.sh assert_fpm_profile static 5
#   bash switch.sh restore_baseline
#
# A session that exports SWITCH_RESTORE_ON_EXIT=1 additionally gets the
# baseline restored from an EXIT trap even when a cell aborts.
# ==============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/tallpbx}"
FS_CONF_DIR="${FS_CONF_DIR:-/etc/freeswitch}"
FS_DB_PATH="${FS_DB_PATH:-/var/lib/freeswitch/db}"
NGINX_SITE_DIRS="${NGINX_SITE_DIRS:-/etc/nginx/sites-enabled /etc/nginx/conf.d}"
STATE_DIR="${SWITCH_STATE_DIR:-/var/lib/tallpbx/load-test-state}"

mkdir -p "$STATE_DIR"

# Directory holding the FreeSWITCH autoload configuration files.
FS_AUTOLOAD_DIR="${FS_CONF_DIR}/autoload_configs"

# Absolute paths of the files each switch rewrites.
FS_XML_CURL_CONF="${FS_CONF_DIR}/autoload_configs/xml_curl.conf.xml"
FS_JSON_CDR_CONF="${FS_CONF_DIR}/autoload_configs/json_cdr.conf.xml"

# ------------------------------------------------------------------------------
# Logging and failure helpers
# ------------------------------------------------------------------------------

# Write a diagnostic line to stderr so stdout stays machine-readable.
log() {
    printf '[switch] %s\n' "$*" >&2
}

# Announce a failed switch assertion in the format the orchestrator greps for.
fail_switch() {
    printf 'SWITCH-FAILED %s\n' "$*" >&2
}

# Report a hard usage or environment error and exit non-zero.
die() {
    fail_switch "$*"
    exit 1
}

# ------------------------------------------------------------------------------
# Snapshot and restore primitives
# ------------------------------------------------------------------------------

# Copy a file into the state directory the first time it is touched.
#
# The path is recorded next to the copy so restore_baseline can put the exact
# file back even when the pool or site file is discovered dynamically.
snapshot_once() {
    local key="$1" target="$2"

    [ -f "$target" ] || return 0

    if [ ! -f "${STATE_DIR}/${key}.bak" ]; then
        cp -p "$target" "${STATE_DIR}/${key}.bak"
        printf '%s\n' "$target" > "${STATE_DIR}/${key}.path"
    fi
}

# Put one snapshotted file back where it came from.
restore_file() {
    local key="$1" target

    [ -f "${STATE_DIR}/${key}.bak" ] || return 0

    target="$(cat "${STATE_DIR}/${key}.path")"
    cp -p "${STATE_DIR}/${key}.bak" "$target"
    log "restored ${target}"
}

# Remember an nginx file a cell rewrote so the teardown can put it back.
#
# Backups live next to the original (${file}.loadtest.bak) because nginx
# paths are discovered at apply time; the list is what makes restoration
# possible from a different process.
remember_nginx_file() {
    local file="$1"

    touch "${STATE_DIR}/nginx-files.list"
    grep -qxF "$file" "${STATE_DIR}/nginx-files.list" || printf '%s\n' "$file" >> "${STATE_DIR}/nginx-files.list"
}

# ------------------------------------------------------------------------------
# PHP-FPM helpers
# ------------------------------------------------------------------------------

# Report the short PHP version (for example 8.5) used in service and socket names.
php_short_version() {
    php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || printf '8.5'
}

# Resolve the PHP-FPM pool file for the running PHP version.
fpm_pool_file() {
    local current version found
    version="$(php_short_version)"
    current="/etc/php/${version}/fpm/pool.d/www.conf"

    if [ -f "$current" ]; then
        printf '%s' "$current"
        return 0
    fi

    # An unmatched glob makes ls fail; return the empty result instead of
    # letting set -e kill the caller.
    found="$(ls -1 /etc/php/*/fpm/pool.d/www.conf 2>/dev/null | head -n 1 || true)"
    printf '%s' "$found"
    return 0
}

# Derive the PHP-FPM systemd unit name from the pool path.
php_fpm_service() {
    local pool version

    pool="$(fpm_pool_file)"

    if [ -n "$pool" ]; then
        version="$(printf '%s' "$pool" | sed -E 's|^/etc/php/([^/]+)/.*|\1|')"
        printf 'php%s-fpm' "$version"
        return 0
    fi

    printf 'php-fpm'
}

# Resolve the PHP-FPM listening socket nginx must forward the fast controller to.
php_fpm_socket() {
    local socket

    socket="$(ls -1 /var/run/php/php*-fpm.sock 2>/dev/null | head -n 1 || true)"

    if [ -z "$socket" ]; then
        socket="/var/run/php/php$(php_short_version)-fpm.sock"
    fi

    printf '%s' "$socket"
}

# Reload a systemd unit, falling back to a restart when reload is unsupported.
service_reload() {
    local unit="$1"

    systemctl reload "$unit" >/dev/null 2>&1 || systemctl restart "$unit" >/dev/null 2>&1
    systemctl is-active --quiet "$unit"
}

# ------------------------------------------------------------------------------
# PHP-FPM pool profile
# ------------------------------------------------------------------------------

# Apply a PHP-FPM process-manager mode and worker cap, then assert the result.
apply_fpm_profile() {
    local mode="${1:-}" children="${2:-}" pool current_mode current_children
    local want_start="" want_min_spare="" want_max_spare="" want_max_requests=""
    local current_start current_min_spare current_max_spare current_max_requests

    case "$mode" in
        static | dynamic) ;;
        *)
            fail_switch "fpm-profile mode must be static or dynamic (got '${mode}')"
            return 1
            ;;
    esac

    if ! [[ "$children" =~ ^[0-9]+$ ]]; then
        fail_switch "fpm-profile children must be numeric (got '${children}')"
        return 1
    fi

    pool="$(fpm_pool_file)"

    if [ -z "$pool" ] || [ ! -f "$pool" ]; then
        fail_switch "php-fpm pool file not found"
        return 1
    fi

    snapshot_once fpm-pool "$pool"

    # Deliberate split of responsibility: this library writes disposable,
    # per-cell profiles - including `static` pools the shipped default never
    # uses - and applies them in place. Correctness here comes from
    # snapshot_once() and restore_baseline(), not from a config test. The
    # shipped shape itself is owned by scripts/resources/php-fpm-pool.sh; do
    # not merge the two, because the harness runs against staged remote
    # checkouts that may predate that script.
    #
    # The installer ships pm = dynamic with ONE resident worker: start and
    # min_spare 1, max_spare 2, and worker recycling at 10,000 requests. A cell
    # that claims to measure "the shipped default" is only faithful when the
    # harness applies those same knobs, so a dynamic profile writes and asserts
    # them alongside mode and ceiling.
    if [ "$mode" = "dynamic" ]; then
        want_start=1
        want_min_spare=1
        want_max_spare=2
        want_max_requests=10000
    fi

    # Skip when the pool already runs the requested shape; restarting php-fpm
    # for an identical file only interrupts the cell.
    current_mode="$(awk -F= '/^pm[[:space:]]*=/{value=$2; gsub(/[[:space:]]/, "", value); print value}' "$pool" | head -1)"
    current_children="$(awk -F= '/^pm\.max_children[[:space:]]*=/{value=$2; gsub(/[[:space:]]/, "", value); print value}' "$pool" | head -1)"
    current_start="$(awk -F= '/^pm\.start_servers[[:space:]]*=/{value=$2; gsub(/[[:space:]]/, "", value); print value}' "$pool" | head -1)"
    current_min_spare="$(awk -F= '/^pm\.min_spare_servers[[:space:]]*=/{value=$2; gsub(/[[:space:]]/, "", value); print value}' "$pool" | head -1)"
    current_max_spare="$(awk -F= '/^pm\.max_spare_servers[[:space:]]*=/{value=$2; gsub(/[[:space:]]/, "", value); print value}' "$pool" | head -1)"
    current_max_requests="$(awk -F= '/^pm\.max_requests[[:space:]]*=/{value=$2; gsub(/[[:space:]]/, "", value); print value}' "$pool" | head -1)"
    if [ "$current_mode" = "$mode" ] && [ "$current_children" = "$children" ] \
        && { [ -z "$want_start" ] || { [ "$current_start" = "$want_start" ] \
            && [ "$current_min_spare" = "$want_min_spare" ] \
            && [ "$current_max_spare" = "$want_max_spare" ] \
            && [ "$current_max_requests" = "$want_max_requests" ]; }; }; then
        log "php-fpm already ${mode}/${children} in ${pool}"
        assert_fpm_profile "$mode" "$children"
        return $?
    fi

    sed -i -E "s/^[;[:space:]]*pm[[:space:]]*=[[:space:]]*.*/pm = ${mode}/" "$pool"
    sed -i -E "s/^[;[:space:]]*pm\.max_children[[:space:]]*=[[:space:]]*.*/pm.max_children = ${children}/" "$pool"

    # Only a dynamic profile carries the shipped floor and recycling bound;
    # a static cell sets the mode and ceiling alone.
    if [ -n "$want_start" ]; then
        sed -i -E "s/^[;[:space:]]*pm\.start_servers[[:space:]]*=[[:space:]]*.*/pm.start_servers = ${want_start}/" "$pool"
        sed -i -E "s/^[;[:space:]]*pm\.min_spare_servers[[:space:]]*=[[:space:]]*.*/pm.min_spare_servers = ${want_min_spare}/" "$pool"
        sed -i -E "s/^[;[:space:]]*pm\.max_spare_servers[[:space:]]*=[[:space:]]*.*/pm.max_spare_servers = ${want_max_spare}/" "$pool"
        sed -i -E "s/^[;[:space:]]*pm\.max_requests[[:space:]]*=[[:space:]]*.*/pm.max_requests = ${want_max_requests}/" "$pool"
    fi

    if ! service_reload "$(php_fpm_service)"; then
        fail_switch "php-fpm did not come back after applying ${mode}/${children}"
        return 1
    fi

    log "applied php-fpm ${mode}/${children} in ${pool}"
    assert_fpm_profile "$mode" "$children"
}

# Read the pool file back and prove the mode and worker cap are live.
assert_fpm_profile() {
    local mode="${1:-}" children="${2:-}" pool current_mode current_children current_max_requests

    pool="$(fpm_pool_file)"

    if [ -z "$pool" ] || [ ! -f "$pool" ]; then
        fail_switch "php-fpm pool file not found while asserting"
        return 1
    fi

    current_mode="$(sed -nE 's/^[[:space:]]*pm[[:space:]]*=[[:space:]]*([a-z]+).*/\1/p' "$pool" | tail -n 1)"
    current_children="$(sed -nE 's/^[[:space:]]*pm\.max_children[[:space:]]*=[[:space:]]*([0-9]+).*/\1/p' "$pool" | tail -n 1)"
    current_max_requests="$(sed -nE 's/^[[:space:]]*pm\.max_requests[[:space:]]*=[[:space:]]*([0-9]+).*/\1/p' "$pool" | tail -n 1)"

    if [ "$current_mode" != "$mode" ] || [ "$current_children" != "$children" ]; then
        fail_switch "fpm-profile expected ${mode}/${children} but read ${current_mode:-unset}/${current_children:-unset}"
        return 1
    fi

    # A dynamic cell also claims the shipped recycling bound, so prove it: a
    # leftover value from the host would silently change what the cell measures.
    if [ "$mode" = "dynamic" ] && [ "$current_max_requests" != "10000" ]; then
        fail_switch "fpm-profile expected pm.max_requests 10000 but read ${current_max_requests:-unset}"
        return 1
    fi

    if ! systemctl is-active --quiet "$(php_fpm_service)"; then
        fail_switch "php-fpm service is not active"
        return 1
    fi

    log "asserted php-fpm ${mode}/${children}"
}

# ------------------------------------------------------------------------------
# FreeSWITCH log level
# ------------------------------------------------------------------------------

# Path of the FreeSWITCH file log the log-level probe reads.
FS_LOG_FILE="${FS_LOG_FILE:-/var/log/freeswitch/freeswitch.log}"

# Map a level to the next more verbose one.
#
# Proving a ceiling needs two probes: a line at the requested level must
# appear, and a line one step more verbose must not.
fs_next_verbose_level() {
    case "$1" in
        alert) printf 'crit' ;;
        crit) printf 'err' ;;
        err) printf 'warning' ;;
        warning) printf 'notice' ;;
        notice) printf 'info' ;;
        info) printf 'debug' ;;
        *) printf '' ;;
    esac
}

# Write one probe line at a level and report whether the file log kept it.
#
# `fsctl loglevel` reports DISABLE on FreeSWITCH 1.11.4 even while logging is
# working, so parsing it produces a false failure. Emitting a known line and
# looking for it is the only trustworthy read-back.
fs_log_probe() {
    local level="$1" marker="$2"

    fs_cli -x "log ${level} ${marker}" >/dev/null 2>&1 || return 1
    sleep 1

    grep -q "${marker}" "$FS_LOG_FILE" 2>/dev/null
}

# Apply a runtime FreeSWITCH log level and prove it with a probe pair.
apply_fs_loglevel() {
    local level="${1:-}"

    case "$level" in
        debug | info | notice | warning | err | crit | alert) ;;
        *)
            fail_switch "fs log level must be one of debug, info, notice, warning, err, crit, alert (got '${level}')"
            return 1
            ;;
    esac

    # The runtime level cannot be read back reliably, so the baseline is the
    # level the application declares in .env; restore_baseline re-applies it.

    if ! fs_cli -x "fsctl loglevel ${level}" >/dev/null 2>&1; then
        fail_switch "fsctl loglevel ${level} was rejected by FreeSWITCH"
        return 1
    fi

    log "applied FreeSWITCH log level ${level}"
    assert_fs_loglevel "$level"
}

# Prove the level is live: the requested level reaches the log, the next one up does not.
assert_fs_loglevel() {
    local level="${1:-}" next stamp keep_marker drop_marker

    if ! command -v fs_cli >/dev/null 2>&1; then
        fail_switch "fs_cli is not installed"
        return 1
    fi

    stamp="TALLPBX-LOGPROBE-$$-$(date +%s)"
    keep_marker="${stamp}-${level}-KEEP"

    if ! fs_log_probe "$level" "$keep_marker"; then
        fail_switch "fs log level ${level} produced no line in ${FS_LOG_FILE}"
        return 1
    fi

    next="$(fs_next_verbose_level "$level")"

    if [ -n "$next" ]; then
        drop_marker="${stamp}-${next}-DROP"

        if fs_log_probe "$next" "$drop_marker"; then
            fail_switch "fs log level ${level} still passes ${next} lines"
            return 1
        fi
    fi

    log "asserted FreeSWITCH log level ${level} (probe pair)"
}

# ------------------------------------------------------------------------------
# FreeSWITCH runtime database placement
# ------------------------------------------------------------------------------

# Report whether the runtime database directory is tmpfs-backed or on disk.
fs_db_mode() {
    local fs_type

    fs_type="$(findmnt -n -o FSTYPE --target "$FS_DB_PATH" 2>/dev/null || true)"

    if [ "$fs_type" = "tmpfs" ]; then
        printf 'tmpfs'
        return 0
    fi

    printf 'disk'
}

# Stop FreeSWITCH and wait for the daemon to exit.
fs_stop() {
    systemctl stop freeswitch >/dev/null 2>&1 || true

    local attempt
    for attempt in $(seq 1 30); do
        if ! systemctl is-active --quiet freeswitch; then
            return 0
        fi
        sleep 1
    done

    return 1
}

# Start FreeSWITCH and wait until the event socket answers commands.
fs_start() {
    systemctl start freeswitch >/dev/null 2>&1 || return 1

    local attempt
    for attempt in $(seq 1 60); do
        if fs_cli -x status >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done

    return 1
}

# Move the FreeSWITCH runtime database between disk and tmpfs, preserving data.
apply_fs_db_mode() {
    local mode="${1:-}" current

    case "$mode" in
        disk | tmpfs) ;;
        *)
            fail_switch "fs db mode must be disk or tmpfs (got '${mode}')"
            return 1
            ;;
    esac

    current="$(fs_db_mode)"

    if [ ! -f "${STATE_DIR}/fs-db-mode.baseline" ]; then
        printf '%s' "$current" > "${STATE_DIR}/fs-db-mode.baseline"
    fi

    if [ "$current" = "$mode" ]; then
        log "FreeSWITCH runtime database already ${mode}"
        assert_fs_db_mode "$mode"
        return
    fi

    if ! fs_stop; then
        fail_switch "FreeSWITCH did not stop before switching the runtime database"
        return 1
    fi

    if [ "$mode" = "tmpfs" ]; then
        rm -rf "${STATE_DIR}/fs-db-copy"
        mkdir -p "${STATE_DIR}/fs-db-copy" "$FS_DB_PATH"
        cp -a "${FS_DB_PATH}/." "${STATE_DIR}/fs-db-copy/" 2>/dev/null || true

        if ! mount -t tmpfs -o size=64m,mode=0750 tmpfs "$FS_DB_PATH"; then
            fs_start || true
            fail_switch "tmpfs mount failed for ${FS_DB_PATH}"
            return 1
        fi

        cp -a "${STATE_DIR}/fs-db-copy/." "$FS_DB_PATH/" 2>/dev/null || true
        chown -R freeswitch:freeswitch "$FS_DB_PATH" 2>/dev/null || true
    else
        if ! umount "$FS_DB_PATH"; then
            fs_start || true
            fail_switch "umount failed for ${FS_DB_PATH}"
            return 1
        fi

        cp -a "${STATE_DIR}/fs-db-copy/." "$FS_DB_PATH/" 2>/dev/null || true
        chown -R freeswitch:freeswitch "$FS_DB_PATH" 2>/dev/null || true
    fi

    if ! fs_start; then
        fail_switch "FreeSWITCH did not come back after switching the runtime database to ${mode}"
        return 1
    fi

    log "applied FreeSWITCH runtime database mode ${mode}"
    assert_fs_db_mode "$mode"
}

# Prove the runtime database directory is backed by the requested filesystem.
assert_fs_db_mode() {
    local mode="${1:-}" current

    current="$(fs_db_mode)"

    if [ "$current" != "$mode" ]; then
        fail_switch "fs db mode expected ${mode} but ${FS_DB_PATH} is ${current}"
        return 1
    fi

    if ! systemctl is-active --quiet freeswitch; then
        fail_switch "freeswitch service is not active"
        return 1
    fi

    log "asserted FreeSWITCH runtime database mode ${mode}"
}

# ------------------------------------------------------------------------------
# nginx XML handler front controller
# ------------------------------------------------------------------------------

# List the nginx files that currently define the XML handler location.
xml_location_files() {
    local directory

    for directory in $NGINX_SITE_DIRS; do
        [ -d "$directory" ] || continue
        grep -l -s 'location = /api/v1/xml-handler' "$directory"/* 2>/dev/null || true
    done | xargs -r readlink -f | sort -u
}

# Print the location block body for the requested front controller.
xml_location_body() {
    local path="$1" socket

    socket="$(php_fpm_socket)"

    if [ "$path" = "fast" ]; then
        cat <<BODY
        access_log off;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root/xml-handler-fast.php;
        fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
        fastcgi_pass unix:${socket};
BODY
        return 0
    fi

    cat <<'BODY'
        access_log off;
        try_files $uri /index.php?$query_string;
BODY
}

# Replace the XML handler location body in one nginx file.
rewrite_xml_location() {
    local file="$1" body_file="$2" backup="${file}.loadtest.bak"

    [ -f "$backup" ] || cp -p "$file" "$backup"

    awk -v body_file="$body_file" '
        /^[[:space:]]*location = \/api\/v1\/xml-handler[[:space:]]*\{/ {
            print
            while ((getline line < body_file) > 0) {
                print line
            }
            close(body_file)
            skipping = 1
            next
        }
        skipping && /^[[:space:]]*\}/ {
            skipping = 0
            print
            next
        }
        skipping { next }
        { print }
    ' "$file" > "${file}.loadtest.tmp"

    mv "${file}.loadtest.tmp" "$file"
    log "rewrote XML handler location in ${file}"
}

# Point the XML handler at the stock router or the fast front controller.
apply_xml_path() {
    local path="${1:-}" body_file files file index=0

    case "$path" in
        stock | fast) ;;
        *)
            fail_switch "xml path must be stock or fast (got '${path}')"
            return 1
            ;;
    esac

    files="$(xml_location_files)"

    if [ -z "$files" ]; then
        fail_switch "no nginx file defines 'location = /api/v1/xml-handler'"
        return 1
    fi

    body_file="${STATE_DIR}/xml-location.body"
    xml_location_body "$path" > "$body_file"

    while IFS= read -r file; do
        [ -n "$file" ] || continue
        rewrite_xml_location "$file" "$body_file"
        remember_nginx_file "$file"
        index=$((index + 1))
    done <<< "$files"

    # nginx refuses to reload an invalid configuration, and a reload failure
    # leaves the old worker set serving, so the check is part of the apply.
    if ! nginx -t >/dev/null 2>&1; then
        nginx -t || true
        fail_switch "nginx rejected the rewritten XML handler location"
        return 1
    fi

    systemctl reload nginx >/dev/null 2>&1 || systemctl restart nginx >/dev/null 2>&1

    log "applied XML path ${path} across ${index} nginx file(s)"
    assert_xml_path "$path"
}

# Prove the running nginx configuration serves the requested front controller.
assert_xml_path() {
    local path="${1:-}" effective

    # The dump shows exactly what the master process loaded, which a file
    # read cannot prove after a failed reload.
    effective="$(nginx -T 2>/dev/null | grep -A6 'location = /api/v1/xml-handler' || true)"

    if [ -z "$effective" ]; then
        fail_switch "nginx -T does not contain the XML handler location"
        return 1
    fi

    if [ "$path" = "fast" ]; then
        if ! printf '%s' "$effective" | grep -q 'xml-handler-fast.php'; then
            fail_switch "xml path expected fast but nginx serves the stock router"
            return 1
        fi
    else
        if printf '%s' "$effective" | grep -q 'xml-handler-fast.php'; then
            fail_switch "xml path expected stock but nginx serves the fast controller"
            return 1
        fi

        if ! printf '%s' "$effective" | grep -q 'try_files'; then
            fail_switch "xml path expected stock but the location has no try_files route"
            return 1
        fi
    fi

    log "asserted XML path ${path}"
}

# ------------------------------------------------------------------------------
# Persistent worker / listener restarts
# ------------------------------------------------------------------------------

# Restart FreeSWITCH so it re-reads the switch configuration files.
#
# Several modules read their configuration only at load time, so a file edit
# alone changes nothing: the running module keeps what it booted with. A
# reload is not reliable on this build, and the harness runs on a host with
# no live calls, so the restart is the honest mechanism.
restart_freeswitch() {
    local attempt

    if ! systemctl restart freeswitch >/dev/null 2>&1; then
        fail_switch "could not restart freeswitch; the old configuration stays loaded"
        return 1
    fi

    for attempt in $(seq 1 60); do
        if fs_cli -x "status" >/dev/null 2>&1; then
            sleep 2
            return 0
        fi

        sleep 1
    done

    fail_switch "freeswitch did not come back after a restart"
    return 1
}


# Restart the ESL listener so it re-reads the environment.
#
# The listener boots once and keeps its configuration for its whole life, so
# an .env change is invisible to it until it restarts.
restart_listener() {
    local attempt

    # Back-to-back restarts of the same unit can fail while systemd is still
    # starting the previous one, so the restart is attempted a few times
    # before the cell is failed.
    for attempt in 1 2 3; do
        if systemctl restart freeswitch-listener >/dev/null 2>&1; then
            break
        fi

        sleep 3
    done

    for attempt in $(seq 1 30); do
        if systemctl is-active freeswitch-listener >/dev/null 2>&1; then
            sleep 1
            return 0
        fi

        sleep 1
    done

    fail_switch "freeswitch-listener did not come back"
    return 1
}

# Count the listener start-up records in the application log.
#
# Used to prove a restart produced a *new* record, so an assertion never
# passes on a line left over from an earlier configuration.
listener_record_count() {
    local count

    # grep -c prints 0 and exits non-zero when nothing matches, so the output
    # is captured rather than appended to a fallback.
    count="$(grep -c 'FreeSWITCH ESL listener started' "${APP_DIR}/storage/logs/laravel.log" 2>/dev/null)"

    printf '%s\n' "${count:-0}"
}

# Read the listener's most recent start-up record.
#
# The daemon's console output is buffered and never reaches the journal, so
# this record is the only runtime evidence of what it actually subscribed to
# and whether broadcast debouncing is in force.
listener_last_record() {
    grep 'FreeSWITCH ESL listener started' "${APP_DIR}/storage/logs/laravel.log" 2>/dev/null | tail -1
}

# Apply the broadcast debounce window, in milliseconds.
#
# 500 is the shipped default: at most about two broadcasts per second per
# scope. 0 disables debouncing, so every event the listener handles is
# dispatched to Reverb — the configuration that shows what the debounce is
# actually saving.
apply_broadcast_throttle() {
    local value="${1:-}" before

    if ! [[ "$value" =~ ^[0-9]+$ ]]; then
        fail_switch "broadcast throttle must be a number of milliseconds (got '${value}')"
        return 1
    fi

    snapshot_env
    before="$(listener_record_count)"
    env_set FS_BROADCAST_THROTTLE_MS "$value"

    restart_listener || return 1

    log "applied broadcast throttle ${value}ms"
    assert_broadcast_throttle "$value" "$before"
}

# Prove the debounce window the listener is actually running with.
#
# Read from the listener's own start-up record, not from .env: the record is
# what the process reported after subscribing. A record older than the restart
# proves nothing, so the caller passes the count observed before restarting.
assert_broadcast_throttle() {
    local expected="${1:-}" before="${2:-}" record

    if [ -n "$before" ] && [ "$(listener_record_count)" -le "$before" ]; then
        fail_switch "the listener wrote no new start-up record; the throttle cannot be in effect"
        return 1
    fi

    record="$(listener_last_record)"

    if [ -z "$record" ]; then
        fail_switch "the listener has recorded no start-up line; the throttle cannot be proven"
        return 1
    fi

    if ! printf '%s' "$record" | grep -q "\"broadcast_throttle_ms\":${expected}"; then
        fail_switch "the listener reports $(printf '%s' "$record" | grep -o '\"broadcast_throttle_ms\":[0-9]*') instead of ${expected}ms"
        return 1
    fi

    log "asserted broadcast throttle ${expected}ms from the listener's own start-up record"
}

# Apply the ESL event subscription profile.
#
# essential subscribes to the 10 call-lifecycle events; full subscribes to the
# whole catalogue. Only measurable under call traffic, because that is the
# only time events exist to be filtered.
apply_esl_profile() {
    local profile="${1:-}" before

    case "$profile" in
        essential | full) ;;
        *)
            fail_switch "esl profile must be essential or full (got '${profile}')"
            return 1
            ;;
    esac

    snapshot_env
    before="$(listener_record_count)"
    env_set FS_ESL_EVENT_PROFILE "$profile"

    restart_listener || return 1

    log "applied ESL profile ${profile}"
    assert_esl_profile "$profile" "$before"
}

# Prove the profile the listener is actually running with.
#
# Read from the listener's start-up record, which also carries how many event
# types it subscribed to — a count the configuration alone cannot prove.
assert_esl_profile() {
    local expected="${1:-}" before="${2:-}" record

    if [ -n "$before" ] && [ "$(listener_record_count)" -le "$before" ]; then
        fail_switch "the listener wrote no new start-up record; the profile cannot be in effect"
        return 1
    fi

    record="$(listener_last_record)"

    if [ -z "$record" ]; then
        fail_switch "the listener has recorded no start-up line; the profile cannot be proven"
        return 1
    fi

    if ! printf '%s' "$record" | grep -q "\"profile\":\"${expected}\""; then
        fail_switch "the listener reports $(printf '%s' "$record" | grep -o '\"profile\":\"[a-z]*\"') instead of ${expected}"
        return 1
    fi

    local events
    events="$(printf '%s' "$record" | grep -o '\"event_types\":[0-9]*' | cut -d: -f2)"

    log "asserted ESL profile ${expected} from the listener's own start-up record (${events:-?} event types)"
}



# ------------------------------------------------------------------------------
# FreeSWITCH Octane XML binding
# ------------------------------------------------------------------------------

# Report whether the tallpbx-octane binding is active in xml_curl.conf.xml.
octane_binding_state() {
    local file="${1:-$FS_XML_CURL_CONF}" active

    active="$(grep -cE '^[[:space:]]*<binding name="tallpbx-octane"' "$file" 2>/dev/null || true)"

    if [ "${active:-0}" -gt 0 ]; then
        printf 'on'
        return 0
    fi

    printf 'off'
}

# Refuse an xml_curl configuration whose bindings lack a gateway-url.
#
# A binding without a URL reaches the module with a null url, and the first
# lookup that lands on it passes NULL to strstr() and kills the switch.
assert_xml_curl_config() {
    local file="${1:-$FS_XML_CURL_CONF}"

    python3 - "$file" <<'PY'
import re
import sys

path = sys.argv[1]
try:
    text = open(path, encoding="utf-8").read()
except OSError as exc:
    print(f"gateway-url check failed: {exc}", file=sys.stderr)
    sys.exit(1)

# Strip XML comments so a disabled binding is not reported as broken.
text = re.sub(r"<!--.*?-->", "", text, flags=re.S)

bad = 0
for match in re.finditer(r"<binding\b([^>]*)>(.*?)</binding>", text, flags=re.S):
    name = re.search(r'name="([^"]*)"', match.group(1))
    label = name.group(1) if name else "unnamed"
    if "gateway-url" not in match.group(2):
        print(f"gateway-url missing for binding [{label}]", file=sys.stderr)
        bad += 1

sys.exit(1 if bad else 0)
PY
}

# Apply an XML binding change by restarting the switch.
#
# Reloading the XML module unloads it while lookups can still hold the old
# user_data pointer. That binding memory is freed, and the next fetch
# dereferences it and segfaults inside xml_url_fetch. A restart swaps the
# module with no lookups in flight, which is the only safe way to re-read the
# bindings at runtime.
restart_freeswitch_for_binding_change() {
    local waited=0

    systemctl restart freeswitch || return 1

    while [ "$waited" -lt 60 ]; do
        if fs_cli -x "status" 2>/dev/null | grep -q '^UP'; then
            return 0
        fi
        sleep 1
        waited=$((waited + 1))
    done

    printf 'FreeSWITCH did not come back after a binding restart\n' >&2
    return 1
}

# Enable or disable the persistent-worker XML binding.
#
# The FPM variants are only meaningful with this binding disabled, because
# mod_xml_curl tries bindings in file order and would otherwise answer every
# request from the RoadRunner worker.
apply_octane_binding() {
    local state="${1:-}"

    case "$state" in
        on | off) ;;
        *)
            fail_switch "octane binding must be on or off (got '${state}')"
            return 1
            ;;
    esac

    if [ ! -f "$FS_XML_CURL_CONF" ]; then
        fail_switch "xml_curl.conf.xml not found at ${FS_XML_CURL_CONF}"
        return 1
    fi

    snapshot_once xml-curl-conf "$FS_XML_CURL_CONF"

    # A repeated apply must be free: when the file already holds the wanted
    # state the runtime already matches it, so no rewrite, no restart and no
    # registration churn. Every cell used to unload the module here even when
    # nothing changed, which is what killed the switch during the ladder.
    if [ "$(octane_binding_state)" = "$state" ]; then
        log "octane XML binding already ${state}; nothing to change"
        assert_octane_binding "$state"
        return $?
    fi

    # Rewriting an already-commented block would nest comment markers, which
    # XML forbids, so an idempotent apply skips straight to the assertion.
    if [ "$(octane_binding_state)" != "$state" ]; then
        awk -v state="$state" '
        /<binding name="tallpbx-octane">/ { active = 1 }
        {
            if (active) {
                if (state == "on") {
                    line = $0
                    gsub(/^[[:space:]]*<!--[[:space:]]*/, "", line)
                    gsub(/[[:space:]]*-->[[:space:]]*$/, "", line)
                    print line
                } else {
                    print "<!-- " $0 " -->"
                }
            } else {
                print
            }
            if (active && $0 ~ /<\/binding>/) {
                active = 0
            }
        }
        ' "$FS_XML_CURL_CONF" > "${FS_XML_CURL_CONF}.loadtest.tmp"

        mv "${FS_XML_CURL_CONF}.loadtest.tmp" "$FS_XML_CURL_CONF"
    fi

    if ! assert_xml_curl_config; then
        fail_switch "xml_curl config has a binding without gateway-url"
        return 1
    fi

    if ! restart_freeswitch_for_binding_change; then
        fail_switch "FreeSWITCH did not come back after the binding restart"
        return 1
    fi

    log "applied Octane XML binding ${state}"
    assert_octane_binding "$state"
}

# Prove the binding block is active or commented out as requested.
assert_octane_binding() {
    local state="${1:-}" current

    current="$(octane_binding_state)"

    if [ "$current" != "$state" ]; then
        fail_switch "octane binding expected ${state} but xml_curl.conf.xml reports ${current}"
        return 1
    fi

    log "asserted Octane XML binding ${state}"
}

# ------------------------------------------------------------------------------
# XML cache TTLs
# ------------------------------------------------------------------------------

# Print the value the application actually resolves for one config key.
#
# The application is bootstrapped the same way an HTTP request would, so a
# cached config file left behind by an earlier cell is caught here instead of
# silently invalidating the measurement.
app_config_value() {
    local key="$1"

    (
        cd "$APP_DIR"
        php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            echo config($argv[1]);
        ' "$key"
    )
}

# Apply the cache TTLs (dialplan, contributor, and optionally directory/ACL).
#
# The extra two arguments exist for the uncached-everywhere pass, where a
# directory entry cached for 60 s would hide half of the DB cost the run is
# trying to measure.
apply_ttl() {
    local dialplan="${1:-}" contributor="${2:-}" directory="${3:-}" acl="${4:-}"

    if ! [[ "$dialplan" =~ ^[0-9]+$ ]] || ! [[ "$contributor" =~ ^[0-9]+$ ]]; then
        fail_switch "ttl values must be non-negative integers (got '${dialplan}'/'${contributor}')"
        return 1
    fi

    snapshot_env
    env_set FS_XML_HANDLER_DIALPLAN_CACHE_TTL "$dialplan"
    env_set FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL "$contributor"

    [ -n "$directory" ] && env_set FS_XML_HANDLER_DIRECTORY_CACHE_TTL "$directory"
    [ -n "$acl" ] && env_set FS_XML_HANDLER_ACL_CACHE_TTL "$acl"

    log "applied cache TTLs dialplan=${dialplan} contributor=${contributor}${directory:+ directory=${directory}}${acl:+ acl=${acl}}"
    assert_ttl "$dialplan" "$contributor" "$directory" "$acl"
}

# Prove the application resolves the requested cache TTLs.
assert_ttl() {
    local dialplan="${1:-}" contributor="${2:-}" directory="${3:-}" acl="${4:-}"
    local current_dialplan current_contributor current_directory current_acl

    current_dialplan="$(app_config_value freeswitch.xml_handler.dialplan_cache_ttl)"
    current_contributor="$(app_config_value freeswitch.xml_handler.dialplan_contributor_cache_ttl)"

    if [ "$current_dialplan" != "$dialplan" ] || [ "$current_contributor" != "$contributor" ]; then
        fail_switch "ttl expected ${dialplan}/${contributor} but application resolves ${current_dialplan:-unset}/${current_contributor:-unset}"
        return 1
    fi

    if [ -n "$directory" ]; then
        current_directory="$(app_config_value freeswitch.xml_handler.directory_cache_ttl)"

        if [ "$current_directory" != "$directory" ]; then
            fail_switch "directory ttl expected ${directory} but application resolves ${current_directory:-unset}"
            return 1
        fi
    fi

    if [ -n "$acl" ]; then
        current_acl="$(app_config_value freeswitch.xml_handler.acl_cache_ttl)"

        if [ "$current_acl" != "$acl" ]; then
            fail_switch "acl ttl expected ${acl} but application resolves ${current_acl:-unset}"
            return 1
        fi
    fi

    log "asserted cache TTLs dialplan=${dialplan} contributor=${contributor}"
}

# ------------------------------------------------------------------------------
# CDR ingestion transport
# ------------------------------------------------------------------------------

# Return the ingestion URL configured for one transport id.
cdr_transport_url() {
    case "$1" in
        fpm) printf 'http://127.0.0.1/api/v1/cdr' ;;
        octane) printf 'http://127.0.0.1:8090/api/v1/cdr' ;;
        *) return 1 ;;
    esac
}

# Point mod_json_cdr at the FPM or the Octane ingestion endpoint.
apply_cdr_transport() {
    local transport="${1:-}" url

    if ! url="$(cdr_transport_url "$transport")"; then
        fail_switch "cdr transport must be fpm or octane (got '${transport}')"
        return 1
    fi

    if [ ! -f "$FS_JSON_CDR_CONF" ]; then
        fail_switch "json_cdr.conf.xml not found at ${FS_JSON_CDR_CONF}"
        return 1
    fi

    snapshot_once json-cdr-conf "$FS_JSON_CDR_CONF"

    sed -i -E "s|<param name=\"url\"[[:space:]]+value=\"[^\"]*\"|<param name=\"url\" value=\"${url}\"|" "$FS_JSON_CDR_CONF"

    if ! fs_cli -x "reload mod_json_cdr" >/dev/null 2>&1; then
        fail_switch "FreeSWITCH rejected 'reload mod_json_cdr'"
        return 1
    fi

    log "applied CDR transport ${transport} (${url})"
    assert_cdr_transport "$transport"
}

# Prove the module config points at the transport and the endpoint is alive.
assert_cdr_transport() {
    local transport="${1:-}" url configured code

    if ! url="$(cdr_transport_url "$transport")"; then
        fail_switch "cdr transport must be fpm or octane (got '${transport}')"
        return 1
    fi

    configured="$(grep -oE '<param name="url"[[:space:]]+value="[^"]*"' "$FS_JSON_CDR_CONF" 2>/dev/null || true)"

    if ! printf '%s' "$configured" | grep -qF "$url"; then
        fail_switch "cdr transport expected ${url} but json_cdr.conf.xml has '${configured:-nothing}'"
        return 1
    fi

    # 401 proves the route is reachable and the shared token is still enforced;
    # 404 means the worker never loaded routes/api.php.
    code="$(curl -s -o /dev/null -m 5 -w '%{http_code}' -X POST "$url" \
        -H 'Content-Type: application/json' -d '{}' || true)"

    if [ "$code" != "401" ]; then
        fail_switch "cdr endpoint ${url} answered ${code:-no response}, expected 401"
        return 1
    fi

    log "asserted CDR transport ${transport}"
}

# ------------------------------------------------------------------------------
# .env helpers
# ------------------------------------------------------------------------------

# Read one key from .env, returning an empty string when it is absent.
env_get() {
    local key="$1"

    grep -E "^#?[[:space:]]*${key}=" "${APP_DIR}/.env" 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"' || true
}

# Set one .env key, adding it when absent, and drop the cached configuration.
env_set() {
    local key="$1" value="$2" file="${APP_DIR}/.env"

    if grep -qE "^#?[[:space:]]*${key}=" "$file"; then
        sed -i -E "s|^#?[[:space:]]*${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi

    # A cached config would keep serving the previous value to every worker.
    (cd "$APP_DIR" && php artisan config:clear >/dev/null 2>&1) || true
}

# Record the original .env values this library may change.
snapshot_env() {
    local file="${STATE_DIR}/env.baseline" key line

    [ -f "$file" ] && return 0

    : > "$file"

    for key in FS_XML_HANDLER_DIALPLAN_CACHE_TTL FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL FS_XML_HANDLER_DIRECTORY_CACHE_TTL FS_XML_HANDLER_ACL_CACHE_TTL FS_ESL_EVENT_PROFILE FS_BROADCAST_THROTTLE_MS; do
        line="$(grep -E "^#?[[:space:]]*${key}=" "${APP_DIR}/.env" 2>/dev/null | tail -n 1 || true)"
        printf '%s\t%s\n' "$key" "$line" >> "$file"
    done
}

# Put the snapshotted .env values back, removing keys that did not exist.
restore_env() {
    local file="${STATE_DIR}/env.baseline" key line had_value

    [ -f "$file" ] || return 0

    while IFS=$'\t' read -r key line; do
        [ -n "$key" ] || continue
        had_value="$(env_get "$key")"
        sed -i -E "/^#?[[:space:]]*${key}=/d" "${APP_DIR}/.env"

        if [ -n "$line" ]; then
            printf '%s\n' "$line" >> "${APP_DIR}/.env"
        fi

        log "restored ${key} (was '${had_value:-unset}')"
    done < "$file"

    (cd "$APP_DIR" && php artisan config:clear >/dev/null 2>&1) || true
}

# ------------------------------------------------------------------------------
# Measurement probes
# ------------------------------------------------------------------------------

# Milliseconds since the epoch, used only to time the lookup probe.
now_ms() {
    date +%s%3N
}

# Measure what FreeSWITCH waits for on one directory lookup.
#
# Asking FreeSWITCH itself measures the path a real call takes, including
# anything answered before nginx is reached. `status` is timed the same way and
# subtracted, because the command-line round trip is fixed and large enough
# to swamp the lookup it is meant to measure.
probe_lookup() {
    local extension="${1:-}" domain="${2:-}" count="${3:-30}"
    local started index find_ms status_ms net_ms

    if [ -z "$extension" ] || [ -z "$domain" ]; then
        fail_switch "probe_lookup needs an extension and a domain"
        return 1
    fi

    started="$(now_ms)"

    for index in $(seq 1 "$count"); do
        fs_cli -x "find_user_xml id ${extension} ${domain}" >/dev/null 2>&1 || true
    done

    find_ms=$(( $(now_ms) - started ))

    started="$(now_ms)"

    for index in $(seq 1 "$count"); do
        fs_cli -x "status" >/dev/null 2>&1 || true
    done

    status_ms=$(( $(now_ms) - started ))

    # The subtraction removes the CLI overhead; never report a negative cost.
    net_ms=$(( find_ms - status_ms ))
    [ "$net_ms" -lt 0 ] && net_ms=0

    printf 'lookup_count=%s find_user_ms=%s status_ms=%s net_ms=%s\n' \
        "$count" "$find_ms" "$status_ms" "$net_ms"
}


# ------------------------------------------------------------------------------
# Baseline restoration
# ------------------------------------------------------------------------------

# Put every switch this library touched back to its pre-run value.
#
# Idempotent by design: with no snapshots present it does nothing, so a cell
# that failed before its first apply can still call it safely from a trap.
restore_baseline() {
    local exit_code=$?

    trap - EXIT INT TERM

    log "restoring baseline configuration"

    local file level mode xml_before xml_after json_before json_after
    local esl_before throttle_before esl_after throttle_after

    esl_before="$(env_get FS_ESL_EVENT_PROFILE)"
    throttle_before="$(env_get FS_BROADCAST_THROTTLE_MS)"

    restore_env

    esl_after="$(env_get FS_ESL_EVENT_PROFILE)"
    throttle_after="$(env_get FS_BROADCAST_THROTTLE_MS)"

    if [ "$esl_before" != "$esl_after" ] || [ "$throttle_before" != "$throttle_after" ]; then
        restart_listener >/dev/null 2>&1 || true
    fi

    restore_file fpm-pool

    # Restoring the file leaves the runtime on the old bindings, so restart
    # only when the file actually changed; reloading the module is never safe.
    xml_before="$(sha256sum "$FS_XML_CURL_CONF" 2>/dev/null | awk '{print $1}')"
    restore_file xml-curl-conf
    xml_after="$(sha256sum "$FS_XML_CURL_CONF" 2>/dev/null | awk '{print $1}')"
    if [ "$xml_before" != "$xml_after" ]; then
        restart_freeswitch_for_binding_change >/dev/null 2>&1 || log "binding restart after restore failed"
    fi

    # The CDR module reads its configuration on load as well, so reload it
    # only when the restore actually changed the file.
    json_before="$(sha256sum "$FS_JSON_CDR_CONF" 2>/dev/null | awk '{print $1}')"
    restore_file json-cdr-conf
    json_after="$(sha256sum "$FS_JSON_CDR_CONF" 2>/dev/null | awk '{print $1}')"
    if [ "$json_before" != "$json_after" ]; then
        fs_cli -x "reload mod_json_cdr" >/dev/null 2>&1 || true
    fi

    # nginx files keep their backup next to the original; the recorded list
    # is what survives between the apply process and this one.
    if [ -f "${STATE_DIR}/nginx-files.list" ]; then
        while IFS= read -r file; do
            [ -n "$file" ] || continue

            if [ -f "${file}.loadtest.bak" ]; then
                cp -p "${file}.loadtest.bak" "$file"
                log "restored ${file}"
            fi
        done < "${STATE_DIR}/nginx-files.list"
    fi

    level="$(env_get FS_LOG_LEVEL)"

    if [ -n "$level" ]; then
        fs_cli -x "fsctl loglevel ${level}" >/dev/null 2>&1 || true
    fi

    if [ -f "${STATE_DIR}/fs-db-mode.baseline" ]; then
        mode="$(cat "${STATE_DIR}/fs-db-mode.baseline")"
        if [ -n "$mode" ] && [ "$(fs_db_mode)" != "$mode" ]; then
            apply_fs_db_mode "$mode" || log "runtime database restore failed"
        fi
    fi

    if nginx -t >/dev/null 2>&1; then
        systemctl reload nginx >/dev/null 2>&1 || true
    fi

    service_reload "$(php_fpm_service)" >/dev/null 2>&1 || true

    log "baseline restored"

    return "$exit_code"
}

# Print the available commands.
usage() {
    cat <<'USAGE'
Usage: switch.sh <command> [arguments]

  apply_fpm_profile <static|dynamic> <children>   assert_fpm_profile <static|dynamic> <children>
  apply_fs_loglevel <debug|info|notice|warning|err|crit|alert>   assert_fs_loglevel <level>
  apply_fs_db_mode <disk|tmpfs>                   assert_fs_db_mode <disk|tmpfs>
  apply_xml_path <stock|fast>                     assert_xml_path <stock|fast>
  apply_octane_binding <on|off>                   assert_octane_binding <on|off>
  apply_ttl <dialplan> <contributor> [directory] [acl]   assert_ttl <dialplan> <contributor> [directory] [acl]
  apply_cdr_transport <fpm|octane>                assert_cdr_transport <fpm|octane>
  apply_esl_profile <essential|full>              assert_esl_profile <essential|full> [before]
  apply_broadcast_throttle <ms>                   assert_broadcast_throttle <ms> [before]
  probe_lookup <extension> <domain> [count]
  assert_xml_curl_config [path]                   octane_binding_state [path]
  restore_baseline
USAGE
}

# Dispatch one command; hyphenated and underscored spellings are equivalent.
main() {
    local command="${1:-}"

    if [ "$#" -gt 0 ]; then
        shift
    fi

    case "$command" in
        apply_fpm_profile | apply-fpm-profile) apply_fpm_profile "$@" ;;
        assert_fpm_profile | assert-fpm-profile) assert_fpm_profile "$@" ;;
        apply_fs_loglevel | apply-fs-loglevel) apply_fs_loglevel "$@" ;;
        assert_fs_loglevel | assert-fs-loglevel) assert_fs_loglevel "$@" ;;
        apply_fs_db_mode | apply-fs-db-mode) apply_fs_db_mode "$@" ;;
        assert_fs_db_mode | assert-fs-db-mode) assert_fs_db_mode "$@" ;;
        apply_xml_path | apply-xml-path) apply_xml_path "$@" ;;
        assert_xml_path | assert-xml-path) assert_xml_path "$@" ;;
        apply_octane_binding | apply-octane-binding) apply_octane_binding "$@" ;;
        assert_xml_curl_config | assert-xml-curl-config) assert_xml_curl_config "$@" ;;
        octane_binding_state | octane-binding-state) octane_binding_state "$@" ;;
        assert_octane_binding | assert-octane-binding) assert_octane_binding "$@" ;;
        apply_ttl | apply-ttl) apply_ttl "$@" ;;
        assert_ttl | assert-ttl) assert_ttl "$@" ;;
        apply_cdr_transport | apply-cdr-transport) apply_cdr_transport "$@" ;;
        assert_cdr_transport | assert-cdr-transport) assert_cdr_transport "$@" ;;
        apply_broadcast_throttle | apply-broadcast-throttle) apply_broadcast_throttle "$@" ;;
        assert_broadcast_throttle | assert-broadcast-throttle) assert_broadcast_throttle "$@" ;;
        apply_esl_profile | apply-esl-profile) apply_esl_profile "$@" ;;
        assert_esl_profile | assert-esl-profile) assert_esl_profile "$@" ;;
        probe_lookup | probe-lookup) probe_lookup "$@" ;;
        restore_baseline | restore-baseline) restore_baseline ;;
        '' | -h | --help | help)
            usage
            return 1
            ;;
        *)
            printf 'Unknown command: %s\n' "$command" >&2
            usage >&2
            return 2
            ;;
    esac
}

# A multi-command session can ask for the baseline to be restored on exit even
# when a cell aborts. Single-shot calls never restore implicitly.
if [ "${SWITCH_RESTORE_ON_EXIT:-0}" = "1" ]; then
    trap restore_baseline EXIT INT TERM
fi

# Only run the dispatcher when executed, so the file can also be sourced.
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
