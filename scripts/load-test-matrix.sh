#!/usr/bin/env bash
# ==============================================================================
# TallPBX Load-Test Refresh Matrix Runner
#
# Orchestrates the two-stage refresh series described in
# docs/load-testing-refresh-implementation-plan.md:
#
#   --list      print every tier x variant x phase the matrix will run
#   --dry-run   print the exact command for each cell without executing
#   (default)   execute the selected cells, writing artifacts under
#               storage/app/load-tests/<series>/<tier>/<variant>/
#
# One invocation drives one hardware tier, because each tier is a fresh
# install on its own host:
#
#   PBX_HOST=x.x.x.218 --tier=h1-1c1g bash scripts/load-test-matrix.sh
#
# Every switch goes through scripts/load-test/lib/switch.sh, which asserts the
# applied value before the cell runs and is reverted by the teardown trap.
# A failed switch or a failed run fails that cell only; the series continues.
#
# Note on where the XML load runs: `pbx:load-test:dialplan` reads the seeded
# tenant from the application database, so it executes on the PBX under test
# over SSH. The SIPp scenarios, which only need credentials from the CSV, run
# on the load generator.
# ==============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# shellcheck source=lib/manifest.sh
source "${ROOT_DIR}/scripts/load-test/lib/manifest.sh"

# The three execution phases every tier x variant combination can run.
PHASES=(xml sip ablation)

SERIES="${SERIES:-refresh-$(date +%Y%m%d)}"
ARTIFACT_ROOT="${ARTIFACT_ROOT:-${ROOT_DIR}/storage/app/load-tests/${SERIES}}"
PBX_HOST="${PBX_HOST:-local}"
SSH_PORT="${SSH_PORT:-22}"
SSH_OPTS="${SSH_OPTS:--o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10}"
REMOTE_APP_DIR="${REMOTE_APP_DIR:-/var/www/tallpbx}"
REMOTE_ARTIFACT_ROOT="${REMOTE_ARTIFACT_ROOT:-${REMOTE_APP_DIR}/storage/app/load-tests/${SERIES}}"
PBX_URL="${PBX_URL:-http://127.0.0.1/api/v1/xml-handler}"
PBX_XML_TOKEN="${PBX_XML_TOKEN:-}"
# Load-test data uses the same environment names as scripts/pbx-sipp-validate.sh,
# so an operator who already knows one tool does not have to learn a second
# vocabulary. The LOAD_* names stay available for programmatic callers.
LOAD_TENANT="${LOAD_TENANT:-${TENANT:-load-test-beta}}"
LOAD_DOMAIN="${LOAD_DOMAIN:-${DOMAIN:-load.test.local}}"
LOAD_EXTENSIONS="${LOAD_EXTENSIONS:-${EXTENSIONS:-20}}"
LOAD_START="${LOAD_START:-${START:-2000}}"
LOAD_PASSWORD="${LOAD_PASSWORD:-${PASSWORD:-LoadTest1234}}"

# SSH target used by the SIPp runner, which expects PBX_HOST to be an address.
SIPP_PBX_HOST="${SIPP_PBX_HOST:-}"
if [ -z "$SIPP_PBX_HOST" ]; then
    if [ "$PBX_HOST" = "local" ]; then
        SIPP_PBX_HOST="127.0.0.1"
    else
        SIPP_PBX_HOST="$PBX_HOST"
    fi
fi

TIER=""
VARIANT=""
PHASE=""
SECTION=""
PROFILE=""
REPEATS="${REPEATS:-3}"
CPS="${CPS:-5}"
LIST_MODE=0
DRY_RUN=0

MATRIX_CACHE="${ARTIFACT_ROOT}/matrix.json"

# Lookups are memoised: building a dry-run touches every profile field, and one
# PHP process per field would dominate the runtime.
#
# Values are keyed by their flattened dot path and map keys by "map:index",
# both loaded in a single PHP pass. The load runs in the current shell (via a
# process substitution) so the cache survives the subshells that command
# substitution creates.
declare -A MATRIX_KEY_CACHE=()
declare -A MATRIX_VALUE_CACHE=()
MATRIX_LOADED=0

