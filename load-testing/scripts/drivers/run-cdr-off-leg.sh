#!/usr/bin/env bash
# Clean "CDR pipeline off" leg.
#
# The previous attempt unloaded mod_json_cdr before each cell, but the variant
# switch restarts FreeSWITCH, and FreeSWITCH loads the module again from
# modules.conf.xml — so the leg was never actually off, and the unload/reload
# churn disturbed the runs. Here the module is removed from the boot list
# first, so a restart cannot bring it back, and the state is verified after
# every cell.
#
# A separate series keeps this leg's artifacts out of the main ladder's
# directories, so nothing is overwritten.
set -uo pipefail

cd /var/www/tallpbx || exit 1

export PBX_HOST=162.243.62.138
export SSH_OPTS="-i ${HOME}/.ssh/ppx2-client.rsa -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4"
export SIPP_HOST=107.170.3.55
export SERIES=refresh-20261103-cdr-off
# The pipeline is deliberately off, so the CDR gate must judge the calls only.
export CDR_EXPECT_RECORDS=0

LOGDIR="${ROOT_DIR:-/var/www/tallpbx}/load-testing/results/${SERIES}/driver-logs"; mkdir -p "$LOGDIR"
SUMMARY="${LOGDIR}/cdr-off-leg.csv"
mkdir -p "$LOGDIR"
[ -f "$SUMMARY" ] || echo "transport,variant,cps,successful,failed,module_state" > "$SUMMARY"

pbx() { timeout 90 ssh -i "$HOME/.ssh/ppx2-client.rsa" -o ConnectTimeout=8 root@162.243.62.138 "$@"; }

# Force the "off" state again and report what it is now. The module was seen
# coming back mid-leg last time, so every cell re-asserts this first.
# Both patterns are anchored to the UNCOMMENTED line: an already-commented line
# must not be matched again, or the file becomes invalid nested XML and
# FreeSWITCH refuses to boot on the next restart.
ensure_cdr_off() {
    pbx 'grep -q "^[[:space:]]*<load module=\"mod_json_cdr\"/>" /etc/freeswitch/autoload_configs/modules.conf.xml && sed -i "s|^[[:space:]]*<load module=\"mod_json_cdr\"/>|    <!-- <load module=\"mod_json_cdr\"/> -->|" /etc/freeswitch/autoload_configs/modules.conf.xml; fs_cli -x "module_exists mod_json_cdr" | grep -q "^true" && fs_cli -x "unload mod_json_cdr" >/dev/null 2>&1; fs_cli -x "module_exists mod_json_cdr"' 2>/dev/null
}

echo "=== disabling mod_json_cdr in the boot list $(date -u +%FT%TZ) ==="
pbx 'cp /etc/freeswitch/autoload_configs/modules.conf.xml /tmp/modules.conf.xml.bak; grep -q "^[[:space:]]*<load module=\"mod_json_cdr\"/>" /etc/freeswitch/autoload_configs/modules.conf.xml && sed -i "s|^[[:space:]]*<load module=\"mod_json_cdr\"/>|    <!-- <load module=\"mod_json_cdr\"/> -->|" /etc/freeswitch/autoload_configs/modules.conf.xml; fs_cli -x "unload mod_json_cdr" >/dev/null 2>&1; fs_cli -x "module_exists mod_json_cdr"' \
    | tee "${LOGDIR}/cdr-off-module-state.log"

for variant in v-lua-stock v-lua-fast; do
    for cps in 8 10; do
        label="cdroff-${variant}-cps${cps}"
        echo "=== ${label} starting $(date -u +%FT%TZ) ==="

        before_state=$(ensure_cdr_off | tail -1)
        echo "module_state_before=${before_state}" >> "${LOGDIR}/${label}-transport.log"

        timeout 900 bash load-testing/scripts/load-test-matrix.sh --tier=h1-1c1g --phase=sip --variant="${variant}" --cps="${cps}" --repeats=1 \
            > "${LOGDIR}/${label}.log" 2>&1 || true

        # The module must still be absent after the cell's switch restart.
        state=$(pbx 'fs_cli -x "module_exists mod_json_cdr"' 2>/dev/null | tr -d '[:space:]')
        gate=$(grep -a "CDR-GATE" "${LOGDIR}/${label}.log" | tail -1)
        ok=$(grep -ao "successful=[0-9]*" <<< "$gate" | head -1 | cut -d= -f2)
        fail=$(grep -ao "failed=[0-9]*" <<< "$gate" | head -1 | cut -d= -f2)
        echo "off,${variant},${cps},${ok:-NA},${fail:-NA},${state:-unknown}" >> "$SUMMARY"

        echo "=== ${label} done: ${ok:-NA} ok / ${fail:-NA} failed, module_exists=${state} $(date -u +%FT%TZ) ==="
    done
done

echo "=== restoring mod_json_cdr $(date -u +%FT%TZ) ==="
pbx 'cp /tmp/modules.conf.xml.bak /etc/freeswitch/autoload_configs/modules.conf.xml; fs_cli -x "load mod_json_cdr" >/dev/null 2>&1; fs_cli -x "module_exists mod_json_cdr"' \
    | tee -a "${LOGDIR}/cdr-off-module-state.log"

echo "=== CDR OFF LEG COMPLETE $(date -u +%FT%TZ) ==="
