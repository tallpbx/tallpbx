#!/usr/bin/env bash
set -euo pipefail

PBX_HOST="${PBX_HOST:-127.0.0.1}"
PBX_PORT="${PBX_PORT:-5060}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOAD_GENERATOR_IP="${LOAD_GENERATOR_IP:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
TENANT="${TENANT:-load-test-beta}"
DOMAIN="${DOMAIN:-load.test.local}"
EXTENSIONS="${EXTENSIONS:-20}"
START="${START:-2000}"
PASSWORD="${PASSWORD:-LoadTest1234!}"
SIPP_UAS_PORT="${SIPP_UAS_PORT:-5088}"
UAC_LOCAL_PORT="${UAC_LOCAL_PORT:-5064}"
EXTENSION_UAS_LOCAL_PORT="${EXTENSION_UAS_LOCAL_PORT:-5066}"
REGISTER_LOCAL_PORT="${REGISTER_LOCAL_PORT:-${EXTENSION_UAS_LOCAL_PORT}}"
OUTBOUND_UAC_LOCAL_PORT="${OUTBOUND_UAC_LOCAL_PORT:-5068}"
REGISTER_RATE="${REGISTER_RATE:-5}"
REGISTER_COUNT="${REGISTER_COUNT:-${EXTENSIONS}}"
EXTENSION_UAS_COUNT="${EXTENSION_UAS_COUNT:-${EXTENSIONS}}"
CALL_RATE="${CALL_RATE:-2}"
MAX_SIMULTANEOUS="${MAX_SIMULTANEOUS:-5}"
CALLS="${CALLS:-10}"
OUTBOUND_CALL_RATE="${OUTBOUND_CALL_RATE:-1}"
OUTBOUND_MAX_SIMULTANEOUS="${OUTBOUND_MAX_SIMULTANEOUS:-2}"
OUTBOUND_CALLS="${OUTBOUND_CALLS:-5}"
MEDIA_FLOW="${MEDIA_FLOW:-0}"
MEDIA_CALL_RATE="${MEDIA_CALL_RATE:-1}"
MEDIA_MAX_SIMULTANEOUS="${MEDIA_MAX_SIMULTANEOUS:-1}"
MEDIA_CALLS="${MEDIA_CALLS:-1}"
MEDIA_UAC_LOCAL_PORT="${MEDIA_UAC_LOCAL_PORT:-5070}"
MEDIA_RTP_PORT="${MEDIA_RTP_PORT:-6000}"
MEDIA_RTP_ECHO="${MEDIA_RTP_ECHO:-1}"
MEDIA_CAPTURE="${MEDIA_CAPTURE:-1}"
MEDIA_CAPTURE_INTERFACE="${MEDIA_CAPTURE_INTERFACE:-any}"
MEDIA_RECORDING_DESTINATION="${MEDIA_RECORDING_DESTINATION:-*732}"
MEDIA_MOH_DESTINATION="${MEDIA_MOH_DESTINATION:-load_test_moh}"
MEDIA_ANNOUNCEMENT_DESTINATION="${MEDIA_ANNOUNCEMENT_DESTINATION:-load_test_announcement}"
EXTENDED="${EXTENDED:-0}"
EXTENDED_UAS_PORT="${EXTENDED_UAS_PORT:-5090}"
EXTENDED_UAC_LOCAL_PORT="${EXTENDED_UAC_LOCAL_PORT:-5076}"
EXTENDED_CALLS="${EXTENDED_CALLS:-3}"
EXTENDED_CALL_RATE="${EXTENDED_CALL_RATE:-1}"
EXTENDED_MAX_SIMULTANEOUS="${EXTENDED_MAX_SIMULTANEOUS:-1}"
REGISTER_TIMEOUT="${REGISTER_TIMEOUT:-120}"
UAS_TIMEOUT="${UAS_TIMEOUT:-300}"
UAC_TIMEOUT="${UAC_TIMEOUT:-300}"
MEDIA_TIMEOUT="${MEDIA_TIMEOUT:-120}"
FORCE_SEED="${FORCE_SEED:-0}"
SKIP_SEED="${SKIP_SEED:-0}"
CSV_PATH="${CSV_PATH:-storage/app/load-tests/sipp-users.csv}"
RUN_DIR="${RUN_DIR:-storage/app/load-tests/sipp-e2e-$(date +%Y%m%d-%H%M%S)}"

cd "${ROOT_DIR}"

# Ensure RUN_DIR is an absolute path so all artifacts are stored in one predictable location.
if [[ "${RUN_DIR}" != /* ]]; then
  RUN_DIR="${ROOT_DIR}/${RUN_DIR}"
fi

if [[ -z "${LOAD_GENERATOR_IP}" ]]; then
  echo "Unable to detect LOAD_GENERATOR_IP. Set it explicitly." >&2
  exit 2
fi

if ! command -v sipp >/dev/null 2>&1; then
  echo "SIPp is not installed. Install sipp on the load generator before running end-to-end validation." >&2
  exit 127
fi

mkdir -p "${RUN_DIR}"

REGISTER_SCENARIO="${ROOT_DIR}/tools/sipp/register.xml"
UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-extension.xml"
OUTBOUND_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-outbound.xml"
UAS_SCENARIO="${ROOT_DIR}/tools/sipp/uas-auto-answer.xml"
MEDIA_CLIENT_HANGUP_SCENARIO="${ROOT_DIR}/tools/sipp/uac-media-client-hangup.xml"
MEDIA_SERVER_HANGUP_SCENARIO="${ROOT_DIR}/tools/sipp/uac-media-server-hangup.xml"
# Extended SIPp scenarios for parity audit modules (gated by EXTENDED=1)
RING_GROUP_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-ring-group.xml"
VOICEMAIL_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-voicemail.xml"
CONFERENCE_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-conference.xml"
CALL_FORWARD_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-call-forward.xml"
TIME_CONDITION_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-time-condition.xml"
FOLLOW_ME_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-follow-me.xml"
EMERGENCY_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-emergency.xml"
CALL_BLOCK_UAC_SCENARIO="${ROOT_DIR}/tools/sipp/uac-call-block.xml"
CSV_ABSOLUTE="${ROOT_DIR}/${CSV_PATH}"
AUTH_CSV_ABSOLUTE="${RUN_DIR}/sipp-users-auth.csv"

for required_file in "${REGISTER_SCENARIO}" "${UAC_SCENARIO}" "${OUTBOUND_UAC_SCENARIO}" "${UAS_SCENARIO}" "${MEDIA_CLIENT_HANGUP_SCENARIO}" "${MEDIA_SERVER_HANGUP_SCENARIO}"; do
  if [[ ! -f "${required_file}" ]]; then
    echo "Missing SIPp scenario: ${required_file}" >&2
    exit 2
  fi
done

if [[ "${SKIP_SEED}" != "1" && ( "${FORCE_SEED}" == "1" || ! -f "${CSV_ABSOLUTE}" || "${MEDIA_FLOW}" == "1" ) ]]; then
  seed_args=(
    php artisan pbx:load-test:seed
    "--tenant=${TENANT}"
    "--domain=${DOMAIN}"
    "--extensions=${EXTENSIONS}"
    "--start=${START}"
    "--password=${PASSWORD}"
    "--sipp-host=${LOAD_GENERATOR_IP}"
    "--sipp-port=${SIPP_UAS_PORT}"
    "--output=${CSV_PATH}"
  )

  if [[ "${MEDIA_FLOW}" == "1" ]]; then
    seed_args+=(--include-media-fixtures)
  fi

  if [[ "${EXTENDED}" == "1" ]]; then
    seed_args+=(--include-extended-fixtures)
  fi

  "${seed_args[@]}"
fi

if [[ ! -f "${CSV_ABSOLUTE}" ]]; then
  echo "Missing SIPp CSV: ${CSV_ABSOLUTE}" >&2
  exit 2
fi

awk -F';' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]" }' \
  "${CSV_ABSOLUTE}" > "${AUTH_CSV_ABSOLUTE}"

{
  echo "# SIPp End-To-End Validation"
  echo
  echo "- Started: $(date --iso-8601=seconds)"
  echo "- PBX target: ${PBX_HOST}:${PBX_PORT}"
  echo "- Load generator IP: ${LOAD_GENERATOR_IP}"
  echo "- Tenant: ${TENANT}"
  echo "- SIP realm: ${DOMAIN}"
  echo "- CSV: ${CSV_ABSOLUTE}"
  echo "- SIPp auth CSV: ${AUTH_CSV_ABSOLUTE}"
  echo "- Register rate/count: ${REGISTER_RATE}/${REGISTER_COUNT}"
  echo "- Call rate/limit/count: ${CALL_RATE}/${MAX_SIMULTANEOUS}/${CALLS}"
  echo "- Outbound call rate/limit/count: ${OUTBOUND_CALL_RATE}/${OUTBOUND_MAX_SIMULTANEOUS}/${OUTBOUND_CALLS}"
  echo "- Seed skipped: ${SKIP_SEED}"
  echo "- Media flow enabled: ${MEDIA_FLOW}"
  echo "- Extended parity scenarios: ${EXTENDED}"
  if [[ "${MEDIA_FLOW}" == "1" ]]; then
    echo "- Media rate/limit/count: ${MEDIA_CALL_RATE}/${MEDIA_MAX_SIMULTANEOUS}/${MEDIA_CALLS}"
    echo "- Media destinations: recording=${MEDIA_RECORDING_DESTINATION}, moh=${MEDIA_MOH_DESTINATION}, announcement=${MEDIA_ANNOUNCEMENT_DESTINATION}"
    echo "- Media RTP base port: ${MEDIA_RTP_PORT}"
    echo "- Media RTP echo: ${MEDIA_RTP_ECHO}"
    echo "- Media capture: ${MEDIA_CAPTURE} (${MEDIA_CAPTURE_INTERFACE})"
  fi
  echo
} > "${RUN_DIR}/summary.md"

if command -v fs_cli >/dev/null 2>&1; then
  {
    fs_cli -x status || true
    fs_cli -x "show channels count" || true
    fs_cli -x "show calls count" || true
    fs_cli -x "show registrations count" || true
  } > "${RUN_DIR}/freeswitch-before.log" 2>&1
fi

# Execute a single validation step. Runs the command within RUN_DIR so that
# any trace, error, or message log files generated by SIPp are written directly
# to the artifacts directory rather than polluting the repository root directory.
run_step() {
  local name="$1"
  shift

  echo "Running ${name}..."
  printf '%q ' "$@" > "${RUN_DIR}/${name}.command"
  echo >> "${RUN_DIR}/${name}.command"

  if (cd "${RUN_DIR}" && "$@") > "${RUN_DIR}/${name}.log" 2>&1; then
    echo "- ${name}: passed" >> "${RUN_DIR}/summary.md"
  else
    local exit_code=$?
    echo "- ${name}: failed with exit code ${exit_code}" >> "${RUN_DIR}/summary.md"
    return "${exit_code}"
  fi
}

write_media_csv() {
  local destination="$1"
  local output="$2"

  awk -F';' -v destination="${destination}" 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]", destination }' \
    "${CSV_ABSOLUTE}" > "${output}"
}

start_media_capture() {
  local name="$1"
  local port="$2"

  if [[ "${MEDIA_CAPTURE}" != "1" ]] || ! command -v tcpdump >/dev/null 2>&1; then
    echo ""
    return 0
  fi

  timeout "${MEDIA_TIMEOUT}" tcpdump -i "${MEDIA_CAPTURE_INTERFACE}" -w "${RUN_DIR}/${name}.rtp.pcap" "udp port ${port}" \
    > "${RUN_DIR}/${name}.tcpdump.log" 2>&1 &
  local pid="$!"
  echo "- ${name}: RTP capture started as pid ${pid} on UDP ${port}" >> "${RUN_DIR}/summary.md"
  echo "${pid}"
}

stop_media_capture() {
  local pid="$1"

  if [[ -n "${pid}" ]] && kill -0 "${pid}" >/dev/null 2>&1; then
    kill "${pid}" >/dev/null 2>&1 || true
    wait "${pid}" >/dev/null 2>&1 || true
  fi
}

run_media_step() {
  local name="$1"
  local scenario="$2"
  local destination="$3"
  local local_port="$4"
  local rtp_port="$5"
  local media_csv="${RUN_DIR}/${name}.csv"

  write_media_csv "${destination}" "${media_csv}"
  local capture_pid
  capture_pid="$(start_media_capture "${name}" "${rtp_port}")"
  local rtp_echo_args=()
  if [[ "${MEDIA_RTP_ECHO}" == "1" ]]; then
    rtp_echo_args=(-rtp_echo)
  fi

  if run_step "${name}" \
    timeout "${MEDIA_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${scenario}" \
      -inf "${media_csv}" \
      -i "${LOAD_GENERATOR_IP}" \
      -mi "${LOAD_GENERATOR_IP}" \
      -p "${local_port}" \
      -mp "${rtp_port}" \
      "${rtp_echo_args[@]}" \
      -r "${MEDIA_CALL_RATE}" \
      -l "${MEDIA_MAX_SIMULTANEOUS}" \
      -m "${MEDIA_CALLS}" \
      -trace_err \
      -trace_msg \
      -trace_counts \
      -trace_stat \
      -fd 5; then
    stop_media_capture "${capture_pid}"
  else
    local exit_code=$?
    stop_media_capture "${capture_pid}"
    return "${exit_code}"
  fi
}

extension_uas_pid=""
outbound_uas_pid=""
cleanup() {
  for pid in "${extension_uas_pid}" "${outbound_uas_pid}"; do
    if [[ -n "${pid}" ]] && kill -0 "${pid}" >/dev/null 2>&1; then
      kill "${pid}" >/dev/null 2>&1 || true
      wait "${pid}" >/dev/null 2>&1 || true
    fi
  done
}

# Start a background process (such as a UAS responder). Runs within RUN_DIR
# so that SIPp writes any scenario logs and counters into the artifacts folder.
start_background() {
  local name="$1"
  shift

  echo "Starting ${name}..." >&2
  printf '%q ' "$@" > "${RUN_DIR}/${name}.command"
  echo >> "${RUN_DIR}/${name}.command"
  (cd "${RUN_DIR}" && "$@") > "${RUN_DIR}/${name}.log" 2>&1 &
  local pid="$!"
  echo "- ${name}: started as pid ${pid}" >> "${RUN_DIR}/summary.md"

  if ! kill -0 "${pid}" >/dev/null 2>&1; then
    echo "${name} exited early. See ${RUN_DIR}/${name}.log" >&2
    return 1
  fi

  echo "${pid}"
}
trap cleanup EXIT

run_step register \
  timeout "${REGISTER_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
    -sf "${REGISTER_SCENARIO}" \
    -inf "${AUTH_CSV_ABSOLUTE}" \
    -i "${LOAD_GENERATOR_IP}" \
    -p "${REGISTER_LOCAL_PORT}" \
    -r "${REGISTER_RATE}" \
    -m "${REGISTER_COUNT}" \
    -trace_err \
    -trace_counts \
    -trace_stat \
    -fd 5

extension_uas_pid="$(start_background extension-registered-uas \
  timeout "${UAS_TIMEOUT}" sipp \
    -sf "${UAS_SCENARIO}" \
    -i "${LOAD_GENERATOR_IP}" \
    -p "${EXTENSION_UAS_LOCAL_PORT}" \
    -trace_err \
    -trace_counts \
    -trace_stat \
    -fd 5)"

outbound_uas_pid="$(start_background outbound-gateway-uas \
  timeout "${UAS_TIMEOUT}" sipp \
    -sf "${UAS_SCENARIO}" \
    -i "${LOAD_GENERATOR_IP}" \
    -p "${SIPP_UAS_PORT}" \
    -trace_err \
    -trace_counts \
    -trace_stat \
    -fd 5)"

sleep 5

run_step extension-calls \
  timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
    -sf "${UAC_SCENARIO}" \
    -inf "${AUTH_CSV_ABSOLUTE}" \
    -i "${LOAD_GENERATOR_IP}" \
    -p "${UAC_LOCAL_PORT}" \
    -r "${CALL_RATE}" \
    -l "${MAX_SIMULTANEOUS}" \
    -m "${CALLS}" \
    -trace_err \
    -trace_counts \
    -trace_stat \
    -fd 5

run_step outbound-calls \
  timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
    -sf "${OUTBOUND_UAC_SCENARIO}" \
    -inf "${AUTH_CSV_ABSOLUTE}" \
    -i "${LOAD_GENERATOR_IP}" \
    -p "${OUTBOUND_UAC_LOCAL_PORT}" \
    -r "${OUTBOUND_CALL_RATE}" \
    -l "${OUTBOUND_MAX_SIMULTANEOUS}" \
    -m "${OUTBOUND_CALLS}" \
    -trace_err \
    -trace_counts \
    -trace_stat \
    -fd 5

if [[ "${MEDIA_FLOW}" == "1" ]]; then
  run_media_step recording-media "${MEDIA_SERVER_HANGUP_SCENARIO}" "${MEDIA_RECORDING_DESTINATION}" "${MEDIA_UAC_LOCAL_PORT}" "${MEDIA_RTP_PORT}"
  run_media_step moh-media "${MEDIA_CLIENT_HANGUP_SCENARIO}" "${MEDIA_MOH_DESTINATION}" "$((MEDIA_UAC_LOCAL_PORT + 2))" "$((MEDIA_RTP_PORT + 2))"
  run_media_step announcement-media "${MEDIA_SERVER_HANGUP_SCENARIO}" "${MEDIA_ANNOUNCEMENT_DESTINATION}" "$((MEDIA_UAC_LOCAL_PORT + 4))" "$((MEDIA_RTP_PORT + 4))"
fi

# Extended parity-audit scenarios — gated by EXTENDED=1.
# Requires --include-extended-fixtures seeded via pbx:load-test:seed.
# Uses the same auto-answer UAS already running on EXTENSION_UAS_LOCAL_PORT.
if [[ "${EXTENDED}" == "1" ]]; then
  echo "" >> "${RUN_DIR}/summary.md"
  echo "### Extended Parity Audit Scenarios" >> "${RUN_DIR}/summary.md"

  # The base UASes time out once the base phases end; the extended
  # scenarios (ring groups, voicemail, conference, call-forward) need both
  # the registered-extension UAS and the outbound gateway UAS alive.
  extension_uas_pid="$(start_background extension-registered-uas \
    timeout 1200 sipp \
      -sf "${UAS_SCENARIO}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "${EXTENSION_UAS_LOCAL_PORT}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5)"

  outbound_uas_pid="$(start_background outbound-gateway-uas \
    timeout 1200 sipp \
      -sf "${UAS_SCENARIO}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "${SIPP_UAS_PORT}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5)"

  sleep 3

  run_step ring-group-calls \
    timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${RING_GROUP_UAC_SCENARIO}" \
      -inf "${AUTH_CSV_ABSOLUTE}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "${EXTENDED_UAC_LOCAL_PORT}" \
      -r "${EXTENDED_CALL_RATE}" \
      -l "${EXTENDED_MAX_SIMULTANEOUS}" \
      -m "${EXTENDED_CALLS}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5

  run_step voicemail-calls \
    timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${VOICEMAIL_UAC_SCENARIO}" \
      -inf "${AUTH_CSV_ABSOLUTE}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "$((EXTENDED_UAC_LOCAL_PORT + 2))" \
      -r "${EXTENDED_CALL_RATE}" \
      -l "${EXTENDED_MAX_SIMULTANEOUS}" \
      -m "${EXTENDED_CALLS}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5

  run_step conference-calls \
    timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${CONFERENCE_UAC_SCENARIO}" \
      -inf "${AUTH_CSV_ABSOLUTE}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "$((EXTENDED_UAC_LOCAL_PORT + 4))" \
      -r "${EXTENDED_CALL_RATE}" \
      -l "${EXTENDED_MAX_SIMULTANEOUS}" \
      -m "${EXTENDED_CALLS}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5

  run_step call-forward-calls \
    timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${CALL_FORWARD_UAC_SCENARIO}" \
      -inf "${AUTH_CSV_ABSOLUTE}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "$((EXTENDED_UAC_LOCAL_PORT + 6))" \
      -r "${EXTENDED_CALL_RATE}" \
      -l "${EXTENDED_MAX_SIMULTANEOUS}" \
      -m "${EXTENDED_CALLS}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5

  run_step time-condition-calls \
    timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${TIME_CONDITION_UAC_SCENARIO}" \
      -inf "${AUTH_CSV_ABSOLUTE}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "$((EXTENDED_UAC_LOCAL_PORT + 8))" \
      -r "${EXTENDED_CALL_RATE}" \
      -l "${EXTENDED_MAX_SIMULTANEOUS}" \
      -m "${EXTENDED_CALLS}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5

  run_step follow-me-calls \
    timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${FOLLOW_ME_UAC_SCENARIO}" \
      -inf "${AUTH_CSV_ABSOLUTE}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "$((EXTENDED_UAC_LOCAL_PORT + 10))" \
      -r "${EXTENDED_CALL_RATE}" \
      -l "${EXTENDED_MAX_SIMULTANEOUS}" \
      -m "${EXTENDED_CALLS}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5

  run_step emergency-calls \
    timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${EMERGENCY_UAC_SCENARIO}" \
      -inf "${AUTH_CSV_ABSOLUTE}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "$((EXTENDED_UAC_LOCAL_PORT + 12))" \
      -r "${EXTENDED_CALL_RATE}" \
      -l "${EXTENDED_MAX_SIMULTANEOUS}" \
      -m "${EXTENDED_CALLS}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5

  run_step call-block-calls \
    timeout "${UAC_TIMEOUT}" sipp "${PBX_HOST}:${PBX_PORT}" \
      -sf "${CALL_BLOCK_UAC_SCENARIO}" \
      -inf "${AUTH_CSV_ABSOLUTE}" \
      -i "${LOAD_GENERATOR_IP}" \
      -p "$((EXTENDED_UAC_LOCAL_PORT + 14))" \
      -r "${EXTENDED_CALL_RATE}" \
      -l "${EXTENDED_MAX_SIMULTANEOUS}" \
      -m "${EXTENDED_CALLS}" \
      -trace_err \
      -trace_counts \
      -trace_stat \
      -fd 5
fi

cleanup

if command -v fs_cli >/dev/null 2>&1; then
  {
    fs_cli -x status || true
    fs_cli -x "show channels count" || true
    fs_cli -x "show calls count" || true
    fs_cli -x "show registrations count" || true
  } > "${RUN_DIR}/freeswitch-after.log" 2>&1
fi

{
  echo
  echo "- Finished: $(date --iso-8601=seconds)"
  echo "- Artifacts: ${RUN_DIR}"
} >> "${RUN_DIR}/summary.md"

echo "SIPp end-to-end validation artifacts: ${RUN_DIR}"
