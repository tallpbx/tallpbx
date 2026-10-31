#!/usr/bin/env bash
# ==============================================================================
# TallPBX Cache Optimization & Hit Rate Sweep Runner
#
# Purpose:
#   Runs a standardized 5-tier sweep across different XML handler cache settings
#   (cold baseline, contributor cache, default 60s burst, 30s call-center profile,
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
TOKEN="${FS_XML_HANDLER_TOKEN:-}"
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

# Define the 4 standard sweep tiers under current defaults (60s since v4.2.0).
# Format: num | label | dialplan_ttl | contributor_ttl | scenario | requests | concurrency
declare -a RUNS=(
  "1|cold-baseline|0|0|mixed|100|5"
  "2|dialplan-cache-off|0|60|mixed|100|5"
  "3|prod-baseline-60|60|60|mixed|100|5"
  "4|memory-hit-ceiling|60|60|cache-hit|100|5"
)

printf "%-4s %-22s %-14s %-14s %-14s %-10s %-10s %-10s %-10s\n" "Run" "Operational Mode" "Dialplan TTL" "Contrib TTL" "Directory TTL" "ACL TTL" "Req/sec" "Avg (ms)" "Hit Rate"
printf "%-4s %-22s %-14s %-14s %-14s %-10s %-10s %-10s %-10s\n" "----" "----------------------" "------------" "-----------" "-------------" "-------" "----------" "----------" "----------"

# Snapshot the granular TTL lines the sweep will modify so the operator's exact
# values (including commented-out lines) can be restored afterward.
SNAPSHOT_DIALPLAN=$(grep -E '^#? ?FS_XML_HANDLER_DIALPLAN_CACHE_TTL=' .env | tail -n 1 || true)
SNAPSHOT_CONTRIBUTOR=$(grep -E '^#? ?FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL=' .env | tail -n 1 || true)

# Ensure both keys exist in .env so per-run overrides reliably apply. Missing
# keys would silently inherit the master default instead of the tier's value.
grep -qE '^#? ?FS_XML_HANDLER_DIALPLAN_CACHE_TTL=' .env || printf 'FS_XML_HANDLER_DIALPLAN_CACHE_TTL=60\n' >> .env
grep -qE '^#? ?FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL=' .env || printf 'FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL=60\n' >> .env

# Restore the snapshotted operator values on exit or interrupt; keys that were
# absent before the sweep are removed again so .env returns to its original state.
restore_defaults() {
  echo
  echo "Restoring the exact .env cache TTL values captured before the sweep..."
  sed -i -E '/^#? ?FS_XML_HANDLER_(DIALPLAN_CACHE|CONTRIBUTOR_CACHE)_TTL=/d' .env
  if [ -n "$SNAPSHOT_DIALPLAN" ]; then
    printf '%s\n' "$SNAPSHOT_DIALPLAN" >> .env
  fi
  if [ -n "$SNAPSHOT_CONTRIBUTOR" ]; then
    printf '%s\n' "$SNAPSHOT_CONTRIBUTOR" >> .env
  fi
  php artisan optimize:clear > /dev/null 2>&1 || true
  php artisan optimize > /dev/null 2>&1 || true
}
trap restore_defaults EXIT

for item in "${RUNS[@]}"; do
  IFS="|" read -r num name d_ttl c_ttl scenario reqs conc <<< "$item"

  # Update cache settings in .env for this run (uncomments if currently commented)
  sed -i "s/^#\? \?FS_XML_HANDLER_DIALPLAN_CACHE_TTL=.*/FS_XML_HANDLER_DIALPLAN_CACHE_TTL=$d_ttl/" .env
  sed -i "s/^#\? \?FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL=.*/FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL=$c_ttl/" .env
  php artisan optimize:clear > /dev/null 2>&1
  php artisan optimize > /dev/null 2>&1
  # Clear only the FreeSWITCH XML dialplan cache (DB 1).
  # DB 0 (security sliding windows and mutex locks) and DB 2 (sessions) must never be flushed.
  redis-cli -n "${REDIS_CACHE_DB:-1}" flushdb > /dev/null 2>&1

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

  DIR_VAL="$(grep -E '^#? ?FS_XML_HANDLER_DIRECTORY_CACHE_TTL=' .env | cut -d= -f2 | tr -d ' ' || true)"
  DIR_TTL="${DIR_VAL:-60}s"
  ACL_VAL="$(grep -E '^#? ?FS_XML_HANDLER_ACL_CACHE_TTL=' .env | cut -d= -f2 | tr -d ' ' || true)"
  ACL_TTL="${ACL_VAL:-60}s"

  printf "%-4s %-22s %-14s %-14s %-14s %-10s %-10s %-10s %-10s\n" "$num" "$name" "${d_ttl}s" "${c_ttl}s" "$DIR_TTL" "$ACL_TTL" "$RPS" "${AVG}ms" "$RATE"
done

echo "=========================================================="
echo " Cache sweep completed successfully."
echo " Results and JSON reports are saved in: $OUTPUT_DIR"
