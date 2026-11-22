#!/usr/bin/env bash
# ==============================================================================
# TallPBX Standard Tier Comparison Driver (PHP-FPM vs. Laravel Octane)
#
# Executes the single uniform comparison contract declared in
# load-testing/matrix.php (`standard_comparison`) for one hardware tier under a
# shared SERIES directory so every tier produces identical, directly comparable
# datasets on the exact same build SHA:
#
#   1. Matched XML lookup throughput & latency (`100x5`, `500x25`, `1000x25`,
#      `mixed`, `production-60`, 3 repeats) for both PHP-FPM (`v-fast-default`)
#      and Laravel Octane (`v-octane`) with identical dynamic PHP-FPM pools.
#   2. Matched 60-second SIP call capacity & per-call `setup` + `teardown`
#      latency ladder across `cps_ladder` rates via `run-ladder.sh` with the
#      standard seeded account count (`EXTENSIONS=30`, `LADDER_SECONDS=60`).
#   3. Optional extended telephony + media validation (`EXTENDED=1 MEDIA_FLOW=1`)
#      confirming all 14 functional scenarios pass on the same build & tier.
#
# Run this script once per tier (`h1-1c1g`, `h2-2c2g`, `h4-4c8g`) with the SAME
# `SERIES` name, then run `consolidate-series.py` to verify cross-tier build
# parity and emit one clean consolidated CSV + Markdown comparison report.
# ==============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$ROOT_DIR"

TIER=""
SERIES_ARG=""
DRY_RUN=0
SKIP_XML=0
SKIP_SIP=0
SKIP_EXTENDED=0
REPEATS_OVERRIDE=""

usage() {
    cat <<'USAGE'
Usage: SERIES=<name> PBX_HOST=<host> SIPP_HOST=<host> run-tier-comparison.sh --tier=<id> [options]

Options:
  --tier=<id>      Hardware tier from load-testing/matrix.php (standard: h1-1c1g, h2-2c2g, h4-4c8g)
  --series=<name>  Shared series name across all tiers (or set SERIES env var)
  --repeats=<n>    Override XML repeats per profile (default from matrix.php standard_comparison)
  --dry-run        Print the exact XML, SIP ladder, and extended validation commands without executing
  --skip-xml       Skip the XML lookup throughput & latency battery
  --skip-sip       Skip the 60-second SIP capacity & per-call setup/teardown latency ladder
  --skip-extended  Skip the 14-scenario extended + media functional validation pass
  -h, --help       Show this help text

Environment:
  SERIES           Shared series directory name across all tiers (default: series-YYYYMMDD)
  PBX_HOST         Target PBX IP or hostname (required when executing)
  SIPP_HOST        Load generator IP or hostname (required when executing SIP/extended)
  ARTIFACT_ROOT    Override series output root (default: load-testing/results/<SERIES>)
USAGE
}

for arg in "$@"; do
    case "$arg" in
        --tier=*) TIER="${arg#*=}" ;;
        --series=*) SERIES_ARG="${arg#*=}" ;;
        --repeats=*) REPEATS_OVERRIDE="${arg#*=}" ;;
        --dry-run) DRY_RUN=1 ;;
        --skip-xml) SKIP_XML=1 ;;
        --skip-sip) SKIP_SIP=1 ;;
        --skip-extended) SKIP_EXTENDED=1 ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown option: %s\n' "$arg" >&2
            usage >&2
            exit 2
            ;;
    esac
done

if [ -z "$TIER" ]; then
    printf 'Specify --tier=<id>. Standard tiers: h1-1c1g, h2-2c2g, h4-4c8g.\n' >&2
    exit 2
fi

if [ -n "$REPEATS_OVERRIDE" ] && ! [[ "$REPEATS_OVERRIDE" =~ ^[1-9][0-9]*$ ]]; then
    printf 'Repeats must be a positive integer.\n' >&2
    exit 2
fi

# Read the single declarative standard comparison contract from matrix.php so
# every tier runs the exact same profiles, cache policy, account count, and
# call duration with zero ad-hoc drift.
eval "$(php -r '
    $matrix = require $argv[1];
    $tier = $argv[2];
    if (!isset($matrix["tiers"][$tier])) {
        fwrite(STDERR, "Unknown tier: ".$tier.PHP_EOL);
        exit(2);
    }
    $std = $matrix["standard_comparison"];
    printf("TIER_VCPU=%d\n", (int) $matrix["tiers"][$tier]["vcpu"]);
    printf("TIER_RAM_MB=%d\n", (int) $matrix["tiers"][$tier]["ram_mb"]);
    printf("XML_SECTION=%s\n", escapeshellarg((string) $std["xml_section"]));
    printf("XML_TTL=%s\n", escapeshellarg((string) $std["xml_ttl"]));
    printf("XML_REPEATS=%d\n", (int) $std["xml_repeats"]);
    printf("SIP_EXTENSIONS=%d\n", (int) $std["sip_extensions"]);
    printf("SIP_DURATION=%d\n", (int) $std["sip_duration_seconds"]);
    printf("XML_PROFILES=(%s)\n", implode(" ", array_map("escapeshellarg", $std["xml_profiles"])));
    printf("STD_TIERS=(%s)\n", implode(" ", array_map("escapeshellarg", $std["tiers"])));
