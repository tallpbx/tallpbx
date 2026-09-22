# One-Line Installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a one-command bootstrap installer for TallPBX, automate the IPv4 preference fix for hosts without an IPv6 default route, and stop headless installs from hanging at the administrator password prompt.

**Architecture:** A new self-contained `scripts/bootstrap.sh` is fetched through a pipe, prepares `/var/www/tallpbx` (clone or safe fast-forward), then `exec`s the existing `scripts/install.sh` with the terminal re-attached via `/dev/tty`. The installer gains two small changes: two helper functions in `environment.sh` plus an early call site that activates the `gai.conf` IPv4 precedence rule when no IPv6 default route exists, and a terminal guard before administrator credential prompts. Documentation, release-process notes, and the changelog are updated last.

**Tech Stack:** Bash 5 (Debian 13 target), Pest 4 with `Symfony\Component\Process` (matching existing installer tests), Git, `iproute2`.

**Spec:** `docs/design-document-one-line-installer.md`

## Global Constraints

- Target platform is Debian 13 (trixie); no new runtime dependencies are introduced.
- Every function and every non-obvious line gets a plain-language comment (mandatory AGENTS.md rule); the bootstrap must be readable by an administrator who does not know Bash.
- Tracked scripts keep their file modes; everything is invoked as `bash <script>` (never `chmod +x`).
- Pest tests only. Run targeted: `php artisan test --compact --filter="<name>"`. Never run the full suite.
- Run `php artisan optimize:clear` after every code change.
- Run `vendor/bin/pint --dirty --format agent` before finalizing any PHP change.
- Conventional commit subjects, imperative mood, at most 72 characters. Commit steps run only after the maintainer explicitly approves committing (project rule: no commit or push without approval).
- Do not change `install.sh`'s questionnaire wording, flags, or step order beyond the two specified edits.
- The bootstrap never stashes, resets, or deletes user work.

## Review Focus

Inputs/conditions the spec implies but no test executes end-to-end; each line gets the pinning test noted in the task that owns the code:

1. Piping the one-liner as a non-root user must fail fast with clear guidance (not half-install) — pinned by the root-check assertion in Task 3.
2. A mistyped or hostile `--ref` (spaces, leading dash, shell metacharacters) must be rejected before any Git operation — pinned by the invalid-ref behavioral test in Task 3.
3. An existing working copy with local changes, or a diverged history, must be refused and never rewritten — pinned by the dirty-refusal behavioral test and `--ff-only` assertion in Task 3.
4. A run without an interactive terminal must never hang and must fail with guidance when values are missing — pinned by the guard ordering test in Task 2 and the `/dev/tty` fallback note in Task 3.
5. Hosts with a working IPv6 default route, or an already-active precedence rule, must stay untouched — pinned by the route-state and idempotency tests in Task 1.

---

### Task 1: Automatic IPv4 preference in the installer

**Files:**
- Modify: `scripts/resources/environment.sh` (append two helper functions at end of file)
- Modify: `scripts/install.sh` (insert one call-site block after the core-dependency install, before the `# The application clone is a fixed...` comment)
- Modify: `INSTALL.md` (replace the "Prefer IPv4 When the Host Has No IPv6 Default Route" subsection, currently lines ~100-135)
- Test: `tests/Feature/InstallerDefaultsTest.php` (append new tests)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `ipv6_default_route_state()` prints `present`, `absent`, or `unknown`; `ensure_ipv4_precedence()` makes `precedence ::ffff:0:0/96  100` active in `${FSPBX_GAI_CONF:-/etc/gai.conf}`, idempotently, silently (the caller logs).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InstallerDefaultsTest.php`:

```php
it('detects the IPv6 default route state used by the installer', function (): void {
    $stubDirectory = sys_get_temp_dir().'/pbx-ip-stub-'.bin2hex(random_bytes(8));
    mkdir($stubDirectory, 0700);

    // The stub replaces the real "ip" command: it prints whatever the test
    // writes into its ip-output file, so route states can be simulated.
    file_put_contents(
        $stubDirectory.'/ip',
        "#!/bin/bash\ncat \"{$stubDirectory}/ip-output\" 2>/dev/null || true\n"
    );
    chmod($stubDirectory.'/ip', 0755);

    $helperPath = escapeshellarg(base_path('scripts/resources/environment.sh'));
    $stubPath = escapeshellarg($stubDirectory);

    try {
        // A host with a default IPv6 route.
        file_put_contents($stubDirectory.'/ip-output', "default via fe80::1 dev eth0 proto ra\n");
        $process = new Process(['bash', '-c', 'PATH='.$stubPath.':"$PATH"; source '.$helperPath.'; ipv6_default_route_state'], base_path());
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(trim($process->getOutput()))->toBe('present');

        // A host with a global address but no default IPv6 route.
        file_put_contents($stubDirectory.'/ip-output', '');
        $process = new Process(['bash', '-c', 'PATH='.$stubPath.':"$PATH"; source '.$helperPath.'; ipv6_default_route_state'], base_path());
        $process->run();
        expect(trim($process->getOutput()))->toBe('absent');

        // A host without the ip command reports an unknown state.
        $process = new Process(['bash', '-c', 'PATH=/nonexistent; source '.$helperPath.'; ipv6_default_route_state'], base_path());
        $process->run();
        expect(trim($process->getOutput()))->toBe('unknown');
    } finally {
        unlink($stubDirectory.'/ip-output');
        unlink($stubDirectory.'/ip');
        rmdir($stubDirectory);
    }
});

