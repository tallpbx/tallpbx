#!/usr/bin/env bash
# Compare the two XML handlers at every offered call rate on one test host.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT_DIR"

case "${1:-}" in
    --tier=*) tier="${1#*=}" ;;
    -h | --help)
        printf 'Usage: PBX_HOST=<host> SIPP_HOST=<host> %s --tier=<id>\n' "$0"
        exit 0
        ;;
    *)
        printf 'Specify --tier=<id>. List tiers with load-testing/scripts/load-test-matrix.sh --list --compare-xml-handlers.\n' >&2
        exit 2
        ;;
esac

if [ "$#" -ne 1 ]; then
    printf 'The ladder takes one argument, --tier=<id>.\n' >&2
    exit 2
fi

ladder_rates="$(php -r '
    $matrix = require $argv[1];
    if (!isset($matrix["tiers"][$argv[2]])) {
        fwrite(STDERR, "Unknown tier: ".$argv[2].PHP_EOL);
        exit(2);
    }
    $rates = $matrix["tiers"][$argv[2]]["cps_ladder"] ?? $matrix["cps_ladder"];
    echo implode(PHP_EOL, $rates);
' load-testing/matrix.php "$tier")"

: "${PBX_HOST:?Set PBX_HOST to the target PBX IP or hostname}"
: "${SIPP_HOST:?Set SIPP_HOST to the SIPp generator IP or hostname}"
export PBX_HOST SIPP_HOST

if [ -n "${SSH_IDENTITY:-}" ]; then
    export SSH_OPTS="-i ${SSH_IDENTITY} -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10"
fi

: "${SERIES:=ladder-$(date -u +%Y%m%dT%H%M%SZ)-$$}"
: "${ARTIFACT_ROOT:=${ROOT_DIR}/load-testing/results/${SERIES}}"
export SERIES ARTIFACT_ROOT

# Claim a fresh ladder run for this tier. Old ladder rows must never pass a
# current rung, while sharing the tier directory with XML artifacts from the
# same series stays allowed.
mkdir -p "${ARTIFACT_ROOT}/${tier}"
logdir="${ARTIFACT_ROOT}/${tier}/driver-logs"
existing_ladders=("${ARTIFACT_ROOT}/${tier}"/*/ladder.csv)
if [ -e "${existing_ladders[0]}" ] || ! mkdir "$logdir" 2>/dev/null; then
    printf 'Use a fresh SERIES or artifact directory for %s.\n' "$tier" >&2
    exit 1
fi

read_completion() {
    local variant="$1" cps="$2"
    local labels=("${ARTIFACT_ROOT}/${tier}/${variant}"-fpm-dynamic*/ladder.csv)

    if [ "${#labels[@]}" -ne 1 ] || [ ! -f "${labels[0]}" ]; then
        return 1
    fi

    awk -F, -v rate="$cps" -v expected="${tier}/${variant}/sip-cps${cps}" '
        NR == 1 {
            for (i = 1; i <= NF; i++) {
                col[$i] = i
            }
            next
        }
        $2 == rate && $5 == expected {
            rows++
            done_calls = $3
            total_calls = $3 + $4
            setup_p50 = (col["setup_p50_ms"] ? $(col["setup_p50_ms"]) : "")
            setup_p95 = (col["setup_p95_ms"] ? $(col["setup_p95_ms"]) : "")
            teardown_p50 = (col["teardown_p50_ms"] ? $(col["teardown_p50_ms"]) : "")
            teardown_p95 = (col["teardown_p95_ms"] ? $(col["teardown_p95_ms"]) : "")
        }
        END {
            if (rows != 1 || total_calls <= 0) exit 1
            percentage = 100 * done_calls / total_calls
            printf "%.1f\t%d\t%d\t%s\t%s\t%s\t%s\n", percentage, (percentage < 95), (percentage < 80), setup_p50, setup_p95, teardown_p50, teardown_p95
        }
    ' "${labels[0]}"
}

format_handler_summary() {
    local pct="$1" setup_p50="$2" setup_p95="$3" teardown_p50="$4" teardown_p95="$5"
    local detail=""

    if [ -n "$setup_p50" ] && [ -n "$setup_p95" ]; then
        detail="setup p50=${setup_p50}ms p95=${setup_p95}ms"
    fi

    if [ -n "$teardown_p50" ] && [ -n "$teardown_p95" ]; then
        if [ -n "$detail" ]; then
            detail="${detail}, "
        fi
        detail="${detail}teardown p50=${teardown_p50}ms p95=${teardown_p95}ms"
    fi

    if [ -n "$detail" ]; then
        printf '%s%% (%s)' "$pct" "$detail"
    else
        printf '%s%%' "$pct"
    fi
}

consecutive=0
while IFS= read -r cps; do
    log="${logdir}/ladder-cps${cps}.log"
    printf 'Running %s at %s calls/sec with both XML handlers\n' "$tier" "$cps"

    if ! bash load-testing/scripts/load-test-matrix.sh --tier="$tier" --compare-xml-handlers --phase=sip --cps="$cps" > "$log" 2>&1; then
        printf 'Comparison failed at %s calls/sec. See %s\n' "$cps" "$log" >&2
        exit 1
    fi

    if ! fpm="$(read_completion v-fast-default "$cps")" || ! octane="$(read_completion v-octane "$cps")"; then
        printf 'Missing or ambiguous comparison result at %s calls/sec. See %s\n' "$cps" "$log" >&2
        exit 1
    fi

    IFS=$'\t' read -r fpm_pct fpm_below95 fpm_below80 fpm_sp50 fpm_sp95 fpm_tp50 fpm_tp95 <<< "$fpm"
    IFS=$'\t' read -r oct_pct oct_below95 oct_below80 oct_sp50 oct_sp95 oct_tp50 oct_tp95 <<< "$octane"

    printf '%s calls/sec: PHP-FPM %s, Octane %s\n' \
        "$cps" \
        "$(format_handler_summary "$fpm_pct" "$fpm_sp50" "$fpm_sp95" "$fpm_tp50" "$fpm_tp95")" \
        "$(format_handler_summary "$oct_pct" "$oct_sp50" "$oct_sp95" "$oct_tp50" "$oct_tp95")"

    if [ "$fpm_below80" = "1" ] && [ "$oct_below80" = "1" ]; then
        printf 'Both handlers dropped below 80%% completion at %s calls/sec; stopping ladder.\n' "$cps"
        break
    fi

    if [ "$fpm_below95" = "1" ] && [ "$oct_below95" = "1" ]; then
        consecutive=$((consecutive + 1))
    else
        consecutive=0
    fi

    if [ "$consecutive" -ge 2 ]; then
        printf 'Both handlers completed under 95%% of calls at two consecutive rates.\n'
        break
    fi
done <<< "$ladder_rates"

printf 'Paired ladder complete. Results: %s/%s\n' "$ARTIFACT_ROOT" "$tier"