# ------------------------------------------------------------------------------
# Matrix access
# ------------------------------------------------------------------------------

# Dump the declarative matrix to JSON once per series so later reads are cheap.
matrix_cache() {
    if [ ! -f "$MATRIX_CACHE" ]; then
        mkdir -p "$(dirname "$MATRIX_CACHE")"
        php -r '
            $root = $argv[1];
            require $root."/vendor/autoload.php";
            $app = require $root."/bootstrap/app.php";
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            echo json_encode(config("load-test-matrix"), JSON_THROW_ON_ERROR);
        ' "$ROOT_DIR" > "$MATRIX_CACHE"
    fi

    printf '%s' "$MATRIX_CACHE"
}

# Flatten the matrix into dot-path lookups in one PHP pass.
#
# Type V lines carry a scalar value, type K lines carry the ordered key names
# of a map so the runner can iterate tiers and variants deterministically.
matrix_load() {
    if [ "$MATRIX_LOADED" = "1" ]; then
        return 0
    fi

    local file type a b c
    file="$(matrix_cache)"

    while IFS=$'\t' read -r type a b c; do
        case "$type" in
            V) MATRIX_VALUE_CACHE["$a"]="$b" ;;
            K) MATRIX_KEY_CACHE["$a:$b"]="$c" ;;
        esac
    done < <(php -r '
        $m = json_decode((string) file_get_contents($argv[1]), true);

        $walk = function (string $prefix, $node) use (&$walk): void {
            if (is_array($node)) {
                $index = 0;
                foreach ($node as $key => $child) {
                    echo "K\t", $prefix, "\t", $index, "\t", $key, PHP_EOL;
                    $index++;
                }
                foreach ($node as $key => $child) {
                    $walk($prefix === "" ? (string) $key : $prefix.".".$key, $child);
                }

                return;
            }

            if (is_bool($node)) { $node = $node ? "true" : "false"; }
            if (is_null($node)) { $node = ""; }
            echo "V\t", $prefix, "\t", $node, "\t", PHP_EOL;
        };

        $walk("", $m);
    ' "$file")

    MATRIX_LOADED=1
}

# List the keys of one top-level matrix map.
matrix_keys() {
    matrix_load

    local prefix="$1" index=0

    while [ -n "${MATRIX_KEY_CACHE[$prefix:$index]+set}" ]; do
        printf '%s\n' "${MATRIX_KEY_CACHE[$prefix:$index]}"
        index=$((index + 1))
    done
}

# Read one scalar field from one matrix entry.
matrix_value() {
    matrix_load
    printf '%s\n' "${MATRIX_VALUE_CACHE[$1.$2.$3]:-}"
}

# List one array field (requires, values) from one matrix entry.
matrix_list() {
    matrix_load

    local prefix="$1.$2.$3" index=0

    while [ -n "${MATRIX_VALUE_CACHE[$prefix.$index]+set}" ]; do
        printf '%s\n' "${MATRIX_VALUE_CACHE[$prefix.$index]}"
        index=$((index + 1))
    done
}

# List one top-level array (the XML sections the battery covers).
matrix_top_list() {
    matrix_load

    local prefix="$1" index=0

    while [ -n "${MATRIX_VALUE_CACHE[$prefix.$index]+set}" ]; do
        printf '%s\n' "${MATRIX_VALUE_CACHE[$prefix.$index]}"
        index=$((index + 1))
    done
}

# Resolve the worker cap a tier uses for the requested PHP-FPM mode.
tier_fpm_children() {
    local tier="$1" mode="$2" alt

    alt="$(matrix_value tiers "$tier" fpm_alt)"

    if [ -n "$alt" ] && [ "$mode" = "$alt" ]; then
        matrix_value tiers "$tier" fpm_alt_children
        return 0
    fi

    matrix_value tiers "$tier" fpm_children
}

# ------------------------------------------------------------------------------
# Selection and validation
# ------------------------------------------------------------------------------

