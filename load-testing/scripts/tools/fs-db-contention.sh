#!/usr/bin/env bash
# ==============================================================================
# Concurrent SQLite vs. MariaDB Runtime-DB Contention Workload
#
# Standard SIPp capacity cells register endpoints sequentially before placing
# calls, which leaves sofia_reg_internal mostly read-only during the call
# window. This tool drives concurrent multi-table writes against FreeSWITCH's
# runtime database (core.db, sofia_reg_internal.db, voicemail_default.db)
# while polling fs_cli read commands and scanning freeswitch.log for SQLite
# locking errors ("SQLite is BUSY" / "database is locked").
#
# Run on the load generator host against a seeded tenant (>= 30 extensions when
# REGISTER_CHURN_RATE > 0 so extensions 2020+ churn registrations on a dedicated
# port without overwriting the UAS Contact port 5066 for 2000-2019).
# ==============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
LOAD_TESTING_DIR="${APP_DIR}/load-testing"

PBX_HOST="${PBX_HOST:?Set PBX_HOST to the target PBX IP or hostname}"
PBX_PORT="${PBX_PORT:-5060}"
LOAD_GENERATOR_IP="${LOAD_GENERATOR_IP:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
CSV_PATH="${CSV_PATH:-${APP_DIR}/storage/app/load-tests/sipp-users.csv}"
DOMAIN="${DOMAIN:-}"

CALL_RATE="${CALL_RATE:-4}"
DURATION_SECONDS="${DURATION_SECONDS:-60}"
REGISTER_CHURN_RATE="${REGISTER_CHURN_RATE:-5}"
VOICEMAIL_RATE="${VOICEMAIL_RATE:-1}"
FS_CLI_POLL_INTERVAL="${FS_CLI_POLL_INTERVAL:-1}"

UAC_CALL_PORT="${UAC_CALL_PORT:-5064}"
UAS_LOCAL_PORT="${UAS_LOCAL_PORT:-5066}"
REGISTER_CHURN_PORT="${REGISTER_CHURN_PORT:-5072}"
VOICEMAIL_UAC_PORT="${VOICEMAIL_UAC_PORT:-5074}"

RUN_DIR="${RUN_DIR:-${APP_DIR}/storage/app/load-tests/fs-db-contention-$(date +%Y%m%d-%H%M%S)}"
SSH_PORT="${SSH_PORT:-22}"
SSH_OPTS="${SSH_OPTS:--o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10}"

if [ -z "$LOAD_GENERATOR_IP" ]; then
    echo "Unable to detect LOAD_GENERATOR_IP. Set it explicitly." >&2
    exit 2
fi

if ! command -v sipp >/dev/null 2>&1; then
    echo "sipp is required on the load generator." >&2
    exit 127
fi

if [ ! -f "$CSV_PATH" ]; then
    echo "Missing seeded CSV: ${CSV_PATH}" >&2
    exit 2
fi

mkdir -p "$RUN_DIR"

AUTH_ALL="${RUN_DIR}/sipp-users-auth-all.csv"
AUTH_CALLS="${RUN_DIR}/sipp-users-auth-calls.csv"
AUTH_REG="${RUN_DIR}/sipp-users-auth-reg.csv"

awk -F';' -v realm="${DOMAIN}" 'BEGIN { OFS = ";" } NR == 1 { print; next } { if (realm != "") $3 = realm; print $0, "[authentication username="$1" password="$2"]" }' \
    "$CSV_PATH" > "$AUTH_ALL"

# First 20 rows handle registered extension calls; remaining rows (or all rows
# when <= 20 are present) handle registration churn on a separate local port.
awk 'NR == 1 || NR <= 21' "$AUTH_ALL" > "$AUTH_CALLS"
total_rows=$(( $(wc -l < "$AUTH_ALL") - 1 ))
if [ "$total_rows" -gt 20 ]; then
    awk 'NR == 1 || NR > 21' "$AUTH_ALL" > "$AUTH_REG"
else
    cp "$AUTH_ALL" "$AUTH_REG"
fi

uas_pid=""
reg_pid=""
vm_pid=""
poll_pid=""

cleanup() {
    local pid
    for pid in "$reg_pid" "$vm_pid" "$poll_pid" "$uas_pid"; do
        if [ -n "$pid" ] && kill -0 "$pid" >/dev/null 2>&1; then
            kill "$pid" >/dev/null 2>&1 || true
            wait "$pid" >/dev/null 2>&1 || true
        fi
    done
}
trap cleanup EXIT INT TERM

pbx_exec() {
    # shellcheck disable=SC2086
    ssh $SSH_OPTS -p "$SSH_PORT" "root@${PBX_HOST}" "$@"
}

# Record baseline FreeSWITCH log line count before the contention window.
log_lines_before="$(pbx_exec 'wc -l < /var/log/freeswitch/freeswitch.log 2>/dev/null || echo 0' 2>/dev/null || echo 0)"

# 1. Initial registration of the call endpoints on UAS_LOCAL_PORT.
(cd "$RUN_DIR" && timeout 120 sipp "${PBX_HOST}:${PBX_PORT}" \
    -sf "${LOAD_TESTING_DIR}/tools/sipp/register.xml" \
    -inf "$AUTH_CALLS" \
    -i "$LOAD_GENERATOR_IP" \
    -p "$UAS_LOCAL_PORT" \
    -r 5 \
    -m "$(( $(wc -l < "$AUTH_CALLS") - 1 ))" \
    -trace_err -trace_counts -trace_stat) > "${RUN_DIR}/init-register.log" 2>&1

