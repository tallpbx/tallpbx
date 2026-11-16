#!/bin/bash
# Installs the bounded worker-restart helper and its passwordless sudo rule.
#
# Idempotent: re-running rewrites the same two files and re-validates the
# sudoers drop-in. Invoke as root from the installer or once by hand on an
# existing appliance after the source that contains it has been deployed:
#
#   sudo bash scripts/resources/install-update-workers.sh
set -euo pipefail

helper_source="$(cd "$(dirname "$0")" && pwd)/tallpbx-update-workers"
sudoers_source="$(cd "$(dirname "$0")" && pwd)/tallpbx-update-workers.sudoers"
helper_target=/usr/local/sbin/tallpbx-update-workers
sudoers_target=/etc/sudoers.d/tallpbx-update-workers

if [ "$(id -u)" -ne 0 ]; then
    echo "install-update-workers.sh must run as root" >&2
    exit 77
fi

if [ ! -f "$helper_source" ] || [ ! -f "$sudoers_source" ]; then
    echo "the worker-restart helper sources are missing" >&2
    exit 66
fi

install -m 0750 -o root -g www-data "$helper_source" "$helper_target"
install -m 0440 -o root -g root "$sudoers_source" "$sudoers_target"

# A malformed drop-in breaks every sudo invocation on the host, so validate it
# before anything depends on it.
if ! visudo -c -f "$sudoers_target" >/dev/null 2>&1; then
    rm -f "$sudoers_target"
    echo "the sudoers drop-in failed validation and was removed" >&2
    exit 65
fi

echo "Installed $helper_target and $sudoers_target"
