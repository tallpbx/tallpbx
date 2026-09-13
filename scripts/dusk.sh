#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="127.0.0.1"
PORT="8001"
DUSK_CHROMEDRIVER_PORT="${DUSK_CHROMEDRIVER_PORT:-9515}"
DUSK_DRIVER_URL="${DUSK_DRIVER_URL:-http://localhost:${DUSK_CHROMEDRIVER_PORT}}"

# Run all commands from the application root so Artisan finds the Dusk
# environment file and the browser always targets the temporary server.
cd "$ROOT_DIR"

# Keep the disposable browser-test database current and give its Super
# Administrators group the same permissions as a real installation. This is
# intentionally the existing PHP seeder, rather than a second Dusk-only
# permission list, so browser tests exercise the normal setup mechanism.
php artisan migrate --force --env=dusk
php artisan db:seed --class=AdminSeeder --force --env=dusk

# Stop the temporary Laravel server when the browser test ends or is interrupted.
cleanup() {
    if [ -n "${SERVER_PID:-}" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID"
        wait "$SERVER_PID" 2>/dev/null || true
    fi

    if [ -n "${CHROMEDRIVER_PID:-}" ] && kill -0 "$CHROMEDRIVER_PID" 2>/dev/null; then
        kill "$CHROMEDRIVER_PID"
        wait "$CHROMEDRIVER_PID" 2>/dev/null || true
    fi
}

trap cleanup EXIT INT TERM

# Start the local browser driver when using the default local address. This
# keeps one command sufficient for administrators and CI; an explicitly
# supplied remote DUSK_DRIVER_URL remains untouched.
if [ "$DUSK_DRIVER_URL" = "http://localhost:${DUSK_CHROMEDRIVER_PORT}" ]; then
    if ! command -v chromedriver >/dev/null 2>&1; then
        echo "ChromeDriver is required. Install the chromium-driver package before running Dusk." >&2
        exit 1
    fi

    chromedriver --port="$DUSK_CHROMEDRIVER_PORT" > storage/logs/dusk-chromedriver.log 2>&1 &
    CHROMEDRIVER_PID=$!

    for _ in $(seq 1 30); do
        if curl --fail --silent --output /dev/null "${DUSK_DRIVER_URL}/status"; then
            break
        fi

        sleep 1
    done

    if ! curl --fail --silent --output /dev/null "${DUSK_DRIVER_URL}/status"; then
        echo "ChromeDriver failed to start. See storage/logs/dusk-chromedriver.log." >&2
        exit 1
    fi
fi

# Start an isolated Dusk application server instead of sending browser tests to
# the production Nginx/PHP-FPM service.
APP_ENV=dusk php artisan serve --host="$HOST" --port="$PORT" > storage/logs/dusk-server.log 2>&1 &
SERVER_PID=$!

# Wait briefly for Laravel to finish booting before launching the browser.
READY=false

for _ in $(seq 1 30); do
    if curl --fail --silent --output /dev/null "http://${HOST}:${PORT}/panel/login"; then
        READY=true
        break
    fi

    sleep 1
done

if [ "$READY" != true ]; then
    echo "Dusk server failed to start. See storage/logs/dusk-server.log." >&2
    exit 1
fi

# Pass the temporary server URL to Dusk while allowing a custom ChromeDriver
# address when a caller intentionally supplies one.
DUSK_DRIVER_URL="$DUSK_DRIVER_URL" \
APP_URL="http://${HOST}:${PORT}" \
php artisan dusk "$@"
