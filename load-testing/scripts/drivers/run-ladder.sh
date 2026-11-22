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
    echo implode(PHP_EOL, $matrix["cps_ladder"]);
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

# Claim a fresh tier directory. Old rows must never pass a current rung.
mkdir -p "$ARTIFACT_ROOT"
if ! mkdir "${ARTIFACT_ROOT}/${tier}"; then
    printf 'Use a fresh SERIES or artifact directory for %s.\n' "$tier" >&2
    exit 1
fi
logdir="${ARTIFACT_ROOT}/${tier}/driver-logs"
mkdir "$logdir"

read_completion() {
    local variant="$1" cps="$2"
    local labels=("${ARTIFACT_ROOT}/${tier}/${variant}"-fpm-dynamic*/ladder.csv)

    if [ "${#labels[@]}" -ne 1 ] || [ ! -f "${labels[0]}" ]; then
        return 1
    fi

    awk -F, -v rate="$cps" -v expected="${tier}/${variant}/sip-cps${cps}" '
        NR > 1 && $2 == rate && $5 == expected {
            rows++
            done_calls = $3
            total_calls = $3 + $4
        }
        END {
            if (rows != 1 || total_calls <= 0) exit 1
            percentage = 100 * done_calls / total_calls
            printf "%.1f %d\n", percentage, (percentage < 95)
        }
    ' "${labels[0]}"
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

    printf '%s calls/sec: PHP-FPM %s%%, Octane %s%%\n' "$cps" "${fpm% *}" "${octane% *}"

    if [ "${fpm##* }" = "1" ] && [ "${octane##* }" = "1" ]; then
        consecutive=$((consecutive + 1))
    else
        consecutive=0
    fi

    if [ "$consecutive" -ge 3 ]; then
        printf 'Both handlers completed under 95%% of calls at three consecutive rates.\n'
        break
    fi
done <<< "$ladder_rates"

printf 'Paired ladder complete. Results: %s/%s\n' "$ARTIFACT_ROOT" "$tier"