# Print the available options.
usage() {
    cat <<'USAGE'
Usage: load-test-matrix.sh [options]

  --list                 Print every tier x variant x phase and exit
  --dry-run              Print the exact command per cell without executing
  --tier=<id>            Restrict to one hardware tier (required to execute)
  --variant=<id>         Restrict to one XML-serving variant
  --phase=<xml|sip|ablation>  Restrict to one phase
  --section=<dialplan|directory|mixed>  Restrict the XML battery to one section
  --profile=<id>         Restrict the XML battery to one load profile (25x1, 100x5, ...)
  --repeats=<n>          Repetitions per cell (default 3)
  --cps=<n>              Offered calls per second for the SIP and ablation phases (default 5)

Environment:
  PBX_HOST               SSH target of the PBX under test ('local' runs in place)
  PBX_URL                XML handler URL the load client calls
  PBX_XML_TOKEN          XML handler token, when authentication is enabled
  SERIES                 Series directory name (default refresh-YYYYMMDD)
  TENANT / DOMAIN        Seeded tenant slug and SIP realm
  EXTENSIONS / START     Generated extension count and first number
  PASSWORD               SIP password for the generated accounts
  REPEATS / CPS          Repetitions per cell and offered call rate (also flags)

See docs/load-testing-guide.md -> Automated Refresh Harness for the full
procedure, artifact layout and the remote-access policy.
USAGE
}

# Parse the command line into the filter variables.
parse_args() {
    local arg

    for arg in "$@"; do
        case "$arg" in
            --list) LIST_MODE=1 ;;
            --dry-run) DRY_RUN=1 ;;
            --tier=*) TIER="${arg#*=}" ;;
            --variant=*) VARIANT="${arg#*=}" ;;
            --phase=*) PHASE="${arg#*=}" ;;
            --section=*) SECTION="${arg#*=}" ;;
            --profile=*) PROFILE="${arg#*=}" ;;
            --repeats=*) REPEATS="${arg#*=}" ;;
            --cps=*) CPS="${arg#*=}" ;;
            -h | --help | help)
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
}

# Fail fast when a filter names something the matrix does not contain.
validate_filters() {
    local tiers variants

    tiers="$(matrix_keys tiers)"
    variants="$(matrix_keys variants)"

    if [ -n "$TIER" ] && ! grep -qxF "$TIER" <<< "$tiers"; then
        printf 'Unknown tier: %s\n' "$TIER" >&2
        return 2
    fi

    if [ -n "$VARIANT" ] && ! grep -qxF "$VARIANT" <<< "$variants"; then
        printf 'Unknown variant: %s\n' "$VARIANT" >&2
        return 2
    fi

    if [ -n "$PHASE" ] && [ "$PHASE" != "xml" ] && [ "$PHASE" != "sip" ] && [ "$PHASE" != "ablation" ]; then
        printf 'Unknown phase: %s\n' "$PHASE" >&2
        return 2
    fi

    if ! [[ "$REPEATS" =~ ^[0-9]+$ ]] || [ "$REPEATS" -lt 1 ]; then
        printf 'Repeats must be a positive integer\n' >&2
        return 2
    fi

    return 0
}

# Print the selected tiers, one per line.
selected_tiers() {
    if [ -n "$TIER" ]; then
        printf '%s\n' "$TIER"
        return
    fi

    matrix_keys tiers
}

# Print the selected variants, one per line.
selected_variants() {
    if [ -n "$VARIANT" ]; then
        printf '%s\n' "$VARIANT"
        return
    fi

    matrix_keys variants
}

# Print the selected phases, one per line.
selected_phases() {
    if [ -n "$PHASE" ]; then
        printf '%s\n' "$PHASE"
        return
    fi

    printf '%s\n' "${PHASES[@]}"
}

# ------------------------------------------------------------------------------
# Remote execution helpers
# ------------------------------------------------------------------------------

# Run one command on the PBX under test.
run_on_pbx() {
    local command="$1"

    if [ "$PBX_HOST" = "local" ] || [ -z "$PBX_HOST" ]; then
        bash -c "$command"
        return
    fi

    # shellcheck disable=SC2086
    ssh $SSH_OPTS -p "$SSH_PORT" "$PBX_HOST" "$command"
}

