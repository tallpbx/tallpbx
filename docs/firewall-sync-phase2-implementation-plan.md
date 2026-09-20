# Firewall Sync Verification (Phase 2) — Implementation Plan

**Status:** Scoped and approved in principle — ready for implementation in a fresh session. Phase 1 (honest apply reporting, policy-level drift banner, and the generator preflight) shipped in commit `9d864f0`. Phase 3 (ban-set reconciliation / auto-heal) is scoped separately in `docs/firewall-sync-phase3-implementation-plan.md` and must not start before this phase ships.

**How to use this document (fresh session):** Read it together with `docs/security-architecture.md`. Follow `## 8. Implementation Checklist` in order using the project's mandatory TDD rules (write the failing Pest test first, then implement). All file paths and symbol names below are exact. Helper tests must stay hermetic — copy the isolation patterns from `tests/Feature/Modules/Security/SecurityExecutorTest.php` (isolated `TALLPBX_FIREWALL_CONF_DIR`, stub `nft`, never touch `/etc/tallpbx` or the live kernel).

## 1. Why This Exists

Phase 1 detects **policy-level** drift only: the Security Center re-reads the live kernel input-chain policy (via the helper's `status` action) and compares it against the saved policy (`firewall_default_policy`). If the two policy values match, the UI reports "in sync" — even when the rest of the ruleset differs. Content-level drift (set membership, service rules, custom rules, a stale or partially applied file) is therefore invisible today.

Phase 2 closes that gap with verified provenance:

- The bounded root helper records **what it actually loaded** into a sidecar file (`/etc/tallpbx/firewall.nft.applied`) — but only after re-reading the live kernel policy and confirming it matches the policy the ruleset file declares. The sidecar is authoritative because the privileged process that loaded the ruleset writes it, not the app.
- The application compares a **deterministic digest of the desired state** (compiled fresh from MariaDB) against the sidecar digest.
- The Security Center shows a small sync indicator, and a scheduled `security:verify` command detects drift when nobody is watching the panel.

## 2. Goals / Non-Goals

**Goals**

- A post-apply verification record (sidecar) written exclusively by the bounded helper, only when the live kernel policy matches the ruleset's declared policy.
- A deterministic canonical digest of the desired ruleset that stays stable while ban timeouts decay second by second.
- A Security Center sync indicator (`in sync` / `out of sync` / `unverified`) that updates through the existing event-driven refresh — no polling, no manual refresh buttons.
- A `security:verify` CLI command with monitoring-friendly exit codes, scheduled every five minutes.
- All of the above testable hermetically (no live kernel access from the test suite).

**Non-Goals**

- Automatic healing or reconciliation of any kind (that is Phase 3).
- Comparing every live kernel element — the sidecar proves "what was last loaded"; live anchors remain policy-only, as today.
- UI polling — the indicator rides the existing `refresh-security` / Echo events only.
- No sudoers changes: the helper gains no new action (the `apply` action is extended internally).
- No database migrations.

## 3. Current State Inventory (verified)

| Piece | Today | File |
|---|---|---|
| Ruleset compiler | `generate()` builds the file; input chain policy line `type filter hook input priority -10; policy drop;`; ban elements carry decaying ` timeout <N>s` countdowns | `SecurityConfigGenerator` |
| Pending/active files | `writePending()` / `writeActive()` write `/etc/tallpbx/firewall.nft.pending` / `firewall.nft`, chmod 0640 | `SecurityConfigGenerator` |
| Helper `apply` | pending-exists check → `nft -c -f` → `mv` pending→active (root:www-data 0640) → `nft -f`; no post-apply verification today | `scripts/resources/tallpbx-security` |
| Helper env override precedent | `TALLPBX_FIREWALL_CONF_DIR` (tests only; sudo's `env_reset` keeps production paths hardcoded) | same file, documented at the top |
| Executor | `SecurityExecutor::apply()` runs the helper; blocked during Pest/Dusk runs | `SecurityExecutor` |
| UI | `liveFirewallPolicy` + `refreshLiveFirewallPolicy()` parse the helper `status` output; drift banner in the page header | `SecurityManager`, `security-manager.blade.php` |
| CLI | `security:apply`, `security:status`, `security:unban`; imports at the top of the module provider | `Modules\Security\Console\Commands` |
| Scheduler | `Schedule::command(...)` entries live in `routes/console.php` | |
| Test patterns | Helper tests execute the real bash script against an isolated conf dir; Livewire tests bind Mockery fakes for executor and generator in `beforeEach` | `SecurityExecutorTest`, `SecurityManagerLivewireTest` |

## 4. Design

### 4.1 Provenance markers in the generated ruleset (`SecurityConfigGenerator`)

After the shebang line, `generate()` emits two machine-readable comment lines:

```nft
#!/usr/sbin/nft -f
# tallpbx-policy: drop
# tallpbx-digest: sha256:<64 lowercase hex chars>
```

- `tallpbx-policy` mirrors the input-chain policy actually emitted (`$chainPolicy = $firewallEnabled ? $defaultPolicy : 'accept'`).
- `tallpbx-digest` is `'sha256:'.hash('sha256', canonicalForm($content))` where `canonicalForm()`:
  - drops blank lines and comment lines (this also strips the markers themselves, so the digest is identical whether computed from the raw `$lines` before insertion or from the final content);
  - inside `elements = { ... }` lines: strips the ` timeout <N>s` suffix from each element and **sorts** the elements (set membership is unordered; MariaDB row order must never fake drift);
  - keeps every other line in order — custom-rule sequence IS semantic.
- Add `public function canonicalDigest(): string` that calls `generate()`, canonicalizes, and returns the same digest string the marker carries — the verifier calls this.
- Why canonicalization: ban elements decay every second (`timeout 17841s` → `timeout 17840s`), so the raw file hash can never be recomputed later. The helper (bash) never hashes anything — it only copies the digest marker into the sidecar.

Insertion point: at the end of `generate()`, after `$chainPolicy` is in scope — `array_splice($lines, 1, 0, ['# tallpbx-policy: '.$chainPolicy, '# tallpbx-digest: '.$digest])` before the final `implode`.

Existing tests only use `toContain` fragments, so the two added marker lines break nothing (verified while scoping).

### 4.2 Helper: post-apply verification + sidecar (`scripts/resources/tallpbx-security`)

Add an `nft` binary override next to the existing conf-dir override, with the same documented rationale (sudo's `env_reset` strips it in production, so the hardcoded path stays authoritative; tests point it at a stub):

```bash
NFT_BIN="${TALLPBX_NFT_BIN:-/usr/sbin/nft}"
```

Replace every `/usr/sbin/nft` call with `"$NFT_BIN"`.

Extend the `apply` action after the successful `nft -f`:

```bash
DECLARED_POLICY="$(sed -n 's/^# tallpbx-policy: \([a-z]*\)$/\1/p' "$FIREWALL_ACTIVE")"
RULESET_DIGEST="$(sed -n 's/^# tallpbx-digest: \(sha256:[0-9a-f]\{64\}\)$/\1/p' "$FIREWALL_ACTIVE")"
LIVE_CHAIN="$("$NFT_BIN" list chain inet tallpbx_filter input 2>/dev/null || true)"
LIVE_POLICY="$(printf '%s\n' "$LIVE_CHAIN" | sed -n 's/.*hook input[^;]*; policy \([a-z]*\);.*/\1/p')"

if [ -n "$DECLARED_POLICY" ] && [ -n "$RULESET_DIGEST" ] && [ "$LIVE_POLICY" = "$DECLARED_POLICY" ]; then
    SIDECAR="${FIREWALL_CONF_DIR}/firewall.nft.applied"
    printf 'digest=%s\npolicy=%s\napplied_at=%s\n' \
        "$RULESET_DIGEST" "$DECLARED_POLICY" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$SIDECAR"
    chown root:www-data "$SIDECAR" 2>/dev/null || true
    chmod 0640 "$SIDECAR" 2>/dev/null || true
    echo "SUCCESS: Ruleset applied atomically and verified against the live kernel"
else
    echo "WARNING: Ruleset applied, but the live kernel policy could not be verified - sync sidecar not written" >&2
fi
```

Pitfalls to respect (the project has been burned by these before):

- The script runs with `set -euo pipefail`: never build pipelines like `cmd | head` inside `$(...)` (SIGPIPE + `pipefail` aborts the script); guard the `nft list` call with `|| true` and parse via `printf | sed` as above. Use plain `sed` without `| head` for the single-match marker extractions.
- Keep the ruleset loaded even when verification fails: write no sidecar and still exit 0 (the apply itself succeeded; drift is reported by the verifier instead).
- No new helper action → no sudoers change.

### 4.3 Verifier (`Modules\Security\Services\FirewallSyncVerifier` + `Modules\Security\Support\FirewallSyncStatus`)

- `FirewallSyncStatus` (readonly value object): `state` (`in_sync` | `drift` | `unknown`), `desiredDigest`, `appliedDigest`, `appliedPolicy`, `appliedAt`, `issues` (string[]); small factories (`inSync()`, `drift()`, `unknown()`), `isInSync()`, `isDrift()`.
- `FirewallSyncVerifier::__construct(SecurityConfigGenerator $generator, ?string $firewallDir = null)` — dir defaults to `/etc/tallpbx`; tests pass a temp dir. No container binding needed (auto-resolves).
- `verify(): FirewallSyncStatus`:
  1. Read `{dir}/firewall.nft.applied`; missing ⇒ `unknown` ("no verified apply record yet"); unreadable/malformed (digest not `sha256:[0-9a-f]{64}`, policy not `drop|accept`, `applied_at` missing) ⇒ `unknown`.
  2. Compute `$generator->canonicalDigest()` inside try/catch (`\Throwable` ⇒ `unknown` with the exception message — the database may be uncompilable).
  3. Digest equal ⇒ `in_sync`; otherwise ⇒ `drift`.

### 4.4 CLI `security:verify` + schedule

- New `Modules\Security\Console\Commands\SecurityVerifyCommand`, styled exactly like `SecurityStatusCommand` (same docblock/comment density), `handle(FirewallSyncVerifier $verifier)`.
- Output: a table (State / Applied at / Applied policy / Desired digest / Applied digest) plus issue lines.
- Exit codes: `in_sync` ⇒ SUCCESS; `unknown` ⇒ SUCCESS with a warning line (must not fail on hosts where the helper is not yet deployed); `drift` ⇒ `Log::warning('Firewall sync drift detected', [...])` + FAILURE.
- Register the command in `Modules\Security\Providers\ModuleServiceProvider` wherever `SecurityApplyCommand` / `SecurityStatusCommand` are registered (see the imports at the top of that provider).
- Schedule in `routes/console.php`: `Schedule::command('security:verify')->everyFiveMinutes()->withoutOverlapping();` (matches the existing every-five-minutes convention).

### 4.5 UI: sync indicator + banner extension

`SecurityManager`:

- New public properties: `public ?string $firewallSyncState = null;` and `public ?string $firewallSyncAppliedAt = null;` (humanized string for display).
- New private `refreshFirewallSyncState()`: resolve `app(FirewallSyncVerifier::class)`, call `verify()`, store `state` and `Carbon::parse($status->appliedAt)->diffForHumans()` when present; catch `\Throwable` ⇒ both null.
- Call it from `mount()` and from `refreshStatus()` (the existing merged listener — Livewire 4 maps one handler per event name, so it rides along there), and after a successful apply inside `autoApplyFirewallRuleset()` (the sidecar is fresh at that point).

`security-manager.blade.php`:

- Drift banner condition (header area): extend to `|| $firewallSyncState === 'drift'`. Body branch order: `liveFirewallPolicy === 'absent'` ⇒ not-loaded body; policy mismatch ⇒ existing body; **otherwise ⇒ new content-drift body**.
- Firewall Status card: add a third line under the policy text — success "sync verified" (with applied-at), error short "out of sync" line, or muted "unverified" text.

New language keys (en + es + fr, placed next to `security_drift_*` in each `lang/<locale>/admin.php`): `security_sync_verified`, `security_sync_drift`, `security_sync_unverified`, `security_drift_content_body`.

## 5. Tests (TDD — red first, then implement)

- **`SecurityConfigGeneratorTest`** additions:
  - generated ruleset contains `# tallpbx-policy: drop` and a `sha256:` digest marker; `canonicalDigest()` equals the marker value;
  - digest is stable while ban timeouts decay (create a ban, generate, mutate its `expires_at` closer, generate again ⇒ same digest);
  - digest changes when set membership changes, when a rule/port/service changes, and when the default policy changes.
- **`SecurityExecutorTest`** additions (stub nft pattern):
  - stub script honors: `-c` / `-f` ⇒ exit 0; `list chain` ⇒ cat a fixture file path from an env var;
  - apply with matching chain-policy fixture ⇒ exit 0, sidecar exists with `digest=`, `policy=`, `applied_at=`, pending file consumed;
  - apply with mismatching chain-policy fixture ⇒ exit 0, **no** sidecar, stderr contains `WARNING`;
  - apply with a missing digest marker ⇒ no sidecar (conservative).
- **New `FirewallSyncVerifierTest`**: `in_sync` (sidecar digest built from the real `canonicalDigest()`, temp dir), `drift` (wrong digest), `unknown` (missing file / malformed digest / malformed policy).
- **New `SecurityVerifyCommandTest`**: exit codes + output per state; `Log::spy()` asserts the drift warning.
- **`SecurityManagerLivewireTest`** additions: bind a Mockery fake `FirewallSyncVerifier` in `beforeEach` defaulting to `unknown` (keeps the existing 35 tests untouched and the suite hermetic — the real verifier would otherwise read the live `/etc/tallpbx`); then tests for the verified indicator, the content-drift banner (+ Re-apply button), and no banner when in sync.
- Affected-suite run (relevant files only, parallel):

  ```bash
  php artisan test --compact --parallel \
    tests/Feature/Modules/Security/SecurityConfigGeneratorTest.php \
    tests/Feature/Modules/Security/SecurityExecutorTest.php \
    tests/Feature/Modules/Security/FirewallSyncVerifierTest.php \
    tests/Feature/Modules/Security/SecurityVerifyCommandTest.php \
    tests/Feature/Modules/Security/SecurityManagerLivewireTest.php
  ```

## 6. Decisions Taken

- Canonicalization = strip comments/blanks + normalize and sort set elements; everything else stays order-sensitive.
- Sidecar format `digest=` / `policy=` / `applied_at=` (UTC ISO-8601), mode `0640 root:www-data`, written by the helper only.
- Sidecar written only when declared policy == live policy; otherwise no sidecar + stderr warning (conservative drift).
- `security:verify` cadence: every five minutes; drift ⇒ exit 1 + `Log::warning`; unknown ⇒ exit 0.
- Alerts are passive in Phase 2 (log + UI indicator). No realtime broadcast/email on drift yet — reconsider in Phase 3.
- `TALLPBX_NFT_BIN` test override added under the same security rationale as `TALLPBX_FIREWALL_CONF_DIR`.

## 7. Known Limitations (carry into documentation)

- The sidecar proves "what was last loaded", not the current RAM state. External kernel edits to non-policy content are not detected by the verifier (the live policy anchor is still checked separately on every page load).
- A hand-edited `firewall.nft` loaded manually at boot does not update the sidecar — the indicator conservatively reports drift until the next panel apply. Safe direction.
- The live-policy anchor parsing stays pinned by tests (it depends on nft output formatting).
- Until the updated helper is deployed to `/usr/local/sbin/tallpbx-security`, every verify result is `unknown` (indicator shows "unverified").

## 8. Implementation Checklist (fresh session order)

1. Generator: markers + `canonicalForm()` + `canonicalDigest()` with red-first tests.
2. Helper: `NFT_BIN` override + apply verification + sidecar with red-first tests (stubbed nft).
3. `FirewallSyncStatus` + `FirewallSyncVerifier` with red-first tests.
4. `security:verify` command + provider registration + scheduler entry with red-first tests.
5. UI: properties + `refreshFirewallSyncState()` + banner/card + lang keys (en/es/fr) with Livewire test additions.
6. `php artisan optimize:clear`; run the affected suites (command in §5).
7. `vendor/bin/pint --dirty --format agent`; Dusk security smoke: `bash scripts/dusk.sh --filter='renders the security manager dashboard'`.
8. Docs: update the "Live sync guarantee" blockquote in `docs/security-architecture.md`; add the CHANGELOG `[Unreleased]` entry.
9. Deploy the helper on the live host: copy `scripts/resources/tallpbx-security` to `/usr/local/sbin/tallpbx-security`, `chown root:www-data`, `chmod 0750`. The first sidecar appears on the next apply.
10. Live verification: trigger one apply from the panel, run `php artisan security:verify` (expect in sync), and inspect `/etc/tallpbx/firewall.nft.applied`.

## 9. Rollout Notes

- No migrations and no installer changes required; re-running the installer also refreshes the deployed helper.
- Existing hosts keep working without the new helper — the indicator simply shows "unverified" until the helper is deployed and the next apply runs.
- Do not commit or push without the user's explicit approval (standing project rule).
