#!/usr/bin/env bash
set -uo pipefail
cd /var/www/tallpbx || exit 1
export SSH_OPTS="-i /root/.ssh/ppx2-client.rsa -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10"
export PBX_HOST=162.243.62.138

for v in v-stock-static v-octane; do
    echo "===== ${v} ====="
    bash load-testing/scripts/load-test-matrix.sh \
        --tier=h1-1c1g --variant="$v" --phase=xml \
        --profile=100x5 --ttl=production-60 --section=dialplan --repeats=3
    echo "----- ${v} exit=$? -----"
done
echo "MONITORED RUN DONE"
