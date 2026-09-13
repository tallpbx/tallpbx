#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Give a generated directory and everything inside it the ownership and modes
# PHP-FPM needs after Laravel or Vite creates files as another system user.
repair_path() {
    local path="$1"

    # The directory may not exist until the related Laravel or Vite feature has
    # run for the first time, so there is nothing to repair in that case.
    if [ ! -e "$path" ]; then
        return
    fi

    # Generated files are application runtime data, not source code. Make them
    # writable by www-data and retain that group on newly created directories.
    chown -R www-data:www-data "$path" 2>/dev/null || true
    find "$path" -type d -exec chmod 2775 {} + 2>/dev/null || true
    find "$path" -type f -exec chmod 664 {} + 2>/dev/null || true
}

repair_path "$ROOT_DIR/bootstrap/cache"
repair_path "$ROOT_DIR/storage/framework/views"
repair_path "$ROOT_DIR/public/build"

if [ -e "$ROOT_DIR/bootstrap/cache/.gitignore" ]; then
    chmod 755 "$ROOT_DIR/bootstrap/cache/.gitignore" 2>/dev/null || true
fi