' load-testing/matrix.php "$TIER")"

if [ -n "$REPEATS_OVERRIDE" ]; then
    XML_REPEATS="$REPEATS_OVERRIDE"
fi

if [ -n "$SERIES_ARG" ]; then
    SERIES="$SERIES_ARG"
else
    : "${SERIES:=series-$(date -u +%Y%m%d)}"
fi

: "${ARTIFACT_ROOT:=${ROOT_DIR}/load-testing/results/${SERIES}}"
export SERIES ARTIFACT_ROOT
export EXTENSIONS="${EXTENSIONS:-$SIP_EXTENSIONS}"
export LADDER_SECONDS="${LADDER_SECONDS:-$SIP_DURATION}"

if [ "$DRY_RUN" = "1" ]; then
    printf '=== Standard Comparison Plan: %s (%s vCPU / %s MB) [SERIES=%s] ===\n' \
        "$TIER" "$TIER_VCPU" "$TIER_RAM_MB" "$SERIES"
    if [ "$SKIP_XML" = "0" ]; then
        for profile in "${XML_PROFILES[@]}"; do
            printf 'SERIES=%s ARTIFACT_ROOT=%s bash load-testing/scripts/load-test-matrix.sh --tier=%s --compare-xml-handlers --phase=xml --profile=%s --ttl=%s --section=%s --repeats=%s\n' \
                "$SERIES" "$ARTIFACT_ROOT" "$TIER" "$profile" "$XML_TTL" "$XML_SECTION" "$XML_REPEATS"
        done
    fi
    if [ "$SKIP_SIP" = "0" ]; then
        printf 'SERIES=%s ARTIFACT_ROOT=%s EXTENSIONS=%s LADDER_SECONDS=%s bash load-testing/scripts/drivers/run-ladder.sh --tier=%s\n' \
            "$SERIES" "$ARTIFACT_ROOT" "$EXTENSIONS" "$LADDER_SECONDS" "$TIER"
    fi
    if [ "$SKIP_EXTENDED" = "0" ]; then
        printf 'EXTENDED=1 MEDIA_FLOW=1 SKIP_SEED=1 PBX_HOST=<pbx> LOAD_GENERATOR_IP=<sipp> bash load-testing/scripts/pbx-sipp-validate.sh # 14-scenario functional & media pass\n'
    fi
    printf 'python3 load-testing/scripts/tools/consolidate-series.py %s --csv-out=load-testing/results/consolidated/%s-comparison.csv\n' \
        "$ARTIFACT_ROOT" "$SERIES"
    exit 0
fi

: "${PBX_HOST:?Set PBX_HOST to the target PBX IP or hostname}"
export PBX_HOST

if [ "$SKIP_SIP" = "0" ] || [ "$SKIP_EXTENDED" = "0" ]; then
    : "${SIPP_HOST:?Set SIPP_HOST to the SIPp generator IP or hostname}"
    export SIPP_HOST
fi

if [ "$SKIP_XML" = "0" ]; then
    for profile in "${XML_PROFILES[@]}"; do
        printf '=== XML Comparison: %s profile=%s section=%s ttl=%s repeats=%s ===\n' \
            "$TIER" "$profile" "$XML_SECTION" "$XML_TTL" "$XML_REPEATS"
        bash load-testing/scripts/load-test-matrix.sh \
            --tier="$TIER" \
            --compare-xml-handlers \
            --phase=xml \
            --profile="$profile" \
            --ttl="$XML_TTL" \
            --section="$XML_SECTION" \
            --repeats="$XML_REPEATS"
    done

    # Fail fast before spending time on the SIP ladder if any XML cell failed.
    if [ -s "${ARTIFACT_ROOT}/${TIER}/failures.log" ]; then
        printf 'XML comparison recorded failures in %s/%s/failures.log — fix before running SIP.\n' "$ARTIFACT_ROOT" "$TIER" >&2
        cat "${ARTIFACT_ROOT}/${TIER}/failures.log" >&2
        exit 1
    fi
fi

