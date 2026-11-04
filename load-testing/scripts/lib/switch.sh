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
FS_LUA_CONF="${FS_CONF_DIR}/autoload_configs/lua.conf.xml"
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
    local mode="${1:-}" children="${2:-}" pool

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

    sed -i -E "s/^[;[:space:]]*pm[[:space:]]*=[[:space:]]*.*/pm = ${mode}/" "$pool"
    sed -i -E "s/^[;[:space:]]*pm\.max_children[[:space:]]*=[[:space:]]*.*/pm.max_children = ${children}/" "$pool"

    if ! service_reload "$(php_fpm_service)"; then
        fail_switch "php-fpm did not come back after applying ${mode}/${children}"
        return 1
    fi

    log "applied php-fpm ${mode}/${children} in ${pool}"
    assert_fpm_profile "$mode" "$children"
}

# Read the pool file back and prove the mode and worker cap are live.
assert_fpm_profile() {
    local mode="${1:-}" children="${2:-}" pool current_mode current_children

    pool="$(fpm_pool_file)"

    if [ -z "$pool" ] || [ ! -f "$pool" ]; then
        fail_switch "php-fpm pool file not found while asserting"
        return 1
    fi

    current_mode="$(sed -nE 's/^[[:space:]]*pm[[:space:]]*=[[:space:]]*([a-z]+).*/\1/p' "$pool" | tail -n 1)"
    current_children="$(sed -nE 's/^[[:space:]]*pm\.max_children[[:space:]]*=[[:space:]]*([0-9]+).*/\1/p' "$pool" | tail -n 1)"

    if [ "$current_mode" != "$mode" ] || [ "$current_children" != "$children" ]; then
        fail_switch "fpm-profile expected ${mode}/${children} but read ${current_mode:-unset}/${current_children:-unset}"
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
# FreeSWITCH Lua XML bindings
# ------------------------------------------------------------------------------

# Report whether the Lua XML handler bindings are active in lua.conf.xml.
lua_bindings_state() {
    local active

    active="$(grep -cE '^[[:space:]]*<param name="xml-handler-bindings"' "$FS_LUA_CONF" 2>/dev/null || true)"

    if [ "${active:-0}" -gt 0 ]; then
        printf 'on'
        return 0
    fi

    printf 'off'
}

# Enable or disable the mod_lua XML bindings and the Laravel Redis mirror.
#
# Both halves must move together: an enabled binding without the mirror flag
# reads an empty Redis namespace and silently answers nothing, which is
# exactly the kind of half-applied switch this library exists to prevent.

# Restart the persistent worker so it picks up a changed .env value.
#
# PHP-FPM reads .env on every request, but the worker boots once and keeps
# its configuration for its whole life. Without this restart a flag change is
# invisible to the process that actually serves the lookups, and the cell
# measures the old configuration while the files on disk look correct.
restart_xml_worker() {
    if ! systemctl is-active tallpbx-xml-worker >/dev/null 2>&1; then
        return 0
    fi

    if ! systemctl restart tallpbx-xml-worker >/dev/null 2>&1; then
        fail_switch "could not restart tallpbx-xml-worker; it still holds the old configuration"
        return 1
    fi

    # The worker answers a moment after the unit is active again.
    local attempt

    for attempt in $(seq 1 15); do
        if curl -s -o /dev/null -m 2 -X POST "http://127.0.0.1:8090/api/v1/xml-handler" -d "<xml/>"; then
            break
        fi

        sleep 1
    done

    return 0
}

# Report whether a Redis key pattern currently matches anything.
#
# The mirror is read and written by two different clients, so the check has to
# work whether or not the server requires a password.
redis_pattern_present() {
    local pattern="$1" password

    if redis-cli --scan --pattern "$pattern" 2>/dev/null | grep -q .; then
        return 0
    fi

    password="$(env_get REDIS_PASSWORD)"

    if [ -z "$password" ]; then
        return 1
    fi

    redis-cli -a "$password" --no-auth-warning --scan --pattern "$pattern" 2>/dev/null | grep -q .
}

# Prove the mirror can actually be written.
#
# The file and the flag can both be correct while the process serving lookups
# still refuses to write the mirror, so only a behavioural check catches it.
# One lookup is triggered and the identity key the writer must have produced
# is looked for. The epoch suffix is part of the key, so a prefix is matched.
assert_lua_mirror_writable() {
    local extension="${LUA_MIRROR_EXTENSION:-2000}"
    local domain="${LUA_MIRROR_DOMAIN:-load.test.local}"
    local pattern="pbx:fsxml:tenant:user:${domain}:${extension}:*"
    local attempt

    if ! command -v redis-cli >/dev/null 2>&1; then
        fail_switch "redis-cli is required to prove the Lua mirror is writable"
        return 1
    fi

    for attempt in 1 2 3; do
        fs_cli -x "find_user_xml id ${extension} ${domain}" >/dev/null 2>&1 || true

        if redis_pattern_present "$pattern"; then
            log "asserted Lua mirror is writable (${pattern})"
            return 0
        fi

        sleep 1
    done

    fail_switch "the Lua mirror was not written after a lookup (${pattern} matched nothing)"
    return 1
}

# Restart FreeSWITCH so it re-reads the XML handler configuration.
#
# mod_lua reads lua.conf.xml when the module loads, so a file edit alone
# changes nothing: the running module keeps the bindings it booted with. A
# reload of the module is not reliable on this build, and the harness runs on
# a host with no live calls, so the restart is the honest mechanism.
restart_freeswitch() {
    local attempt

    if ! systemctl restart freeswitch >/dev/null 2>&1; then
        fail_switch "could not restart freeswitch; the Lua bindings stay inert"
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

# Prove the Lua handler actually serves lookups.
#
# Configuration being right is not evidence: the file can list the bindings,
# the flag can be true and the mirror can be full, while FreeSWITCH still
# asks PHP. The timing middleware writes one line to the application log for
# every request PHP handles, so a batch of lookups plus a count of the new
# lines says who served them: no new lines means FreeSWITCH answered them
# itself.
#
# This requires FS_XML_HANDLER_LOG_TIMING, which apply_lua_bindings turns on
# while the proxy is enabled. Without it the count would be zero for the wrong
# reason, so the check refuses to run.
assert_lua_serves() {
    local extension="${LUA_MIRROR_EXTENSION:-2000}"
    local domain="${LUA_MIRROR_DOMAIN:-load.test.local}"
    local log_file="${APP_DIR}/storage/logs/laravel.log"
    local timing before after served index

    timing="$(env_get FS_XML_HANDLER_LOG_TIMING)"

    if [ "${timing,,}" != "true" ]; then
        fail_switch "lookup attribution needs FS_XML_HANDLER_LOG_TIMING=true"
        return 1
    fi

    before="$(grep -c 'XML Handler timing.' "$log_file" 2>/dev/null || true)"

    for index in 1 2 3; do
        fs_cli -x "find_user_xml id ${extension} ${domain}" >/dev/null 2>&1 || true
    done

    sleep 1
    after="$(grep -c 'XML Handler timing.' "$log_file" 2>/dev/null || true)"
    served=$(( after - before ))

    if [ "$served" -gt 0 ]; then
        fail_switch "the Lua handler served none of 3 lookups; ${served} reached PHP"
        return 1
    fi

    log "asserted the Lua handler serves lookups (0 of 3 reached PHP)"
}

# Restart the ESL listener so it re-reads the environment.
#
# The listener boots once and keeps its configuration for its whole life, so
# an .env change is invisible to it until it restarts — the same trap that
# kept the Lua flag from reaching the worker.
restart_listener() {
    local attempt

    if ! systemctl restart freeswitch-listener >/dev/null 2>&1; then
        fail_switch "could not restart freeswitch-listener"
        return 1
    fi

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

apply_lua_bindings() {
    local state="${1:-}"

    case "$state" in
        on | off) ;;
        *)
            fail_switch "lua bindings must be on or off (got '${state}')"
            return 1
            ;;
    esac

    if [ ! -f "$FS_LUA_CONF" ]; then
        fail_switch "lua.conf.xml not found at ${FS_LUA_CONF}"
        return 1
    fi

    snapshot_once lua-conf "$FS_LUA_CONF"
    snapshot_env

    # Captured before the edit: whether FreeSWITCH needs a restart depends on
    # whether the binding state actually changes.
    local before_state
    before_state="$(lua_bindings_state)"

    if [ "$state" = "on" ]; then
        # Attribution is only possible when PHP logs the requests it serves,
        # so enabling the proxy enables that logging for the duration.
        env_set FS_XML_HANDLER_LOG_TIMING true

        # The pattern contains an alternation, so the delimiter must not be a pipe.
        sed -i -E 's#^([[:space:]]*)<!--[[:space:]]*(<param name="xml-handler-(script|bindings)"[^>]*/>)[[:space:]]*-->#\1\2#' "$FS_LUA_CONF"
        env_set FS_LUA_PROXY_ENABLED true
    else
        sed -i -E 's#^([[:space:]]*)(<param name="xml-handler-(script|bindings)"[^>]*/>)#\1<!-- \2 -->#' "$FS_LUA_CONF"
        env_set FS_LUA_PROXY_ENABLED false
    fi

    # FreeSWITCH reads lua.conf.xml at module load, so a file edit changes
    # nothing on its own. Restart only when the state actually changes, so the
    # Lua-off variants do not pay for a restart they do not need.
    if [ "$before_state" != "$state" ]; then
        restart_freeswitch || return 1
    fi

    # The flag is read by the persistent worker, which boots once. Restart it
    # only when the state actually changes: an unconditional restart would put
    # a full framework boot in front of every variant, and the transient that
    # follows lands inside the next cell's measurement.
    if [ "$before_state" != "$state" ]; then
        restart_xml_worker || return 1
    fi

    # `reload mod_lua` unloads and reloads the module; on this build it can
    # answer "Already Loaded!" and leave the module unloaded. Loading it when
    # it is already present is harmless, so ensure it is present instead.
    if ! fs_cli -x "module_exists mod_lua" 2>/dev/null | grep -q '^true'; then
        fs_cli -x "load mod_lua" >/dev/null 2>&1 || true
    fi

    log "applied Lua XML bindings ${state}"
    assert_lua_bindings "$state" || return 1

    # Configuration being correct is not proof that the proxy works. Proving
    # the mirror can be written, and that FreeSWITCH answers from it, is.
    if [ "$state" = "on" ]; then
        assert_lua_mirror_writable || return 1
        assert_lua_serves || return 1
    fi
}

# Prove the bindings file, the mirror flag and the module agree.
assert_lua_bindings() {
    local state="${1:-}" current mirror

    current="$(lua_bindings_state)"
    mirror="$(env_get FS_LUA_PROXY_ENABLED)"

    if [ "$current" != "$state" ]; then
        fail_switch "lua bindings expected ${state} but lua.conf.xml reports ${current}"
        return 1
    fi

    if [ "$state" = "on" ] && [ "${mirror,,}" != "true" ]; then
        fail_switch "lua bindings are on but FS_LUA_PROXY_ENABLED is '${mirror:-unset}'"
        return 1
    fi

    if [ "$state" = "off" ] && [ "${mirror,,}" = "true" ]; then
        fail_switch "lua bindings are off but FS_LUA_PROXY_ENABLED is still true"
        return 1
    fi

    if [ "$state" = "on" ] && ! fs_cli -x "module_exists mod_lua" 2>/dev/null | grep -q '^true'; then
        fail_switch "mod_lua is not loaded"
        return 1
    fi

    log "asserted Lua XML bindings ${state}"
}

# ------------------------------------------------------------------------------
# FreeSWITCH Octane XML binding
# ------------------------------------------------------------------------------

# Report whether the tallpbx-octane binding is active in xml_curl.conf.xml.
octane_binding_state() {
    local active

    active="$(grep -cE '^[[:space:]]*<binding name="tallpbx-octane"' "$FS_XML_CURL_CONF" 2>/dev/null || true)"

    if [ "${active:-0}" -gt 0 ]; then
        printf 'on'
        return 0
    fi

    printf 'off'
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

    if ! fs_cli -x "reload mod_xml_curl" >/dev/null 2>&1; then
        fail_switch "FreeSWITCH rejected 'reload mod_xml_curl'"
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

    for key in FS_XML_HANDLER_DIALPLAN_CACHE_TTL FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL FS_XML_HANDLER_DIRECTORY_CACHE_TTL FS_XML_HANDLER_ACL_CACHE_TTL FS_LUA_PROXY_ENABLED; do
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
# A Lua cache hit is answered inside FreeSWITCH and never reaches nginx or
# PHP, so an HTTP client cannot observe it. Asking FreeSWITCH itself is the
# only measurement on that path. `status` is timed the same way and
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

    printf 'lookup_count=%s find_user_ms=%s status_ms=%s net_ms=%s lua_bindings=%s\n' \
        "$count" "$find_ms" "$status_ms" "$net_ms" "$(lua_bindings_state)"
}

# Make the Lua pair agree again after a restore.
#
# The baseline is defined by the bindings in lua.conf.xml; the .env mirror
# flag follows them. A snapshot can capture the two disagreeing (flag on,
# bindings commented), and restoring that faithfully leaves Laravel writing
# a Redis mirror FreeSWITCH never reads: cost with no benefit, and a cell
# that looks configured but measures as if the feature were off.
coherent_baseline() {
    local bindings flag

    bindings="$(lua_bindings_state)"
    flag="$(env_get FS_LUA_PROXY_ENABLED)"

    if [ "$bindings" = "off" ] && [ "${flag,,}" = "true" ]; then
        env_set FS_LUA_PROXY_ENABLED false
        log "baseline-corrected FS_LUA_PROXY_ENABLED true -> false (bindings are commented)"

        return 0
    fi

    if [ "$bindings" = "on" ] && [ "${flag,,}" != "true" ]; then
        env_set FS_LUA_PROXY_ENABLED true
        log "baseline-corrected FS_LUA_PROXY_ENABLED ${flag:-unset} -> true (bindings are active)"
    fi
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

    local file level mode

    restore_env
    restore_file fpm-pool
    restore_file lua-conf
    restore_file xml-curl-conf
    restore_file json-cdr-conf

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

    # The flag and the bindings are one setting in two places, so the pair is
    # reconciled before the baseline is declared restored.
    coherent_baseline

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

    fs_cli -x "reload mod_xml_curl" >/dev/null 2>&1 || true
    fs_cli -x "reload mod_lua" >/dev/null 2>&1 || true
    fs_cli -x "reload mod_json_cdr" >/dev/null 2>&1 || true

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
  apply_lua_bindings <on|off>                     assert_lua_bindings <on|off>
  apply_octane_binding <on|off>                   assert_octane_binding <on|off>
  apply_ttl <dialplan> <contributor> [directory] [acl]   assert_ttl <dialplan> <contributor> [directory] [acl]
  apply_cdr_transport <fpm|octane>                assert_cdr_transport <fpm|octane>
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
        apply_lua_bindings | apply-lua-bindings) apply_lua_bindings "$@" ;;
        assert_lua_bindings | assert-lua-bindings) assert_lua_bindings "$@" ;;
        apply_octane_binding | apply-octane-binding) apply_octane_binding "$@" ;;
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
        coherent_baseline | coherent-baseline) coherent_baseline ;;
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
