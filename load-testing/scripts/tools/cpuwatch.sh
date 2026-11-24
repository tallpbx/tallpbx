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
# Compare process counters over the measured time between snapshots. A
# process that exits between snapshots can leave unobserved CPU time.
set -uo pipefail
export LC_ALL=C

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
    local cmd="$1" detected

    case "$cmd" in
        *"freeswitch:listen"*) detected='esl-listener' ;;
        *"reverb:start"*) detected='reverb' ;;
        *"octane:start"* | *"roadrunner-worker"* | *"/rr "*" serve"* | *"rr serve"*) detected='octane-worker' ;;
        *"cdr:work"*) detected='cdr-worker' ;;
        *"queue:work"*) detected='queue-worker' ;;
        *"schedule:work"*) detected='scheduler' ;;
        *"freeswitch"*) detected='freeswitch' ;;
        *"php-fpm"*) detected='php-fpm' ;;
        # The reverse proxy in front of PHP-FPM. A separate nginx class keeps
        # the proxy's share of the core visible, so PHP is not blamed for work
        # nginx did (or vice versa when comparing XML paths).
        *"nginx"*) detected='nginx' ;;
        *"mariadbd"* | *"mysqld"*) detected='mariadb' ;;
        *"redis-server"*) detected='redis' ;;
        *) detected='' ;;
    esac

    if [ "$#" -gt 1 ]; then
        printf -v "$2" '%s' "$detected"
    else
        printf '%s' "$detected"
    fi
}

# Emit the monotonic snapshot time followed by one line per process.
sample() {
    local uptime dir cls stat rss pss field value
    local -a args fields

    read -r uptime _ < /proc/uptime || return 1
    printf 'uptime %s\n' "$uptime"

    for dir in /proc/[0-9]*; do
        mapfile -d '' -t args 2>/dev/null < "$dir/cmdline" || continue
        classify "${args[*]}" cls
        [ -n "$cls" ] || continue

        IFS= read -r stat 2>/dev/null < "$dir/stat" || continue
        # The comm field can contain spaces or parentheses. Field 3 starts
        # after the last closing parenthesis, so utime/stime/starttime are
        # indices 11/12/19 in the remaining fields.
        read -r -a fields <<< "${stat##*) }"
        [ "${#fields[@]}" -ge 20 ] || continue
        rss=0
        pss=0
        while read -r field value _; do
            if [ "$field" = "VmRSS:" ]; then
                rss="$value"
                break
            fi
        done 2>/dev/null < "$dir/status"
        while read -r field value _; do
            if [ "$field" = "Pss:" ]; then
                pss="$value"
                break
            fi
        done 2>/dev/null < "$dir/smaps_rollup"

        printf '%s:%s %s %d %s %s\n' \
            "${dir##*/}" "${fields[19]}" "$cls" "$((fields[11] + fields[12]))" "$rss" "$pss"
    done
}

# Compute deltas by PID and start time before summing by service. A PID
# appearing for the first time contributes only if it was born in this window.
compare() {
    awk -v clk="$CLK_TCK" -v mode="$MODE" -v label="$LABEL" -v initial="${3:-0}" '
        FILENAME == ARGV[1] {
            if ($1 == "uptime") start = $2
            else {
                before[$1] = $3
                seen[$2] = 1
            }
            next
        }
        $1 == "uptime" {
            elapsed = $2 - start
            epoch = $2 - initial
            next
        }
        {
            cls = $2
            seen[cls] = 1
            rss[cls] += $4
            pss[cls] += $5
            split($1, identity, ":")
            if ($1 in before) {
                if ($3 >= before[$1]) ticks[cls] += $3 - before[$1]
            } else if (identity[2] >= start * clk) {
                ticks[cls] += $3
            }
        }
        END {
            if (elapsed <= 0) exit 1
            if (mode == "window") {
                printf "== CPU and memory during %.2fs window: %s ==\n", elapsed, label
            }
            for (cls in seen) {
                cpu = ticks[cls] * 100.0 / (elapsed * clk)
                if (mode == "sample") {
                    printf "%s,%.2f,%s,%.2f,%.1f,%.1f,%.2f\n",
                        label, epoch, cls, cpu, rss[cls] / 1024, pss[cls] / 1024, elapsed
                } else {
                    printf "  %-14s cpu=%6.2f%%  ram_held=%6.1f MB  ram_attributed=%6.1f MB\n",
                        cls, cpu, rss[cls] / 1024, pss[cls] / 1024
                }
            }
        }
    ' "$1" "$2" | sort -b
}

previous_file="$(mktemp)"
current_file="$(mktemp)"
trap 'rm -f "$previous_file" "$current_file"' EXIT
sample > "$previous_file" || exit 1

if [ "$MODE" = "window" ]; then
    sleep "$DURATION"
    sample > "$current_file" || exit 1
    compare "$previous_file" "$current_file"
    exit $?
fi

# Sampling mode: one CSV row per class per interval.
printf 'label,epoch,class,cpu_pct,ram_held_mb,ram_attributed_mb,elapsed_seconds\n'
read -r _ initial < "$previous_file"
deadline=$((10#${initial/./} + DURATION * 100))
measured="$initial"

while ((10#${measured/./} < deadline)); do
    sleep "$INTERVAL"
    sample > "$current_file" || exit 1
    read -r _ measured < "$current_file"
    compare "$previous_file" "$current_file" "$initial" || exit 1
    swap="$previous_file"
    previous_file="$current_file"
    current_file="$swap"
done
