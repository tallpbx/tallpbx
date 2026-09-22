#!/bin/bash
# ==============================================================================
# TallPBX Bootstrap Installer
# ==============================================================================
# This small launcher makes a full TallPBX installation a single command:
#
#   wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/1.1/scripts/bootstrap.sh | bash
#
# It prepares the TallPBX source code at /var/www/tallpbx and then starts the
# main installer (scripts/install.sh), which asks the normal setup questions
# and installs everything. Re-running the same command updates the prepared
# source code safely and runs the installer again.
#
# Options:
#   --ref <branch-or-tag>   Install a specific branch or tag (default: 1.1).
#   --no-demo               Do not ask the installer to add demo data.
#   --no-development        Do not install development tooling.
#   --help                  Show this help text.
# ==============================================================================

# Stop on a failing command, an unset variable, or a failed pipeline so a
# broken download or Git operation can never continue silently and leave a
# half-prepared installation behind.
set -euo pipefail

# The public repository that owns the TallPBX source code, and the folder
# where the application always lives. The main installer relies on this exact
# location, so it is fixed on purpose.
repository_url="https://github.com/tallpbx/tallpbx.git"
application_root="/var/www/tallpbx"

# The branch installed for normal users. --ref replaces it.
requested_ref="1.1"

# Options that are forwarded to the main installer unchanged.
installer_flags=()

# Print the usage text; used by --help and when an unknown option appears.
usage () {
    cat <<'USAGE'
Install TallPBX with one command:

  wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/1.1/scripts/bootstrap.sh | bash

Options:
  --ref <branch-or-tag>   Install a specific branch or tag (default: 1.1).
  --no-demo               Do not ask the installer to add demo data.
  --no-development        Do not install development tooling.
  --help                  Show this help text.
USAGE
}

# Stop unless this script runs as root. Installing packages, services, and
# database content needs root, exactly like the manual install instructions.
require_root () {
    if [ "$EUID" -ne 0 ]; then
        echo "The TallPBX bootstrap must run as root (log in as root, or use sudo)." >&2
        exit 1
    fi
}

# Warn (but continue) when the operating system is not Debian 13, the version
# TallPBX is built and tested for. The main installer stays the source of
# truth for what actually installs, so the bootstrap only advises.
warn_if_not_debian_13 () {
    local codename=""

    if [ -r /etc/os-release ]; then
        codename=$( . /etc/os-release; printf '%s' "${VERSION_CODENAME:-}" )
    fi

    if [ "$codename" != "trixie" ]; then
        echo "Warning: TallPBX targets Debian 13 (trixie); this system reports '${codename:-unknown}'. Continuing anyway."
    fi
}

# Install Git when it is missing — the only package the bootstrap needs before
# the main installer takes over. The lock timeout mirrors the installer so a
# fresh server that is still finishing unattended upgrades waits instead of
# failing.
ensure_git () {
    if command -v git >/dev/null 2>&1; then
        return
    fi

    apt-get -o "DPkg::Lock::Timeout=300" update
    apt-get -o "DPkg::Lock::Timeout=300" install -y git
}

# Check the requested branch or tag before any Git operation: its name must be
# safe to pass to Git (letters, digits, dot, dash, slash, underscore only) and
# it must exist in the repository. A typo therefore stops the install with a
# clear message instead of a confusing Git error.
validate_ref () {
    if [[ ! "$requested_ref" =~ ^[A-Za-z0-9._/-]{1,100}$ ]] || [[ "$requested_ref" == -* ]]; then
        echo "Invalid --ref value: '$requested_ref'" >&2
        exit 1
    fi

    local ls_remote_status=0
    git ls-remote --exit-code "$repository_url" "$requested_ref" >/dev/null 2>&1 || ls_remote_status=$?

    case "$ls_remote_status" in
        0)
            ;;
        2)
            echo "Unknown TallPBX ref: '$requested_ref'. Use a branch or tag that exists in $repository_url." >&2
            exit 1
            ;;
        *)
            echo "Could not reach $repository_url to verify the ref '$requested_ref'. Check the network and run the command again." >&2
            exit 1
            ;;
    esac
}