it('activates the IPv4 precedence rule idempotently', function (): void {
    $gaiPath = tempnam(sys_get_temp_dir(), 'pbx-gai-');
    file_put_contents($gaiPath, "# header\n#precedence ::ffff:0:0/96  100\n# tail\n");

    $helperPath = escapeshellarg(base_path('scripts/resources/environment.sh'));
    $gaiArgument = escapeshellarg($gaiPath);
    $command = <<<BASH
source {$helperPath}
export FSPBX_GAI_CONF={$gaiArgument}
ensure_ipv4_precedence
ensure_ipv4_precedence
grep -q '^precedence ::ffff:0:0/96  100$' {$gaiArgument}
test "\$(grep -c -E '^[[:space:]]*precedence[[:space:]]+::ffff:0:0/96[[:space:]]+100[[:space:]]*$' {$gaiArgument})" = 1
! grep -q '^#precedence ::ffff:0:0/96  100$' {$gaiArgument}
BASH;

    try {
        $process = new Process(['bash', '-c', $command], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    } finally {
        unlink($gaiPath);
    }
});

it('creates the policy file when the IPv4 precedence rule is missing', function (): void {
    $gaiPath = sys_get_temp_dir().'/pbx-gai-create-'.bin2hex(random_bytes(8)).'.conf';

    $helperPath = escapeshellarg(base_path('scripts/resources/environment.sh'));
    $gaiArgument = escapeshellarg($gaiPath);
    $command = 'source '.$helperPath.' && export FSPBX_GAI_CONF='.$gaiArgument.' && ensure_ipv4_precedence';

    try {
        $process = new Process(['bash', '-c', $command], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(file_get_contents($gaiPath))->toBe("precedence ::ffff:0:0/96  100\n");
    } finally {
        if (file_exists($gaiPath)) {
            unlink($gaiPath);
        }
    }
});

it('leaves an already-active IPv4 precedence rule untouched', function (): void {
    $gaiPath = tempnam(sys_get_temp_dir(), 'pbx-gai-active-');
    $original = "precedence ::ffff:0:0/96  100\n# tail-marker\n";
    file_put_contents($gaiPath, $original);

    $helperPath = escapeshellarg(base_path('scripts/resources/environment.sh'));
    $gaiArgument = escapeshellarg($gaiPath);
    $command = 'source '.$helperPath.' && export FSPBX_GAI_CONF='.$gaiArgument.' && ensure_ipv4_precedence';

    try {
        $process = new Process(['bash', '-c', $command], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(file_get_contents($gaiPath))->toBe($original);
    } finally {
        unlink($gaiPath);
    }
});

it('checks the IPv6 route before the first package download step', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $environment = (string) file_get_contents(base_path('scripts/resources/environment.sh'));
    $routeCheckPosition = strpos($installer, 'case "$(ipv6_default_route_state)" in');
    $nodeSourcePosition = strpos($installer, 'curl -fsSL "https://deb.nodesource.com');
    $tallStackPosition = strpos($installer, 'run_step "TALL Stack (Laravel, Livewire, Tailwind, DaisyUI)"');
    $install = (string) file_get_contents(base_path('INSTALL.md'));

    expect($environment)->toContain('ipv6_default_route_state ()')
        ->and($environment)->toContain('ensure_ipv4_precedence ()')
        ->and($installer)->toContain('case "$(ipv6_default_route_state)" in')
        ->and($installer)->toContain('ensure_ipv4_precedence')
        ->and($routeCheckPosition)->not->toBeFalse()
        ->and($nodeSourcePosition)->not->toBeFalse()
        ->and($tallStackPosition)->not->toBeFalse()
        ->and($routeCheckPosition)->toBeLessThan($nodeSourcePosition)
        ->and($routeCheckPosition)->toBeLessThan($tallStackPosition)
        ->and($install)->toContain('activating the IPv4 precedence rule in `/etc/gai.conf`')
        ->and($install)->not->toContain("sed -i 's/^#\\s*precedence");
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="IPv6"`
Expected: the five new tests fail because the helpers and call site do not exist yet.

- [ ] **Step 3: Add the helper functions to `environment.sh`**

Append at the end of `scripts/resources/environment.sh`:

```bash
# Report whether this host has a default IPv6 route. The result drives the
# installer's IPv4 preference fix:
#   present - the kernel routes IPv6 traffic somewhere; leave the system alone
#   absent  - there is no default IPv6 route; PHP would keep trying dead IPv6
#             addresses first when downloading files
#   unknown - the check could not run (for example the ip command is missing),
#             so callers must leave the system alone
ipv6_default_route_state () {
    if ! command -v ip >/dev/null 2>&1; then
        printf 'unknown\n'
        return
    fi

    if [ -n "$(ip -6 route show default 2>/dev/null)" ]; then
        printf 'present\n'
    else
        printf 'absent\n'
    fi
}

# Make the system prefer IPv4 addresses when a hostname offers both IPv4 and
# IPv6. This activates the IPv4 precedence line in the name-resolution policy
# file (/etc/gai.conf by default). Running it again changes nothing. Tests can
# point FSPBX_GAI_CONF at a temporary file.
ensure_ipv4_precedence () {
    local gai_file="${FSPBX_GAI_CONF:-/etc/gai.conf}"
    local precedence_rule='precedence ::ffff:0:0/96  100'

    # Already active: nothing to do.
    if grep -Eq '^[[:space:]]*precedence[[:space:]]+::ffff:0:0/96[[:space:]]+100[[:space:]]*$' "$gai_file" 2>/dev/null; then
        return
    fi

    if [ -f "$gai_file" ]; then
        # Debian ships the rule commented out; activate that exact line.
        sed -i -E 's|^#[[:space:]]*precedence[[:space:]]+::ffff:0:0/96[[:space:]]+100[[:space:]]*$|precedence ::ffff:0:0/96  100|' "$gai_file"
    fi

    # Either the file did not exist or it carried no precedence line: append
    # the rule so the IPv4 preference is definitely in place.
    if ! grep -Eq '^[[:space:]]*precedence[[:space:]]+::ffff:0:0/96[[:space:]]+100[[:space:]]*$' "$gai_file" 2>/dev/null; then
        printf '%s\n' "$precedence_rule" >> "$gai_file"
    fi
}
```

- [ ] **Step 4: Add the call site to `install.sh`**

Insert between the core-dependency install block (which ends with `unzip`) and the `# The application clone is a fixed, installer-managed deployment path.` comment:

```bash
# Some VPS providers hand out a global IPv6 address without a default IPv6
# route. PHP then tries the IPv6 address first when it downloads files (for
# example the Composer installer), waits for the connection to time out, and
# the installer fails. When that condition is detected, ask the system's
# name-resolution policy to prefer IPv4 addresses. Hosts with a working IPv6
# default route are left exactly as they are.
case "$(ipv6_default_route_state)" in
    absent)
        ensure_ipv4_precedence
        verbose "No IPv6 default route found; configured the system to prefer IPv4 so downloads do not time out."
        ;;
    unknown)
        warning "Could not inspect IPv6 routes; skipping the IPv4 preference check."
        ;;
esac
```

- [ ] **Step 5: Replace the INSTALL.md subsection**

Replace the whole subsection `### Prefer IPv4 When the Host Has No IPv6 Default Route` (it currently ends with "...would time out the same way without it." just before `## 4. Configure a Static IP Address (Optional)`) with:

```markdown
### IPv4 Preference on Hosts Without an IPv6 Default Route

Some VPS providers assign a global IPv6 address without a default IPv6 route.
PHP then tries IPv6 first when it downloads files from dual-stack hosts such as
`getcomposer.org`, waits for the connection to time out, and the installer
fails at the "Installing Composer" step with:

```text
PHP Warning: copy(https://getcomposer.org/installer): Failed to open stream: Connection timed out
```

The installer now detects this situation automatically and asks the whole
system to prefer IPv4 by activating the IPv4 precedence rule in `/etc/gai.conf`.
Hosts with a working IPv6 default route, or hosts where the rule is already
active, are left untouched.

To confirm the rule after an install:

```bash
grep precedence /etc/gai.conf   # the ::ffff:0:0/96 line should be active
```

To undo the preference manually, put a `#` back in front of the
`precedence ::ffff:0:0/96  100` line (or remove the line).
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="IPv6"`
Then run the full file: `php artisan test --compact tests/Feature/InstallerDefaultsTest.php`
Expected: PASS.

- [ ] **Step 7: Clear caches, lint, and commit (after approval)**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add scripts/resources/environment.sh scripts/install.sh INSTALL.md tests/Feature/InstallerDefaultsTest.php
git commit -m "feat: prefer ipv4 when the host has no ipv6 default route"
```

---

### Task 2: Headless administrator-credential guard

**Files:**
- Modify: `scripts/install.sh` (insert guard inside the `if [ -z "$admin_username" ] || [ -z "$admin_password" ]; then` block, before the `echo ""` / `verbose "Administrator account details"` lines)
- Test: `tests/Feature/InstallerDefaultsTest.php` (append one test)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: string `A non-interactive install cannot ask for administrator credentials.` and the pre-seed guidance line; both appear before `read -rsp "Admin password: "`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/InstallerDefaultsTest.php`:

```php
it('stops headless installs before prompting for administrator credentials', function (): void {
    $installer = (string) file_get_contents(base_path('scripts/install.sh'));
    $guardPosition = strpos($installer, 'A non-interactive install cannot ask for administrator credentials.');
    $promptPosition = strpos($installer, 'read -rsp "Admin password: "');

    expect($guardPosition)->not->toBeFalse()
        ->and($promptPosition)->not->toBeFalse()
        ->and($installer)->toContain('Pre-seed /etc/pbx/installer.env with FSPBX_ADMIN_USERNAME and FSPBX_ADMIN_PASSWORD')
        ->and($guardPosition)->toBeLessThan($promptPosition);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="stops headless installs"`
Expected: FAIL (guard message not found).

- [ ] **Step 3: Implement the guard**

Inside the credentials block, directly after the opening `if [ -z "$admin_username" ] || [ -z "$admin_password" ]; then` line, insert:

```bash
        # A run without a terminal cannot answer the questions below; the
        # password loop would wait at end-of-input forever. Stop with clear
        # guidance instead of hanging.
        if [ ! -t 0 ]; then
            error "A non-interactive install cannot ask for administrator credentials."
            error "Pre-seed /etc/pbx/installer.env with FSPBX_ADMIN_USERNAME and FSPBX_ADMIN_PASSWORD, or choose FSPBX_INITIAL_ADMIN_MODE=activation-code."
            exit 1
        fi
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter="stops headless installs"`
Expected: PASS.

- [ ] **Step 5: Clear caches, lint, and commit (after approval)**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add scripts/install.sh tests/Feature/InstallerDefaultsTest.php
git commit -m "fix: stop headless installs hanging at the admin password prompt"
```

---

### Task 3: Bootstrap installer script

**Files:**
- Create: `scripts/bootstrap.sh`
- Delete: `scripts/bootstrap.sh.example` (`git rm`)
- Test: `tests/Feature/BootstrapInstallerTest.php` (new file)

**Interfaces:**
- Consumes: the installer at `/var/www/tallpbx/scripts/install.sh` (executed at run time, not at test time).
- Produces: CLI options `--ref <branch-or-tag>`, `--no-demo`, `--no-development`, `--help`; helper functions `usage`, `require_root`, `warn_if_not_debian_13`, `ensure_git`, `validate_ref`, `bootstrap_target_state`, `prepare_working_copy`, `hand_off_to_installer`; test seam `FSPBX_BOOTSTRAP_LIB_ONLY=true` (define helpers, exit before running).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/BootstrapInstallerTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('ships an active bootstrap script and retires the reference template', function (): void {
    expect(base_path('scripts/bootstrap.sh'))->toBeFile()
        ->and(base_path('scripts/bootstrap.sh.example'))->not->toBeFile();
});

it('keeps the bootstrap safe for piped one-line installs', function (): void {
    $bootstrap = (string) file_get_contents(base_path('scripts/bootstrap.sh'));
    $rootCheckPosition = strpos($bootstrap, 'EUID');
    $clonePosition = strpos($bootstrap, 'git clone --branch');

    expect($bootstrap)->toContain('set -euo pipefail')
        ->and($bootstrap)->toContain('The TallPBX bootstrap must run as root')
        ->and($bootstrap)->toContain('requested_ref="main"')
        ->and($bootstrap)->toContain('requested_ref="$2"')
        ->and($bootstrap)->toContain('git ls-remote --exit-code')
        ->and($bootstrap)->toContain('git -C "$application_root" merge --ff-only')
        ->and($bootstrap)->toContain('git -C "$application_root" status --porcelain')
        ->and($bootstrap)->toContain('exists but is not a Git working copy of TallPBX')
        ->and($bootstrap)->toContain('</dev/tty')
        ->and($bootstrap)->toContain('exec bash "$application_root/scripts/install.sh"')
        ->and($bootstrap)->toContain('--no-demo|--no-development')
        ->and($bootstrap)->toContain('Unknown bootstrap option:')
        ->and($rootCheckPosition)->not->toBeFalse()
        ->and($clonePosition)->not->toBeFalse()
        ->and($rootCheckPosition)->toBeLessThan($clonePosition);
});

it('classifies the application folder before touching it', function (): void {
    $root = sys_get_temp_dir().'/pbx-bootstrap-state-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);

    $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));
    $stateOf = static function (string $path) use ($bootstrap): string {
        $process = new Process(['bash', '-c',
            'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
            .'application_root='.escapeshellarg($path).'; bootstrap_target_state',
        ], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        return trim($process->getOutput());
    };

    try {
        // No folder at all, then an empty folder: both are "missing".
        expect($stateOf($root.'/absent'))->toBe('missing');

        mkdir($root.'/empty', 0700);
        expect($stateOf($root.'/empty'))->toBe('missing');

        // A non-empty folder without Git metadata is "blocked".
        mkdir($root.'/blocked', 0700);
        file_put_contents($root.'/blocked/important.txt', "keep me\n");
        expect($stateOf($root.'/blocked'))->toBe('blocked');

        // A folder carrying .git is an existing working copy.
        mkdir($root.'/working/.git', 0700, true);
        expect($stateOf($root.'/working'))->toBe('git');
    } finally {
        File::deleteDirectory($root);
    }
});

it('rejects unsafe ref names before any git operation', function (): void {
    $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));

    foreach (['bad ref', '-oops', 'bad;ref', 'ref$HOME'] as $invalidRef) {
        $process = new Process(['bash', '-c',
            'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
            .'requested_ref='.escapeshellarg($invalidRef).'; validate_ref',
        ], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Invalid --ref value');
    }
});

it('refuses to update a working copy that carries local changes', function (): void {
    $repository = sys_get_temp_dir().'/pbx-bootstrap-repo-'.bin2hex(random_bytes(8));
    mkdir($repository, 0700);

    $run = static function (array $command): void {
        $process = new Process($command);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    };

    try {
        // Build a minimal Git working copy so the safety rules run for real.
        $run(['git', 'init', '-q', '-b', '1.1', $repository]);
        $run(['git', '-C', $repository, 'config', 'user.email', 'tests@tallpbx.local']);
        $run(['git', '-C', $repository, 'config', 'user.name', 'TallPBX Tests']);
        file_put_contents($repository.'/tracked.txt', "initial\n");
        $run(['git', '-C', $repository, 'add', 'tracked.txt']);
        $run(['git', '-C', $repository, 'commit', '-q', '-m', 'initial']);

        // A local change must stop the bootstrap before any fetch or merge.
        file_put_contents($repository.'/tracked.txt', "changed\n");

        $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));
        $process = new Process(['bash', '-c',
            'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
            .'application_root='.escapeshellarg($repository).'; requested_ref=1.1; prepare_working_copy',
        ], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('has local changes');
    } finally {
        File::deleteDirectory($repository);
    }
});

it('refuses to touch a folder that is not a git working copy', function (): void {
    $root = sys_get_temp_dir().'/pbx-bootstrap-blocked-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    file_put_contents($root.'/important.txt', "do not delete\n");

    $bootstrap = escapeshellarg(base_path('scripts/bootstrap.sh'));
    $process = new Process(['bash', '-c',
        'FSPBX_BOOTSTRAP_LIB_ONLY=true; source '.$bootstrap.'; '
        .'application_root='.escapeshellarg($root).'; prepare_working_copy',
    ], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('is not a Git working copy of TallPBX')
        ->and($root.'/important.txt')->toBeFile();

    File::deleteDirectory($root);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="bootstrap"`
Expected: FAIL — `scripts/bootstrap.sh` does not exist yet.

- [ ] **Step 3: Create `scripts/bootstrap.sh`**

Create the file with exactly this content (keep the mode as created by the editor; the script is always run as `bash scripts/bootstrap.sh`):

```bash
#!/bin/bash
# ==============================================================================
# TallPBX Bootstrap Installer
# ==============================================================================
# This small launcher makes a full TallPBX installation a single command:
#
#   wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash
#
# It prepares the TallPBX source code at /var/www/tallpbx and then starts the
# main installer (scripts/install.sh), which asks the normal setup questions
# and installs everything. Re-running the same command updates the prepared
# source code safely and runs the installer again.
#
# Options:
#   --ref <branch-or-tag>   Install a specific branch or tag (default: main).
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
requested_ref="main"

# Options that are forwarded to the main installer unchanged.
installer_flags=()

# Print the usage text; used by --help and when an unknown option appears.
usage () {
    cat <<'USAGE'
Install TallPBX with one command:

  wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash

Options:
  --ref <branch-or-tag>   Install a specific branch or tag (default: main).
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
```

- [ ] **Step 4: Delete the inactive template**

Run: `git rm scripts/bootstrap.sh.example`
(History keeps the file; the repository must not carry two bootstrap files.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="bootstrap"`
Expected: PASS (six tests).

- [ ] **Step 6: Clear caches, lint, and commit (after approval)**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add scripts/bootstrap.sh tests/Feature/BootstrapInstallerTest.php
git commit -m "feat: add one-line bootstrap installer"
```

---

### Task 4: Installation guide, release process, and changelog

**Files:**
- Modify: `INSTALL.md` (Section 5 rewrite; one sentence in "Running the Installer Again")
- Modify: `AGENTS.md` (one bullet in the "Tagging Best Practices" list)
- Modify: `CHANGELOG.md` (`[Unreleased]` entries)
- Test: `tests/Feature/BootstrapInstallerTest.php` (append one test)

**Interfaces:**
- Consumes: `scripts/bootstrap.sh` (Task 3) and the URL it is served from.
- Produces: documentation only.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/BootstrapInstallerTest.php`:

```php
it('presents the one-line install as the primary method', function (): void {
    $install = (string) file_get_contents(base_path('INSTALL.md'));

    expect($install)->toContain('wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash')
        ->and($install)->toContain('curl -fsSL https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash')
        ->and($install)->toContain('sha256sum --check')
        ->and($install)->toContain('bash -s -- --ref 1.1')
        ->and($install)->not->toContain('bootstrap.sh.example')
        ->and($install)->not->toContain('(Roadmap)')
        ->and($install)->not->toContain('bash ./scripts/install.sh --no-demo');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter="presents the one-line install"`
Expected: FAIL (INSTALL.md still documents the old flow).

- [ ] **Step 3: Rewrite INSTALL.md Section 5**

Replace everything from the `## 5. Run the Install Script` heading down to (but not including) the `### Installer Questionnaire` heading with:

```markdown
## 5. Run the Install Script

Install TallPBX with a single command:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash
```

The command downloads a small bootstrap script and runs it. The bootstrap
prepares the TallPBX source code in `/var/www/tallpbx`, then starts the main
installer, which asks the setup questions below and installs everything. With
`curl` instead of `wget`:

```bash
curl -fsSL https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash
```

Re-running the same command later safely updates an existing installation's
source code and runs the installer again; it keeps all data.

With no flags, the interactive installer asks whether to include demo data and
development tooling. Choose `No` for demo data on a production install; this
creates the shared Default tenant without sample tenants, users, or extensions.
The installer separately asks how to create the first administrator. Options
placed after the extra `-s --` are passed through to the bootstrap and the
installer:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --ref 1.1
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --no-demo
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --no-demo --no-development
```

A headless run must supply the FreeSWITCH installation method and an
administrator setup mode as environment values, for example the browser
activation code:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | FSPBX_FREESWITCH_INSTALL_METHOD=packages FSPBX_INITIAL_ADMIN_MODE=activation-code bash
```

To create the administrator during installation instead, pre-seed
`/etc/pbx/installer.env` with `FSPBX_ADMIN_USERNAME` and `FSPBX_ADMIN_PASSWORD`
before running the same command.

### Verified Installation (Optional)

For production servers, verify the bootstrap before running it. Every release's
GitHub release notes publish the SHA-256 checksum of `scripts/bootstrap.sh`:

```bash
wget -O /tmp/tallpbx-bootstrap.sh https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh
echo "<checksum-from-the-release-notes>  /tmp/tallpbx-bootstrap.sh" | sha256sum --check --status && bash /tmp/tallpbx-bootstrap.sh
```

### Manual Installation

To prepare the source code yourself (for example from a mirror):

```bash
apt-get install -y git && mkdir -p /var/www && cd /var/www && git clone https://github.com/tallpbx/tallpbx.git
cd /var/www/tallpbx && bash ./scripts/install.sh
```
```

(The rewritten region also removes the old `### Automated Bootstrap Installer
(Roadmap)` subsection, which sat between the headless paragraph and
`### Installer Questionnaire`.)

- [ ] **Step 4: Add the upgrade note**

In the `### Running the Installer Again` subsection, directly after the first
paragraph ("It is safe to run the installer again after an interrupted install
or an ordinary software update. It keeps existing call data, users, settings,
and database records. It reuses saved passwords and choices, keeps the existing
application key, and applies only missing database updates."), add:

```markdown
You can also re-run the one-line command from Section 5: it safely updates the
working copy in `/var/www/tallpbx` and then runs this installer again.
```

- [ ] **Step 5: Add the AGENTS.md release bullet**

At the end of the `### Tagging Best Practices (Do NOT Tag Every Commit)` bullet
list in `AGENTS.md`, add:

```markdown
- Publish the SHA-256 checksum of `scripts/bootstrap.sh` in the GitHub release notes for each release so administrators can use the verified installation path documented in `INSTALL.md`.
```

- [ ] **Step 6: Add the CHANGELOG entries**

Under `## [Unreleased]`, append to `### Added`:

```markdown
- **One-Line Bootstrap Installer**: TallPBX can now be installed with a single command (`wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash`), which prepares the source code in `/var/www/tallpbx` and starts the standard installer. Re-running the command safely updates an existing working copy before re-running the installer. Replaces the inactive `scripts/bootstrap.sh.example` reference template.
- **Automatic IPv4 Preference When the Host Has No IPv6 Default Route**: The installer now detects hosts that advertise IPv6 without a working default route and activates the IPv4 precedence rule in `/etc/gai.conf` automatically, preventing Composer download timeouts that previously required the manual fix documented in INSTALL.md.
```

Append to `### Changed`:

```markdown
- **Installation Guide Now Leads With the One-Line Command**: INSTALL.md presents the bootstrap command as the primary install method, documents an optional SHA-256 verified installation path, and keeps the manual clone-and-run procedure as a fallback. The former roadmap note about a future bootstrap installer was removed.
```

Append to `### Fixed`:

```markdown
- **Headless Installer Credential Hang**: Non-interactive installer runs in "create administrator during installation" mode without saved credentials now stop with clear guidance instead of waiting forever at the password prompt.
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="presents the one-line install"`
Then both suites: `php artisan test --compact tests/Feature/BootstrapInstallerTest.php tests/Feature/InstallerDefaultsTest.php`
Expected: PASS.

- [ ] **Step 8: Clear caches, lint, and commit (after approval)**

```bash
php artisan optimize:clear
vendor/bin/pint --dirty --format agent
git add INSTALL.md AGENTS.md CHANGELOG.md tests/Feature/BootstrapInstallerTest.php
git commit -m "docs: present the one-line installer as the primary install method"
```
