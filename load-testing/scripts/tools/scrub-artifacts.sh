#!/usr/bin/env bash
# ==============================================================================
# TallPBX Artifact Address Scrubber
#
# Replace the public addresses of the measurement hosts in a set of artifacts
# with their last octet only.
#
# The measurement hosts are disposable and their addresses are not public
# knowledge. Every document, table and committed artifact shows them as
# x.x.x.NNN so a reader can tell two hosts apart without being able to reach
# either of them.
#
# The raw artifacts stay on the measurement host; this is applied to the copy
# that is committed.
#
# Usage:
#   scrub-artifacts.sh <directory> [address ...]
#
# With no addresses given, the directory's own IPv4 addresses are discovered
# from the files themselves, so a caller that does not know the hosts still
# gets a scrubbed copy.
# ==============================================================================

set -uo pipefail

# Print the last octet of an address in the documented form.
obfuscate() {
    local address="$1"

    if [[ "$address" =~ ^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\.([0-9]{1,3})$ ]]; then
        printf '%s.%s' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}"
        return 0
    fi

    # Anything that is not a plain IPv4 address is left alone.
    printf '%s' "$address"
}

# Replace every occurrence of one address in every file under a directory.
scrub_one() {
    local directory="$1" address="$2" replacement

    [ -n "$address" ] || return 0
    [ -d "$directory" ] || return 0

    replacement="x.x.x.$(obfuscate "$address" | awk -F. '{print $4}')"

    if [ "$replacement" = "x.x.x." ] || [ "$replacement" = "$address" ]; then
        return 0
    fi

    # Binary files are skipped: a rewritten byte sequence would corrupt them,
    # and a capture file holds no address worth publishing.
    find "$directory" -type f -not -name '*.pcap' -not -name '*.gz' -print0 2>/dev/null \
        | xargs -0 -r grep -Il -- "$address" 2>/dev/null \
        | while IFS= read -r file; do
            sed -i "s/${address//./\\.}/${replacement}/g" "$file" 2>/dev/null || true
        done
}

# Print the distinct IPv4 addresses found in the directory's text files.
discover_addresses() {
    local directory="$1"

    find "$directory" -type f -not -name '*.pcap' -not -name '*.gz' -print0 2>/dev/null \
        | xargs -0 -r grep -IhoE '\b([0-9]{1,3}\.){3}[0-9]{1,3}\b' 2>/dev/null \
        | sort -u
}

directory="${1:-}"
shift || true

if [ -z "$directory" ] || [ ! -d "$directory" ]; then
    printf 'scrub-artifacts: %s is not a directory\n' "$directory" >&2
    exit 2
fi

addresses=("$@")

if [ ${#addresses[@]} -eq 0 ]; then
    mapfile -t discovered < <(discover_addresses "$directory")
    addresses=("${discovered[@]}")
fi

for address in "${addresses[@]}"; do
    scrub_one "$directory" "$address"
done

printf 'scrub-artifacts: scrubbed %d address(es) under %s\n' "${#addresses[@]}" "$directory"
