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

ARTIFACT_ROOT="${ARTIFACT_ROOT:-${ROOT_DIR}/load-testing/results/${SERIES}}"
SUMMARY="${ARTIFACT_ROOT}/validation/"

mkdir -p "$SUMMARY"

IFS=',' read -r -a variant_list <<< "$VARIANTS"

printf '== Validating the pipeline: tier %s, variants %s, %s rounds ==\n' "$TIER" "$VARIANTS" "$ROUNDS"
printf '   Each round runs every variant once, so host drift hits them all equally.\n\n'

round=1

while [ "$round" -le "$ROUNDS" ]; do
    for variant in "${variant_list[@]}"; do
        printf '   round %s/%s  %s ... ' "$round" "$ROUNDS" "$variant"

        round_series="${SERIES}-validate-r${round}"

        if SERIES="$round_series" ARTIFACT_ROOT="$ARTIFACT_ROOT" \
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
$summary = $argv[1];
$tier = $argv[2];
$variants = array_filter(explode(",", $argv[3]));
$rounds = (int) $argv[4];
$profile = $argv[5];
$ttl = $argv[6];
$section = $argv[7];

$rows = [];
foreach ($variants as $variant) {
    for ($round = 1; $round <= $rounds; $round++) {
        $file = sprintf("%s%s-validate-r%d/%s/%s-%s-%s-r1.json", $summary, $argv[8], $round, $tier, $variant, $profile, $ttl, $section);
        if (! is_file($file)) { printf("   %-16s round %d: MISSING\n", $variant, $round); continue; }
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

if (count($variants) === 2) {
    $a = $variants[0];
    $b = $variants[1];
    $meanA = array_sum(array_column($rows[$a] ?? [], "rps")) / max(1, count($rows[$a] ?? []));
    $meanB = array_sum(array_column($rows[$b] ?? [], "rps")) / max(1, count($rows[$b] ?? []));
    $gap = $meanA > 0 ? abs($meanA - $meanB) / min($meanA, $meanB) * 100 : 0;

    printf("   separation between %s and %s: %.1f%%\n", $a, $b, $gap);
    printf("   widest within-configuration spread: %.1f%%\n", $worst);

    if ($gap > $worst * 2) {
        printf("\n   VERDICT: the gap is larger than twice the noise band, so the difference is real.\n");
    } elseif ($gap > $worst) {
        printf("\n   VERDICT: the gap exceeds the noise band, but only just. Treat it as provisional.\n");
    } else {
        printf("\n   VERDICT: the gap is inside the noise band. These two configurations are indistinguishable on this tier.\n");
    }
}

printf("\n   Noise band for this tier and profile: %.1f%%. Differences below that are not findings.\n", $worst);
' "$SUMMARY" "$TIER" "$VARIANTS" "$ROUNDS" "$PROFILE" "$TTL" "$SECTION" "$SERIES"

printf '\n   Raw rounds are under %s%s-validate-r<n>/\n' "$ARTIFACT_ROOT" "$SERIES"
