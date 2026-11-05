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
#   PBX_HOST=x.x.x.218 --tier=h1-1c1g bash load-testing/scripts/load-test-matrix.sh
#
# Every switch goes through load-testing/scripts/lib/switch.sh, which asserts the
# applied value before the cell runs and is reverted by the teardown trap.
# A failed switch or a failed run fails that cell only; the series continues.
#
# Note on where the XML load runs: `pbx:load-test:dialplan` reads the seeded
# tenant from the application database, so it executes on the PBX under test
# over SSH. The SIPp scenarios, which only need credentials from the CSV, run
# on the load generator.
# ==============================================================================

set -euo pipefail

# The repository root two levels up from load-testing/scripts/.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

# shellcheck source=lib/manifest.sh
source "${ROOT_DIR}/load-testing/scripts/lib/manifest.sh"

# The three execution phases every tier x variant combination can run.
PHASES=(xml sip ablation)

# The profile used to warm a variant up before anything is measured. The
# matrix labels it "Warm-up" for exactly this purpose.
WARMUP_PROFILE="25x1"

SERIES="${SERIES:-refresh-$(date +%Y%m%d)}"
ARTIFACT_ROOT="${ARTIFACT_ROOT:-${ROOT_DIR}/load-testing/results/${SERIES}}"
PBX_HOST="${PBX_HOST:-local}"
SSH_PORT="${SSH_PORT:-22}"
# Keepalives turn a dead peer into a fast error: a stalled ssh/scp once froze
# a rung for 2.5 hours with no output and no failure to record.
SSH_OPTS="${SSH_OPTS:--o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4}"
REMOTE_APP_DIR="${REMOTE_APP_DIR:-/var/www/tallpbx}"
REMOTE_ARTIFACT_ROOT="${REMOTE_ARTIFACT_ROOT:-${REMOTE_APP_DIR}/storage/app/load-tests/${SERIES}}"
PBX_URL="${PBX_URL:-http://127.0.0.1/api/v1/xml-handler}"
# Permanent copy of the raw artifacts.
#
# Results now live inside the repository (load-testing/results), which is the
# durable copy: it survives the measurement hosts being deleted and travels
# with a clone. ARCHIVE_ROOT stays available for an extra copy elsewhere, and
# is empty by default so a run does not write outside the repo.
if [ -z "${ARCHIVE_ROOT+set}" ]; then
    ARCHIVE_ROOT=""
fi
# The persistent worker is a second HTTP server serving the same route. A cell
# that applies the Octane binding must be measured against this URL, or it
# measures PHP-FPM and reports the number as Octane.
PBX_OCTANE_URL="${PBX_OCTANE_URL:-http://127.0.0.1:8090/api/v1/xml-handler}"
PBX_XML_TOKEN="${PBX_XML_TOKEN:-}"
# Load-test data uses the same environment names as scripts/pbx-sipp-validate.sh,
# so an operator who already knows one tool does not have to learn a second
# vocabulary. The LOAD_* names stay available for programmatic callers.
LOAD_TENANT="${LOAD_TENANT:-${TENANT:-load-test-beta}}"
# The SIP realm the tenant is seeded on. An empty value means "ask the PBX",
# because FreeSWITCH resolves a registering user under the realm it serves and
# a tenant seeded on any other realm answers 401/403 to every endpoint.
LOAD_DOMAIN="${LOAD_DOMAIN:-${DOMAIN:-}}"
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

# The load generator runs SIPp. It is a separate host so that its packets and
# its CPU never compete with the PBX under test; 'local' keeps a single-host
# smoke run possible, but a capacity number from it is not publishable because
# the generator then measures itself.
SIPP_HOST="${SIPP_HOST:-local}"
SIPP_SSH_PORT="${SIPP_SSH_PORT:-${SSH_PORT}}"
if [ "$SIPP_HOST" = "local" ] || [ -z "$SIPP_HOST" ]; then
    SIPP_APP_DIR="${SIPP_APP_DIR:-${ROOT_DIR}}"
else
    SIPP_APP_DIR="${SIPP_APP_DIR:-/var/www/tallpbx}"
fi

# Where the simulated outbound gateway sends its calls: the UAS the SIPp
# runner starts on the generator. Without this the seeded route points at
# 127.0.0.1 on the PBX, where nothing listens, and every outbound call answers
# 503 before a packet reaches the generator.
SIPP_UAS_PORT="${SIPP_UAS_PORT:-5088}"
if [ "$SIPP_HOST" = "local" ] || [ -z "$SIPP_HOST" ]; then
    SIPP_UAS_HOST="127.0.0.1"
else
    SIPP_UAS_HOST="$SIPP_HOST"
fi

# The base directory of the gate and probe tools that run on this host.
TOOLS_DIR="${ROOT_DIR}/load-testing/scripts/tools"

# Database the CDR gate counts rows in.
PBX_DB_NAME="${PBX_DB_NAME:-tallpbx}"

# The CDR pipeline is asynchronous: mod_json_cdr posts each record when a leg
# ends and the worker digests it afterwards. Reading the count the instant a
# cell finishes would report a healthy pipeline as lost records, so the gate
# keeps polling until the rows the completed calls require have arrived, or
# the budget runs out.
CDR_SETTLE_INTERVAL="${CDR_SETTLE_INTERVAL:-3}"
CDR_SETTLE_MAX="${CDR_SETTLE_MAX:-60}"
# Whether completed calls are expected to leave database records. A cell run
# with the recording pipeline deliberately off passes 0, so the gate judges the
# calls themselves instead of failing a cell whose point was to run unrecorded.
CDR_EXPECT_RECORDS="${CDR_EXPECT_RECORDS:-1}"

TIER=""
VARIANT=""
PHASE=""
SECTION=""
PROFILE=""
TTL=""
ABLATION_FILTER=""
REPEATS="${REPEATS:-3}"
CPS="${CPS:-5}"
# Seconds of offered calls per capacity rung. At the offered rate this is how
# long a rung sustains load: 5 calls/second for 60 seconds = 300 calls. A
# ten-call burst finishes in seconds and says nothing about capacity.
LADDER_SECONDS="${LADDER_SECONDS:-60}"
LIST_MODE=0
DRY_RUN=0

# The matrix cache is scratch, not evidence: keeping it beside the results
# would add an untracked file to a committed directory on every invocation.
MATRIX_CACHE="${MATRIX_CACHE:-${TMPDIR:-/tmp}/tallpbx-load-matrix-${SERIES}.json}"

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
#
# The matrix is a plain PHP array in the test tooling, not an application
# configuration, so it is read directly rather than through the framework's
# configuration repository.
matrix_cache() {
    if [ ! -f "$MATRIX_CACHE" ]; then
        mkdir -p "$(dirname "$MATRIX_CACHE")"
        php -r '
            echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);
        ' "${ROOT_DIR}/load-testing/matrix.php" > "$MATRIX_CACHE"
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
  --ablation=<id>        Restrict the ablation phase to one knob (fs-db, esl-subscriptions, ...)
  --section=<dialplan|directory|mixed>  Restrict the XML battery to one section
  --profile=<id>         Restrict the XML battery to one load profile (25x1, 100x5, ...)
  --ttl=<id>             Restrict the XML battery to one cache policy (production-60, ...)
  --repeats=<n>          Repetitions per cell (default 3)
  --cps=<n>              Offered calls per second for the SIP and ablation phases (default 5)