# 2. Start auto-answer UAS on UAS_LOCAL_PORT.
(cd "$RUN_DIR" && timeout $((DURATION_SECONDS + 90)) sipp \
    -sf "${LOAD_TESTING_DIR}/tools/sipp/uas-auto-answer-capacity.xml" \
    -i "$LOAD_GENERATOR_IP" \
    -p "$UAS_LOCAL_PORT" \
    -trace_err -trace_counts -trace_stat) > "${RUN_DIR}/uas.log" 2>&1 &
uas_pid="$!"
sleep 2

if ! kill -0 "$uas_pid" >/dev/null 2>&1; then
    echo "UAS exited early; see ${RUN_DIR}/uas.log" >&2
    exit 1
fi

# 3. Start concurrent registration churn against sofia_reg_internal.
reg_total=$((REGISTER_CHURN_RATE * DURATION_SECONDS))
if [ "$REGISTER_CHURN_RATE" -gt 0 ] && [ "$reg_total" -gt 0 ]; then
    (cd "$RUN_DIR" && timeout $((DURATION_SECONDS + 60)) sipp "${PBX_HOST}:${PBX_PORT}" \
        -sf "${LOAD_TESTING_DIR}/tools/sipp/register.xml" \
        -inf "$AUTH_REG" \
        -i "$LOAD_GENERATOR_IP" \
        -p "$REGISTER_CHURN_PORT" \
        -r "$REGISTER_CHURN_RATE" \
        -m "$reg_total" \
        -trace_err -trace_counts -trace_stat) > "${RUN_DIR}/register-churn.log" 2>&1 &
    reg_pid="$!"
fi

# 4. Start concurrent voicemail accesses against voicemail_default.
vm_total=$((VOICEMAIL_RATE * DURATION_SECONDS / 2))
if [ "$VOICEMAIL_RATE" -gt 0 ] && [ "$vm_total" -gt 0 ] && [ -f "${LOAD_TESTING_DIR}/tools/sipp/uac-voicemail.xml" ]; then
    (cd "$RUN_DIR" && timeout $((DURATION_SECONDS + 60)) sipp "${PBX_HOST}:${PBX_PORT}" \
        -sf "${LOAD_TESTING_DIR}/tools/sipp/uac-voicemail.xml" \
        -inf "$AUTH_CALLS" \
        -i "$LOAD_GENERATOR_IP" \
        -p "$VOICEMAIL_UAC_PORT" \
        -r "$VOICEMAIL_RATE" \
        -l 4 \
        -m "$vm_total" \
        -trace_err -trace_counts -trace_stat) > "${RUN_DIR}/voicemail.log" 2>&1 &
    vm_pid="$!"
fi

# 5. Poll fs_cli read queries against core/sofia DBs during the load window.
(
    end_ts=$(( $(date +%s) + DURATION_SECONDS ))
    while [ "$(date +%s)" -lt "$end_ts" ]; do
        pbx_exec 'fs_cli -x "show channels count" >/dev/null 2>&1; fs_cli -x "sofia status profile internal reg" >/dev/null 2>&1' || true
        sleep "$FS_CLI_POLL_INTERVAL"
    done
) > "${RUN_DIR}/fs-cli-poll.log" 2>&1 &
poll_pid="$!"

# 6. Run main call workload for DURATION_SECONDS.
call_total=$((CALL_RATE * DURATION_SECONDS))
set +e
(cd "$RUN_DIR" && timeout $((DURATION_SECONDS + 90)) sipp "${PBX_HOST}:${PBX_PORT}" \
    -sf "${LOAD_TESTING_DIR}/tools/sipp/uac-extension-capacity.xml" \
    -inf "$AUTH_CALLS" \
    -i "$LOAD_GENERATOR_IP" \
    -p "$UAC_CALL_PORT" \
    -r "$CALL_RATE" \
    -l "$call_total" \
    -m "$call_total" \
    -trace_err -trace_counts -trace_stat -trace_rtt -rtt_freq 1 -fd 5) > "${RUN_DIR}/calls.log" 2>&1
calls_rc=$?
set -e

[ -n "$reg_pid" ] && wait "$reg_pid" 2>/dev/null || true
[ -n "$vm_pid" ] && wait "$vm_pid" 2>/dev/null || true
[ -n "$poll_pid" ] && wait "$poll_pid" 2>/dev/null || true

# Scan newly appended FreeSWITCH logs for SQLite lock or DB driver errors.
lock_errors="$(pbx_exec "tail -n +$((log_lines_before + 1)) /var/log/freeswitch/freeswitch.log 2>/dev/null | grep -ciE 'SQLite is BUSY|database is locked|SQL ERR|mariadb.*error|CORE_DB' || true" 2>/dev/null || echo unknown)"

{
    echo "# FreeSWITCH Runtime DB Contention Run"
    echo
    echo "- Recorded: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "- PBX: ${PBX_HOST}:${PBX_PORT}"
    echo "- Call rate / duration / total: ${CALL_RATE} CPS / ${DURATION_SECONDS}s / ${call_total}"
    echo "- Register churn rate: ${REGISTER_CHURN_RATE} req/s"
    echo "- Voicemail rate: ${VOICEMAIL_RATE} CPS"
    echo "- Main calls exit code: ${calls_rc}"
    echo "- FreeSWITCH DB/lock log matches: ${lock_errors}"
} > "${RUN_DIR}/summary.md"

cat "${RUN_DIR}/summary.md"
exit "$calls_rc"
