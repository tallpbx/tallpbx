#!/usr/bin/env bash
# Attribute CPU time and memory to the TallPBX service processes.
#
# Two modes:
#
#   cpuwatch.sh <seconds> <label>
#       One aggregate window. Prints the CPU and memory each class used during
#       it.
#
#   cpuwatch.sh --sample <interval> <duration> <label>
#       Repeating samples, one CSV row per class per interval, on stdout.
#       Use this to watch a test run: it shows whether a service was idle for
#       the whole run or busy during part of it.
#
# CPU is read from /proc/<pid>/stat. Every PHP service runs as the same
# binary, so processes are classified by their command line, not by comm.
# Memory is reported as RAM held (the kernel's RSS) and RAM attributed (its
# share once pages shared with other processes are divided among them).
#
# A class reporting zero CPU is genuinely idle: one clock tick is 10 ms, so a
# 20-second window cannot hide more than 0.05 % of a core.
set -uo pipefail

MODE="window"
INTERVAL=1
DURATION=20
LABEL="window"

if [ "${1:-}" = "--sample" ]; then
    MODE="sample"
    INTERVAL="${2:-1}"
    DURATION="${3:-60}"
    LABEL="${4:-sample}"
else
    DURATION="${1:-20}"
    LABEL="${2:-window}"
fi

CLK_TCK="$(getconf CLK_TCK)"

# Classify one process by its command line.
classify() {
    local cmd="$1"

    case "$cmd" in
        *"freeswitch:listen"*) printf 'esl-listener' ;;
        *"reverb:start"*) printf 'reverb' ;;
        *"octane:start"* | *"rr serve"*) printf 'octane-worker' ;;
        *"cdr:work"*) printf 'cdr-worker' ;;
        *"queue:work"*) printf 'queue-worker' ;;
        *"schedule:work"*) printf 'scheduler' ;;
        *"freeswitch"*) printf 'freeswitch' ;;
        *"php-fpm"*) printf 'php-fpm' ;;
        # The reverse proxy in front of PHP-FPM. A separate nginx class keeps
        # the proxy's share of the core visible, so PHP is not blamed for work
        # nginx did (or vice versa when comparing XML paths).
        *"nginx"*) printf 'nginx' ;;
        *"mariadbd"* | *"mysqld"*) printf 'mariadb' ;;
        *"redis-server"*) printf 'redis' ;;
        *) printf '' ;;
    esac
}

# Emit one line per process: class, cpu ticks, rss kB, pss kB.
sample() {
    local pid cmd cls ticks rss pss

    for pid in /proc/[0-9]*; do
        pid="${pid#/proc/}"
        cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null)" || continue
        [ -n "$cmd" ] || continue
        cls="$(classify "$cmd")"
        [ -n "$cls" ] || continue

        ticks="$(awk '{print $14 + $15}' "/proc/$pid/stat" 2>/dev/null)" || continue
        rss="$(awk '/^VmRSS:/{print $2}' "/proc/$pid/status" 2>/dev/null)"
        pss="$(awk '/^Pss:/{print $2}' "/proc/$pid/smaps_rollup" 2>/dev/null)"

        echo "$cls ${ticks:-0} ${rss:-0} ${pss:-0}"
    done
}

# Aggregate the per-process lines into one line per class:
# <class> <cpu ticks> <ram held kB> <ram attributed kB>
aggregate() {
    awk '{
        ticks[$1] += $2
        rss[$1] += $3
        pss[$1] += $4
        seen[$1] = 1
    }
    END {
        for (cls in seen) {
            printf "%s %d %d %d\n", cls, ticks[cls], rss[cls], pss[cls]
        }
    }'
}

# Compare two aggregate snapshots and report the CPU used between them.
#
#   compare <before file> <after file> <ticks in the window>
compare() {
    awk -v total="$3" '
        FNR == NR {
            bt[$1] = $2; br[$1] = $3; bp[$1] = $4; next
        }
        {
            at[$1] = $2; ar[$1] = $3; ap[$1] = $4
        }
        END {
            for (cls in at) { seen[cls] = 1 }
            for (cls in bt) { seen[cls] = 1 }
            for (cls in seen) {
                delta = at[cls] - bt[cls]
                if (delta < 0) { delta = 0 }
                printf "  %-14s cpu=%6.2f%%  ram_held=%6.1f MB  ram_attributed=%6.1f MB\n",
                    cls,
                    (total > 0 ? delta * 100.0 / total : 0),
                    ar[cls] / 1024,
                    ap[cls] / 1024
            }
        }
    ' "$1" "$2" | sort
}

if [ "$MODE" = "window" ]; then
    before_file="$(mktemp)"
    after_file="$(mktemp)"

    sample | aggregate > "$before_file"
    sleep "$DURATION"
    sample | aggregate > "$after_file"

    printf '== CPU and memory during %ss window: %s ==\n' "$DURATION" "$LABEL"
    compare "$before_file" "$after_file" "$((DURATION * CLK_TCK))"

    rm -f "$before_file" "$after_file"
    exit 0
fi

# Sampling mode: one CSV row per class per interval.
printf 'label,epoch,class,cpu_pct,ram_held_mb,ram_attributed_mb\n'

previous_file="$(mktemp)"
current_file="$(mktemp)"

sample | aggregate > "$previous_file"

epoch=0
interval_ticks=$((INTERVAL * CLK_TCK))

while [ "$epoch" -lt "$DURATION" ]; do
    sleep "$INTERVAL"
    epoch=$((epoch + INTERVAL))
    sample | aggregate > "$current_file"

    awk -v label="$LABEL" -v epoch="$epoch" -v total="$interval_ticks" '
        FNR == NR {
            bt[$1] = $2; br[$1] = $3; bp[$1] = $4; next
        }
        {
            at[$1] = $2; ar[$1] = $3; ap[$1] = $4
        }
        END {
            for (cls in at) { seen[cls] = 1 }
            for (cls in bt) { seen[cls] = 1 }
            for (cls in seen) {
                delta = at[cls] - bt[cls]
                if (delta < 0) { delta = 0 }
                printf "%s,%d,%s,%.2f,%.1f,%.1f\n",
                    label, epoch, cls,
                    (total > 0 ? delta * 100.0 / total : 0),
                    ar[cls] / 1024,
                    ap[cls] / 1024
            }
        }
    ' "$previous_file" "$current_file"

    cp "$current_file" "$previous_file"
done

rm -f "$previous_file" "$current_file"
