#!/usr/bin/env bash
# Drive the call-rate ladder: one rung per offered calls-per-second rate,
# all six variants inside each rung (interleaved at the same rate, so host
# drift hits every variant equally). The stop rule from the plan: stop after
# three consecutive rungs whose completion rate is under 95 %.
set -uo pipefail

cd /var/www/tallpbx || exit 1

export PBX_HOST=162.243.62.138
export SSH_OPTS="-i ${HOME}/.ssh/ppx2-client.rsa -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10"
export SIPP_HOST=107.170.3.55
export SERIES=refresh-20261103

mkdir -p /tmp/loadtest-logs

consecutive=0

for cps in 5 8 10 15 20 25 30 40; do
    echo "=== RUNG cps=${cps} starting $(date -u +%FT%TZ) ==="
    bash load-testing/scripts/load-test-matrix.sh --tier=h1-1c1g --phase=sip --cps="${cps}" --repeats=1 \
        > "/tmp/loadtest-logs/ladder-cps${cps}.log" 2>&1 || true

    completion=$(awk -F, -v c="${cps}" 'NR>1 && $2==c {t+=$3+$4; s+=$3} END {if (t>0) printf "%.1f", 100*s/t; else printf "0"}' \
        load-testing/results/refresh-20261103/h1-1c1g/*/ladder.csv 2>/dev/null)

    echo "=== RUNG cps=${cps} completion=${completion}% at $(date -u +%FT%TZ) ==="

    if awk -v v="${completion}" 'BEGIN {exit !(v+0 >= 95)}'; then
        consecutive=0
    else
        consecutive=$((consecutive + 1))
    fi

    if [ "${consecutive}" -ge 3 ]; then
        echo "=== STOP: three consecutive rungs under 95 % ==="
        break
    fi
done

echo "=== LADDER COMPLETE $(date -u +%FT%TZ) ==="
