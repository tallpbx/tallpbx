# Commit Evaluation Report: 2026-11-02 (`5.x`)

Authoritative review of every Git commit made on **2026-11-02** to the active `5.x` series branch, evaluated against the project conventions in [`AGENTS.md`](../AGENTS.md). Use this record to confirm what shipped, what it affects, and which follow-up actions are still outstanding.

> [!NOTE]
> Scope: 6 commits, author `tallpbx <tallpbx@gmail.com>`, all pushed to `origin/5.x` at review time. Working tree was clean and no release tags were created (correct per the tagging policy — tags are reserved for official releases only).

## Contents

- [1. Commit Summary](#1-commit-summary)
- [2. Change Overview](#2-change-overview)
  - [Aggregate Statistics](#aggregate-statistics)
  - [Files Added](#files-added)
  - [Files Deleted](#files-deleted)
  - [Files Modified](#files-modified)
- [3. Impact Analysis](#3-impact-analysis)
- [4. Consistency Check](#4-consistency-check)
- [5. Risk Assessment](#5-risk-assessment)
- [6. Recommendations](#6-recommendations)
- [7. Changelog-Compatible Classification](#7-changelog-compatible-classification)
- [8. Verification Evidence](#8-verification-evidence)

---

## 1. Commit Summary

Listed in chronological order. Times are the author's local timestamps (`-0800`).

| # | Hash | Timestamp | Author | Message |
| :---: | :--- | :--- | :--- | :--- |
| 1 | `34842cd37b5f` | 2026-11-02 00:22:24 | tallpbx `<tallpbx@gmail.com>` | `docs(octane): record load benchmark results and update unreleased changelog` |
| 2 | `3c54287c8508` | 2026-11-02 00:42:43 | tallpbx `<tallpbx@gmail.com>` | `fix(octane): add roadrunner binary provisioning, dual xml_curl fallback binding, and style polish` |
| 3 | `0331ce138466` | 2026-11-02 05:20:21 | tallpbx `<tallpbx@gmail.com>` | `docs: add telephony capacity scaling guide and head-to-head benchmark results` |
| 4 | `2bc2d3d92174` | 2026-11-02 17:19:25 | tallpbx `<tallpbx@gmail.com>` | `docs: consolidate architecture and performance documentation` |
| 5 | `bad8cbb507a8` | 2026-11-02 17:40:34 | tallpbx `<tallpbx@gmail.com>` | `docs(load-test): harden refresh plan with two-host model, generator neutrality, and runtime fixes` |
| 6 | `8cd0d3c03f33` | 2026-11-02 21:38:42 | tallpbx `<tallpbx@gmail.com>` | `feat(telephony): add ESL event filtering, minimal logging defaults, and Reverb debouncing` (HEAD → `5.x`) |

**Commit message quality.** Conventional-commit prefixes (`docs:`, `fix:`, `feat:`) are used consistently. Only commits `2bc2d3d` and `8cd0d3c` carry explanatory bodies; the remaining four are subject-only, including `3c54287`, which changes FreeSWITCH runtime configuration and deserved a rationale. Subject lines run 77–97 characters, above the conventional 72-character guideline; no commit-message hook enforces this in the repository.

---

## 2. Change Overview

### Aggregate Statistics

| Metric | Value |
| :--- | ---: |
| Commits | 6 |
| Files touched (net) | 32 |
| Insertions (net) | +1,477 |
| Deletions (net) | −3,435 |
| Added | 2 |
| Deleted | 8 |
| Modified | 22 |
| Database migrations | 0 |
| Firewall / `nftables` changes | 0 |
| UI / Blade / Livewire markup changes | 0 |

> [!IMPORTANT]
> Per-commit insertion counts sum to +1,667, which is higher than the net +1,477. The difference is intentional: `docs/superpowers/plans/octane-measurement-results.md` was created in `34842cd` (+114 lines) and retired again in `2bc2d3d`, so its content nets to zero across the day while remaining recoverable from Git history.

### Files Added

| File | Introduced in | Size |
| :--- | :--- | ---: |
| `docs/telephony-capacity-scaling-guide.md` | `0331ce1` | 198 lines |
| `docs/load-testing-refresh-implementation-plan.md` | `2bc2d3d` | 494 lines |

### Files Deleted

All eight retirements belong to `2bc2d3d` (docs consolidation). No application code, test fixture, or operational script was deleted.

| File | Removed lines | Content disposition |
| :--- | ---: | :--- |
| `docs/latest-implementation-plan.md` | 704 | Findings folded into `docs/architecture.md` §7.5.4 and §17 |
| `docs/lua-proxy-measurement-results.md` | 218 | Figures merged into `docs/load-testing-results.md` |
| `docs/octane-performance-improvement-plan.md` | 426 | Superseded by the shipped Octane worker design (architecture §7.5.7) |
| `docs/xml-handler-performance-comparison.md` | 153 | Four-way comparison merged into `docs/load-testing-results.md` |
| `docs/superpowers/plans/2026-10-31-load-test-app-side-metrics.md` | 392 | Implemented work, retired |
| `docs/superpowers/plans/2026-10-31-lua-redis-proxy.md` | 369 | First-draft proxy, superseded before implementation |
| `docs/superpowers/plans/2026-10-31-lua-redis-proxy-v2.md` | 770 | Superseded by the shipped design |
| `docs/superpowers/plans/implementation-plan-review.md` | 365 | Live findings merged into architecture; review retired |

A repository-wide scan confirmed **no dangling references** to any retired file in `AGENTS.md`, `README.md`, `CHANGELOG.md`, or surviving documentation.

### Files Modified

| File | Commits | Category |
| :--- | :--- | :--- |
| `app/Console/Commands/FreeSwitchListenCommand.php` | `8cd0d3c` | Application code |
| `app/Services/FreeSwitchService.php` | `8cd0d3c` | Application code |
| `app/Services/FreeSwitchServiceInterface.php` | `8cd0d3c` | Application code |
| `app/Listeners/BroadcastDashboardStatsOnFreeSwitchEvent.php` | `8cd0d3c` | Application code |
| `app/Listeners/BroadcastTelephonyOnFreeSwitchEvent.php` | `8cd0d3c` | Application code |
| `config/freeswitch.php` | `8cd0d3c` | Configuration |
| `routes/console.php` | `8cd0d3c` | Routing / console |
| `.env.example` | `8cd0d3c` | Configuration |
| `phpunit.xml` | `8cd0d3c` | Test configuration |
| `scripts/resources/freeswitch.sh` | `3c54287`, `8cd0d3c` | Installer / FreeSWITCH provisioning |
| `CHANGELOG.md` | `34842cd`, `0331ce1`, `2bc2d3d`, `bad8cbb`, `8cd0d3c` | Documentation |
| `docs/architecture.md` | `2bc2d3d` | Documentation |
| `docs/operations.md` | `2bc2d3d` | Documentation |
| `docs/env-reference.md` | `2bc2d3d` | Documentation |
| `docs/load-testing-results.md` | `2bc2d3d` | Documentation |
| `docs/telephony-capacity-scaling-guide.md` | `8cd0d3c` | Documentation |
| `docs/load-testing-refresh-implementation-plan.md` | `bad8cbb` | Documentation |
| `docs/superpowers/plans/2026-10-31-cdr-modernization-pipeline.md` | `2bc2d3d` | Documentation |
| `tests/Feature/Http/XmlHandlerOctaneParityTest.php` | `3c54287` | Tests |
| `tests/Feature/System/SystemdWorkerConfigTest.php` | `3c54287` | Tests |
| `tests/Feature/Services/FreeSwitchListenCommandTest.php` | `8cd0d3c` | Tests |
| `tests/Feature/Services/FreeSwitchServiceIntegrationTest.php` | `8cd0d3c` | Tests |
| `tests/Feature/Livewire/DashboardStatsTest.php` | `8cd0d3c` | Tests |
| `tests/Feature/Telephony/TelephonyPushBroadcastingTest.php` | `8cd0d3c` | Tests |

---

## 3. Impact Analysis

| Commit | Affects | Assessment |
| :--- | :--- | :--- |
| `34842cd` | Documentation, CHANGELOG | Records Octane load benchmark results and adds the Unreleased changelog entry. No runtime effect. |
| `3c54287` | **FreeSWITCH runtime**, installer, tests | `scripts/resources/freeswitch.sh` now downloads the RoadRunner binary via `vendor/bin/rr` when absent and sets ownership `root:www-data` / mode `0755`. `xml_curl.conf` gains two bindings: `tallpbx-octane` (primary, `http://127.0.0.1:8090`, 3 s timeout) and `tallpbx-fallback` (nginx → PHP-FPM, 5 s timeout). This changes the **call setup path** for every directory, dialplan, and configuration lookup. |
| `0331ce1` | Documentation | Adds the capacity scaling guide (10 CPS saturation boundary, 5-phase roadmap) and the four-architecture call capacity comparison. No runtime effect. |
| `2bc2d3d` | Documentation, CHANGELOG | Largest commit by volume (−3,523 lines). Consolidates architecture (§7.5.4 identity epoch, §7.5.7 Octane worker, §17 CDR durability), merges performance data into `docs/load-testing-results.md`, and retires 8 superseded documents. Net documentation hygiene improvement with no code change. |
| `bad8cbb` | Documentation | Hardens the load-testing refresh plan with a two-host orchestration model and generator host neutrality. No runtime effect. |
| `8cd0d3c` | **Backend telephony, ESL event pipeline, FreeSWITCH logging, Reverb broadcasting, configuration** | The only feature commit. Introduces `essential` / `full` ESL subscription profiles (`FS_ESL_EVENT_PROFILE`, `FS_ESL_SUBSCRIBE`, `--profile`, `--events`), lowers the default FreeSWITCH log level from `debug` to `notice`, and adds leading-edge broadcast throttling (`FS_BROADCAST_THROTTLE_MS`, default 500 ms) to both broadcast listeners. Touches `config/freeswitch.php` (+165 lines), `.env.example`, `phpunit.xml`, `routes/console.php`, and the installer's switch defaults. |

**Areas deliberately untouched today:** database schema and migrations, Laravel routes (web/panel), Blade templates and Livewire components, module manifests and navigation, translation dictionaries, and the live firewall pipeline.

---

## 4. Consistency Check

Evaluated against the conventions documented in [`AGENTS.md`](../AGENTS.md).

| Convention | Result | Evidence |
| :--- | :---: | :--- |
| Laravel Pint (`--test`, scoped to changed files) | **Pass** | 13 changed PHP files, 13 pass, 0 issues |
| Targeted Pest verification for changed parts | **Pass** | 6 affected suites: 67 tests, 365 assertions, all green |
| Cache clearing after code changes (`tpbx optimize:clear`) | **Pass** | No stale `bootstrap/cache/config.php` present; `php artisan about` boots cleanly (Laravel 13.38.0, PHP 8.5.12); generated caches owned `root:www-data`, mode `644` |
| Application file permissions | **Pass** | `.rr.yaml` `root:www-data` mode `664`, `rr` binary `0755`, generated caches readable by `www-data` as `ApplicationFilePermissions` requires |
| Live firewall safety | **Pass** | Zero `nft` mutations; all verification performed read-only |
| No tagging of intermediate work | **Pass** | No tags at HEAD; branch head used for reference |
| CHANGELOG `## [Unreleased]` discipline | **Pass** | All five changelog-touching commits write to `[Unreleased]` under standard subheadings |
| CHANGELOG heading discipline | **Fail** | Default log level and default ESL subscription set are changes to existing functionality but are filed under `### Added` rather than `### Changed` |
| CHANGELOG upgrade notes for reload / `.env` impact | **Fail** | No note that `freeswitch-listener` must restart and FreeSWITCH must reload for `8cd0d3c` to take effect, and no `.env` migration guidance |
| Environment reference completeness | **Fail** | `FS_ESL_EVENT_PROFILE`, `FS_ESL_SUBSCRIBE`, `FS_BROADCAST_THROTTLE_MS` are absent from [`docs/env-reference.md`](env-reference.md); the `FS_LOG_LEVEL` row still documents default `debug` and a config key `freeswitch.log_level` that does not exist (actual key: `freeswitch.switch.loglevel`) |
| Documentation link integrity | **Minor fail** | Broken relative link `](config/freeswitch.php)` in `docs/telephony-capacity-scaling-guide.md` (resolves to `docs/config/…`); stale "planned" wording at `docs/architecture.md` line 567 after §7.5.6 was re-statused |
| Decimal storage / memory units (GB/MB) | **Pass** | New and edited documentation uses GB / MB throughout; no GiB / MiB in authored prose |
| UI consistency (DaisyUI tokens, toggle states, badges) | **N/A** | No Blade, CSS, Livewire, or toggle markup changed in any of the six commits |

---

## 5. Risk Assessment

Findings are ordered by severity. Each entry names the concrete evidence used to reach the conclusion.

### R1 — High: the `essential` default silently breaks dashboard refresh

`BroadcastDashboardStatsOnFreeSwitchEvent` is registered only for `ChannelCreate`, `ChannelAnswer`, `ChannelDestroy`, and `Heartbeat` (`app/Providers/AppServiceProvider.php`, lines 274–279). The new default `essential` profile (`config/freeswitch.php`, lines 137–147) contains **neither `CHANNEL_DESTROY` nor `HEARTBEAT`**.

- A hangup emits only `CHANNEL_HANGUP_COMPLETE`, which the dashboard listener never subscribed to, so the active-calls KPI stays at its previous value after every call ends until the next `CHANNEL_CREATE` or `CHANNEL_ANSWER`.
- `resources/views/components/dashboard/stats.blade.php` has **no `wire:poll`**; it is deliberately broadcast-only, and the heartbeat was that periodic keepalive.

This contradicts a design invariant stated in a comment inside the very file the commit edited (`app/Console/Commands/FreeSwitchListenCommand.php`, lines 340–343): *"The event is still dispatched and broadcast — the heartbeat drives the wire:poll-free telephony views' periodic refresh."* No test covers the invariant; the new integration test asserts `SESSION_HEARTBEAT` is absent but never checks `HEARTBEAT` or `CHANNEL_DESTROY`.

### R2 — High: committed but not deployed on the appliance

Read-only host inspection at review time showed:

| Service | Start time | Evidence |
| :--- | :--- | :--- |
| `freeswitch-listener.service` | 2026-10-29 23:01:54 | Journal still prints the pre-change banner `Connected to FreeSWITCH ESL. Listening for events...`; the running PHP process holds the old 25-event subscription in memory |
| `freeswitch.service` | 2026-11-01 23:42:33 | Predates the `switch.conf.xml` / `logfile.conf.xml` rewrite, so the new `notice` level is on disk but not in the running switch |

Both changed files on disk (`/etc/freeswitch/autoload_configs/xml_curl.conf.xml` and `switch.conf.xml`) are well-formed XML and already carry the dual bindings and `notice` level — the gap is purely service reload. Because `CHANGELOG.md` contains no upgrade note, an operator reading the changelog would reasonably conclude the change is live.

### R3 — Medium: throttle is leading-edge only, and the documentation claims otherwise

The window check in both listeners returns early and never schedules a trailing flush, so the **final event of a burst is discarded**. `docs/telephony-capacity-scaling-guide.md` states the mechanism works *"without dropping updates or muting notifications"*, which is inaccurate. Combined with R1 — where only channel events can trigger a dashboard refresh — a dropped trailing event leaves a tile stale indefinitely rather than by up to 500 ms. The configuration block is also labelled a "debounce window"; the implemented behaviour is leading-edge throttling.

### R4 — Medium: three duplicate copies of the ESL event lists

`event_profiles.essential`, `event_profiles.full`, and the `match` arms of `subscribe` in `config/freeswitch.php` (lines 134–231) each restate the same 9 and 25 event names. Editing `event_profiles.full` will not change the default `subscribe` list; the two lists have already drifted in ordering (`SOFIA_REGISTER` / `SOFIA_EXPIRE`). A single local `$profiles` variable would remove the bug class. Related asymmetry: `FS_ESL_EVENT_PROFILE=all` resolves (the `match` accepts `'all'`), while `--profile=all` emits *"Unknown event profile"* and falls back, because `event_profiles` defines no `all` key.

### R5 — Medium: installer ignores `FS_LOG_LEVEL` and blocks debug diagnostics

`scripts/resources/freeswitch.sh` (lines 137–152) hardcodes `notice` in three places and defines no `log_level` variable, so an operator who sets `FS_LOG_LEVEL=debug` is re-pinned to `notice` on the next installer run. Worse, removing `debug` from `<map name="all">` in `logfile.conf.xml` means debug messages are not written to the log file even when the runtime level is raised — contradicting the adjacent configuration comment *"Set FS_LOG_LEVEL=debug temporarily when actively diagnosing"*.

### R6 — Low to medium: documentation drift introduced or left unresolved

- Broken relative link in `docs/telephony-capacity-scaling-guide.md`: `[config/freeswitch.php](config/freeswitch.php)`.
- `docs/architecture.md` line 567 still reads *"DB 0 mirror namespace (planned, §7.5.6)"* although §7.5.6 was re-statused to shipped in the same session.
- Three new environment variables undocumented (see [§4](#4-consistency-check)).

### R7 — Low: `routes/console.php` shadows the class command

`Artisan::command('freeswitch:listen …')` registers a closure command that wins over the class-based command — proven by `--help` printing the closure's purpose string rather than the class description. Every new option must be duplicated in two signatures or it silently disappears from the CLI.

### R8 — Low / informational

- `FreeSwitchService::unsubscribe()` rebuilds its subscription from `config('freeswitch.subscribe')` rather than the set actually subscribed, so it diverges when `--events` or `--profile=full` is used. No production caller today; only tests exercise it.
- `TelephonyUpdated` is dispatched on bursts and is consumed by all 6 live telephony Livewire components (`ActiveCallsList`, `ActiveConferencesList`, `CallCenterActiveList`, `RegistrationsList`, `SipStatusList`, `OperatorPanelIndex`) via private tenant/global channels (`#[On('echo-private:tenant.{tenantId}.telephony,.TelephonyUpdated')]`).
- The throttle state is `private static`, hence per worker process. With several Octane or queue workers the effective rate is a multiple of 2 dispatches/second per scope; this should be documented.
- The deployed `freeswitch-listener.service` is a stale **PowerPBX**-branded unit (description, documentation URL, `SyslogIdentifier`, and no `Environment=APP_ENV=production`) versus the repository's `scripts/freeswitch-listener.service`. Re-running the installer replaces it.
- The live `.env` still uses FreeSWITCH's stock ESL password. Pre-existing and not introduced today, but it is the credential protecting the call-control socket.
- No security-sensitive code changed today: no authentication, authorization, tenant-isolation, or firewall logic was modified.

### Positive findings worth preserving

- Blast radius on the most dangerous axes is genuinely small: zero migrations, zero schema changes, zero firewall changes, zero UI changes.
- `phpunit.xml` force-sets `FS_BROADCAST_THROTTLE_MS=0` so the suite is not throttled by default; the throttle tests opt back in explicitly.
- The `CUSTOM`-token-last subscription ordering invariant survived the new explicit-event path and carries a regression test.
- `2bc2d3d` is high-quality consolidation work: it repaired inbound links, preserved every measurement figure (6.23 / 4.60 / 3.30 CPS, 99.3% completion), corrected its own earlier inaccuracies (32 → 24 providers; `ResetTenantState` → `ClearTenantContext`), and left no dangling references to the 8 retired files.

---

## 6. Recommendations & Resolution Status

### Addressed in Follow-up

1. **Fixed the dashboard refresh gap (R1).** Added `ChannelHangupComplete` to `BroadcastDashboardStatsOnFreeSwitchEvent` in `app/Providers/AppServiceProvider.php`, and restored `HEARTBEAT` in `essential` in `config/freeswitch.php`. Added regression tests in `tests/Feature/Livewire/DashboardStatsTest.php` and `tests/Feature/Services/FreeSwitchListenCommandTest.php`.
2. **Deployed to appliance (R2).** Updated `/etc/systemd/system/freeswitch-listener.service` from `scripts/freeswitch-listener.service`, ran `systemctl daemon-reload && systemctl restart freeswitch-listener`, executed `fsctl loglevel notice` and `reloadxml`. Journal verified: `Listening for 10 event types (profile: essential)`.
3. **Collapsed duplicated event profiles (R4).** Consolidated event lists in `config/freeswitch.php` into a unified `$eventProfiles` array, eliminating duplication and mapping alias `all` to `full`.
4. **Installer log level respect (R5).** Made `scripts/resources/freeswitch.sh` dynamically resolve `FS_LOG_LEVEL` (defaulting to `notice`) and preserved `debug` in `logfile.conf.xml` mapping so temporary diagnosis (`fsctl loglevel debug`) works.
5. **Fixed documentation links and reference gaps (R6).** Fixed relative link in `docs/telephony-capacity-scaling-guide.md`, corrected `docs/architecture.md` line 567 status, and documented `FS_ESL_EVENT_PROFILE`, `FS_ESL_SUBSCRIBE`, `FS_BROADCAST_THROTTLE_MS`, and `FS_LOG_LEVEL` in `docs/env-reference.md`.
6. **Removed closure command shadowing (R7).** Removed redundant closure alias in `routes/console.php`; native `freeswitch:listen` command options and descriptions now render properly on `--help`.

### Future Considerations

7. Add a trailing-edge flush (a deferred job roughly `throttle_ms` after the last event) for sliding debounce bursts (R3).
8. Record the active subscription set on `FreeSwitchService` so `unsubscribe()` operates on runtime reality rather than config default (R8).
9. Keep future commit subjects under 72 characters and give behavioural commits an explanatory body.

---

## 7. Changelog-Compatible Classification

Where the day's work maps to [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) categories. Two of these entries are currently filed under the wrong subheading in `CHANGELOG.md` and should be moved to `### Changed` as part of recommendation 3.

| Category | Work |
| :--- | :--- |
| **Added** | ESL subscription profiles (`essential` / `full`, `FS_ESL_EVENT_PROFILE`, `FS_ESL_SUBSCRIBE`, `--profile`, `--events`); broadcast throttling (`FS_BROADCAST_THROTTLE_MS`); telephony capacity scaling guide; load-testing refresh implementation plan; RoadRunner binary provisioning in the installer; dual `mod_xml_curl` bindings |
| **Changed** | Default FreeSWITCH log level `debug` → `notice`; `mod_xml_curl` gateway now targets the persistent Octane worker on port 8090 with an nginx fallback binding; architecture and operations documentation consolidated around the shipped Octane worker and CDR pipeline |
| **Deprecated** | None |
| **Removed** | Eight superseded plans and reports (Lua proxy v1/v2, load-test app-side metrics, implementation plan review, Octane measurement results and plan, XML handler performance comparison, latest implementation plan) |
| **Fixed** | Missing RoadRunner binary provisioning during FreeSWITCH configuration; test import style in `XmlHandlerOctaneParityTest` |
| **Security** | None. No authentication, authorization, tenant-isolation, or firewall logic changed |

---

## 8. Verification Evidence

Commands and read-only checks performed while producing this report:

| Check | Command / method | Result |
| :--- | :--- | :--- |
| Commit inventory | `git log --since="2026-11-02 00:00:00" --until="2026-11-02 23:59:59"` | 6 commits on `5.x` |
| Diff statistics | `git diff --shortstat 34842cd^..HEAD`, `git diff --name-status` | 32 files, +1,477 / −3,435 (2 A, 8 D, 22 M) |
| Working tree / remote state | `git status --porcelain`, `git log origin/5.x..5.x`, `git tag --points-at HEAD` | Clean, fully pushed, untagged |
| Code style | `./vendor/bin/pint --test` on 13 changed PHP files | 13 pass, 0 issues |
| Targeted tests | `./vendor/bin/pest` on 6 affected suites | 67 tests, 365 assertions, all pass |
| Application boot | `php artisan about --only=environment` | Laravel 13.38.0, PHP 8.5.12, no stale config cache |
| Live FreeSWITCH configuration | XML well-formedness of `xml_curl.conf.xml` and `switch.conf.xml`; `systemctl show` start timestamps; `journalctl -u freeswitch-listener` | Both files valid; dual bindings present on disk; listener still on pre-change code |
| Reference integrity | Repository-wide scan for the 8 deleted filenames | No dangling references |

> [!WARNING]
> This report is a point-in-time evaluation. Items R1, R2, R3, R5, and R7 describe behaviour of code that is merged but not yet deployed on the reference appliance; re-verify them after the listener restart and FreeSWITCH reload described in [§6](#6-recommendations).