Environment:
  PBX_HOST               SSH target of the PBX under test ('local' runs in place)
  SIPP_HOST              SSH target of the load generator ('local' runs SIPp in place)
  PBX_DB_NAME            Database the CDR gate counts rows in (default tallpbx)
  CDR_SETTLE_INTERVAL    Seconds between CDR row-count polls during the drain
  CDR_SETTLE_MAX         Longest the gate waits for late records, in seconds
  PBX_URL                XML handler URL the load client calls
  PBX_XML_TOKEN          XML handler token, when authentication is enabled
  SERIES                 Series directory name (default refresh-YYYYMMDD)
  ARCHIVE_ROOT           Optional extra copy of the artifacts (empty by default;
                         load-testing/results is the durable copy)
  TENANT / DOMAIN        Seeded tenant slug and SIP realm
  EXTENSIONS / START     Generated extension count and first number
  PASSWORD               SIP password for the generated accounts
  REPEATS / CPS          Repetitions per cell and offered call rate (also flags)
  LADDER_SECONDS         Seconds of offered calls per capacity rung (default 60)

See load-testing/README.md for the full procedure, artifact layout and the
remote-access policy.
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
            --ttl=*) TTL="${arg#*=}" ;;
            --ablation=*) ABLATION_FILTER="${arg#*=}" ;;
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
    local tiers variants ttls

    tiers="$(matrix_keys tiers)"
    variants="$(matrix_keys variants)"
    ttls="$(matrix_keys cache_ttl_profiles)"

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

    if [ -n "$TTL" ] && ! grep -qxF "$TTL" <<< "$ttls"; then
        printf 'Unknown cache policy: %s\n' "$TTL" >&2
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

    # -n keeps ssh from draining the stdin of the `while read` loops that
    # call this function; without it the first cell silently ends the loop.
    # shellcheck disable=SC2086
    ssh -n $SSH_OPTS -p "$SSH_PORT" "$PBX_HOST" "$command"
}

# Run one command on the load generator.
#
# SIPp belongs there rather than on the PBX: the PBX has to reach the
# generator's UAS and RTP ports, and a generator sharing the PBX's CPU would
# be measuring itself. 'local' runs the command in this shell.
run_on_sipp() {
    local command="$1"

    if [ "$SIPP_HOST" = "local" ] || [ -z "$SIPP_HOST" ]; then
        bash -c "$command"
        return
    fi

    # -n keeps ssh from draining stdin, the same trap that silently ended the
    # runner's read loops once already.
    # shellcheck disable=SC2086
    ssh -n $SSH_OPTS -p "$SIPP_SSH_PORT" "$SIPP_HOST" "$command"
}

# Resolve the SIP realm the PBX serves registrations under.
#
# FreeSWITCH looks a registering endpoint up by the realm in the profile, not
# by the realm the client wrote in its request. A tenant seeded on a realm the
# PBX does not serve therefore fails every registration with 401/403, which
# reads as a credentials problem. The host declares the realm it serves in
# FS_DEFAULT_SIP_REALM, so that is the default; an explicit DOMAIN or
# LOAD_DOMAIN still wins, and the documented value is the last resort.
resolve_load_domain() {
    if [ -n "$LOAD_DOMAIN" ]; then
        return 0
    fi

    local realm

    realm="$(run_on_pbx "grep -E '^FS_DEFAULT_SIP_REALM=' $(printf '%q' "${REMOTE_APP_DIR}/.env") | head -n1 | cut -d= -f2-" 2>/dev/null)"
    realm="$(printf '%s' "$realm" | tr -d '[:space:]')"
    realm="${realm//\"/}"
    realm="${realm//\'/}"

    LOAD_DOMAIN="${realm:-load.test.local}"
}

# Count the CDR rows the PBX currently holds.
#
# Prints an empty string when the database cannot be read, which the SIP phase
# treats as a blocking failure: a cell whose records cannot be counted has not
# been verified, whatever its SIPp output says.
cdr_row_count() {
    local count

    count="$(run_on_pbx "mysql -N -e 'select count(*) from cdrs;' $(printf '%q' "$PBX_DB_NAME")" 2>/dev/null | tr -cd '0-9')"

    printf '%s\n' "$count"
}

# Wait for the rows a cell's completed calls should have produced.
#
# The pipeline is asynchronous and does not necessarily start writing while
# the cell is still running: on the first real SIP cell the row count sat
# unchanged for the whole settle window and then jumped by 25. Waiting for a
# stable count therefore reads "settled" before the worker has done anything.
# The wait is driven by the outcome instead — poll until the count has risen
# by at least the number of completed calls, or the budget runs out — and the
# verdict then decides whether what arrived is enough.
cdrs_wait_for() {
    local before="$1" expected="$2" elapsed=0 count=""

    while [ "$elapsed" -lt "$CDR_SETTLE_MAX" ]; do
        count="$(cdr_row_count)"

        if [ -n "$count" ] && [ $((count - before)) -ge "$expected" ]; then
            printf '%s\n' "$count"
            return 0
        fi

        sleep "$CDR_SETTLE_INTERVAL"
        elapsed=$((elapsed + CDR_SETTLE_INTERVAL))
    done

    # Report what was seen so the verdict can name the shortfall.
    printf '%s\n' "${count:-$before}"
}

# Where one SIP cell writes its artifacts.
#
# On a separate generator that is the generator's own directory, which the
# runner copies back afterwards; on a single-host run it is the artifact
# directory itself.
sip_run_dir() {
    local tier="$1" variant="$2"

    if [ "$SIPP_HOST" = "local" ] || [ -z "$SIPP_HOST" ]; then
        printf '%s\n' "${ARTIFACT_ROOT}/${tier}/${variant}/sip-cps${CPS}"
        return
    fi

    printf '%s\n' "${SIPP_APP_DIR}/storage/app/load-tests/${SERIES}/${tier}/${variant}/sip-cps${CPS}"
}

# Copy one SIP cell's artifacts from the generator back to this host.
#
# The verdict is read from the copied files, not from the generator, so a
# generator that is cleaned up or deleted cannot take the evidence with it.
pull_sipp_artifacts() {
    local remote_dir="$1" local_dir="$2"

    if [ "$SIPP_HOST" = "local" ] || [ -z "$SIPP_HOST" ]; then
        return 0
    fi

    # shellcheck disable=SC2086
    scp $SSH_OPTS -P "$SIPP_SSH_PORT" -r "${SIPP_HOST}:${remote_dir}/." "${local_dir}/" >/dev/null 2>&1
}

