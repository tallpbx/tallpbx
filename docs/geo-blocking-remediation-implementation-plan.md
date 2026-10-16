# Geo-Blocking Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix every open finding from the 2026-10-16 code review of `dfda2e56` (v4.0.1 Geo-Blocking subsystem): two High (drop-all window in whitelist mode; broken granular permission), four Medium (i18n regressions, partial-sync fail-open violation, unimplemented automation claim, unlocked shared file writes), and the Low items (stale docs, missing negative-permission tests, pre-filter lockout edge).

**Architecture:** Safety-first reordering of the Livewire concern (`ManagesGeoBlocking`): zone sets are populated and promoted into the kernel *before* the `geo_perimeter` chain is armed, with rollback of persisted state when any step fails. The permission model gains a pass-through so `security.geo-blocking.manage` authorizes the firewall-apply path for geo actions without granting blanket `security.edit`. Cross-process file writes to the shared `external_blocklist.nft.pending` gain an flock protocol, and the geo merge cuts on a marker instead of set names.

**Tech Stack:** Laravel 13, Livewire 4, Pest 3 (+ `--parallel`), nftables via the bounded `/usr/local/sbin/tallpbx-security` helper only.

**Spec:** The findings list below (from the 2026-10-16 review of `21433daf..dfda2e56`). The prior report file was deleted per the report-lifecycle convention, so this plan carries the findings.

## Findings Being Fixed (Spec)

| ID | Severity | Summary |
| --- | --- | --- |
| H1 | High | `setGeoEnabled(true)`/`saveGeoSettings()` apply the ruleset (arming `ip saddr != @geo_allowed_ips drop`) **before** `GeoBlockingService::sync()` populates the sets — drop-all window in whitelist mode; no rollback on sync failure |
| H2 | High | `security.geo-blocking.manage` is unusable: geo actions pass the trait check, then `autoApplyFirewallRuleset()` → `ensureEditPermission()` aborts 403 *after* state was persisted (DB↔kernel drift) |
| M1 | Medium | Key `security_rate_burst_packets` deleted from module dictionaries but still used by `edit-system-service.blade.php:68` — renders raw key |
| M2 | Medium | Key `admin.never` used by `geo-blocking.blade.php:213` — never existed; renders raw key |
| M3 | Medium | Partial geo sync reports `success`: in whitelist mode a shrunken allow-set silently drops the missing countries; `file_put_contents` cache writes unchecked |
| M4 | Medium | Changelog claims automated background sync; `security:sync-geoip` is scheduled nowhere; `syncGeoIpNow()` + `security_geo_sync_now`/`security_geo_syncing` keys are dead code |
| M5 | Medium | `external_blocklist.nft.pending` written read-modify-write without locking by both web geo actions and the hourly threat-feed sync (lost updates); one stale geo header comment accumulates per merge |
| L1 | Low | `docs/security-architecture.md:252` and `docs/ui-tour.md:166` still say Geo-Blocking is "planned"; `docs/plan-external-blocklists-and-geoblocking.md` is a completed plan |
| L3 | Low | No negative-permission tests for the geo subsystem (would have caught H2) |
| L4 | Low | Whitelist-mode enablement with pre-filters disarmed can lock out the trusted admin (geo drop at stage 9.5 precedes the web-port accept when the pre-filter whitelist accept is absent) |
| Info | Info | `geo_blocking_enabled` audit record written before the sync outcome is known |

Not planned (documented rationale): migration filename `2026_10_16_000002` suffix (harmless, order is timestamp-governed); `DatabaseSeederTest` host-tree teardown parallel caveat (serial runs unaffected; a `setBasePath` sandbox is a separate refactor).

## Global Constraints