if [ "$SKIP_SIP" = "0" ]; then
    printf '=== SIP Capacity & Per-Call Latency Ladder: %s (EXTENSIONS=%s, LADDER_SECONDS=%s) ===\n' \
        "$TIER" "$EXTENSIONS" "$LADDER_SECONDS"
    bash load-testing/scripts/drivers/run-ladder.sh --tier="$TIER"

    if [ -s "${ARTIFACT_ROOT}/${TIER}/failures.log" ]; then
        printf 'SIP ladder recorded failures in %s/%s/failures.log.\n' "$ARTIFACT_ROOT" "$TIER" >&2
        cat "${ARTIFACT_ROOT}/${TIER}/failures.log" >&2
        exit 1
    fi
fi

if [ "$SKIP_EXTENDED" = "0" ]; then
    printf '=== Extended & Media Functional Validation (14 scenarios): %s ===\n' "$TIER"
    ssh_opts="${SSH_OPTS:--o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10}"
    if [ -n "${SSH_IDENTITY:-}" ]; then
        ssh_opts="-i ${SSH_IDENTITY} -o IdentitiesOnly=yes ${ssh_opts}"
    fi

    pbx_addr="${PBX_HOST#*@}"
    sipp_addr="${SIPP_HOST#*@}"
    sip_domain="${DOMAIN:-}"
    if [ -z "$sip_domain" ]; then
        # Resolve the realm FreeSWITCH actually serves registrations under.
        # shellcheck disable=SC2086
        sip_domain="$(ssh -n $ssh_opts "$PBX_HOST" "grep -E '^FS_DEFAULT_SIP_REALM=' /var/www/tallpbx/.env | head -n1 | cut -d= -f2-" 2>/dev/null || true)"
        sip_domain="$(printf '%s' "$sip_domain" | tr -d '[:space:]')"
        sip_domain="${sip_domain//\"/}"
        sip_domain="${sip_domain//\'/}"
    fi
    sip_domain="${sip_domain:-$pbx_addr}"

    # Seed extended + media fixtures on the PBX using its real SIP realm and
    # sync the generated credential CSV to the SIPp load generator.
    # shellcheck disable=SC2086
    ssh -n $ssh_opts "$PBX_HOST" \
        "cd /var/www/tallpbx && php artisan pbx:load-test:seed --tenant=load-test-beta --domain=$(printf '%q' "$sip_domain") --extensions=$(printf '%q' "$EXTENSIONS") --start=2000 --password=LoadTest1234 --sipp-host=$(printf '%q' "$sipp_addr") --include-extended-fixtures --include-media-fixtures"
    # shellcheck disable=SC2086
    scp $ssh_opts "${PBX_HOST}:/var/www/tallpbx/storage/app/load-tests/sipp-users.csv" "${ARTIFACT_ROOT}/${TIER}/sipp-users-extended.csv"
    # shellcheck disable=SC2086
    ssh -n $ssh_opts "$SIPP_HOST" "mkdir -p /var/www/tallpbx/storage/app/load-tests"
    # shellcheck disable=SC2086
    scp $ssh_opts "${ARTIFACT_ROOT}/${TIER}/sipp-users-extended.csv" "${SIPP_HOST}:/var/www/tallpbx/storage/app/load-tests/sipp-users.csv"
    # shellcheck disable=SC2086
    ssh -n $ssh_opts "$SIPP_HOST" \
        "cd /var/www/tallpbx && PBX_HOST=$(printf '%q' "$pbx_addr") LOAD_GENERATOR_IP=$(printf '%q' "${LOAD_GENERATOR_IP:-$sipp_addr}") TENANT=load-test-beta DOMAIN=$(printf '%q' "$sip_domain") EXTENSIONS=$(printf '%q' "$EXTENSIONS") SKIP_SEED=1 EXTENDED=1 MEDIA_FLOW=1 bash load-testing/scripts/pbx-sipp-validate.sh" \
        | tee "${ARTIFACT_ROOT}/${TIER}/extended-validation.log"

    if grep -iqE '^- [^:]+: (failed|aborted)|FAIL' "${ARTIFACT_ROOT}/${TIER}/extended-validation.log"; then
        printf 'Extended validation reported failures in %s/%s/extended-validation.log — fix before proceeding.\n' "$ARTIFACT_ROOT" "$TIER" >&2
        exit 1
    fi
fi

printf '\nStandard tier comparison complete for %s under %s/%s.\n' "$TIER" "$ARTIFACT_ROOT" "$TIER"
printf 'After completing all standard tiers (%s) with SERIES=%s, validate and consolidate:\n' "${STD_TIERS[*]}" "$SERIES"
printf '  python3 load-testing/scripts/tools/consolidate-series.py %s --csv-out=load-testing/results/consolidated/%s-comparison.csv\n' \
    "$ARTIFACT_ROOT" "$SERIES"
