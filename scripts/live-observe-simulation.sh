#!/usr/bin/env bash
#
# scripts/live-observe-simulation.sh
# Generates periodic observe-mode test traffic from the simulated network namespace
# to verify live WebSocket/Echo updates in the UI and streaming in the CLI.
#

set -euo pipefail

DURATION_SECONDS=${1:-600} # Default: 10 minutes
INTERVAL_SECONDS=${2:-4}   # Default: every 4 seconds

echo "Starting Observe Mode live traffic simulation..."
echo "  Namespace: tallpbx-test-ns (10.254.254.2)"
echo "  Target:    10.254.254.1"
echo "  Duration:  ${DURATION_SECONDS}s (every ${INTERVAL_SECONDS}s)"

END_TIME=$((SECONDS + DURATION_SECONDS))
COUNT=0

while [ $SECONDS -lt $END_TIME ]; do
    COUNT=$((COUNT + 1))
    
    # Alternate between banned IP probe and TFTP defense probe
    if [ $((COUNT % 2)) -eq 0 ]; then
        # SIP probe from banned IP (10.254.254.2)
        ip netns exec tallpbx-test-ns bash -c 'echo "OPTIONS sip:10.254.254.1 SIP/2.0" | nc -u -w 1 10.254.254.1 5060' 2>/dev/null || true
    else
        # TFTP exploit traversal probe
        ip netns exec tallpbx-test-ns bash -c 'printf "\x00\x01../../etc/passwd\x00octet\x00" | nc -u -w 1 10.254.254.1 69' 2>/dev/null || true
    fi

    sleep "$INTERVAL_SECONDS"
done

echo "Simulation completed after ${COUNT} packets."