- `declare(strict_types=1)`, native type hints, PHPDoc on every touched class/method; PSR-12/PER via `./vendor/bin/pint --dirty` (PHP files only).
- Pest only; targeted tests with `--parallel` (`php artisan test --compact --parallel --filter=<Name>`); never the full suite during development.
- `php artisan optimize:clear` after every task that changes PHP, Blade, config, routes, or lang files.
- NEVER mutate live nftables state; all kernel changes flow through `SecurityExecutorInterface` → bounded helper. Verification of generated nft syntax uses mocks/temp dirs only.
- All new/changed UI strings need en/es/fr parity (module dictionaries for `security_*`, root `lang/<locale>/admin.php` otherwise).
- No commit without the user's approved execution of this plan; conventional commits, one task = one commit.
- Do not touch the released `## [4.0.1]` changelog section — Task 5 makes its automation claim retroactively true; all changelog edits go to `[Unreleased]`.
- Tests follow the permission convention: `module:sync --only-local` + `AdminSeeder` + Super Administrators group attach (already in `SecurityManagerGeoBlockingTest::beforeEach`).

## Review Focus

Failure modes the task tests must pin (owner task in parentheses):

1. **Whitelist enable with zero countries selected** — must refuse with a clear error, never silently arm a drop-everything perimeter (Task 3).
2. **Admin holding only `security.geo-blocking.manage`** — every geo action must complete without 403 and without partial writes; admin with neither permission gets 403 before any DB write (Task 2).
3. **Provider/network failure mid-enable** — enablement aborts with persisted state rolled back; kernel never runs a geo chain with empty allow-set (Task 3).
4. **Unwritable `/etc/tallpbx/geoip` cache dir** — sync surfaces a failure instead of silently proceeding with an empty set (Task 3).
5. **Concurrent UI geo toggle + hourly threat-feed sync** — both writers of `external_blocklist.nft.pending` hold the same flock; repeated merges leave exactly one geo header (Tasks 6).

---

### Task 1: Restore and add missing translation keys (M1, M2)

**Files:**
- Modify: `app-modules/security/lang/en/admin.php`, `app-modules/security/lang/es/admin.php`, `app-modules/security/lang/fr/admin.php` (re-add near the deleted position ~L392)
- Modify: `lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php` (root, add `never`)
- Test: `app-modules/security/tests/SecurityLocalizationKeysTest.php` (create)

**Interfaces:**
- Consumes: none.
- Produces: dictionary keys `security_rate_burst_packets` (module) and `never` (root), all three locales.

- [ ] **Step 1: Write the failing test**

Create `SecurityLocalizationKeysTest.php` (namespace `Tests\Feature\Modules\Security`, standard `beforeEach` with `module:sync --only-local` + `AdminSeeder` — copy the pattern from `SecurityManagerGeoBlockingTest`). Test `geo_and_service_modal_keys_resolve_in_all_locales` iterates `['en', 'es', 'fr']`, calls `app()->setLocale($locale)`, and asserts `__('admin.security_rate_burst_packets') !== 'admin.security_rate_burst_packets'` and `__('admin.never') !== 'admin.never'`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=SecurityLocalizationKeysTest`
Expected: FAIL — both keys resolve to their raw names.

- [ ] **Step 3: Add the keys**

Module dictionaries (identical position in each locale file, alphabetical order as the file uses): `'security_rate_burst_packets' => 'Burst Allowance (packets)'` / `'Tolerancia de ráfaga (paquetes)'` / `'Tolérance de rafale (paquetes)'` (values recovered from `git show 21433daf:app-modules/security/lang/<locale>/admin.php` L392). Root `admin.php` in each locale: `'never' => 'Never'` / `'Nunca'` / `'Jamais'`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=SecurityLocalizationKeysTest` → PASS. Also run `php artisan test --compact --parallel --filter=TranslationParityTest` → PASS.

- [ ] **Step 5: Commit**

`git add app-modules/security/lang lang && git commit -m "fix(security): restore deleted rate-limit burst label and add missing never translation key"`

### Task 2: Make the granular geo permission self-sufficient (H2, L3)

**Files:**
- Modify: `app-modules/security/src/Livewire/SecurityManager.php:227-235` (`ensureEditPermission`) and `:303-311` (`autoApplyFirewallRuleset` signature)
- Modify: `app-modules/security/src/Livewire/Concerns/ManagesGeoBlocking.php:236-239` (`regenerateAndApplyFirewall`)
- Test: `app-modules/security/tests/SecurityManagerGeoBlockingTest.php` (extend)

