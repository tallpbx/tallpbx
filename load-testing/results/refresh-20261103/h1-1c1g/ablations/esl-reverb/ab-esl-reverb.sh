#!/usr/bin/env bash
# Alternate the same XML cell with the ESL listener and Reverb running and
# stopped, so ordering cannot be mistaken for an effect. A warm-up cell runs
# first because PHP-FPM workers start cold after a reload.
set -uo pipefail

run_cell() {
    local tag="$1"

    cd /var/www/tallpbx || exit 1

    php artisan pbx:load-test:dialplan \
        --tenant=load-test-beta \
        --url=http://127.0.0.1/api/v1/xml-handler \
        --requests=100 --concurrency=5 --scenario=mixed --section=dialplan \
        --report="/tmp/cell-${tag}.json" > "/tmp/cell-${tag}.log" 2>&1

    php -r '
        $j = json_decode(file_get_contents($argv[1]), true);
        printf("%-5s rps=%5.1f avg=%5.0fms p50=%5.0fms p95=%5.0fms ok=%.0f%%\n",
            $argv[2],
            $j["requests_per_second"],
            $j["latency_ms"]["average"],
            $j["latency_ms"]["p50"],
            $j["latency_ms"]["p95"],
            $j["success_rate_percent"]);
    ' "/tmp/cell-${tag}.json" "$tag"
}

echo "--- warm-up (discarded) ---"
run_cell warm

echo "--- ON  (esl-listener + reverb running) ---"
run_cell on1

systemctl stop freeswitch-listener tallpbx-reverb
sleep 2
echo "--- OFF (both stopped) ---"
run_cell off1

systemctl start freeswitch-listener tallpbx-reverb
sleep 4
echo "--- ON  (again) ---"
run_cell on2

systemctl stop freeswitch-listener tallpbx-reverb
sleep 2
echo "--- OFF (again) ---"
run_cell off2

systemctl start freeswitch-listener tallpbx-reverb
sleep 3
echo "services restored: listener=$(systemctl is-active freeswitch-listener) reverb=$(systemctl is-active tallpbx-reverb)"
