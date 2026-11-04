#!/usr/bin/env bash
# Attribute CPU time to the TallPBX service processes over a fixed window.
#
# Reads /proc/<pid>/stat before and after the window and reports the delta in
# clock ticks, then converts to "percent of one core". Every PHP service runs
# as the same binary, so processes are classified by their command line, not
# by their comm name.
#
# Usage: cpuwatch.sh <seconds> <label>
set -uo pipefail

DURATION="${1:-20}"
LABEL="${2:-window}"
CLK_TCK="$(getconf CLK_TCK)"
TOTAL_TICKS=$((DURATION * CLK_TCK))

declare -A BEFORE=()

# Classify one process by its command line.
classify() {
    local cmd="$1"

    case "$cmd" in
        *"freeswitch:listen"*) printf 'esl-listener' ;;
        *"reverb:start"*) printf 'reverb' ;;
        *"octane:start"* | *"rr serve"*) printf 'octane-worker' ;;
        *"cdr:work"*) printf 'cdr-worker' ;;
        *"queue:work"*) printf 'queue-worker' ;;
        *"freeswitch"*) printf 'freeswitch' ;;
        *"php-fpm"*) printf 'php-fpm' ;;
        *"mariadbd"* | *"mysqld"*) printf 'mariadb' ;;
        *"redis-server"*) printf 'redis' ;;
        *) printf '' ;;
    esac
}

# Accumulate current CPU ticks per class into the named array.
sample() {
    local pid cmd cls ticks

    for pid in /proc/[0-9]*; do
        pid="${pid#/proc/}"
        cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)" || continue
        [ -n "$cmd" ] || continue
        cls="$(classify "$cmd")"
        [ -n "$cls" ] || continue
        # utime and stime are fields 14 and 15 of stat, after the comm field.
        ticks="$(awk '{print $14 + $15}' "/proc/$pid/stat" 2>/dev/null)" || continue
        echo "$cls $ticks"
    done
}

sample > "/tmp/cpuwatch-before.$$"

sleep "$DURATION"

sample > "/tmp/cpuwatch-after.$$"

printf '== CPU during %ss window: %s ==\n' "$DURATION" "$LABEL"

# Total ticks per class in each snapshot, then the difference.
awk -v total="$TOTAL_TICKS" '
    NR == FNR { before[$1] += $2; next }
    { after[$1] += $2 }
    END {
        for (cls in after) { seen[cls] = 1 }
        for (cls in before) { seen[cls] = 1 }
        for (cls in seen) {
            delta = (after[cls] + 0) - (before[cls] + 0)
            if (delta < 0) { delta = 0 }
            printf "  %-14s ticks=%-7d core-pct=%6.2f%%\n", cls, delta, (total > 0 ? delta * 100.0 / total : 0)
        }
    }
' "/tmp/cpuwatch-before.$$" "/tmp/cpuwatch-after.$$" | sort

rm -f "/tmp/cpuwatch-before.$$" "/tmp/cpuwatch-after.$$"