# Decide whether every step of a SIP cell passed, from the suite's own summary.
#
# The suite writes one line per step, so the summary is the measurement's own
# account of itself. It is the right thing to judge the cell by: the ssh
# session that carried the run can drop while a long step is in progress, and
# a transport failure is not a measurement failure.
sipp_summary_passed() {
    local summary="$1"

    if [ ! -f "$summary" ]; then
        return 1
    fi

    # Any step that did not pass fails the cell.
    if grep -qE '^- [^:]+: (failed|aborted)' "$summary"; then
        return 1
    fi

    # The suite must have run at least one step.
    grep -qE '^- [^:]+: passed' "$summary"
}

# Read the gateway host the load-test tenant's outbound route points at.
#
# The gateway lives in the PBX's database, so the query runs on the PBX. The
# generator is a SIPp-only host and has no database client at all.
pbx_gateway_host() {
    local sql host

    sql="select g.host from gateways g join tenants t on t.id = g.tenant_id where t.slug = '${LOAD_TENANT}' limit 1"

    host="$(run_on_pbx "mysql -N -e \"${sql}\" $(printf '%q' "$PBX_DB_NAME")" 2>/dev/null | tr -d '[:space:]')"

    printf '%s\n' "$host"
}

# Free the SIPp ports on the generator before a cell starts.
#
# Each background UAS the validation script starts lives for `timeout 300`, so
# the previous cell's listener is still holding 5066 or 5088 when the next
# cell begins. The new UAS cannot bind, the scenario aborts, and the outbound
# calls reach nobody - which reads exactly like a routing fault.
#
# The pattern is written so it cannot match the command line that runs it.
release_sipp_ports() {
    local cleanup

    cleanup="pkill -f 'sipp [-]sf' >/dev/null 2>&1 || true
for port in 5066 5088; do
    for attempt in \$(seq 1 20); do
        ss -lun 2>/dev/null | grep -q \":\${port} \" || break
        sleep 1
    done
done
for port in 5066 5088; do
    if ss -lun 2>/dev/null | grep -q \":\${port} \"; then
        echo \"PORT-BUSY \${port}\"
    fi
done"

    run_on_sipp "$cleanup" 2>/dev/null || true
}

# Give the load generator everything one series needs to run its scenarios.
#
# The generator is disposable and may hold any build, so the scenario files and
# the validation script are copied from the checkout under test rather than
# trusted to be current there. The credential file is regenerated on the PBX
# and copied across, which also keeps it in step with the tenant being
# measured: a stale CSV authenticates extensions that no longer exist, and
# every call then fails with a registration error that looks like a PBX fault.
sipp_prepare_generator() {
    local csv_on_pbx="${REMOTE_APP_DIR}/storage/app/load-tests/${SERIES}/sipp-users.csv"
    local csv_local="${ARTIFACT_ROOT}/${TIER}/generator/sipp-users.csv"
    local sync_log="${ARTIFACT_ROOT}/${TIER}/generator/sync.log"
    local seeded

    mkdir -p "${ARTIFACT_ROOT}/${TIER}/generator"

    # A leftover listener from the previous cell would make this one fail in a
    # way that looks like a product fault.
    release_sipp_ports

    if ! seeded="$(run_on_pbx "cd $(printf '%q' "$REMOTE_APP_DIR") && php artisan pbx:load-test:seed --tenant=$(printf '%q' "$LOAD_TENANT") --domain=$(printf '%q' "$LOAD_DOMAIN") --extensions=$(printf '%q' "$LOAD_EXTENSIONS") --start=$(printf '%q' "$LOAD_START") --password=$(printf '%q' "$LOAD_PASSWORD") --sipp-host=$(printf '%q' "$SIPP_UAS_HOST") --sipp-port=$(printf '%q' "$SIPP_UAS_PORT") --output=$(printf '%q' "$csv_on_pbx")" 2>&1)"; then
        printf 'SIPP-PREPARE-FAILED seeding accounts on %s\n%s\n' "$PBX_HOST" "$seeded" >&2
        return 1
    fi

    printf '%s\n' "$seeded" > "${ARTIFACT_ROOT}/${TIER}/generator/seed.log"

    if [ "$SIPP_HOST" = "local" ] || [ -z "$SIPP_HOST" ]; then
        return 0
    fi

    : > "$sync_log"

    if ! run_on_sipp "mkdir -p $(printf '%q' "${SIPP_APP_DIR}/tools") $(printf '%q' "${SIPP_APP_DIR}/scripts") $(printf '%q' "${SIPP_APP_DIR}/storage/app/load-tests")" >> "$sync_log" 2>&1; then
        printf 'SIPP-PREPARE-FAILED could not create the generator directories; see %s\n' "$sync_log" >&2
        return 1
    fi

    # shellcheck disable=SC2086
    if ! scp $SSH_OPTS -P "$SIPP_SSH_PORT" -r "${ROOT_DIR}/load-testing/tools/sipp" "${SIPP_HOST}:${SIPP_APP_DIR}/tools/" >> "$sync_log" 2>&1; then
        printf 'SIPP-PREPARE-FAILED could not copy the SIPp scenarios; see %s\n' "$sync_log" >&2
        return 1
    fi

    # shellcheck disable=SC2086
    if ! scp $SSH_OPTS -P "$SIPP_SSH_PORT" "${ROOT_DIR}/load-testing/scripts/pbx-sipp-validate.sh" "${SIPP_HOST}:${SIPP_APP_DIR}/scripts/pbx-sipp-validate.sh" >> "$sync_log" 2>&1; then
        printf 'SIPP-PREPARE-FAILED could not copy the validation script; see %s\n' "$sync_log" >&2
        return 1
    fi

    # shellcheck disable=SC2086
    if ! scp $SSH_OPTS -P "$SSH_PORT" "${PBX_HOST}:${csv_on_pbx}" "$csv_local" >> "$sync_log" 2>&1; then
        printf 'SIPP-PREPARE-FAILED could not fetch the credential file from %s; see %s\n' "$PBX_HOST" "$sync_log" >&2
        return 1
    fi

    # shellcheck disable=SC2086
    if ! scp $SSH_OPTS -P "$SIPP_SSH_PORT" "$csv_local" "${SIPP_HOST}:${SIPP_APP_DIR}/storage/app/load-tests/sipp-users.csv" >> "$sync_log" 2>&1; then
        printf 'SIPP-PREPARE-FAILED could not deliver the credential file to %s; see %s\n' "$SIPP_HOST" "$sync_log" >&2
        return 1
    fi

    # Prove the generator can actually run before a cell is spent on it.
    local remote_check report

    remote_check="cd $(printf '%q' "$SIPP_APP_DIR") || exit 1
command -v sipp >/dev/null || echo 'MISSING sipp'
test -f load-testing/tools/sipp/uac-extension.xml || echo 'MISSING scenarios'
test -s storage/app/load-tests/sipp-users.csv || echo 'MISSING sipp-users.csv'
echo \"csv_lines=\$(wc -l < storage/app/load-tests/sipp-users.csv 2>/dev/null || echo 0)\""

    if ! report="$(run_on_sipp "$remote_check" 2>&1)"; then
        printf 'SIPP-PREPARE-FAILED could not inspect %s\n%s\n' "$SIPP_HOST" "$report" >&2
        return 1
    fi

    printf '%s\n' "$report" >> "$sync_log"

    case "$report" in
        *MISSING*)
            printf 'SIPP-PREPARE-FAILED generator is not ready\n%s\n' "$report" >&2
            return 1
            ;;
    esac

    # The outbound route has to point at the generator. When it does not, every
    # outbound call answers 503 on the PBX and the cell fails on a setup
    # problem that reads like a routing fault.
    local gateway_host

    gateway_host="$(pbx_gateway_host)"

    if [ "$SIPP_UAS_HOST" != "127.0.0.1" ] && [ "$gateway_host" != "$SIPP_UAS_HOST" ]; then
        printf 'SIPP-PREPARE-FAILED the outbound gateway points at %s, not %s\n' \
            "${gateway_host:-nothing}" "$SIPP_UAS_HOST" >&2
        return 1
    fi

    return 0
}