# Invoke one switch helper on the PBX and fail the cell when it reports a mismatch.
switch_call() {
    local command="$1"
    shift
    local quoted="" arg

    for arg in "$@"; do
        quoted+=" $(printf '%q' "$arg")"
    done

    run_on_pbx "APP_DIR=$(printf '%q' "$REMOTE_APP_DIR") bash $(printf '%q' "$REMOTE_APP_DIR/scripts/load-test/lib/switch.sh") $(printf '%q' "$command")$quoted"
}

# Apply every switch a variant requires, asserting each one as it lands.
apply_variant() {
    local tier="$1" variant="$2" requirement mode

    while IFS= read -r requirement; do
        [ -n "$requirement" ] || continue

        case "$requirement" in
            fpm:*)
                mode="${requirement#fpm:}"
                switch_call apply_fpm_profile "$mode" "$(tier_fpm_children "$tier" "$mode")"
                ;;
            xml-path:*)
                switch_call apply_xml_path "${requirement#xml-path:}"
                ;;
            lua:*)
                switch_call apply_lua_bindings "${requirement#lua:}"
                ;;
            octane:*)
                switch_call apply_octane_binding "${requirement#octane:}"
                ;;
            *)
                printf 'UNKNOWN-REQUIREMENT %s\n' "$requirement" >&2
                return 1
                ;;
        esac
    done < <(matrix_list variants "$variant" requires)
}

# ------------------------------------------------------------------------------
# Command construction (shared by --dry-run and execution)
# ------------------------------------------------------------------------------

# Build the exact dialplan load command for one XML cell.
xml_command() {
    local tier="$1" variant="$2" profile_id="$3" ttl_id="$4" section="$5" repeat="$6"
    local requests concurrency scenario dialplan contributor directory acl report label

    requests="$(matrix_value xml_profiles "$profile_id" requests)"
    concurrency="$(matrix_value xml_profiles "$profile_id" concurrency)"
    scenario="$(matrix_value cache_ttl_profiles "$ttl_id" scenario)"
    dialplan="$(matrix_value cache_ttl_profiles "$ttl_id" dialplan)"
    contributor="$(matrix_value cache_ttl_profiles "$ttl_id" contributor)"
    directory="$(matrix_value cache_ttl_profiles "$ttl_id" directory)"
    acl="$(matrix_value cache_ttl_profiles "$ttl_id" acl)"

    label="${tier}/${variant}/${profile_id}-${ttl_id}-${section}"
    report="${REMOTE_ARTIFACT_ROOT}/${tier}/${variant}/${profile_id}-${ttl_id}-${section}-r${repeat}.json"

    printf 'cd %q && php artisan pbx:load-test:dialplan --tenant=%q --url=%q --requests=%q --concurrency=%q --scenario=%q --section=%q --label=%q --report=%q' \
        "$REMOTE_APP_DIR" "$LOAD_TENANT" "$PBX_URL" "$requests" "$concurrency" "$scenario" "$section" "$label" "$report"

    if [ -n "$PBX_XML_TOKEN" ]; then
        printf ' --token=%q' "$PBX_XML_TOKEN"
    fi
}

# Build the exact SIPp validation command for one SIP cell.
sip_command() {
    local run_dir="$1"

    printf 'PBX_HOST=%q TENANT=%q DOMAIN=%q EXTENSIONS=%q START=%q PASSWORD=%q SKIP_SEED=1 CALL_RATE=%q RUN_DIR=%q bash %q' \
        "$SIPP_PBX_HOST" "$LOAD_TENANT" "$LOAD_DOMAIN" "$LOAD_EXTENSIONS" "$LOAD_START" "$LOAD_PASSWORD" "$CPS" "$run_dir" \
        "${ROOT_DIR}/scripts/pbx-sipp-validate.sh"
}

# Map one ablation id to the switch helper that drives it.
#
# Knobs without a helper are skipped by the ablation phase instead of being
# applied with a guessed command.
ablation_switch_command() {
    case "$1" in
        fs-loglevel) printf 'apply_fs_loglevel' ;;
        fs-db) printf 'apply_fs_db_mode' ;;
        *) return 1 ;;
    esac
}

# Build the exact switch command for one ablation value.
ablation_command() {
    local ablation="$1" helper

    if ! helper="$(ablation_switch_command "$ablation")"; then
        return 1
    fi

    printf 'switch.sh %s %s' "$helper" "$2"
}

