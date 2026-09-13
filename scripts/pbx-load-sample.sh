#!/usr/bin/env bash
set -euo pipefail

INTERVAL="${INTERVAL:-5}"
SAMPLES="${SAMPLES:-0}"
XML_URL="${XML_URL:-http://127.0.0.1/api/v1/xml-handler}"
FS_CLI="${FS_CLI:-fs_cli}"
MYSQLADMIN="${MYSQLADMIN:-mysqladmin}"

sample_count=0

while true; do
  timestamp="$(date --iso-8601=seconds)"
  echo "=== ${timestamp} ==="

  if command -v "${FS_CLI}" >/dev/null 2>&1; then
    "${FS_CLI}" -x status || true
    "${FS_CLI}" -x "show channels count" || true
    "${FS_CLI}" -x "show calls count" || true
    "${FS_CLI}" -x "show registrations count" || true
  else
    echo "fs_cli not found; skipping FreeSWITCH samples"
  fi

  if command -v curl >/dev/null 2>&1; then
    curl -sS -o /dev/null -w "xml_handler_http=%{http_code} time_total=%{time_total}\n" \
      "${XML_URL}?section=dialplan&Caller-Context=public&Caller-Destination-Number=0000" || true
  fi

  if command -v "${MYSQLADMIN}" >/dev/null 2>&1; then
    "${MYSQLADMIN}" status || true
  else
    echo "mysqladmin not found; skipping MariaDB samples"
  fi

  vmstat 1 2 | tail -1 || true
  echo

  sample_count=$((sample_count + 1))

  if [[ "${SAMPLES}" != "0" && "${sample_count}" -ge "${SAMPLES}" ]]; then
    exit 0
  fi

  sleep "${INTERVAL}"
done
