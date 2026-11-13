#!/usr/bin/env bash
#
# TallPBX release-notes extractor.
#
# Prints the RELEASE-NOTES.md section of one released version to stdout, so
# the release workflow can publish it as the GitHub Release body without
# anyone copying text by hand. RELEASE-NOTES.md is the end-user summary; the
# complete record stays in CHANGELOG.md, which remains the single source of
# truth.
#
# The script refuses to run unless BOTH files carry the version: a dated
# CHANGELOG.md section ("## [5.1.2] - 2026-11-10") and a RELEASE-NOTES.md
# section ("## v5.1.2 (2026-11-10)"). A tag missing either one becomes a red
# workflow instead of a release with an empty or wrong body, because AGENTS.md
# forbids tagging a release whose changelog entry is not final.
#
# Usage:
#   scripts/release-notes.sh v5.1.2
#   scripts/release-notes.sh 5.1.2     # the leading v is optional
#
# Exits:
#   0  the release-notes section was printed
#   1  wrong usage, or the version is missing from either file

set -euo pipefail

# Always run from the application root so both files are found regardless of
# where the script was invoked from.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHANGELOG="$ROOT_DIR/CHANGELOG.md"
RELEASE_NOTES="$ROOT_DIR/RELEASE-NOTES.md"

if [ "$#" -ne 1 ]; then
    echo "usage: $0 <version>   (for example v5.1.2)" >&2
    exit 1
fi

# Git tags carry a leading "v" (v5.1.2) while changelog headings do not
# ([5.1.2]); release-notes headings keep the v (## v5.1.2). Accept both
# spellings of the argument and normalise to the bare number.
version="${1#v}"

# Guard 1: the changelog must hold a dated section for this version.
if ! grep -qE "^## \\[$version\\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$" "$CHANGELOG"; then
    echo "release-notes: CHANGELOG.md has no dated section for [$version]." >&2
    echo "release-notes: date the section before tagging (AGENTS.md, release rules)." >&2
    exit 1
fi

# Guard 2: the release-notes file must hold a section for this version, so the
# two files can never drift apart on a published tag.
if ! grep -qE "^## v$version \\(" "$RELEASE_NOTES"; then
    echo "release-notes: RELEASE-NOTES.md has no section for v$version." >&2
    echo "release-notes: write the summary and prepend it before tagging (AGENTS.md, release rules)." >&2
    exit 1
fi

# Print from this version's release-notes heading down to (not including) the
# next "## " heading. The oldest section at the bottom of the file simply runs
# to the end of the file, which the awk loop handles by never finding a stop.
awk -v ver="v$version" '
    !on && index($0, "## " ver " (") == 1 { on = 1; print; next }
    on && /^## / { exit }
    on { print }
' "$RELEASE_NOTES"
