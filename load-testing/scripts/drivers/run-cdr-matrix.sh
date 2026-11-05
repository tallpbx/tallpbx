#!/usr/bin/env bash
# CDR transport capacity matrix: how much call capacity the CDR pipeline costs.
#
# Legs: cdr-octane (ingestion on the persistent worker) and off (mod_json_cdr
# unloaded). The cdr-fpm leg is already measured — the main ladder ran with the
# shipped default. Variants include both Lua paths per the operator's request,
# at the two boundary rates where the CDR cost should dominate.
set -uo pipefail

cd /var/www/tallpbx || exit 1

export PBX_HOST=162.243.62.138
export SSH_OPTS="-i ${HOME}/.ssh/ppx2-client.rsa -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4"
export SIPP_HOST=107.170.3.55
export SERIES=refresh-20261103

LOGDIR="${ROOT_DIR:-/var/www/tallpbx}/load-testing/results/${SERIES}/driver-logs"; mkdir -p "$LOGDIR"
SUMMARY="${LOGDIR}/cdr-matrix.csv"
mkdir -p "$LOGDIR"
[ -f "$SUMMARY" ] || echo "transport,variant,cps,successful,failed" > "$SUMMARY"

pbx() { timeout 90 ssh -i "$HOME/.ssh/ppx2-client.rsa" -o ConnectTimeout=8 root@162.243.62.138 "$@"; }

for transport in octane off; do
    for variant in v-octane v-lua-stock v-lua-fast; do
        for cps in 8 10; do
            label="cdr-${transport}-${variant}-cps${cps}"
            echo "=== ${label} starting $(date -u +%FT%TZ) ==="

            # Apply the transport leg before every cell: the runner's teardown
            # restores the baseline (cdr-fpm URL) after each invocation.
            if [ "$transport" = "octane" ]; then
                pbx 'cd /var/www/tallpbx && APP_DIR=/var/www/tallpbx bash load-testing/scripts/lib/switch.sh apply_cdr_transport octane' \
                    >> "${LOGDIR}/${label}-transport.log" 2>&1 || { echo "TRANSPORT-FAILED ${label}"; continue; }
            else
                pbx 'fs_cli -x "unload mod_json_cdr" >/dev/null 2>&1; fs_cli -x "module_exists mod_json_cdr"' \
                    >> "${LOGDIR}/${label}-transport.log" 2>&1
            fi

            # Per-service CPU attribution across the cell window.
            pbx "cd /var/www/tallpbx && nohup bash load-testing/scripts/tools/cpuwatch.sh --sample 1 360 ${label} > /tmp/${label}-cpu.csv 2>&1 &" >/dev/null 2>&1

            bash load-testing/scripts/load-test-matrix.sh --tier=h1-1c1g --phase=sip --variant="${variant}" --cps="${cps}" --repeats=1 \
                > "${LOGDIR}/${label}.log" 2>&1 || true

            # Reload the module for the off leg so the next cell starts clean.
            [ "$transport" = "off" ] && pbx 'fs_cli -x "load mod_json_cdr" >/dev/null 2>&1; echo reloaded' >> "${LOGDIR}/${label}-transport.log" 2>&1

            gate=$(grep -a "CDR-GATE" "${LOGDIR}/${label}.log" | tail -1)
            ok=$(grep -ao "successful=[0-9]*" <<< "$gate" | head -1 | cut -d= -f2)
            fail=$(grep -ao "failed=[0-9]*" <<< "$gate" | head -1 | cut -d= -f2)
            echo "${transport},${variant},${cps},${ok:-NA},${fail:-NA}" >> "$SUMMARY"

            sleep 5
            pbx "cat /tmp/${label}-cpu.csv" > "${LOGDIR}/${label}-cpu.csv" 2>/dev/null
            echo "=== ${label} done: ${ok:-NA} ok / ${fail:-NA} failed $(date -u +%FT%TZ) ==="
        done
    done
done

echo "=== CDR MATRIX COMPLETE $(date -u +%FT%TZ) ==="