# ------------------------------------------------------------------------------
# Listing and dry runs
# ------------------------------------------------------------------------------

# Print every selected tier x variant x phase.
list_cells() {
    local tier variant phase

    while IFS= read -r tier; do
        while IFS= read -r variant; do
            while IFS= read -r phase; do
                printf '%-10s %-16s %-9s\n' "$tier" "$variant" "$phase"
            done < <(selected_phases)
        done < <(selected_variants)
    done < <(selected_tiers)
}

# Print the exact command for every selected cell without running anything.
dry_run_cells() {
    local tier variant phase profile_id ttl_id section repeat ablation value run_dir

    while IFS= read -r tier; do
        while IFS= read -r variant; do
            while IFS= read -r phase; do
                printf '=== %s / %s / %s\n' "$tier" "$variant" "$phase"

                case "$phase" in
                    xml)
                        for section in $(selected_sections); do
                            while IFS= read -r profile_id; do
                                [ -z "$PROFILE" ] || [ "$PROFILE" = "$profile_id" ] || continue

                                while IFS= read -r ttl_id; do
                                    for repeat in $(seq 1 "$REPEATS"); do
                                        printf '  %s\n' "$(xml_command "$tier" "$variant" "$profile_id" "$ttl_id" "$section" "$repeat")"
                                    done
                                done < <(matrix_keys cache_ttl_profiles)
                            done < <(matrix_keys xml_profiles)
                        done
                        ;;
                    sip)
                        run_dir="${ARTIFACT_ROOT}/${tier}/${variant}/sip-cps${CPS}"
                        printf '  %s\n' "$(sip_command "$run_dir")"
                        ;;
                    ablation)
                        while IFS= read -r ablation; do
                            while IFS= read -r value; do
                                if ablation_command "$ablation" "$value" >/dev/null 2>&1; then
                                    printf '  [%s] %s\n' "$ablation" "$(ablation_command "$ablation" "$value")"
                                else
                                    printf '  [%s] SKIPPED (no switch helper for value %s)\n' "$ablation" "$value"
                                fi
                            done < <(matrix_list ablations "$ablation" values)
                        done < <(matrix_keys ablations)
                        ;;
                esac
            done < <(selected_phases)
        done < <(selected_variants)
    done < <(selected_tiers)
}

# Print the XML sections the battery covers, honouring --section.
selected_sections() {
    if [ -n "$SECTION" ]; then
        printf '%s\n' "$SECTION"
        return
    fi

    matrix_list_sections
}

# List the XML sections declared in the matrix.
matrix_list_sections() {
    matrix_top_list xml_sections
}

# ------------------------------------------------------------------------------
# Execution
# ------------------------------------------------------------------------------

# Record a failed cell and keep the series running.
cell_failed() {
    local label="$1"

    printf 'CELL-FAILED %s\n' "$label" >&2
    printf '%s\n' "$label" >> "${ARTIFACT_ROOT}/${TIER}/failures.log" 2>/dev/null || true
}

# Prove the PBX under test already carries this harness before a cell runs.
#
# The switches and the manifest command live in the repository, so a host
# running an older checkout would fail halfway through a tier with a cryptic
# shell error. Failing here costs one command instead of a half-measured run.
preflight_remote() {
    local app_dir report
    app_dir="$(printf '%q' "$REMOTE_APP_DIR")"

    if ! report="$(run_on_pbx "cd ${app_dir} 2>/dev/null || { echo 'APP-DIR missing'; exit 0; };
        [ -f scripts/load-test/lib/switch.sh ] || echo 'MISSING scripts/load-test/lib/switch.sh';
        [ -f config/load-test-matrix.php ] || echo 'MISSING config/load-test-matrix.php';
        [ -f app/Console/Commands/PbxLoadTestManifestCommand.php ] || echo 'MISSING pbx:load-test:manifest';
        command -v fs_cli >/dev/null || echo 'MISSING fs_cli';
        command -v nginx >/dev/null || echo 'MISSING nginx'" 2>&1)"; then
        printf 'Cannot reach %s over SSH. Check the host, port and key.\n' "$PBX_HOST" >&2
        return 1
    fi

    if [ -n "$report" ]; then
        printf '%s\n' "$report" >&2
        printf 'Deploy the current branch to %s before running the series.\n' "$PBX_HOST" >&2
        return 1
    fi

    return 0
}