**Interfaces:**
- Consumes: `$actor->hasPermission(string $permission): bool` (existing on Admin).
- Produces: `protected function ensureAnyPermission(string ...$permissions): void` on `SecurityManager` (aborts 403 unless the actor holds at least one); `public function autoApplyFirewallRuleset(?LockoutGuardService $lockoutGuard = null, ?SecurityConfigGenerator $generator = null, ?SecurityExecutorInterface $executor = null, bool $force = false, ?array $acceptedPermissions = null): bool` — `$acceptedPermissions === null` keeps today's `security.edit` behavior, non-null delegates to `ensureAnyPermission(...$acceptedPermissions)`.

- [ ] **Step 1: Write the failing tests** (in `SecurityManagerGeoBlockingTest`)

`admin_with_only_geo_permission_can_toggle_geo_without_403`: create a second admin attached to a group granted only `Permission::where('name', 'security.geo-blocking.manage')`; seed a `SecurityGeoRule` with `mode: 'blacklist'`, `countries: ['US']`; act as that admin, `call('setGeoEnabled', true)`; assert no `AuthorizationException` and `$rule->fresh()->enabled === true` (executor/generator mocks from `beforeEach` already fake the apply pipeline).
`admin_without_geo_permissions_gets_403_before_any_state_change`: admin in a group with no security permissions, rule starts disabled; expect 403 and `$rule->fresh()->enabled === false`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter=SecurityManagerGeoBlockingTest`
Expected: first test FAILS with `AuthorizationException` (from `ensureEditPermission` inside `autoApplyFirewallRuleset`, after `enabled=true` was already saved); second may already pass — keep it as the L3 regression pin.

- [ ] **Step 3: Implement the permission pass-through**

Add `ensureAnyPermission(string ...$permissions): void` next to `ensureEditPermission()` (which becomes a one-line delegate). Add the trailing `?array $acceptedPermissions = null` parameter to `autoApplyFirewallRuleset()`; authorize with it before the lockout preflight. In the trait's `regenerateAndApplyFirewall()` pass `acceptedPermissions: ['security.geo-blocking.manage', 'security.edit']`. PHPDoc every new/changed method.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter=SecurityManagerGeoBlockingTest` → PASS. Run `php artisan test --compact --parallel --filter=SecurityManagerAuthorizationTest` → PASS (existing callers keep default behavior).

- [ ] **Step 5: Commit**

`git add app-modules/security/src app-modules/security/tests && git commit -m "fix(security): make granular geo-blocking permission self-sufficient for firewall applies"`

### Task 3: Sync-before-arm reordering, strict whitelist syncs, audit-after-outcome (H1, M3, Info)

**Files:**
- Modify: `app-modules/security/src/Livewire/Concerns/ManagesGeoBlocking.php:46-152` (`setGeoEnabled`, `saveGeoSettings`)
- Modify: `app-modules/security/src/Services/GeoBlockingService.php:41-155` (`sync`) and `:172-204` (`compileSetElementFile`)
- Modify: module dictionaries ×3 — new key `security_geo_whitelist_countries_required`
- Test: `app-modules/security/tests/SecurityManagerGeoBlockingTest.php`, `app-modules/security/tests/GeoBlockingServiceTest.php` (extend both)

**Interfaces:**
- Consumes: Task 2's `regenerateAndApplyFirewall()` pass-through; `GeoBlockingService::sync(?SecurityGeoRule $rule = null, bool $force = false): array{status: string, v4_count: int, v6_count: int, error: ?string}` (shape unchanged, semantics tightened).
- Produces: `sync()` failure semantics — status `failed` (a) as today when all downloads fail with no cache, or (b) **whitelist mode: any selected country yielded no entries**, or (c) a cache `file_put_contents` returned false. On failure `sync()` must NOT call `compileSetElementFile()`/`updateExternalBlocklist()`, must not update `v4_cidrs_count`/`v6_cidrs_count`/`last_sync_at`, and records only `last_status: 'failed'` + `last_error`. `compileSetElementFile()` no longer gates element emission on `$rule->enabled` (populated-but-unreferenced sets are harmless; `clear()` passes empty arrays and still emits only flushes).

- [ ] **Step 1: Write the failing tests**