# Describe what currently sits at the application folder so the next step can
# choose the safe action. Prints exactly one word:
#   missing - nothing is there yet (no folder, or an empty folder)
#   git     - a Git working copy of TallPBX is already prepared
#   blocked - something else lives there; the bootstrap must not touch it
bootstrap_target_state () {
    if [ ! -e "$application_root" ]; then
        printf 'missing\n'
        return
    fi

    if [ -d "$application_root/.git" ]; then
        printf 'git\n'
        return
    fi

    if [ -z "$(ls -A "$application_root" 2>/dev/null)" ]; then
        printf 'missing\n'
        return
    fi

    printf 'blocked\n'
}

# Prepare /var/www/tallpbx with the requested branch or tag:
#   - missing: clone the repository. The clone keeps full history so the
#     upgrade instructions that use plain "git pull" commands keep working.
#   - git: update only when it is safe. Local changes and diverged history are
#     refused with guidance; nothing is ever stashed, reset, or deleted.
#   - blocked: refuse. The bootstrap never overwrites data it does not own.
prepare_working_copy () {
    local state current_branch
    state=$(bootstrap_target_state)

    case "$state" in
        missing)
            echo "Cloning TallPBX ($requested_ref) into $application_root..."
            mkdir -p "$(dirname "$application_root")"
            git clone --branch "$requested_ref" "$repository_url" "$application_root"
            ;;
        git)
            echo "Updating the existing TallPBX working copy at $application_root..."

            if [ -n "$(git -C "$application_root" status --porcelain)" ]; then
                echo "Refusing to update: $application_root has local changes. Save them first (see 'If Git Will Not Pull the Update' in INSTALL.md)." >&2
                exit 1
            fi

            current_branch=$(git -C "$application_root" rev-parse --abbrev-ref HEAD)

            if [ "$current_branch" = "$requested_ref" ]; then
                # The working copy already follows the requested branch, so the
                # update must be a pure fast-forward extension of its history.
                git -C "$application_root" fetch origin "$requested_ref"

                if ! git -C "$application_root" merge --ff-only FETCH_HEAD; then
                    echo "Refusing to update: the working copy cannot be fast-forwarded (local commits or diverged history). See 'If Git Will Not Pull the Update' in INSTALL.md." >&2
                    exit 1
                fi
            else
                # A different branch or a tag: switch the clean working copy
                # over. Tags are immutable, so re-running with the same tag
                # changes nothing.
                git -C "$application_root" fetch --tags origin
                git -C "$application_root" checkout "$requested_ref"
            fi
            ;;
        blocked)
            echo "Refusing to continue: $application_root exists but is not a Git working copy of TallPBX." >&2
            echo "Move that folder aside (or remove it) and run the command again." >&2
            exit 1
            ;;
    esac
}

# Hand the terminal over to the main installer.
# A command like "wget -O- ... | bash" gives this script the download pipe as
# its input, so the installer's questions could not be answered. When a real
# terminal is available, reconnect it and start the installer with exec, which
# replaces this process and keeps the installer's exit status. Without a
# terminal the installer runs non-interactively and applies its documented
# environment requirements.
hand_off_to_installer () {
    echo "TallPBX source is ready at $application_root (ref: $requested_ref)."
    echo "Starting the installer..."

    if { true </dev/tty; } 2>/dev/null; then
        exec bash "$application_root/scripts/install.sh" "${installer_flags[@]}" </dev/tty
    else
        echo "No interactive terminal detected; the installer will run non-interactively and needs the documented environment values."
        exec bash "$application_root/scripts/install.sh" "${installer_flags[@]}"
    fi
}

# Automated tests load the helper functions above without running anything.
# This flag is the only supported caller of this mode.
if [ "${FSPBX_BOOTSTRAP_LIB_ONLY:-false}" = true ]; then
    return 0 2>/dev/null || exit 0
fi

# --- Options ---
# Read the command line. Unknown options stop the run so a typo can never fall
# through to the installer unnoticed.
while [ $# -gt 0 ]; do
    case "$1" in
        --ref)
            if [ $# -lt 2 ]; then
                echo "The --ref option needs a branch or tag value." >&2
                exit 1
            fi
            requested_ref="$2"
            shift 2
            ;;
        --no-demo|--no-development)
            installer_flags+=("$1")
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Unknown bootstrap option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

# --- Run ---
require_root
warn_if_not_debian_13
ensure_git
validate_ref
prepare_working_copy
hand_off_to_installer
