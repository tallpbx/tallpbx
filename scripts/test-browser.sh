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

# Determine whether an explicit test path was provided as an argument
HAS_TARGET=0
for arg in "$@"; do
    target_path="${arg%%:*}"
    if [[ ! "$arg" =~ ^- ]] && { [[ -e "$arg" ]] || [[ -e "$target_path" ]] || [[ "$arg" =~ ^tests/ ]]; }; then
        HAS_TARGET=1
        break
    fi
done

TARGET_DIR=()
if [[ $HAS_TARGET -eq 0 ]]; then
    TARGET_DIR=("tests/Browser")
fi

# Clean up any lingering orphaned Playwright processes from previous runs before starting
pkill -f "playwright run-server" 2>/dev/null || true

# Enforce a maximum execution budget (default 360s, overridable via BROWSER_TEST_TIMEOUT)
# to prevent tests from hanging silently on blocked sockets or deadlocks.
BROWSER_TIMEOUT="${BROWSER_TEST_TIMEOUT:-360}"

set +e
timeout -k 10s "${BROWSER_TIMEOUT}s" ./vendor/bin/pest "${TARGET_DIR[@]}" "${ARGS[@]}"
EXIT_CODE=$?
set -e

if [[ $EXIT_CODE -eq 124 || $EXIT_CODE -eq 137 ]]; then
    echo "" >&2
    echo "================================================================================" >&2
    echo "ERROR: Browser test run timed out after ${BROWSER_TIMEOUT}s!" >&2
    echo "================================================================================" >&2
    echo "--- Active Pest & Chromium Processes ---" >&2
    ps aux | grep -E 'chrome|pest|playwright' | grep -v grep >&2 || true
    echo "" >&2
    echo "--- Recent Application Exception Log (storage/logs/laravel.log) ---" >&2
    if [[ -f storage/logs/laravel.log ]]; then
        tail -n 25 storage/logs/laravel.log >&2
    else
        echo "No storage/logs/laravel.log found." >&2
    fi
    echo "================================================================================" >&2
    pkill -f "playwright" 2>/dev/null || true
    exit 124
fi

exit "$EXIT_CODE"