# Verify the host is the tier the matrix declares, then write its manifest.
prepare_tier() {
    local vcpu ram_mb destination

    vcpu="$(matrix_value tiers "$TIER" vcpu)"
    ram_mb="$(matrix_value tiers "$TIER" ram_mb)"
    destination="${REMOTE_ARTIFACT_ROOT}/${TIER}/manifest.json"

    printf 'Preparing tier %s (%s vCPU / %s MB)\n' "$TIER" "$vcpu" "$ram_mb" >&2

    manifest_write "$destination"

    # Read the manifest back where the runner can see it.
    if [ "$PBX_HOST" = "local" ]; then
        # A local run already writes into the artifact tree; only copy when the
        # two roots really differ.
        if [ "$destination" != "${ARTIFACT_ROOT}/${TIER}/manifest.json" ]; then
            cp "$destination" "${ARTIFACT_ROOT}/${TIER}/manifest.json"
        fi
    else
        scp $SSH_OPTS -P "$SSH_PORT" "${PBX_HOST}:${destination}" "${ARTIFACT_ROOT}/${TIER}/manifest.json" >/dev/null
    fi

    manifest_assert_tier "${ARTIFACT_ROOT}/${TIER}/manifest.json" "$vcpu" "$ram_mb"
}

# Seed the synthetic load-test tenant once per tier.
seed_tenant() {
    printf 'Seeding the synthetic tenant %s\n' "$LOAD_TENANT" >&2

    run_on_pbx "cd $(printf '%q' "$REMOTE_APP_DIR") && php artisan pbx:load-test:seed --tenant=$(printf '%q' "$LOAD_TENANT") --domain=$(printf '%q' "$LOAD_DOMAIN") --extensions=$(printf '%q' "$LOAD_EXTENSIONS") --start=$(printf '%q' "$LOAD_START") --password=$(printf '%q' "$LOAD_PASSWORD") --reset --output=$(printf '%q' "$REMOTE_ARTIFACT_ROOT/sipp-users.csv")"
}

# Restore the PBX baseline and pull the artifacts back to the generator.
teardown_tier() {
    local exit_code=$?

    trap - EXIT INT TERM

    printf 'Restoring the PBX baseline\n' >&2
    switch_call restore_baseline || printf 'WARNING: baseline restore reported a failure\n' >&2

    if [ "$PBX_HOST" != "local" ] && [ -d "${ARTIFACT_ROOT}/${TIER}" ]; then
        scp $SSH_OPTS -P "$SSH_PORT" -r "${PBX_HOST}:${REMOTE_ARTIFACT_ROOT}/${TIER}/." "${ARTIFACT_ROOT}/${TIER}/" >/dev/null 2>&1 \
            || printf 'WARNING: could not pull artifacts from %s\n' "$PBX_HOST" >&2
    fi

    return "$exit_code"
}

# Run the XML battery for one variant: every profile x TTL x section x repeat.
run_xml_phase() {
    local variant="$1" profile_id ttl_id section repeat label

    apply_variant "$TIER" "$variant"

    while IFS= read -r section; do
        while IFS= read -r profile_id; do
            if [ -n "$PROFILE" ] && [ "$PROFILE" != "$profile_id" ]; then
                continue
            fi

            while IFS= read -r ttl_id; do
                # Applying the TTL once per profile keeps the cell boundary
                # explicit even when two profiles share the same values.
                switch_call apply_ttl \
                    "$(matrix_value cache_ttl_profiles "$ttl_id" dialplan)" \
                    "$(matrix_value cache_ttl_profiles "$ttl_id" contributor)" \
                    "$(matrix_value cache_ttl_profiles "$ttl_id" directory)" \
                    "$(matrix_value cache_ttl_profiles "$ttl_id" acl)" || {
                    cell_failed "${TIER}/${variant}/${profile_id}-${ttl_id}-${section}"
                    continue
                }

                for repeat in $(seq 1 "$REPEATS"); do
                    label="${TIER}/${variant}/${profile_id}-${ttl_id}-${section}-r${repeat}"

                    if ! run_on_pbx "$(xml_command "$TIER" "$variant" "$profile_id" "$ttl_id" "$section" "$repeat")" > /dev/null 2>&1; then
                        cell_failed "$label"
                    fi
                done
            done < <(matrix_keys cache_ttl_profiles)
        done < <(matrix_keys xml_profiles)
    done < <(selected_sections)
}