# Print the exact remote command for one switch helper call.
#
# Execution and --dry-run both go through here, so the printed plan cannot
# drift away from what the run actually executes.
switch_command() {
    local command="$1"
    shift
    local quoted="" arg

    for arg in "$@"; do
        quoted+=" $(printf '%q' "$arg")"
    done

    printf 'APP_DIR=%s LUA_MIRROR_EXTENSION=%s LUA_MIRROR_DOMAIN=%s bash %s %s%s' \
        "$(printf '%q' "$REMOTE_APP_DIR")" \
        "$(printf '%q' "$LOAD_START")" \
        "$(printf '%q' "$LOAD_DOMAIN")" \
        "$(printf '%q' "$REMOTE_APP_DIR/load-testing/scripts/lib/switch.sh")" \
        "$(printf '%q' "$command")" "$quoted"
}

# Invoke one switch helper on the PBX and fail the cell when it reports a mismatch.
switch_call() {
    run_on_pbx "$(switch_command "$@")"
}

# Apply every switch a variant requires, asserting each one as it lands.
apply_variant() {
    local tier="$1" variant="$2" requirement mode

    while IFS= read -r requirement; do
        [ -n "$requirement" ] || continue

        case "$requirement" in
            fpm:*)
                mode="${requirement#fpm:}"
                switch_call apply_fpm_profile "$mode" "$(tier_fpm_children "$tier" "$mode")" || return 1
                ;;
            xml-path:*)
                switch_call apply_xml_path "${requirement#xml-path:}" || return 1
                ;;
            lua:*)
                switch_call apply_lua_bindings "${requirement#lua:}" || return 1
                ;;
            octane:*)
                switch_call apply_octane_binding "${requirement#octane:}" || return 1
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

# Return the XML handler URL one variant must be measured against.
#
# Five variants are served by nginx, so the shared URL is correct for them.
# The Octane variant switches FreeSWITCH to the persistent worker, which is a
# different HTTP server on port 8090; posting to nginx would measure the pool
# instead and mislabel the result.
xml_url_for_variant() {
    local variant="$1" requirement

    while IFS= read -r requirement; do
        if [ "$requirement" = "octane:on" ]; then
            printf '%s\n' "$PBX_OCTANE_URL"
            return 0
        fi
    done < <(matrix_list variants "$variant" requires)

    printf '%s\n' "$PBX_URL"
}

# Build the exact dialplan load command for one XML cell.
xml_command() {
    local tier="$1" variant="$2" profile_id="$3" ttl_id="$4" section="$5" repeat="$6"
    local requests concurrency scenario dialplan contributor directory acl report label url

    requests="$(matrix_value xml_profiles "$profile_id" requests)"
    concurrency="$(matrix_value xml_profiles "$profile_id" concurrency)"
    scenario="$(matrix_value cache_ttl_profiles "$ttl_id" scenario)"
    dialplan="$(matrix_value cache_ttl_profiles "$ttl_id" dialplan)"
    contributor="$(matrix_value cache_ttl_profiles "$ttl_id" contributor)"
    directory="$(matrix_value cache_ttl_profiles "$ttl_id" directory)"
    acl="$(matrix_value cache_ttl_profiles "$ttl_id" acl)"

    label="${tier}/${variant}/${profile_id}-${ttl_id}-${section}"
    report="${REMOTE_ARTIFACT_ROOT}/${tier}/${variant}/${profile_id}-${ttl_id}-${section}-r${repeat}.json"
    url="$(xml_url_for_variant "$variant")"

    printf 'cd %q && php artisan pbx:load-test:dialplan --tenant=%q --url=%q --requests=%q --concurrency=%q --scenario=%q --section=%q --label=%q --report=%q' \
        "$REMOTE_APP_DIR" "$LOAD_TENANT" "$url" "$requests" "$concurrency" "$scenario" "$section" "$label" "$report"

    if [ -n "$PBX_XML_TOKEN" ]; then
        printf ' --token=%q' "$PBX_XML_TOKEN"
    fi
}

# Build the command that checks one cell report against what the matrix asked
# for: the counters must add up, every successful response must have a
# latency sample, and the run must have been measured against the server that
# variant uses.
verify_command() {
    local tier="$1" variant="$2" profile_id="$3" ttl_id="$4" section="$5" repeat="$6"
    local report label requests url

    report="${REMOTE_ARTIFACT_ROOT}/${tier}/${variant}/${profile_id}-${ttl_id}-${section}-r${repeat}.json"
    label="${tier}/${variant}/${profile_id}-${ttl_id}-${section}"
    requests="$(matrix_value xml_profiles "$profile_id" requests)"
    url="$(xml_url_for_variant "$variant")"

    printf 'cd %q && php artisan pbx:load-test:verify %q --expect-endpoint=%q --expect-label=%q --expect-requests=%q' \
        "$REMOTE_APP_DIR" "$report" "$url" "$label" "$requests"
}

# Build the FreeSWITCH lookup probe for one XML cell.
#
# The HTTP client asks PHP directly, so it can never see a Lua cache hit: a hit
# is answered inside FreeSWITCH. Timing find_user_xml (minus the fixed CLI
# overhead) is the only measurement that covers the path a real call takes.
probe_command() {
    switch_command probe_lookup "$LOAD_START" "$LOAD_DOMAIN" "${PROBE_COUNT:-30}"
}

# Build the per-service CPU attribution command that wraps an ablation cell.
#
# The output lands on the PBX inside the tier's artifact tree, so the teardown
# pulls it home with everything else; each row is one class and one second, with
# the share of one CPU core that class used.
cpuwatch_command() {
    local label="$1" destination="$2" seconds="${3:-360}" directory

    directory="$(dirname "$destination")"

    printf 'mkdir -p %q && cd %q && nohup bash %q --sample 1 %q %q > %q 2>&1 &' \
        "$directory" "$REMOTE_APP_DIR" "$REMOTE_APP_DIR/load-testing/scripts/tools/cpuwatch.sh" \
        "$seconds" "$label" "$destination"
}

