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

exec ./vendor/bin/pest tests/Browser "$@"
