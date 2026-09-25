#!/usr/bin/env bash
# ==============================================================================
# TallPBX Cache Optimization & Hit Rate Sweep Runner
#
# Purpose:
#   Runs a standardized 5-tier sweep across different XML handler cache settings
#   (cold baseline, contributor cache, default 5s burst, 30s call-center profile,
#   and 100% memory hit ceiling) and calculates the exact Redis cache hit rate.
#
# Usage:
#   PBX_URL="http://127.0.0.1/api/v1/xml-handler" \
#   TENANT="load-test-beta" \
#   bash scripts/run-cache-sweep.sh
# ==============================================================================

set -euo pipefail

# Configuration parameters with sensible production defaults
PBX_URL="${PBX_URL:-http://127.0.0.1/api/v1/xml-handler}"
TENANT="${TENANT:-load-test-beta}"
TOKEN="${FREESWITCH_XML_HANDLER_TOKEN:-}"
NETWORK_INTERFACE="${NETWORK_INTERFACE:-public}"
OUTPUT_DIR="storage/app/load-tests/cache-sweep-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUTPUT_DIR"

echo "=========================================================="
echo " Starting TallPBX Cache Optimization & Hit Rate Sweep"
echo " Target URL: $PBX_URL"
echo " Tenant:     $TENANT"
echo " Interface:  $NETWORK_INTERFACE"
echo " Artifacts:  $OUTPUT_DIR"
echo "=========================================================="

# Define the 5 sweep tiers:
# Format: num | label | dialplan_ttl | contributor_ttl | scenario | requests | concurrency
declare -a RUNS=(
  "1|cold-baseline|0|0|mixed|100|5"
  "2|contributor-only|0|5|mixed|100|5"
  "3|prod-baseline-100|5|5|mixed|100|5"
  "4|call-center-ttl30|30|30|mixed|100|5"
  "5|memory-hit-ceiling|5|5|cache-hit|100|5"
)

printf "%-4s %-20s %-8s %-8s %-10s %-10s %-10s\n" "Run" "Configuration" "D-TTL" "C-TTL" "Req/sec" "Avg (ms)" "Hit Rate"
printf "%-4s %-20s %-8s %-8s %-10s %-10s %-10s\n" "----" "--------------------" "--------" "--------" "----------" "----------" "----------"

# Ensure cleanup on interrupt or exit so production .env is restored
restore_defaults() {
  echo
  echo "Restoring recommended production cache defaults in .env (TTL=5s)..."
  sed -i 's/^XML_CACHE_TTL=.*/XML_CACHE_TTL=5/' .env
  sed -i 's/^XML_CACHE_DIALPLAN_TTL=.*/# XML_CACHE_DIALPLAN_TTL=5/' .env
  sed -i 's/^XML_CACHE_CONTRIBUTOR_TTL=.*/# XML_CACHE_CONTRIBUTOR_TTL=5/' .env
  sed -i 's/^FREESWITCH_XML_HANDLER_CACHE_TTL=.*/FREESWITCH_XML_HANDLER_CACHE_TTL=5/' .env
  sed -i 's/^FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=.*/# FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5/' .env
  sed -i 's/^FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=.*/# FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=5/' .env
  php artisan optimize:clear > /dev/null 2>&1 || true
  php artisan optimize > /dev/null 2>&1 || true
}
trap restore_defaults EXIT

for item in "${RUNS[@]}"; do
  IFS="|" read -r num name d_ttl c_ttl scenario reqs conc <<< "$item"

  # Update cache settings in .env for this run (uncomments if currently commented)
  sed -i "s/^#\? \?XML_CACHE_DIALPLAN_TTL=.*/XML_CACHE_DIALPLAN_TTL=$d_ttl/" .env
  sed -i "s/^#\? \?XML_CACHE_CONTRIBUTOR_TTL=.*/XML_CACHE_CONTRIBUTOR_TTL=$c_ttl/" .env
  sed -i "s/^#\? \?FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=.*/FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=$d_ttl/" .env
  sed -i "s/^#\? \?FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=.*/FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=$c_ttl/" .env
  php artisan optimize:clear > /dev/null 2>&1
  php artisan optimize > /dev/null 2>&1
  redis-cli flushdb > /dev/null 2>&1

  # Capture Redis stats before the run
  H_PRE=$(redis-cli info stats | awk -F: '/keyspace_hits/ {print $2}' | tr -d '\r')
  M_PRE=$(redis-cli info stats | awk -F: '/keyspace_misses/ {print $2}' | tr -d '\r')

  # Execute the dialplan load test command
  REPORT="$OUTPUT_DIR/run${num}-${name}.json"
  php artisan pbx:load-test:dialplan \
    --tenant="$TENANT" \
    --url="$PBX_URL" \
    --scenario="$scenario" \
    --requests="$reqs" \
    --concurrency="$conc" \
    --token="$TOKEN" \
    --interface="$NETWORK_INTERFACE" \
    --label="cache-sweep-${name}" \
    --report="$REPORT" > /dev/null 2>&1 || true

  # Capture Redis stats after the run
  H_POST=$(redis-cli info stats | awk -F: '/keyspace_hits/ {print $2}' | tr -d '\r')
  M_POST=$(redis-cli info stats | awk -F: '/keyspace_misses/ {print $2}' | tr -d '\r')
  DH=$((H_POST - H_PRE))
  DM=$((M_POST - M_PRE))
  TOT=$((DH + DM))
  RATE="0.0%"
  if [ "$TOT" -gt 0 ]; then
    RATE=$(awk "BEGIN {printf \"%.1f%%\", ($DH / $TOT) * 100}")
  fi

  # Extract throughput and average latency from the generated report
  RPS="N/A"
  AVG="N/A"
  if [ -f "$REPORT" ]; then
    RPS=$(grep '"requests_per_second"' "$REPORT" | head -n 1 | awk -F': ' '{print $2}' | tr -d ',')
    AVG=$(grep '"average"' "$REPORT" | head -n 1 | awk -F': ' '{print $2}' | tr -d ',')
  fi

  printf "%-4s %-20s %-8s %-8s %-10s %-10s %-10s\n" "$num" "$name" "${d_ttl}s" "${c_ttl}s" "$RPS" "${AVG}ms" "$RATE"
done

echo "=========================================================="
echo " Cache sweep completed successfully."
echo " Results and JSON reports are saved in: $OUTPUT_DIR"