# Where one ablation cell writes its SIPp artifacts on the generator.
#
# Same layout as a ladder cell, with the knob's label in the path so two values
# of one ablation can never overwrite each other.
ablation_run_dir() {
    local variant="$1" label="$2"

    if [ "$SIPP_HOST" = "local" ] || [ -z "$SIPP_HOST" ]; then
        printf '%s\n' "${ARTIFACT_ROOT}/${TIER}/${variant}/${label}"
        return
    fi

    printf '%s\n' "${SIPP_APP_DIR}/storage/app/load-tests/${SERIES}/${TIER}/${variant}/${label}"
}

# Build the exact SIPp validation command for one SIP cell.
#
# A capacity rung must offer its rate for a whole window, so the call count is
# derived from the rate; the functional script's fixed ten calls would finish
# in seconds. The rung uses the pause-free capacity scenario, and keeps one
# outbound call as a gateway canary.
sip_command() {
    local run_dir="$1"
    local calls=$((CPS * LADDER_SECONDS))

    printf 'cd %q && PBX_HOST=%q TENANT=%q DOMAIN=%q EXTENSIONS=%q START=%q PASSWORD=%q SKIP_SEED=1 CALL_RATE=%q CALLS=%q MAX_SIMULTANEOUS=%q OUTBOUND_CALLS=1 UAC_SCENARIO=%q RUN_DIR=%q bash %q' \
        "$SIPP_APP_DIR" "$SIPP_PBX_HOST" "$LOAD_TENANT" "$LOAD_DOMAIN" "$LOAD_EXTENSIONS" "$LOAD_START" \
        "$LOAD_PASSWORD" "$CPS" "$calls" "$calls" \
        "${SIPP_APP_DIR}/load-testing/tools/sipp/uac-extension-capacity.xml" \
        "$run_dir" "${SIPP_APP_DIR}/load-testing/scripts/pbx-sipp-validate.sh"
}

# Where the smoke preflight writes its artifacts.
#
# The preflight is a setup check, not a measurement, so it gets its own
# directory: a later cell must never read its summary as one of the series.
smoke_run_dir() {
    if [ "$SIPP_HOST" = "local" ] || [ -z "$SIPP_HOST" ]; then
        printf '%s\n' "${ARTIFACT_ROOT}/${TIER}/sip-smoke"
        return
    fi

    printf '%s\n' "${SIPP_APP_DIR}/storage/app/load-tests/${SERIES}/${TIER}/sip-smoke"
}

# Build the SIP smoke preflight: two registrations (the caller and the callee
# it calls), one extension call and one outbound call — the whole chain
# (register, bridge, gateway, CDR) in about two minutes, before any series
# cell is spent on it. The callee registration is explicit: the smoke must
# never depend on a registration left behind by an earlier run.
smoke_command() {
    local run_dir="$1"

    printf 'cd %q && PBX_HOST=%q TENANT=%q DOMAIN=%q EXTENSIONS=%q START=%q PASSWORD=%q SKIP_SEED=1 REGISTER_COUNT=2 CALLS=1 OUTBOUND_CALLS=1 MAX_SIMULTANEOUS=1 OUTBOUND_MAX_SIMULTANEOUS=1 RUN_DIR=%q bash %q' \
        "$SIPP_APP_DIR" "$SIPP_PBX_HOST" "$LOAD_TENANT" "$LOAD_DOMAIN" "$LOAD_EXTENSIONS" "$LOAD_START" \
        "$LOAD_PASSWORD" "$run_dir" "${SIPP_APP_DIR}/load-testing/scripts/pbx-sipp-validate.sh"
}

# Map one ablation id to the switch helper that drives it.
#
# Knobs without a helper are skipped by the ablation phase instead of being
# applied with a guessed command.
ablation_switch_command() {
    case "$1" in
        fs-loglevel) printf 'apply_fs_loglevel' ;;
        fs-db) printf 'apply_fs_db_mode' ;;
        esl-subscriptions) printf 'apply_esl_profile' ;;
        broadcast-throttle) printf 'apply_broadcast_throttle' ;;
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

    # The SIP smoke preflight is printed first because it runs first: a
    # broken chain is found in two minutes rather than at minute 30 of a
    # six-variant series.
    if printf '%s\n' "$(selected_phases)" | grep -qx 'sip'; then
        printf '=== %s / smoke / preflight\n' "${TIER:-<tier>}"
        printf '  %s\n' "$(smoke_command "$(smoke_run_dir)")"
    fi

    while IFS= read -r tier; do
        while IFS= read -r variant; do
            while IFS= read -r phase; do
                printf '=== %s / %s / %s\n' "$tier" "$variant" "$phase"

                case "$phase" in
                    xml)
                        # The warm-up cell is printed first because it runs
                        # first: the first requests after a switch are always
                        # slower, and measuring them would understate the
                        # variant.
                        printf '  %s\n' "$(xml_command "$tier" "$variant" "$WARMUP_PROFILE" "$(warmup_ttl)" "$(selected_sections | head -n1)" 0)"

                        for section in $(selected_sections); do
                            while IFS= read -r profile_id; do
                                [ -z "$PROFILE" ] || [ "$PROFILE" = "$profile_id" ] || continue

                                while IFS= read -r ttl_id; do
                                    [ -z "$TTL" ] || [ "$TTL" = "$ttl_id" ] || continue

                                    for repeat in $(seq 1 "$REPEATS"); do
                                        printf '  %s\n' "$(xml_command "$tier" "$variant" "$profile_id" "$ttl_id" "$section" "$repeat")"
                                    done

                                    # Printed next to the cells it describes: the
                                    # lookup probe is what a real call waits for.
                                    printf '  %s\n' "$(probe_command)"
                                done < <(matrix_keys cache_ttl_profiles)
                            done < <(matrix_keys xml_profiles)
                        done
                        ;;
                    sip)
                        printf '  %s\n' "$(sip_command "$(sip_run_dir "$tier" "$variant")")"
                        ;;
                    ablation)
                        while IFS= read -r ablation; do
                            # Honour --ablation=<id> in the dry run too, so the
                            # printed plan matches what a real run would do.
                            if [ -n "$ABLATION_FILTER" ] && [ "$ablation" != "$ABLATION_FILTER" ]; then
                                continue
                            fi

                            while IFS= read -r value; do
                                if ablation_command "$ablation" "$value" >/dev/null 2>&1; then
                                    printf '  [%s] %s\n' "$ablation" "$(ablation_command "$ablation" "$value")"
                                    # Ablation cells place calls and are wrapped in a
                                    # per-service CPU sample, exactly as they run.
                                    printf '  [%s] %s\n' "$ablation" "$(cpuwatch_command "ablation-${ablation}-${value}" "${REMOTE_ARTIFACT_ROOT}/${TIER}/${variant}/ablation-${ablation}-${value}-cps${CPS}-r1-cpu.csv")"
                                    printf '  [%s] %s\n' "$ablation" "$(sip_command "$(ablation_run_dir "$variant" "ablation-${ablation}-${value}-cps${CPS}-r1")")"
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

