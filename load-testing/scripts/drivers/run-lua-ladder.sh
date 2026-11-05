#!/usr/bin/env bash
# Measure the two Lua-serving variants through the same call-rate rungs the
# rest of the ladder used, so their numbers are directly comparable.
set -uo pipefail

cd /var/www/tallpbx || exit 1

export PBX_HOST=162.243.62.138
export SSH_OPTS="-i ${HOME}/.ssh/ppx2-client.rsa -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4"
export SIPP_HOST=107.170.3.55
export SERIES=refresh-20261103

mkdir -p /tmp/loadtest-logs

for cps in 2 3 5 8 10; do
    for variant in v-lua-stock v-lua-fast; do
        echo "=== LUA RUNG ${variant} cps=${cps} starting $(date -u +%FT%TZ) ==="
        bash load-testing/scripts/load-test-matrix.sh --tier=h1-1c1g --phase=sip --variant="${variant}" --cps="${cps}" --repeats=1 \
            > "/tmp/loadtest-logs/lua-${variant}-cps${cps}.log" 2>&1 || true
        tail -1 "load-testing/results/refresh-20261103/h1-1c1g/${variant}/ladder.csv" 2>/dev/null
    done
done

echo "=== LUA LADDER COMPLETE $(date -u +%FT%TZ) ==="