# Run the SIPp feature validation and one capacity step for one variant.
run_sip_phase() {
    local variant="$1" run_dir label

    apply_variant "$TIER" "$variant"

    run_dir="${ARTIFACT_ROOT}/${TIER}/${variant}/sip-cps${CPS}"
    mkdir -p "$run_dir"
    label="${TIER}/${variant}/sip-cps${CPS}"

    if ! bash -c "$(sip_command "$run_dir")" > "${run_dir}/sipp.log" 2>&1; then
        cell_failed "$label"
    fi
}

# Run the single-knob ablations the switch library can drive.
#
# Knobs without an apply helper are reported as skipped rather than silently
# dropped, so the operator sees which ones still need a manual switch.
run_ablation_phase() {
    local variant="$1" ablation value repeat label helper

    apply_variant "$TIER" "$variant"

    while IFS= read -r ablation; do
        while IFS= read -r value; do
            if ! helper="$(ablation_switch_command "$ablation")"; then
                printf 'ABLATION-SKIPPED %s=%s (no switch helper)\n' "$ablation" "$value" >&2
                continue
            fi

            label="${TIER}/${variant}/ablation-${ablation}-${value}"

            if ! switch_call "$helper" "$value"; then
                cell_failed "$label"
                continue
            fi

            for repeat in $(seq 1 "$REPEATS"); do
                if ! run_on_pbx "$(xml_command "$TIER" "$variant" "100x5" "production-60" "dialplan" "$repeat")" > /dev/null 2>&1; then
                    cell_failed "${label}-r${repeat}"
                fi
            done
        done < <(matrix_list ablations "$ablation" values)
    done < <(matrix_keys ablations)
}

# Execute the selected cells for the selected tier.
execute_cells() {
    local variant phase failures=0

    if [ -z "$TIER" ]; then
        printf 'Execute mode requires --tier=<id>; each tier is a separate fresh host.\n' >&2
        exit 2
    fi

    mkdir -p "${ARTIFACT_ROOT}/${TIER}"
    trap teardown_tier EXIT INT TERM

    if ! preflight_remote; then
        printf 'Preflight failed; no measurement was taken on %s.\n' "$TIER" >&2
        exit 1
    fi

    prepare_tier
    seed_tenant

    while IFS= read -r variant; do
        while IFS= read -r phase; do
            printf 'Running %s / %s / %s\n' "$TIER" "$variant" "$phase" >&2

            case "$phase" in
                xml) run_xml_phase "$variant" || failures=$((failures + 1)) ;;
                sip) run_sip_phase "$variant" || failures=$((failures + 1)) ;;
                ablation) run_ablation_phase "$variant" || failures=$((failures + 1)) ;;
            esac
        done < <(selected_phases)
    done < <(selected_variants)

    if [ "$failures" -gt 0 ]; then
        printf '%s phase(s) reported failures; see %s/failures.log\n' "$failures" "${ARTIFACT_ROOT}/${TIER}" >&2
        return 1
    fi

    printf 'Series artifacts: %s\n' "$ARTIFACT_ROOT"
}

# Parse the command line, then list, dry-run, or execute.
main() {
    parse_args "$@"

    # Load the matrix in this shell, before any command substitution runs, so
    # the subshells inherit a populated cache instead of each paying for a PHP
    # round trip.
    matrix_load

    if ! validate_filters; then
        exit 2
    fi

    if [ "$LIST_MODE" = "1" ]; then
        list_cells
        return 0
    fi

    if [ "$DRY_RUN" = "1" ]; then
        dry_run_cells
        return 0
    fi

    execute_cells
}

main "$@"