# The cache policy a warm-up cell runs under.
#
# It uses the policy the run is measuring, so the warm-up exercises the same
# cache configuration the measured cells will.
warmup_ttl() {
    if [ -n "$TTL" ]; then
        printf '%s\n' "$TTL"
        return
    fi

    matrix_keys cache_ttl_profiles | head -n1
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
        [ -f load-testing/scripts/lib/switch.sh ] || echo 'MISSING load-testing/scripts/lib/switch.sh';
        [ -f load-testing/matrix.php ] || echo 'MISSING load-testing/matrix.php';
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

    # The tenant is created once and then reused. Recreating it on every run
    # changes its id, and the dialplan context carries that id: a registration
    # made against the previous context routes into a context that no longer
    # exists, so every outbound call answers 503.
    run_on_pbx "cd $(printf '%q' "$REMOTE_APP_DIR") && php artisan pbx:load-test:seed --tenant=$(printf '%q' "$LOAD_TENANT") --domain=$(printf '%q' "$LOAD_DOMAIN") --extensions=$(printf '%q' "$LOAD_EXTENSIONS") --start=$(printf '%q' "$LOAD_START") --password=$(printf '%q' "$LOAD_PASSWORD") --sipp-host=$(printf '%q' "$SIPP_UAS_HOST") --sipp-port=$(printf '%q' "$SIPP_UAS_PORT") --output=$(printf '%q' "$REMOTE_ARTIFACT_ROOT/sipp-users.csv")"
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

    # The measurement hosts are disposable and their addresses are not public
    # knowledge, so the committed copy shows them as x.x.x.NNN. The raw
    # artifacts stay on the host.
    if [ -d "${ARTIFACT_ROOT}/${TIER}" ]; then
        bash "${TOOLS_DIR}/scrub-artifacts.sh" "${ARTIFACT_ROOT}/${TIER}" "$PBX_HOST" "$SIPP_HOST" >/dev/null 2>&1 \
            || printf 'WARNING: could not obfuscate the host addresses in the artifacts\n' >&2
    fi

    # The measurement hosts get deleted at the end of the series, so the only
    # copy that survives is the one outside the application tree.
    if [ -n "$ARCHIVE_ROOT" ] && [ -d "${ARTIFACT_ROOT}/${TIER}" ]; then
        mkdir -p "${ARCHIVE_ROOT}/${TIER}"

        if cp -a "${ARTIFACT_ROOT}/${TIER}/." "${ARCHIVE_ROOT}/${TIER}/" 2>/dev/null; then
            printf 'Archived raw artifacts to %s/%s\n' "$ARCHIVE_ROOT" "$TIER" >&2
        else
            printf 'WARNING: could not archive artifacts to %s\n' "$ARCHIVE_ROOT" >&2
        fi
    fi

    return "$exit_code"
}

# Build a short readiness probe for the XML handler.
readiness_command() {
    printf 'cd %q && php artisan pbx:load-test:dialplan --tenant=%q --url=%q --requests=%q --concurrency=%q --scenario=%q --section=%q --report=%q' \
        "$REMOTE_APP_DIR" "$LOAD_TENANT" "$PBX_URL" 5 1 internal dialplan "/tmp/tallpbx-readiness.json"
}

# Wait until the XML handler answers after a switch change.
#
# Reloading PHP-FPM or nginx leaves a brief window where the next request is
# answered with 502. That failure belongs to the switch, not to the
# measurement, so the runner waits it out instead of recording a fake result.
wait_for_xml_ready() {
    local attempt

    for attempt in $(seq 1 15); do
        if run_on_pbx "$(readiness_command)" >/dev/null 2>&1; then
            return 0
        fi

        sleep 2
    done

    return 1
}

# Run the XML battery for one variant: every profile x TTL x section x repeat.
run_xml_phase() {
    local variant="$1" profile_id ttl_id section repeat label cell_log

    # A cell measured behind a half-applied switch is a plausible but
    # meaningless number, so a failed switch fails the cell instead.
    if ! apply_variant "$TIER" "$variant"; then
        cell_failed "${TIER}/${variant}/xml (variant switches)"
        return 1
    fi

    if ! wait_for_xml_ready; then
        printf 'XML-HANDLER-NOT-READY after applying %s\n' "$variant" >&2
    fi

    # Warm-up cells: the first requests after a switch are always slower,
    # because the pool has just restarted and the worker has just booted. One
    # small pass is not enough to absorb a fresh worker, so two run — still
    # cheap, and they remove the transient from the measurement instead of
    # leaving it in the spread between repeats.
    warmup_ttl_id="$(warmup_ttl)"
    warmup_section="$(selected_sections | head -n1)"

    switch_call apply_ttl \
        "$(matrix_value cache_ttl_profiles "$warmup_ttl_id" dialplan)" \
        "$(matrix_value cache_ttl_profiles "$warmup_ttl_id" contributor)" \
        "$(matrix_value cache_ttl_profiles "$warmup_ttl_id" directory)" \
        "$(matrix_value cache_ttl_profiles "$warmup_ttl_id" acl)" >/dev/null 2>&1 || true

    for warmup_pass in 1 2; do
        warmup_log="${ARTIFACT_ROOT}/${TIER}/logs/${TIER}_${variant}_warmup-${warmup_pass}.log"
        mkdir -p "$(dirname "$warmup_log")"

        if ! run_on_pbx "$(xml_command "$TIER" "$variant" "$WARMUP_PROFILE" "$warmup_ttl_id" "$warmup_section" "w${warmup_pass}")" > "$warmup_log" 2>&1; then
            printf 'WARMUP-FAILED %s/%s pass %s\n' "$TIER" "$variant" "$warmup_pass" >&2
        fi
    done

    while IFS= read -r section; do
        while IFS= read -r profile_id; do
            if [ -n "$PROFILE" ] && [ "$PROFILE" != "$profile_id" ]; then
                continue
            fi

            while IFS= read -r ttl_id; do
                [ -z "$TTL" ] || [ "$TTL" = "$ttl_id" ] || continue

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
                    cell_log="${ARTIFACT_ROOT}/${TIER}/logs/${label//\//_}.log"
                    mkdir -p "$(dirname "$cell_log")"

                    # Keep the command output: a silent cell is a failed cell
                    # nobody can diagnose.
                    if ! run_on_pbx "$(xml_command "$TIER" "$variant" "$profile_id" "$ttl_id" "$section" "$repeat")" > "$cell_log" 2>&1; then
                        cell_failed "$label"
                        continue
                    fi

                    # A cell that ran is not automatically a cell that measured
                    # what it claimed. The report is checked against the
                    # matrix before its numbers may be used.
                    if ! run_on_pbx "$(verify_command "$TIER" "$variant" "$profile_id" "$ttl_id" "$section" "$repeat")" >> "$cell_log" 2>&1; then
                        cell_failed "$label (report failed verification)"
                    fi
                done

                # Time what FreeSWITCH itself waits for under this cache
                # policy. The HTTP client cannot cover this path.
                probe_log="${ARTIFACT_ROOT}/${TIER}/${variant}/probe-${profile_id}-${ttl_id}.txt"
                mkdir -p "$(dirname "$probe_log")"

                if ! run_on_pbx "$(probe_command)" > "$probe_log" 2>&1; then
                    cell_failed "${TIER}/${variant}/${profile_id}-${ttl_id}-${section}-probe"
                fi
            done < <(matrix_keys cache_ttl_profiles)
        done < <(matrix_keys xml_profiles)
    done < <(selected_sections)
}