`GeoBlockingServiceTest` — `whitelist_sync_fails_when_any_country_yields_no_entries`: provider mock returns empty `v4`/`v6` for one of two countries, no cache files; assert `sync()` returns `failed`, and the geo pending file was not written (or contains no `add element` lines). `sync_reports_cache_write_failure`: make the temp cache dir read-only (`chmod 0500`); assert `failed` with an error mentioning the cache write; restore permissions in teardown.
`SecurityManagerGeoBlockingTest` — `enabling_whitelist_geo_populates_sets_before_applying_ruleset`: mode whitelist, countries `['US']`; use Mockery `ordered()` so `updateExternalBlocklist` must be invoked before the executor `apply` pathway, and inside the `updateExternalBlocklist` mock closure assert the temp geo pending file already contains `add element inet tallpbx_filter geo_allowed_ips`. `enabling_whitelist_geo_with_failed_sync_leaves_rule_disabled`: provider returns empty; assert rule stays disabled and an error toast was dispatched (assert `dispatchedBrowserEvent`/session flash per the component's `notifyError` mechanism). `enabling_whitelist_geo_with_no_countries_is_refused`: countries `[]`; assert refusal with the new key's message and rule stays disabled.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter="GeoBlockingServiceTest|SecurityManagerGeoBlockingTest"`
Expected: all new tests FAIL against current apply-before-sync ordering.

- [ ] **Step 3: Implement**

`setGeoEnabled(true)` order: permission → lockout guard → (whitelist) refuse when `geoCountries` empty with `security_geo_whitelist_countries_required` → `sync($rule, force: true)` while `$rule->enabled` is still false → whitelist mode aborts on `failed` (persist nothing; notify) / blacklist mode tolerates `failed` (fail-open, notify warning, still proceed) → persist `enabled=true` → `regenerateAndApplyFirewall()` with rollback of `enabled` on failure → audit `geo_blocking_enabled` **after** the outcome including `sync: {$result['status']}` in the description. Disable path unchanged except audit stays last.
`saveGeoSettings()` order: permission → validate → lockout guard (whitelist) → snapshot previous `mode/countries` → persist → if enabled: `sync($rule, force: false)`; on whitelist-mode `failed` roll back the snapshot, notify, return → `regenerateAndApplyFirewall()` with the same rollback on failure → audit + notify.
`GeoBlockingService::sync()`: track per-country failures; check both `file_put_contents` returns (`false` → error `Failed to write zone cache for {$cc}`); apply the Produces failure semantics; gate element emission removal in `compileSetElementFile()`.
Add `security_geo_whitelist_countries_required` to the three module dictionaries (en: `Select at least one country before enabling Block All Except Selected.`; es/fr translations mirroring the existing dictionary tone for the mode label).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter="GeoBlockingServiceTest|SecurityManagerGeoBlockingTest|SecurityConfigGeneratorGeoTest"` → PASS.

- [ ] **Step 5: Commit**

`git add app-modules/security && git commit -m "fix(security): populate geo sets before arming perimeter and fail closed on partial whitelist syncs"`

### Task 4: Refuse whitelist enablement while pre-filters are disarmed (L4)

**Files:**
- Modify: `app-modules/security/src/Livewire/Concerns/ManagesGeoBlocking.php` (whitelist branches of `setGeoEnabled`/`saveGeoSettings`)
- Modify: module dictionaries ×3 — new key `security_geo_prefilter_required`
- Test: `app-modules/security/tests/SecurityManagerGeoBlockingTest.php` (extend)

**Interfaces:**
- Consumes: `SecuritySetting::getBoolean('prefilter_enabled', true)` (existing key, confirmed).
- Produces: refusal branch only — no signature changes.

- [ ] **Step 1: Write the failing test**

`enabling_whitelist_geo_with_prefilters_disarmed_is_refused`: `SecuritySetting::updateOrCreate(['key' => 'prefilter_enabled'], ['value' => '0'])`; mode whitelist + countries `['US']`; `call('setGeoEnabled', true)`; assert error toast with the new key and rule stays disabled. Same guard asserted for `saveGeoSettings` switching an enabled rule into whitelist mode.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=SecurityManagerGeoBlockingTest` → FAIL (currently enables).

- [ ] **Step 3: Implement**

In both whitelist branches (next to the existing `isWhitelisted` refusal), add: if `! SecuritySetting::getBoolean('prefilter_enabled', true)` → `notifyError(__(...security_geo_prefilter_required))` and return, with an inline comment explaining the stage-9.5-vs-stage-10 lockout mechanics. Add the key to the three dictionaries (en: `Built-in pre-filters must be enabled before using Block All Except Selected, otherwise this administrator could be locked out.`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --parallel --filter=SecurityManagerGeoBlockingTest` → PASS.

- [ ] **Step 5: Commit**

`git add app-modules/security && git commit -m "fix(security): refuse whitelist geo enablement while pre-filters are disarmed"`

### Task 5: Schedule the geo-ip sync and remove the dead manual action (M4)

**Files:**
- Modify: `routes/console.php:79-83` (new entry next to `security:sync-threat-feeds`)
- Modify: `app-modules/security/src/Livewire/Concerns/ManagesGeoBlocking.php:171-193` (delete `syncGeoIpNow()`)
- Modify: module dictionaries ×3 (delete `security_geo_sync_now`, `security_geo_syncing`)
- Test: `tests/Feature/Console/ScheduleRegistrationTest.php` (create; create the `Console` directory if absent)

**Interfaces:**
- Consumes: existing `security:sync-geoip` command (self-guarding: skips disabled/empty rules).
- Produces: weekly schedule entry; no API changes.

- [ ] **Step 1: Write the failing test**

`geo_ip_sync_is_scheduled_weekly`: run `artisan('schedule:list')`, capture output, assert it contains `security:sync-geoip` and `weekly` (the command itself skips disabled rules, so scheduling weekly is safe for all installs).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --parallel --filter=ScheduleRegistrationTest` → FAIL (no entry).

- [ ] **Step 3: Implement**

In `routes/console.php` after the threat-feeds entry add `Schedule::command('security:sync-geoip')->weekly()->withoutOverlapping();` with a one-line comment matching the neighboring style (weekly aligns with the default `sync_interval`; the command honours its own state checks). Delete `syncGeoIpNow()` and the two dead keys from all three dictionaries; verify with `grep -rn "security_geo_sync_now\|security_geo_syncing\|syncGeoIpNow" app-modules lang` → no matches. Leave the `[4.0.1]` changelog section untouched — this task makes its automation claim true.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter="ScheduleRegistrationTest|SecurityCommandsTest|TranslationParityTest"` → PASS.

- [ ] **Step 5: Commit**

`git add routes/console.php app-modules/security lang && git commit -m "fix(security): schedule weekly geo-ip zone sync and remove dead manual sync action"`

### Task 6: Lock the shared pending file and stop geo header accumulation (M5)

**Files:**
- Modify: `app-modules/security/src/Services/GeoBlockingService.php:200-242` (`compileSetElementFile` write + `mergeWithExternalBlocklistPending`)
- Modify: `app-modules/security/src/Services/ThreatFeedIngestionService.php:185-220` (`compileSetElementFile`)
- Test: `app-modules/security/tests/GeoBlockingServiceTest.php` (extend)

**Interfaces:**
- Consumes: none new.
- Produces: internal write protocol only — no public signature changes. Both writers of `external_blocklist.nft.pending` use: `fopen($path, 'c+')` → `flock($fp, LOCK_EX)` → read/rewrite via `ftruncate($fp, 0)` + `rewind($fp)` + `fwrite(...)` → `flock($fp, LOCK_UN)` + `fclose($fp)`. `geo_blocking.nft.pending` is written atomically (write `{$target}.tmp` then `rename()`).

- [ ] **Step 1: Write the failing tests**

`repeated_merges_leave_exactly_one_geo_header`: write an `external_blocklist.nft.pending` fixture containing an external header/flush/add plus a stale geo block (header + flush + add) and a second stale geo header line; call `compileSetElementFile()` twice via the service; assert the merged file contains exactly one occurrence of `# TallPBX Geo-Blocking kernel elements` and retains the external `add element` line.
`threat_feed_compile_preserves_geo_elements`: in `GeoBlockingServiceTest` (or `SecurityThreatFeedTest` where the ingestion harness lives) run the ingestion compile with a geo pending fixture present; assert the written external pending contains both the external and geo `add element` lines and one geo header.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --parallel --filter="GeoBlockingServiceTest|SecurityThreatFeedTest"` → FAIL (current per-line filter keeps stale headers).

- [ ] **Step 3: Implement**

In `mergeWithExternalBlocklistPending()`: hold the flock for the whole read-filter-write; replace the per-line `str_contains` filter with a marker cut — drop everything from the first line starting with `# TallPBX Geo-Blocking kernel elements` to the end (the geo block is always the trailing block), then append the fresh geo content. In the service's `compileSetElementFile()`: write `geo_blocking.nft.pending` via tmp+rename. In `ThreatFeedIngestionService::compileSetElementFile()`: perform the final `external_blocklist.nft.pending` write under the same flock protocol. Inline comments must state why the lock exists (hourly root-context feed sync vs web-context geo toggles).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --parallel --filter="GeoBlockingServiceTest|SecurityThreatFeedTest|SecuritySyncThreatFeedsCommandTest"` → PASS.

- [ ] **Step 5: Commit**

`git add app-modules/security && git commit -m "fix(security): lock shared blocklist pending file and stop geo header accumulation"`

### Task 7: Documentation truth-up and changelog (L1, Unreleased entries)

**Files:**
- Modify: `docs/security-architecture.md:252`, `docs/ui-tour.md:166`
- Delete: `docs/plan-external-blocklists-and-geoblocking.md` (completed-plan cleanup convention; remove inbound references when updating the two lines above)
- Modify: `CHANGELOG.md` `[Unreleased]` only

**Interfaces:** none.

- [ ] **Step 1: Update the two stale lines**

`security-architecture.md:252`: replace "with dedicated IPdeny-based Geo-Blocking planned as a standalone tab; see `docs/plan-external-blocklists-and-geoblocking.md`" with shipped wording ("a dedicated Geo-Blocking tab ships in the Security Command Center — pure-drop `geo_perimeter` subchain, IPdeny zones with GitHub-mirror fallback, weekly `security:sync-geoip` refresh"). `ui-tour.md:166`: replace "with dedicated Geo-Blocking planned as a standalone tab" with a one-line shipped-feature mention pointing at the Geo-Blocking tab. Sweep `grep -rn "plan-external-blocklists-and-geoblocking" docs *.md` → no remaining references, then delete the plan doc (`git rm`).

- [ ] **Step 2: Write `[Unreleased]` changelog entries**

Under `### Fixed` in `[Unreleased]`, plain-English entries for H1/H2/M1–M5/L4 (admin perspective, e.g. "Enabling Block All Except Selected now downloads the country allow-set before the firewall rule takes effect, so traffic is never interrupted during the download"), plus a `### Changed` note that Geo-IP zones now refresh weekly via scheduler. Note the `.env`/migration implications: none (no new migration; `/etc/tallpbx/geoip/` already existed).

- [ ] **Step 3: Verify docs guard tests**

Run: `php artisan test --compact --parallel --filter="LocalizationTest|TranslationParityTest"` → PASS (INSTALL.md untouched, parity intact).

- [ ] **Step 4: Commit**

`git add docs CHANGELOG.md && git commit -m "docs: align geo-blocking documentation with shipped subsystem and update changelog"`

### Task 8: Verification gate (no commit)

- [ ] `php artisan optimize:clear` — boots cleanly.
- [ ] `./vendor/bin/pint --dirty --test` — clean (only if PHP files changed; Tasks 2–6 always).
- [ ] Targeted suite: `php artisan test --compact --parallel --filter="SecurityManagerGeoBlockingTest|GeoBlockingServiceTest|SecurityLocalizationKeysTest|ScheduleRegistrationTest|SecurityCommandsTest|SecurityThreatFeedTest|TranslationParityTest|LocalizationTest|SecurityManagerAuthorizationTest"` — all PASS.
- [ ] `php artisan route:list --path=panel` still resolves; `php artisan schedule:list` shows `security:sync-geoip` weekly.
- [ ] Working tree clean after commits; report results to the user. Release tagging (suggested `v4.0.2`, fixes-only) is a separate, user-approved step.

---

## Post-Execution Cleanup

This plan follows the documentation lifecycle convention: once every task is verified and the user accepts the work, delete `docs/geo-blocking-remediation-implementation-plan.md` (untracked-style cleanup, same as completed plans).
