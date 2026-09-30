#!/usr/bin/env bash
#
# TallPBX browser test runner (Pest 4 + Playwright).
#
# Runs every test under tests/Browser with Pest. The browser tests are served
# by an in-process HTTP server backed by the same in-memory SQLite database as
# the rest of the test suite, so this runner needs no dedicated database, no
# `.env` swapping, and no ChromeDriver daemon.
#
# Usage:
#   bash scripts/test-browser.sh                       # run every browser test
#   bash scripts/test-browser.sh --filter="dashboard"  # run a subset
#
# Extra arguments are passed straight through to Pest.

set -euo pipefail

# Always run from the application root so Pest finds phpunit.xml and the
# tests/Browser directory, regardless of where the script was invoked from.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

ARGS=("$@")
HAS_PARALLEL=0
HAS_PROCESSES=0

for arg in "$@"; do
    if [[ "$arg" == "--parallel" ]]; then
        HAS_PARALLEL=1
    elif [[ "$arg" =~ ^(-p|--processes) ]]; then
        HAS_PROCESSES=1
    fi
done

# Each browser worker maintains an independent Chromium instance (~5-6 OS
# processes per instance). On a standard 4-vCPU PBX host, capping parallel workers
# at 2 prevents CPU saturation and memory thrashing, leaving 2 cores free for host
# PBX services (FreeSWITCH, MariaDB, PHP-FPM, Redis).
if [[ $HAS_PARALLEL -eq 1 && $HAS_PROCESSES -eq 0 ]]; then
    ARGS+=("--processes=2")
fi

exec ./vendor/bin/pest tests/Browser "${ARGS[@]}"