# Prefetch every extension's directory entry before a SIP cell.
#
# Measured on the 1 vCPU tier: the first lookup for an extension costs about
# 1.1 s, while the same lookup warm costs 72 ms. SIPp retransmits a REGISTER
# after 500 ms, so a cold lookup makes it send the request twice; the second
# 401 that comes back cannot be placed in the scenario and the step fails for
# a reason that has nothing to do with what it measures. Warming the entries
# first costs a few seconds and removes the artefact.
warm_directory() {
    local start="$LOAD_START" count="$LOAD_EXTENSIONS" domain="$LOAD_DOMAIN"
    local warm_command

    warm_command="for i in \$(seq 0 $((count - 1))); do fs_cli -x \"find_user_xml id \$((start + i)) ${domain}\" >/dev/null 2>&1; done"

    if ! run_on_pbx "$warm_command" >/dev/null 2>&1; then
        printf 'WARNING: could not warm the directory before the SIP cell\n' >&2
    fi
}

# Run the SIPp feature validation and one capacity step for one variant.
#
# Three things make the result trustworthy: the run happens on the generator,
# whose artifacts are copied back before anything is read, and the CDR gate
# compares the calls SIPp completed with the rows the database gained. Without
# that gate a cell can report every call answered while the pipeline recorded
# none of them.
run_sip_phase() {
    local variant="$1" remote_run_dir local_run_dir label before after counts ladder_file
    local successful failed already_failed=0

    # A cell measured behind a half-applied switch is a plausible but
    # meaningless number, so a failed switch fails the cell instead. This is
    # how the Lua round that could not serve lookups is kept out of the data.
    if ! apply_variant "$TIER" "$variant"; then
        cell_failed "${TIER}/${variant}/sip-cps${CPS} (variant switches)"
        return 1
    fi

    remote_run_dir="$(sip_run_dir "$TIER" "$variant")"
    local_run_dir="${ARTIFACT_ROOT}/${TIER}/${variant}/sip-cps${CPS}"
    label="${TIER}/${variant}/sip-cps${CPS}"

    mkdir -p "$local_run_dir"

    # The directory lookup is the first thing a registering endpoint waits for.
    warm_directory

    # The generator is prepared for every cell: the scenarios, the validation
    # script and a credential file that matches the tenant being measured.
    # Preparation also rewrites the outbound route to point at the generator,
    # and skipping it on a later cell leaves that route pointing at
    # 127.0.0.1 on the PBX, where nothing listens.
    if ! sipp_prepare_generator; then
        cell_failed "${TIER}/generator"
        return 1
    fi

    before="$(cdr_row_count)"
    printf '%s\n' "$before" > "${local_run_dir}/cdr-before.txt"

    if [ -z "$before" ]; then
        printf 'CDR-UNAVAILABLE cannot read the CDR row count on %s\n' "$PBX_HOST" >&2
        cell_failed "${label}-cdr"
        return 1
    fi

    run_status=0

    run_on_sipp "$(sip_command "$remote_run_dir")" > "${local_run_dir}/sipp.log" 2>&1 || run_status=$?

    # The artifacts are pulled before the cell is judged, because the summary
    # arrives with them and a stale summary would decide the wrong thing.
    pull_sipp_artifacts "$remote_run_dir" "$local_run_dir" \
        || printf 'WARNING: could not pull SIPp artifacts from %s\n' "$SIPP_HOST" >&2

    # The summary is the measurement's own account of itself, so it decides the
    # cell. The session that carried the run can drop while a long step is in
    # progress, and a transport failure is not a measurement failure.
    if ! sipp_summary_passed "${local_run_dir}/summary.md"; then
        cell_failed "$label"
        already_failed=1
    elif [ "$run_status" != "0" ]; then
        printf 'WARNING: the generator session dropped during %s, but every step passed\n' "$label" >&2
    fi

    # SIPp's tally says how many rows the database must gain, so it is read
    # before the wait: without it there is nothing to wait for.
    if ! counts="$(bash "${TOOLS_DIR}/cdr-gate.sh" sipp-counts "${local_run_dir}/extension-calls.log" 2>"${local_run_dir}/cdr-gate.err")"; then
        printf 'CDR-GATE FAILED label=%s reason=no-tally\n' "$label" | tee "${local_run_dir}/cdr-gate.txt" >&2
        cell_failed "${label}-cdr"
        return 1
    fi

    successful="${counts%% *}"
    failed="${counts##* }"

    # One row per rung: the stop rule (three consecutive rungs under 95 %
    # completion) is applied from this record, not from memory.
    ladder_file="${ARTIFACT_ROOT}/${TIER}/${variant}/ladder.csv"
    mkdir -p "$(dirname "$ladder_file")"

    if [ ! -f "$ladder_file" ]; then
        printf 'recorded_at,cps,successful,failed,label\n' > "$ladder_file"
    fi

    printf '%s,%s,%s,%s,%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$CPS" "$successful" "$failed" "$label" >> "$ladder_file"

    after="$(cdrs_wait_for "$before" "$successful")"
    printf '%s\n' "$after" > "${local_run_dir}/cdr-after.txt"

    if ! bash "${TOOLS_DIR}/cdr-gate.sh" verdict \
        --before="$before" --after="$after" --successful="$successful" --failed="$failed" \
        --expect-records="$CDR_EXPECT_RECORDS" \
        --label="$label" | tee "${local_run_dir}/cdr-gate.txt"; then
        cell_failed "${label}-cdr"
        return 1
    fi

    return "$already_failed"
}

