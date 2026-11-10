#!/usr/bin/env bash
# Compare FreeSWITCH's runtime SQLite database on disk vs in memory (tmpfs)
# for the stock variant across a small CPS ladder.
#
# Each rung runs the fs-db ablation, which measures BOTH modes under real
# call load inside one invocation — the same host state, the same warm-up,
# back to back — so drift between the modes is minimal. Every cell carries a
# per-service CPU sample (named by rate) and is judged by the CDR gate. The
# smoke preflight runs once with the first cell of the series.
set -uo pipefail

cd /var/www/tallpbx || exit 1

# Required targets: a committed script never hardcodes host addresses.
: "${PBX_HOST:?Set PBX_HOST to the target PBX IP or hostname}"
: "${SIPP_HOST:?Set SIPP_HOST to the SIPp generator IP or hostname}"
export PBX_HOST SIPP_HOST

# Optional explicit identity: export SSH_OPTS only when one is given, so
# the runner's own default (StrictHostKeyChecking=accept-new, keepalives)
# still applies for agent/default-key sessions.
if [ -n "${SSH_IDENTITY:-}" ]; then
    export SSH_OPTS="-i ${SSH_IDENTITY} -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10"
fi
export SERIES=refresh-20261105-slimboot-sqlite

echo "=== SQLITE SMOKE + cps=2 reference start $(date -u +%FT%TZ) ==="
if bash load-testing/scripts/load-test-matrix.sh --tier=h1-1c1g --phase=sip --variant=v-stock-dynamic --cps=2 --repeats=1 \
    > /tmp/sqlite-ladder-smoke.log 2>&1; then
    echo "=== SQLITE SMOKE ok $(date -u +%FT%TZ) ==="
else
    echo "=== SQLITE SMOKE FAILED (see /tmp/sqlite-ladder-smoke.log) $(date -u +%FT%TZ) ==="
fi

for cps in 2 3 4 5 6; do
    echo "=== SQLITE RUNG cps=${cps} start $(date -u +%FT%TZ) ==="

    if bash load-testing/scripts/load-test-matrix.sh --tier=h1-1c1g --phase=ablation --variant=v-stock-dynamic --ablation=fs-db --cps="${cps}" --repeats=1 \
        > "/tmp/sqlite-ladder-cps${cps}.log" 2>&1; then
        echo "=== SQLITE RUNG cps=${cps} ok $(date -u +%FT%TZ) ==="
    else
        echo "=== SQLITE RUNG cps=${cps} FAILED (see /tmp/sqlite-ladder-cps${cps}.log) $(date -u +%FT%TZ) ==="
    fi
done

echo SQLITE-LADDER-DONE
