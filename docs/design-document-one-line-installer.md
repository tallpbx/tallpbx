# Design: One-Line TallPBX Installation

Status: Approved design, ready for implementation planning
Date: September 22, 2026

## Summary

TallPBX is currently installed by cloning the repository by hand and then
running `bash ./scripts/install.sh` from inside the clone. This design adds a
small **bootstrap script** that makes installation a single command — the same
experience FreePBX and FusionPBX provide:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash
```

The bootstrap prepares `/var/www/tallpbx`, then hands the terminal to the
existing installer, which keeps asking its normal questions. The same work also
automates the "Prefer IPv4 When the Host Has No IPv6 Default Route" procedure
that INSTALL.md currently asks administrators to perform by hand.

## Why

- **Simplicity for new users.** One copy-pasteable command, comparable to the
  FreePBX and FusionPBX installers, instead of editing URLs and chaining shell
  commands by hand.
- **Fewer failed installs.** The IPv4 preference fix becomes automatic, so
  Composer downloads no longer time out on VPS hosts that advertise IPv6
  without a working default route.
- **Planned work.** `scripts/bootstrap.sh.example` already described this flow
  as a future milestone, and INSTALL.md documents it as a roadmap item. This
  design activates that milestone with the verification posture chosen below.

## Decisions

| Decision | Choice | Rationale |
| --- | --- | --- |
| Experience | One command, interactive | The installer's questionnaire (demo data, development tooling, FreeSWITCH method, administrator setup) still runs in the same terminal. |
| Default source | `main` | Maintainer decision (September 22, 2026): the one-line installer delivers the latest installer from `main`; `--ref` pins a release branch or tag (for example `1.1` or `v1.1.2`) when reproducibility matters. |
| Integrity | HTTPS pipe only | Matches the FreePBX/FusionPBX simplicity; integrity rests on TLS and GitHub account security. The opt-in verified path was removed on September 22, 2026 because a same-channel checksum added little beyond TLS. Amends the unpublished-release precondition in `bootstrap.sh.example`. |
| Architecture | Separate bootstrap script | Keeps "launch me" and "install the PBX" as two small, independently testable scripts, exactly as the template described. |

## Component 1: New `scripts/bootstrap.sh`

Replaces `scripts/bootstrap.sh.example`, which is deleted (git history keeps
it). The script is fetched through a pipe and therefore must be **self-contained
before the clone exists** — it cannot source the repository's helper files until
afterward. Like the rest of the installer, it uses plain-language comments for
every function and non-obvious step.

### Inputs

- `--ref <branch-or-tag>` — overrides the default ref `main`.
- `--no-demo` and `--no-development` — passed through to `install.sh`.
- `--help` — usage text.
- Any other argument: reject with a usage message and exit code 1, matching
  `install.sh`'s argument behavior.

### Behavior, step by step

1. **Root check.** Exit with a clear message unless the effective user is root
   (`$EUID` is `0`). The installer changes packages and services, so this match
   with INSTALL.md's "log in as root" instruction is intentional.
2. **OS sanity (warning only).** When `/etc/os-release` does not identify Debian
   13 (`trixie`), print a warning and continue. The bootstrap deliberately does
   not hard-gate the OS so that the one-line path and the manual path behave
   the same way; `install.sh` remains the source of truth for what actually
   installs.
3. **Ensure Git.** When `git` is missing, run `apt-get -o DPkg::Lock::Timeout=300
   update` and `apt-get -o DPkg::Lock::Timeout=300 install -y git`. The lock
   timeout mirrors `environment.sh`'s `apt_get_with_lock_wait`, because a fresh
   server can still be finishing unattended upgrades.
4. **Validate the requested ref.** The ref must match
   `^[A-Za-z0-9._/-]{1,100}$` and must not begin with `-`, which prevents a
   crafted ref from being interpreted as a Git option. Then confirm the ref
   exists with `git ls-remote --exit-code <repository> <ref>`; exit code 2 means
   no such branch or tag.
5. **Classify `/var/www/tallpbx`** (a helper that returns one of three states,
   used by the automated tests):
   - `missing` — no directory or an empty one.
   - `git` — an existing Git working copy.
   - `blocked` — a non-empty directory that is not a Git working copy.
6. **Prepare the working copy.**
   - `missing`: `git clone --branch <ref> https://github.com/tallpbx/tallpbx.git
     /var/www/tallpbx`. A full clone is used (the repository packs to roughly
     5 MiB), so the standard Git commands in the upgrade documentation keep
     working; a shallow clone would break `git pull --ff-only` guidance.
   - `git`: update only when it is safe, following the same promise as the
     manual upgrade instructions:
     - `git status --porcelain` reports changes → **refuse** with guidance that
       references the "If Git Will Not Pull the Update" section of INSTALL.md.
       The bootstrap never stashes, resets, or discards local work.
     - Clean tree and the requested ref is the checked-out branch: `git fetch
       origin <ref>` then `git merge --ff-only FETCH_HEAD`. "Fast-forward only"
       means the update applies only when it is a pure extension of the current
       history; anything else is refused. A failed fast-forward prints the same
       refusal message.
     - Clean tree and a different branch or a tag: fetch and check out the
       requested ref. Tags are immutable, so re-running with the same tag is a
       no-op and switching to a newer tag is a deliberate choice.
   - `blocked`: **refuse** with a message explaining that
     `/var/www/tallpbx exists but is not a Git working copy`. This is the
     safeguard the template required: the bootstrap must never overwrite a
     directory it does not own.
7. **Hand off to the installer.** Print one line naming the prepared path and
   ref, then run:

   ```bash
   exec bash /var/www/tallpbx/scripts/install.sh <passthrough flags> </dev/tty
   ```

   - `exec` replaces the bootstrap process, so the installer owns the terminal
     and the exit status. It is the final statement of the script, which keeps
     the pipe safe: the bootstrap has already finished reading its own text.
   - `/dev/tty` is the controlling terminal of the session. A script that
     arrived through a pipe has a pipe as standard input, and `install.sh`
     decides whether to ask questions by checking `[ -t 0 ]` — so without this
     redirect the questionnaire would be skipped. When `/dev/tty` cannot be
     opened (for example in CI), the bootstrap prints a plain-language note,
     runs the installer without the redirect, and the installer's existing
     non-interactive requirements apply (documented environment values).

### Test seam

A documented `FSPBX_BOOTSTRAP_LIB_ONLY=true` environment flag makes the bootstrap
define its helper functions and exit before any system work. The automated
tests use it to exercise the directory-classification and argument handling
directly, the same way existing tests source `environment.sh`.

## Component 2: `scripts/install.sh` and `environment.sh` Changes

Both changes are deliberately small; the installer's questionnaire, steps, and
flag semantics stay untouched.

### 2a. Automatic IPv4 preference

`/etc/gai.conf` is the system name-resolution policy file. Its IPv4 precedence
line controls the order in which addresses are tried when a hostname resolves
to both IPv4 and IPv6. Debian ships the line commented out.

- `environment.sh` gains `ipv6_default_route_state()`: prints `present`,
  `absent`, or `unknown`. `absent` means `ip -6 route show default` returned
  nothing; `unknown` covers a missing `ip` command or another detection
  failure.
- `environment.sh` gains `ensure_ipv4_precedence()`: idempotently makes
  `precedence ::ffff:0:0/96  100` active in `${FSPBX_GAI_CONF:-/etc/gai.conf}`.
  If the line is already active nothing changes; if it is present in commented
  form it is uncommented; if the file has no such line the rule is appended; a
  missing file is created. The path override exists so the tests can use a
  temporary file. It logs one plain-language line explaining why.
- `install.sh` calls this in system preparation **after** the core dependencies
  (so `ip` from iproute2 is available) and **before** the steps that download
  packages and application dependencies (NodeSource, then the TALL step, where
  Composer runs through PHP). Hosts with a working IPv6 default route are left
  untouched.

### 2b. Headless administrator-credential guard

Found during design: with `FSPBX_INITIAL_ADMIN_MODE=installer`, no saved
credentials, and no terminal, the password loop reads end-of-input forever and
prints "Admin password cannot be empty" without stopping. The bootstrap's
non-interactive path makes this reachable deliberately, so the credential block
gains a terminal check: without `[ -t 0 ]` it exits with a clear error telling
the operator to pre-seed `/etc/pbx/installer.env` (`FSPBX_ADMIN_USERNAME`,
`FSPBX_ADMIN_PASSWORD`) or to choose `activation-code` mode.

## Component 3: Documentation, Changelog, Release Process

### INSTALL.md

- Section 5 became a single short path: the `wget -O- … | bash` command,
  flag passthrough examples (`… | bash -s -- --no-demo --no-development`), the
  idempotent re-run note, administrator setup, and compact maintenance
  sections. A later simplification pass (September 22, 2026) removed the
  manual-installation, headless, and installer-questionnaire sections, the VM
  platform line, and condensed the remaining operational guidance; the
  service-management table and the health-check/test commands moved to
  `docs/operations.md` with a pointer left in the guide.
- The "Automated Bootstrap Installer (Roadmap)" subsection is deleted, replaced
  by the real behavior above.
- The "Prefer IPv4 When the Host Has No IPv6 Default Route" procedure becomes a
  short subsection stating the installer now applies the fix automatically when
  needed, with manual verification (`grep precedence /etc/gai.conf`) and manual
  revert instructions (re-comment or remove the line).
- The upgrade section gains one sentence: re-running the one-line bootstrap
  fast-forwards the working copy and re-runs the installer.

### AGENTS.md

No release-process change; the checksum-publication line was removed together
with the verified installation path on September 22, 2026.

### CHANGELOG.md (`[Unreleased]`)

- Added: one-line bootstrap installer; automatic IPv4 preference on hosts
  without an IPv6 default route.
- Changed: installation guide now leads with the one-line command;
  `scripts/bootstrap.sh.example` replaced by the active bootstrap.
- Fixed: non-interactive installer runs in administrator-installer mode exit
  with guidance instead of waiting forever at the password prompt.

## Testing Plan (Tests First)

New `tests/Feature/BootstrapInstallerTest.php`, written failing before the
implementation, in the established `InstallerDefaultsTest` style (content
assertions plus `Process`-based behavioral tests). Targeted runs use
`php artisan test --compact --filter=… --parallel`.

Content assertions:

- `scripts/bootstrap.sh` exists; `scripts/bootstrap.sh.example` no longer does.
- Root check, default ref `main`, `--ref` validation, `git ls-remote --exit-code`,
  `git merge --ff-only`, `git status --porcelain` refusal, non-Git refusal
  message, `</dev/tty` hand-off, `exec bash` with passthrough flags, usage
  rejection of unknown arguments, library-only test seam.

Behavioral assertions (via the seam and temporary directories):

- Directory classification returns `missing` / `git` / `blocked` correctly.
- `ipv6_default_route_state()` returns `absent`, `present`, and `unknown` using
  a stub `ip` executable placed first in `PATH`.
- `ensure_ipv4_precedence()` against a temporary gai file: uncomments the
  Debian default line; is idempotent across repeated runs; appends when the
  line is missing; creates a missing file; leaves an already-active line
  untouched.

Ordering assertions in `install.sh`:

- The IPv4-preference call appears after the core-dependency install and before
  the TALL/Composer step.
- The headless guard appears before `read -rsp "Admin password: "`.

Documentation assertions:

- INSTALL.md contains the one-liner URL and no longer references
  `bootstrap.sh.example`, a checksum-verification procedure, the
  manual-installation or headless instructions, the installer-questionnaire
  section, the service-management table or health-check/test guidance (now in
  `docs/operations.md`), or the VM platform line.

Definition of done: the new and existing installer test suites pass
(`--filter`), `php artisan optimize:clear` runs clean, `vendor/bin/pint --dirty
--format agent` reports no changes, and CHANGELOG/INSTALL/AGENTS updates are in
place. Nothing is committed without the maintainer's approval.

## Security Considerations

- Integrity relies on HTTPS and GitHub account security, matching the
  references; no checksum-verification procedure is documented.
- The bootstrap downloads only from the hardcoded repository URL and clamps the
  ref to a safe character set, so a hostile ref cannot inject Git options.
- No destructive Git operations: dirty or diverged working copies are refused
  and guidance is printed; the script never stashes or resets.
- The only package the bootstrap installs before the main installer is `git`;
  apt runs with the same lock-wait behavior as the rest of the project.
- No new sudoers entries or privileged helpers are introduced.

## Assumptions and Constraints

- The GitHub repository stays public and `raw.githubusercontent.com` reachable
  from target servers.
- The documented URL points into the same branch it installs, so the URL only
  works once this change has landed on that branch (`main`).
- Target servers are freshly installed Debian 13, run the bootstrap as root,
  and have network access to GitHub, the SignalWire package repository (for the
  packages FreeSWITCH method), and Packagist.

## Non-Goals

- No custom install domain (no `get.tallpbx.com`); plain GitHub URLs only.
- No automated release-asset publishing or checksum-based verification.
- No changes to the installer questionnaire semantics, module behavior, or the
  manual upgrade path.
- No OS hard-gating in the bootstrap.
- No self-bootstrapping `install.sh`; the two scripts stay separate.

## Open Questions

None. Every decision above is final for this design; implementation details
(exact wording of messages, function decomposition) are settled during
planning without reopening the choices.