# Run the single-knob ablations the switch library can drive.
#
# Every ablation cell is a CALL cell with the knob applied: the ESL, Reverb and
# CDR knobs do no work during dialplan requests, so an XML cell would measure
# nothing about the cost they exist to expose. Each cell is wrapped in a
# per-service CPU sample, and the CDR gate judges the calls it placed.
run_ablation_phase() {
    local variant="$1" ablation value repeat label helper cpu_label
    local remote_run_dir local_run_dir before after counts successful failed

    if ! apply_variant "$TIER" "$variant"; then
        cell_failed "${TIER}/${variant}/ablation (variant switches)"
        return 1
    fi

    while IFS= read -r ablation; do
        while IFS= read -r value; do
            # A focused run pays only for the knob it asked about; the whole
            # matrix runs only when no filter was given.
            if [ -n "$ABLATION_FILTER" ] && [ "$ablation" != "$ABLATION_FILTER" ]; then
                continue
            fi

            if ! helper="$(ablation_switch_command "$ablation")"; then
                printf 'ABLATION-SKIPPED %s=%s (no switch helper)\n' "$ablation" "$value" >&2
                continue
            fi

            cpu_label="ablation-${ablation}-${value}"
            label="${TIER}/${variant}/${cpu_label}"

            if ! switch_call "$helper" "$value"; then
                cell_failed "$label"
                continue
            fi

            for repeat in $(seq 1 "$REPEATS"); do
                remote_run_dir="$(ablation_run_dir "$variant" "${cpu_label}-cps${CPS}-r${repeat}")"
                local_run_dir="${ARTIFACT_ROOT}/${TIER}/${variant}/${cpu_label}-cps${CPS}-r${repeat}"
                mkdir -p "$local_run_dir"

                warm_directory

                if ! sipp_prepare_generator; then
                    cell_failed "${label}-r${repeat} (generator)"
                    continue
                fi

                before="$(cdr_row_count)"
                if [ -z "$before" ]; then
                    cell_failed "${label}-r${repeat}-cdr"
                    continue
                fi

                # Per-service CPU across the whole cell window, on the PBX.
                # The sample is named by rate and repeat so a ladder over
                # several rungs never overwrites the earlier rungs' samples.
                run_on_pbx "$(cpuwatch_command "$cpu_label" "${REMOTE_ARTIFACT_ROOT}/${TIER}/${variant}/${cpu_label}-cps${CPS}-r${repeat}-cpu.csv" 360)" >/dev/null 2>&1 \
                    || printf 'WARNING: could not start the CPU sample for %s\n' "$label" >&2

                run_on_sipp "$(sip_command "$remote_run_dir")" > "${local_run_dir}/sipp.log" 2>&1 || true

                pull_sipp_artifacts "$remote_run_dir" "$local_run_dir" || true

                if ! sipp_summary_passed "${local_run_dir}/summary.md"; then
                    cell_failed "${label}-r${repeat}"
                fi

                if counts="$(bash "${TOOLS_DIR}/cdr-gate.sh" sipp-counts "${local_run_dir}/extension-calls.log" 2>"${local_run_dir}/cdr-gate.err")"; then
                    successful="${counts%% *}"
                    failed="${counts##* }"
                    after="$(cdrs_wait_for "$before" "$successful")"
                    bash "${TOOLS_DIR}/cdr-gate.sh" verdict \
                        --before="$before" --after="$after" --successful="$successful" --failed="$failed" \
                        --expect-records="$CDR_EXPECT_RECORDS" \
                        --label="${label}-r${repeat}" | tee "${local_run_dir}/cdr-gate.txt" \
                        || cell_failed "${label}-r${repeat}-cdr"
                fi
            done
        done < <(matrix_list ablations "$ablation" values)
    done < <(matrix_keys ablations)
}

# Run the SIP smoke preflight before any series cell.
#
# One registration, one extension call, one outbound call, then the CDR gate
# on the rows those calls must have produced. A chain that cannot pass this
# in two minutes will fail every cell of a 30-minute series for the same
# reason, so the preflight fails the whole run instead of one cell.
run_sip_smoke() {
    local remote_run_dir local_run_dir label before after counts
    local successful failed

    remote_run_dir="$(smoke_run_dir)"
    local_run_dir="${ARTIFACT_ROOT}/${TIER}/sip-smoke"
    label="${TIER}/smoke"

    mkdir -p "$local_run_dir"

    # The generator is prepared exactly as for a measured cell: scenarios,
    # validation script, credential file, outbound route pointing at the
    # generator's UAS.
    if ! sipp_prepare_generator; then
        printf 'SMOKE-FAILED %s (generator preparation)\n' "$label" >&2
        return 1
    fi

    before="$(cdr_row_count)"

    if [ -z "$before" ]; then
        printf 'SMOKE-FAILED %s (cannot read the CDR row count on %s)\n' "$label" "$PBX_HOST" >&2
        return 1
    fi

    printf '%s\n' "$before" > "${local_run_dir}/cdr-before.txt"

    run_on_sipp "$(smoke_command "$remote_run_dir")" > "${local_run_dir}/sipp.log" 2>&1 \
        || printf 'WARNING: the generator session dropped during %s; judged by the summary\n' "$label" >&2

    pull_sipp_artifacts "$remote_run_dir" "$local_run_dir" \
        || printf 'WARNING: could not pull SIPp artifacts from %s\n' "$SIPP_HOST" >&2

    # The summary is the run's own account of itself and decides the result.
    if ! sipp_summary_passed "${local_run_dir}/summary.md"; then
        printf 'SMOKE-FAILED %s (see %s/summary.md)\n' "$label" "$local_run_dir" >&2
        return 1
    fi

    # The CDR gate counts the calls SIPp completed and waits for the rows
    # those calls must have produced. A cell that completes calls but leaves
    # no records is a broken pipeline, not a passing smoke.
    if ! counts="$(bash "${TOOLS_DIR}/cdr-gate.sh" sipp-counts "${local_run_dir}/extension-calls.log" 2>"${local_run_dir}/cdr-gate.err")"; then
        printf 'SMOKE-FAILED %s (no SIPp tally)\n' "$label" >&2
        return 1
    fi

    successful="${counts%% *}"
    failed="${counts##* }"

    after="$(cdrs_wait_for "$before" "$successful")"
    printf '%s\n' "$after" > "${local_run_dir}/cdr-after.txt"

    if ! bash "${TOOLS_DIR}/cdr-gate.sh" verdict \
        --before="$before" --after="$after" --successful="$successful" --failed="$failed" \
        --expect-records="$CDR_EXPECT_RECORDS" \
        --label="$label" | tee "${local_run_dir}/cdr-gate.txt"; then
        printf 'SMOKE-FAILED %s (CDR gate)\n' "$label" >&2
        return 1
    fi

    return 0
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

    # A failure list from an earlier run would misreport this one.
    : > "${ARTIFACT_ROOT}/${TIER}/failures.log"

    if ! preflight_remote; then
        printf 'Preflight failed; no measurement was taken on %s.\n' "$TIER" >&2
        exit 1
    fi

    prepare_tier
    seed_tenant

    # The SIP smoke preflight runs before any series cell: a broken chain is
    # found in two minutes rather than after the first 30-minute series has
    # failed for a reason that was visible at setup time. It is paid once per
    # tier — the ladder is many short invocations, and its passing record is
    # what tells later invocations the chain was already proven.
    if printf '%s\n' "$(selected_phases)" | grep -qx 'sip'; then
        smoke_gate="${ARTIFACT_ROOT}/${TIER}/sip-smoke/cdr-gate.txt"

        if [ -f "$smoke_gate" ] && grep -q '^CDR-GATE ok' "$smoke_gate"; then
            printf 'SMOKE-ALREADY-PASSED %s (see %s)\n' "$TIER" "$smoke_gate" >&2
        elif ! run_sip_smoke; then
            printf 'SIP smoke preflight failed; no series cell was measured on %s.\n' "$TIER" >&2
            exit 1
        fi
    fi

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

    # Everything below prints or runs a cell, and every cell names the realm.
    resolve_load_domain

    if [ "$DRY_RUN" = "1" ]; then
        dry_run_cells
        return 0
    fi

    execute_cells
}

main "$@"
