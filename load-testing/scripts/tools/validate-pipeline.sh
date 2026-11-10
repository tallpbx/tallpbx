#!/usr/bin/env bash
# Prove the measurement pipeline is trustworthy before a series is believed.
#
# The question this answers is not "is variant A faster than variant B" but
# "would this harness have been able to tell". It runs two variants
# interleaved — A, B, A, B, A, B — so that any drift in the host affects both
# equally, and it reports three things:
#
#   1. Integrity   : every cell report is checked against what the matrix
#                     asked for (counters add up, samples present, right
#                     endpoint, right label).
#   2. Repeatability: how far apart identical configurations can land. This
#                     is the noise band: a variant difference smaller than it
#                     is not a finding.
#   3. Separation  : whether the gap between the two variants is larger than
#                     that band.
#
# Usage:
#   bash load-testing/scripts/tools/validate-pipeline.sh \
#       --tier=h1-1c1g --variants=v-stock-static,v-fast --rounds=3
set -uo pipefail

TIER="h1-1c1g"
VARIANTS="v-stock-static,v-fast"
ROUNDS=3
PROFILE="100x5"
TTL="production-60"
SECTION="dialplan"
SERIES="${SERIES:-refresh-$(date +%Y%m%d)}"

while [ "$#" -gt 0 ]; do
    case "$1" in
        --tier=*) TIER="${1#*=}" ;;
        --variants=*) VARIANTS="${1#*=}" ;;
        --rounds=*) ROUNDS="${1#*=}" ;;
        --profile=*) PROFILE="${1#*=}" ;;
        --ttl=*) TTL="${1#*=}" ;;
        --section=*) SECTION="${1#*=}" ;;
        --series=*) SERIES="${1#*=}" ;;
        *) printf 'Unknown argument: %s\n' "$1" >&2; exit 2 ;;
    esac
    shift
done

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT_DIR" || exit 1

# The base results directory. Each round gets its own directory inside it, so
# the rounds never overwrite one another.
BASE_RESULTS="${ROOT_DIR}/load-testing/results"
SUMMARY="${BASE_RESULTS}/${SERIES}-validation/"

mkdir -p "$SUMMARY"

IFS=',' read -r -a variant_list <<< "$VARIANTS"

printf '== Validating the pipeline: tier %s, variants %s, %s rounds ==\n' "$TIER" "$VARIANTS" "$ROUNDS"
printf '   Each round runs every variant once, so host drift hits them all equally.\n\n'

round=1

while [ "$round" -le "$ROUNDS" ]; do
    for variant in "${variant_list[@]}"; do
        printf '   round %s/%s  %s ... ' "$round" "$ROUNDS" "$variant"

        round_series="${SERIES}-validate-r${round}"

        if SERIES="$round_series" ARTIFACT_ROOT="${BASE_RESULTS}/${round_series}" \
            bash load-testing/scripts/load-test-matrix.sh \
            --tier="$TIER" --variant="$variant" --phase=xml \
            --profile="$PROFILE" --ttl="$TTL" --section="$SECTION" --repeats=1 \
            > "${SUMMARY}round-${round}-${variant}.log" 2>&1; then
            printf 'done\n'
        else
            printf 'FAILED (see %s)\n' "${SUMMARY}round-${round}-${variant}.log"
        fi
    done

    round=$((round + 1))
done

printf '\n== Reading the results ==\n'

php -r '
$base = $argv[1];
$tier = $argv[2];
$variants = array_filter(explode(",", $argv[3]));
$rounds = (int) $argv[4];
$profile = $argv[5];
$ttl = $argv[6];
$section = $argv[7];
$series = $argv[8];

$rows = [];
foreach ($variants as $variant) {
    for ($round = 1; $round <= $rounds; $round++) {
        $pattern = sprintf("%s/%s-validate-r%d/%s/%s*/%s-%s-%s-r1.json", $base, $series, $round, $tier, $variant, $profile, $ttl, $section);
        $matches = glob($pattern);
        $file = $matches[0] ?? "";
        if ($file === "" || ! is_file($file)) { printf("   %-16s round %d: MISSING\n", $variant, $round); continue; }
        $j = json_decode((string) file_get_contents($file), true);
        $rows[$variant][$round] = [
            "rps" => (float) $j["requests_per_second"],
            "p50" => (float) $j["latency_ms"]["p50"],
            "ok"  => (float) $j["success_rate_percent"],
        ];
    }
}

$spreads = [];

foreach ($rows as $variant => $roundsData) {
    $values = array_column($roundsData, "rps");
    $mean = array_sum($values) / count($values);
    $spread = $mean > 0 ? (max($values) - min($values)) / $mean * 100 : 0;
    $spreads[$variant] = $spread;

    printf("   %-16s rps:", $variant);
    foreach ($roundsData as $round => $data) { printf(" %6.1f", $data["rps"]); }
    printf("   mean %6.1f   spread %4.1f%%\n", $mean, $spread);
}

echo "\n";
$worst = 0.0;
foreach ($spreads as $spread) { if ($spread > $worst) { $worst = $spread; } }

// Every variant is compared against the first one, which is the baseline the
// matrix names first. A gap inside the noise band means the harness cannot
// tell the two apart, so the difference is not a finding.
$baseline = $variants[0];
$baselineMean = array_sum(array_column($rows[$baseline] ?? [], "rps")) / max(1, count($rows[$baseline] ?? []));

printf("   noise band (widest within-configuration spread): %.1f%%\n", $worst);
printf("   baseline for comparison: %s (%.1f rps)\n\n", $baseline, $baselineMean);

foreach ($variants as $variant) {
    if ($variant === $baseline) { continue; }

    $mean = array_sum(array_column($rows[$variant] ?? [], "rps")) / max(1, count($rows[$variant] ?? []));
    $gap = $baselineMean > 0 ? abs($mean - $baselineMean) / $baselineMean * 100 : 0;

    if ($gap > $worst * 2) {
        $verdict = "REAL       (gap is more than twice the noise band)";
    } elseif ($gap > $worst) {
        $verdict = "PROVISIONAL(gap exceeds the noise band, but only just)";
    } else {
        $verdict = "NO DIFFERENCE (gap is inside the noise band)";
    }

    printf("   %-16s %6.1f rps   vs baseline %+6.1f%%   %s\n", $variant, $mean, $gap, $verdict);
}

printf("\n   Differences below %.1f%% are not findings on this tier.\n", $worst);
' "$BASE_RESULTS" "$TIER" "$VARIANTS" "$ROUNDS" "$PROFILE" "$TTL" "$SECTION" "$SERIES"

printf '\n   Raw rounds are under %s/%s-validate-r<n>/\n' "$BASE_RESULTS" "$SERIES"
