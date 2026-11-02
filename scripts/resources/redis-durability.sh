#!/bin/bash
# ==============================================================================
# Redis Durability Helpers
# ==============================================================================
# FreeSWITCH posts one JSON call record per call leg. Each accepted record waits
# in the Redis stream on database 3 until the CDR worker commits it to MariaDB,
# so for a short window Redis holds the only copy.
#
# Two host settings protect that window:
#
#   1. Append-only persistence rewrites accepted records to disk every second,
#      so a restart does not discard undigested stream entries.
#   2. vm.overcommit_memory = 1 lets Redis fork for a snapshot even when the
#      kernel's heuristic policy would refuse it. With write refusal enabled,
#      a failed snapshot otherwise makes Redis reject every write.
#
# This file only defines functions. Sourcing it never changes the host, which
# keeps it safe to load from tests.
# ==============================================================================

# Put one "keyword value" setting into a Redis configuration file.
#
# The first matching active or commented line is replaced in place so the file
# keeps its original ordering, and any duplicate lines for the same keyword are
# removed. When the file has no line for the keyword, the setting is appended.
# Running this function repeatedly leaves exactly one line per keyword.
ensure_redis_setting() {
    local conf="$1"
    local key="$2"
    local value="$3"
    local tmp

    if [ ! -f "$conf" ]; then
        printf 'Redis configuration not found: %s\n' "$conf" >&2
        return 1
    fi

    tmp="$(mktemp)" || return 1

    # awk is used instead of sed because stock redis.conf lists several
    # commented alternatives for the same keyword (appendfsync has three);
    # a sed substitution would turn every one of them into a duplicate.
    awk -v key="$key" -v value="$value" '
        BEGIN { matched = 0 }
        $0 ~ "^[#[:space:]]*" key "([[:space:]]|$)" {
            if (matched == 0) {
                print key " " value
                matched = 1
            }
            next
        }
        { print }
        END { if (matched == 0) print key " " value }
    ' "$conf" > "$tmp" || {
        rm -f "$tmp"
        return 1
    }

    # Keep the original ownership and mode; mktemp creates a root-owned 0600
    # file, and the Redis service must still be able to read its configuration.
    chown --reference="$conf" "$tmp" 2>/dev/null || true
    chmod --reference="$conf" "$tmp" 2>/dev/null || true
    mv "$tmp" "$conf"
}

# Enable append-only persistence for the Redis instance holding the CDR stream.
configure_redis_durability() {
    local conf="${1:-/etc/redis/redis.conf}"

    # A missing file means Redis is not installed on this host yet; the PHP
    # installer step already reports that condition, so stay quiet here.
    if [ ! -f "$conf" ]; then
        return 0
    fi

    # Every-second fsync bounds the loss window to about one second while
    # keeping the disk sync off the request path.
    ensure_redis_setting "$conf" "appendonly" "yes" || return 1
    ensure_redis_setting "$conf" "appendfsync" "everysec" || return 1

    # Write refusal is deliberate: the ingestion endpoint answers 503 and
    # FreeSWITCH spools the record to disk. A silently accepted, unpersisted
    # write would be lost instead.
    ensure_redis_setting "$conf" "stop-writes-on-bgsave-error" "yes" || return 1
}

# Persist the kernel setting Redis needs before it forks for a snapshot.
configure_redis_overcommit() {
    local drop_in="${1:-/etc/sysctl.d/99-tallpbx-redis.conf}"

    mkdir -p "$(dirname "$drop_in")" || return 1

    cat > "$drop_in" <<'SYSCTL'
# Redis forks a child process to write its snapshot. Under the kernel's
# heuristic overcommit policy that fork can fail even when memory is free.
# Because Redis refuses writes after a failed snapshot, always-overcommit is
# required to keep the CDR stream writable.
vm.overcommit_memory = 1
SYSCTL
}

# Apply the drop-in now so the setting also holds for the running kernel.
apply_redis_overcommit() {
    local drop_in="${1:-/etc/sysctl.d/99-tallpbx-redis.conf}"

    if command -v sysctl >/dev/null 2>&1; then
        # Containers may not allow this; the next boot still reads the drop-in.
        sysctl -p "$drop_in" >/dev/null 2>&1 || true
    fi
}

# Report whether the running kernel uses always-overcommit.
#
# The optional argument overrides the kernel view path so this check can run
# against a fixture in tests.
verify_redis_overcommit() {
    local proc_path="${1:-/proc/sys/vm/overcommit_memory}"
    local value=""

    if [ -r "$proc_path" ]; then
        value="$(tr -d '[:space:]' < "$proc_path")"
    fi

    if [ "$value" != "1" ]; then
        printf 'vm.overcommit_memory is %s, expected 1\n' "${value:-unknown}" >&2
        return 1
    fi

    return 0
}

# Report whether the running Redis server has append-only persistence enabled.
#
# The optional argument overrides the redis-cli executable, which lets tests
# substitute a fixture instead of requiring a live server.
verify_redis_appendonly() {
    local cli="${1:-redis-cli}"
    local value=""

    if ! command -v "$cli" >/dev/null 2>&1; then
        # Without the client there is nothing to interrogate; the installer
        # treats a missing client as "not verifiable" rather than a failure.
        return 0
    fi

    value="$("$cli" --raw CONFIG GET appendonly 2>/dev/null | tail -n 1)"

    if [ "$value" != "yes" ]; then
        printf 'Redis appendonly is %s, expected yes\n' "${value:-unknown}" >&2
        return 1
    fi

    return 0
}
