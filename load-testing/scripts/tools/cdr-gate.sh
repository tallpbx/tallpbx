#!/usr/bin/env bash
# ==============================================================================
# TallPBX CDR Gate
#
# Decide whether a SIP load cell actually left call records in the database.
#
# An XML cell makes no calls, so the most it can prove is that the CDR
# pipeline was left undisturbed. A SIP cell places real calls, and the
# question that matters is whether every completed call produced a record.
# Without this check a cell can report 100 % call success while the CDR
# worker is dead, and nobody notices until an operator looks for a bill.
#
# Two jobs:
#
#   cdr-gate.sh sipp-counts <step.log>
#       Read SIPp's own end-of-run tally: completed and failed calls.
#
#   cdr-gate.sh verdict --before=<n> --after=<n> \
#                       --successful=<n> --failed=<n> --label=<text>
#       Compare the calls SIPp completed with the rows the database gained.
#
# Exit status is 0 only when the cell's records account for its calls.
#
# Why the tally comes from the log and not the stats CSV: SIPp writes that
# CSV on an interval, so the last few calls of a short cell are missing from
# it. The screen dump at exit is exact.
# ==============================================================================

set -uo pipefail

# Print how to use the tool, then exit with the given status.
usage() {
    cat >&2 <<'USAGE'
Usage:
  cdr-gate.sh sipp-counts <step.log>
  cdr-gate.sh verdict --before=<n> --after=<n> --successful=<n> --failed=<n> --label=<text>

  sipp-counts   Read the completed and failed call tally from a SIPp step log.
                Prints "<successful> <failed>". Fails when the log has no tally,
                because a crashed run must not read as "zero calls, nothing to check".
  verdict       Decide whether the database rows gained account for the calls
                the run completed. Prints a CDR-GATE line and exits non-zero
                when it cannot.
USAGE

    exit "${1:-2}"
}

# Read one cumulative tally out of a SIPp statistics screen.
#
# SIPp prints three columns: the counter name, a periodic value and a
# cumulative value. The cumulative one is what a whole cell is judged on, and
# it is the last column of the line. A missing counter prints nothing, which
# the caller treats as "no tally" rather than as zero.
sipp_tally() {
    local log="$1" counter="$2" value

    value="$(grep -a -- "$counter" "$log" 2>/dev/null | tail -n 1 | awk -F'|' '{print $3}' | tr -cd '0-9')"

    printf '%s' "$value"
}

# Print the completed and failed call counts from one SIPp step log.
command_sipp_counts() {
    local log="${1:-}" successful failed

    if [ -z "$log" ]; then
        printf 'cdr-gate: usage: sipp-counts <step.log>\n' >&2
        return 2
    fi

    if [ ! -f "$log" ]; then
        printf 'cdr-gate: no-tally %s does not exist\n' "$log" >&2
        return 1
    fi

    successful="$(sipp_tally "$log" 'Successful call')"
    failed="$(sipp_tally "$log" 'Failed call')"

    # A run that died before printing its statistics screen leaves neither
    # counter behind. Reporting 0/0 would make the gate pass a cell that
    # never measured anything.
    if [ -z "$successful" ] || [ -z "$failed" ]; then
        printf 'cdr-gate: no-tally %s carries no SIPp statistics screen\n' "$log" >&2
        return 1
    fi

    printf '%s %s\n' "$successful" "$failed"
}

# Reject a value that is not a non-negative integer.
require_count() {
    local name="$1" value="$2"

    if ! [[ "$value" =~ ^[0-9]+$ ]]; then
        printf 'cdr-gate: %s must be a non-negative integer (got "%s")\n' "$name" "$value" >&2
        return 1
    fi
}

# Compare the rows the database gained against the calls the run completed.
#
# Every completed call ends an a-leg, and an a-leg always has a record. So the
# rows gained must be at least the number of completed calls; fewer means the
# pipeline dropped records, which is a data-integrity failure rather than a
# slow worker. More rows than calls is expected and fine: a bridged call
# records both legs, and anything else on the host adds its own.
command_verdict() {
    local before="" after="" successful="" failed="" label="unknown"
    local rows

    for argument in "$@"; do
        case "$argument" in
            --before=*) before="${argument#*=}" ;;
            --after=*) after="${argument#*=}" ;;
            --successful=*) successful="${argument#*=}" ;;
            --failed=*) failed="${argument#*=}" ;;
            --label=*) label="${argument#*=}" ;;
            *) printf 'cdr-gate: unknown argument "%s"\n' "$argument" >&2; return 2 ;;
        esac
    done

    require_count --before "$before" || return 2
    require_count --after "$after" || return 2
    require_count --successful "$successful" || return 2
    require_count --failed "$failed" || return 2

    rows=$((after - before))

    # No calls attempted: the cell is not a measurement, whatever it reported.
    if [ "$successful" -eq 0 ] && [ "$failed" -eq 0 ]; then
        printf 'CDR-GATE FAILED label=%s reason=no-calls rows=%s successful=%s failed=%s\n' \
            "$label" "$rows" "$successful" "$failed"
        return 1
    fi

    # Calls were attempted but none completed. The cell already failed on its
    # own terms; the gate records why rather than inventing a record problem.
    if [ "$successful" -eq 0 ]; then
        printf 'CDR-GATE FAILED label=%s reason=no-success rows=%s successful=%s failed=%s\n' \
            "$label" "$rows" "$successful" "$failed"
        return 1
    fi

    if [ "$rows" -eq 0 ]; then
        printf 'CDR-GATE FAILED label=%s reason=no-records rows=%s successful=%s failed=%s\n' \
            "$label" "$rows" "$successful" "$failed"
        return 1
    fi

    if [ "$rows" -lt "$successful" ]; then
        printf 'CDR-GATE FAILED label=%s reason=partial rows=%s successful=%s failed=%s\n' \
            "$label" "$rows" "$successful" "$failed"
        return 1
    fi

    printf 'CDR-GATE ok label=%s rows=%s successful=%s failed=%s\n' \
        "$label" "$rows" "$successful" "$failed"
}

command="${1:-}"
[ $# -gt 0 ] && shift

case "$command" in
    sipp-counts) command_sipp_counts "$@" ;;
    verdict) command_verdict "$@" ;;
    ''|help|--help|-h) usage 0 ;;
    *) printf 'cdr-gate: unknown command "%s"\n' "$command" >&2; usage 2 ;;
esac
