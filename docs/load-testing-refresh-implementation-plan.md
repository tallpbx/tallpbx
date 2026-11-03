# Load-Testing Refresh & Results Rewrite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Prerequisite:** the implementation steps in [`docs/telephony-capacity-scaling-guide.md`](telephony-capacity-scaling-guide.md) (Phases 1–5: ESL event filtering, tmpfs FreeSWITCH DB, production log levels, Octane worker sizing, dual `mod_xml_curl` binding) must be in place before Task 1 runs. This plan measures the platform those changes produce; it does not implement them.

**Goal:** Re-run the TallPBX performance suite in two stages. **Stage A** runs the full matrix on the three shared tiers (1 vCPU/1 GB, 1 vCPU/2 GB, 2 vCPU/2 GB) and ends at a decision gate that classifies every optimisation as **default**, **optional**, or **no longer considered**. **Stage B** then tests only the surviving configuration on the dedicated tiers (2 vCPU/8 GB, 4 vCPU/16 GB) to confirm the decision scales. The data then rewrites `docs/load-testing-results.md` and drives the disposition of the optional code paths. The series has three outputs, not one: comparable performance statistics per configuration, the bottleneck that first saturates on each tier, and for each bottleneck the remediation options with their measured or estimated gain and cost.

**Architecture:** A declarative matrix (`config/load-test-matrix.php`) feeds an orchestrating runner (`scripts/load-test-matrix.sh`) that provisions nothing itself but drives the existing seed, XML load-test, cache-sweep and SIPp harnesses. Every switch (PHP-FPM pool, FS log level, FS runtime DB, XML path, cache TTL) is symmetric — apply, assert, run, restore — and every run writes a manifest plus raw artifacts under `storage/app/load-tests/<series>/`. The Stage A gate freezes the configuration set that Stage B is allowed to run; the results document is then rewritten from the decision matrix, not by hand-editing old tables.

**Tech Stack:** Laravel 13 / PHP 8.5, Pest, Bash, SIPp (`tools/sipp/*.xml`), FreeSWITCH 1.11.4, MariaDB 11, Redis 7, PHP-FPM, RoadRunner/Octane, `nftables`.

**Spec:** [`docs/load-testing-results.md`](load-testing-results.md) (current baseline to be replaced), [`docs/load-testing-guide.md`](load-testing-guide.md) (harness reference), [`docs/telephony-capacity-scaling-guide.md`](telephony-capacity-scaling-guide.md) (prerequisite implementation), [`docs/architecture.md`](architecture.md) §7.5 (serving paths under test).

## Global Constraints

- **Full default firewall in every run.** The complete default `nftables` ruleset (geo-blocking, pre-filters, default-drop) stays active. Never create, probe, or flush live `nftables` state to "make a test easier".
- **No unasserted switches.** After any switch (FPM pool, log level, XML path, TTL, tmpfs), the runner reads the value back and fails the cell if it did not change. The 2026-11-01 four-way run produced meaningless numbers from one silently failed switch.
- **Fresh install per hardware tier.** One installer run per tier, interactive, SignalWire PAT entered at the prompt; no tier inherits a previous tier's `.env`, caches, or database.
- **Reproducible builds only.** Every manifest records branch, `git rev-parse HEAD`, PHP/FreeSWITCH/Redis/MariaDB versions, kernel, hardware, FPM profile, FS log level, FS DB mode, XML path, cache TTLs, and a redacted `.env` snapshot.
- **Obfuscate public IPs** to the last octet (`x.x.x.NNN`) in every document, table, and committed artifact.
- **Artifacts never in git.** All raw runs live under `storage/app/load-tests/<series>/`; only the rewritten results document is committed.
- **Medians, not single runs.** Every reported cell is the median of at least three identical repetitions; boundary cells get three attempts.
- **Interleaved comparisons.** When two configurations are compared on the same tier, alternate them round-by-round; never run one to completion then the other.
- **Restore before leaving a tier.** `.env`, FPM pool, FS log level, FS DB mode, XML bindings, and cache TTLs return to the documented baseline; the runner verifies this in a teardown step.
- **Targeted tests, parallel.** `./vendor/bin/pest <path> --parallel` for every code change; `bash -n` for every script.
- **No production code removal in this plan.** Disposition (keep / optional / delete) is recorded as a decision; removals ship as their own plan.
- **Two stages, one gate.** Stage A (three shared tiers) runs the full matrix and ends at an explicit decision gate. Stage B (two dedicated tiers) may run **only** the configuration frozen at that gate; the runner rejects any eliminated variant on a Stage B tier.
- **Benchmarks must not create or lose CDRs.** Synthetic posts carry a `loadtest-` `call_uuid`, are counted before and after, and are deleted per cell; an accepted post that is not in the stream, the spool, or `cdrs` fails the run.
- **Every bottleneck gets a cause and a remedy.** A saturated metric without an attributed cause and at least one tested (or explicitly untested) remediation does not enter the register.

## Review Focus

1. **A switch that reports success but did not apply** — the highest-cost failure in this project's measurement history. Every cell asserts its configuration before the first request; a failed assertion aborts the cell, never the series.
2. **Shared-vCPU noise mistaken for a hardware limit.** The 1 vCPU tiers move by a factor of two between quiet and busy periods. Comparisons must be interleaved and median-based; a single sweep is not evidence.
3. **Inherited baselines after the scaling-guide changes.** No number from the current `load-testing-results.md` may be copied forward. Tmpfs, ESL filtering and log levels all change the ceiling, so every tier is re-baselined, including the FPM profiles. The known contradiction — 10.95 req/s at `100×5` for a 12-worker 4-core pool versus 26.4 req/s for a 5-worker 1 vCPU pool in the current document — must be reproduced or replaced, never quietly dropped.
4. **Log-level and tmpfs changes leaking between cells.** A cell run with `debug` logging or disk-backed SQLite that is not restored contaminates every later cell on that tier; teardown verification is mandatory.
5. **Failure-mode misclassification at the ramp boundary.** A rejected call can be `403` (XML timeout), `503` (SPS limit), a worker-queue timeout, or packet loss. The ladder records the exact SIP/FreeSWITCH cause per step; "it failed" is not a result.
6. **Stage B scope creep.** Re-testing an eliminated variant on dedicated hardware because "it is already provisioned" defeats the gate and inflates the series. The frozen set from Task 10 is the only input to Stage B.
7. **CDR benchmark pollution or phantom success.** A cell that accepts 5,000 posts but leaves rows, stream entries and spool files that do not reconcile is a data-integrity failure, not a performance result; every cell reconciles accepted = digested + spooled + still-queued.
8. **A bottleneck recorded without a cause or a remedy.** A saturated metric is not a finding. Every entry needs an attributed cause (process, syscall, queue, lock) and at least one tested remediation, or an explicit "untested" label with the reason.

---

## 1. What is being measured (matrix)

### 1.1 Hardware tiers (one fresh install each)

| ID | Stage | vCPU | RAM | CPU allocation | Swap | PHP-FPM profiles under test |
|:--|:--:|--:|--:|:--|--:|:--|
| `h1-1c1g` | **A** | 1 | 1 GB | shared | 2 GB | **Debian dynamic default** (`pm = dynamic`, `max_children = 5`) **and** `pm = static`, `max_children = 5` |
| `h1-1c2g` | **A** | 1 | 2 GB | shared | 2 GB | `pm = static`, `max_children = 6` |
| `h2-2c2g` | **A** | 2 | 2 GB | shared | 2 GB | `pm = static`, `max_children = 6` |
| `h2d-2c8g` | **B** | 2 | 8 GB | **dedicated** | 2 GB | `pm = static`, `max_children = 12` |
| `h4d-4c16g` | **B** | 4 | 16 GB | **dedicated** | 2 GB | `pm = static`, `max_children = 24` |

**Stage A** runs the complete matrix on the three shared tiers. **Stage B** runs only the configuration frozen at the Task 10 gate, on the two dedicated tiers, to confirm that the decision scales.

The dynamic-vs-static comparison is run **only** on `h1-1c1g`; it exists to quantify the installer's static-pool recommendation on the smallest tier.

### 1.2 XML-serving variants (Stage A: all six; Stage B: frozen set only)

All six variants below are exercised on every Stage A tier. After the Task 10 gate, Stage B runs only the variants it keeps.

| ID | What runs | Switch to apply |
|:--|:--|:--|
| `v-stock-dynamic` | Stock PHP-FPM with the Debian dynamic pool | `pm = dynamic`, `max_children = 5` (**h1-1c1g only**) |
| `v-stock-static` | Stock PHP-FPM, static pool | `location = /api/v1/xml-handler` → `try_files ... /index.php`; bindings off |
| `v-fast` | Fast front controller + PHP-FPM | `SCRIPT_FILENAME .../xml-handler-fast.php`; bindings off |
| `v-lua-stock` | Lua mirror + stock PHP-FPM | Lua bindings on, `FS_LUA_PROXY_ENABLED=true`, nginx → `index.php` |
| `v-lua-fast` | Lua mirror + fast controller | Bindings on, nginx → `xml-handler-fast.php` |
| `v-octane` | Persistent RoadRunner worker | FreeSWITCH `tallpbx-octane` binding to `127.0.0.1:8090`; nginx fallback retained |

### 1.3 XML load profiles (per variant)

- `25x1` warm-up, `100x5` moderate burst, `500x25` heavy burst, `1000x25` sustained ceiling.
- Sections: `dialplan`, `directory`, `mixed`.
- Scenarios: `mixed` (varied destinations) and `cache-hit` (same destination).
- Cache-TTL profiles: `0/0` cold, `0/60` dialplan off, `60/60` production, `60/60 cache-hit`, `30/30` extended retention, and an uncached-DB pass (TTL `0` everywhere).
- **Failure ramp:** increase total requests at fixed concurrency until the error rate exceeds 1 % or `p99` exceeds 5 s; record the last healthy step and the first failing step.

### 1.4 SIPp call profiles (Stage A: every variant; Stage B: final configuration)

- **Feature validation (14 scenarios):** `register`, `uac-extension`, `uac-extension-capacity`, `uac-outbound`, `uac-call-block`, `uac-call-forward`, `uac-conference`, `uac-emergency`, `uac-follow-me`, `uac-ring-group`, `uac-time-condition`, `uac-voicemail`, `uac-media-client-hangup`, `uac-media-server-hangup` (`tools/sipp/*.xml`).
- **Capacity ladder:** `2, 3, 5, 8, 10, 15, 20, 25, 30, 40` offered CPS, 5-second calls. Stop the ladder after three consecutive steps with completion < 95 %, then repeat the last passing and first failing step three times.
- **Every step records:** offered vs achieved CPS, attempts, completions, failure cause (`403`, `503`, timeout, `USER_NOT_REGISTERED`, socket error), setup `p50/p95/max`, peak concurrency, and the CPU split (ESL listener, FreeSWITCH core, Reverb, MariaDB, Redis, softirq).

### 1.5 Ablations (Stage A tiers only: `h1-1c1g`, `h1-1c2g`, `h2-2c2g`)

Each ablation changes exactly one knob against that tier's best-performing variant:

| Ablation | Values | Question answered |
|:--|:--|:--|
| FreeSWITCH log level | `debug`, `info`, `notice` | CPU cost of verbose telephony logging |
| Laravel log level | `debug`, `info`, `notice` (panel + worker) | CPU/IO cost of application logging |
| FreeSWITCH runtime DB | disk SQLite vs `tmpfs` | `SQLITE_BUSY` cost under call load |
| ESL subscriptions | broad (25 events) vs filtered profile | CPU recovered by event filtering |
| Reverb broadcasting | on vs off | WebSocket broadcast cost during calls |
| CDR pipeline | off, `cdr-fpm`, `cdr-octane` | whether the pipeline caps call capacity at the tier boundary (§1.8) |
| OPcache JIT | off vs on (probe) | whether JIT helps XML assembly |
| Redis AOF | off vs `everysec` | durability cost for CDR safety |

Ablations double as remediation tests: any knob that moves the boundary becomes a candidate in the remediation register, and the measured delta is recorded rather than estimated.

### 1.6 Current scenarios being reconciled (from the existing results document)

Every row here must reappear in the new document or be explicitly retired with a reason: hardware sizing matrix (5 tiers); XML routing comparison (3 load tiers + uncached + memory ceiling); cache-policy sweep (cold, dialplan-off, production, memory ceiling, legacy 5 s, extended 30 s, uncached); network interfaces (public IPv4, private IPv4, public IPv6); per-tier live-call ladders (3/5/8/10 and 2/5/10/15/20/25/30 CPS); 14-scenario feature validation; four-way XML comparison (2026-11-01); Octane worker HTTP benchmarks (2026-11-02); cross-architecture call-capacity ladder (2026-11-02).

### 1.7 Two-stage execution and the decision gate

**Stage A — measure everything on the shared tiers.** The three shared tiers (`h1-1c1g`, `h1-1c2g`, `h2-2c2g`) run the complete matrix: all six XML-serving variants (dynamic FPM only on `h1-1c1g`), every XML load profile and TTL profile, the failure ramps, the 14 SIPp feature scenarios, the CPS ladder for each variant, all ablations, and both CDR ingestion transports (§1.8).

**Gate — classify, then freeze.** Using the rules in Task 10, every optimisation is classified as **default**, **optional**, or **no longer considered**, with the Stage A number that justifies the classification. The gate writes `stage-a-decision.md` and freezes the configuration set Stage B may use: the default stack plus any optional variants retained for further consideration. Eliminated variants are never run again.

**Stage B — confirm on dedicated tiers.** The two dedicated tiers (`h2d-2c8g`, `h4d-4c16g`) run **only** the frozen configuration: its XML profiles, failure ramp, 14 feature scenarios, CPS ladder and the frozen CDR ingestion transport. Stage B answers one question — does the default/optional decision hold when CPU is no longer shared? It is not a second bake-off.

**Stopping is allowed.** If the gate produces a clear default and the operator chooses not to test dedicated hardware, the series ends after Stage A and Tasks 11–14 run on Stage A data only; the results document records that Stage B was not performed and why.

### 1.8 CDR pipeline at higher call volumes — is it a bottleneck? (new scope, one decision)

The first question is capacity, not architecture: **does the current CDR pipeline reduce call capacity, or fall behind, as call volume rises?** Only if it does — or if it costs more than it should — does the ingestion transport become a decision. The pipeline design itself is not under review.

| ID | Transport | Endpoint | Notes |
|:--|:--|:--|:--|
| `cdr-fpm` | nginx + PHP-FPM (today) | `http://127.0.0.1/api/v1/cdr` | Shares the FPM pool with the XML fallback |
| `cdr-octane` | Persistent RoadRunner worker | `http://127.0.0.1:8090/api/v1/cdr` | Same route file, same controller, same token and throttle; the switch is the URL in `json_cdr.conf.xml` |

Both transports hit the same `CdrIngestionController`, the same `throttle:cdr-ingestion` limiter and the same `pbx:cdr:stream`. The worker already loads `routes/api.php`, so the Octane endpoint exists today; the series proves it under load and verifies the route is genuinely served by the worker.

**Benchmarks (Stage A tiers):**

- **Capacity cost of the pipeline (primary test).** Run the CPS ladder three times — CDR pipeline **off**, **on with `cdr-fpm`**, **on with `cdr-octane`** — and compare maximum sustainable CPS (completion ≥ 95 %), call-setup `p50/p95`, XML `p95`, and CPU attribution. The off-versus-on delta is the pipeline's capacity cost at that tier.
- **Bottleneck threshold.** Record the offered CPS at which any of these first appears: XML `p95` +10 %, call-setup `p95` +20 %, ingestion error >1 %, stream backlog growing across two consecutive minutes, digest lag >5 s, or CDR-related CPU >15 % of a core. That CPS is the pipeline's ceiling on the tier.
- **Per-call cost.** CPU seconds, Redis operations and MariaDB writes per 100 calls, split between FreeSWITCH `mod_json_cdr`, the ingestion endpoint and `cdr:work`.
- **Ingestion rate ladder.** Synthetic valid posts at 100, 600, 1,200, 2,400, 6,000 and 12,000 per minute per transport, measuring `p50/p95/p99`, success rate, `429`/`503`, `XLEN` growth and spool files; push until errors exceed 1 %.
- **Digestion throughput.** `tpbx cdr:work --once` drain rate (entries/second) and post-to-row lag at each ladder step.
- **Coupling check.** XML `p95` with `cdr-fpm` versus `cdr-octane` at the same call rate — does moving ingestion onto the worker hurt the XML hot path?
- **Backpressure drill at volume.** During a sustained call burst, point the transport at a closed port for 10 seconds; confirm every record lands in the spool; restore; run `tpbx cdr:drain-spool`; confirm row count equals accepted posts plus spooled posts, with no loss and no duplicates.

**Decision added to the gate:**

- **Keep as-is (`cdr-fpm`, default)** if the off-versus-on delta at the tier's recommended CPS is inside the noise, the backlog does not grow, and no data is lost — the pipeline is not the bottleneck.
- **Optional (`cdr-octane`)** if the pipeline measurably caps capacity or ingestion tail, and moving the endpoint raises the call ceiling by ≥10 % (or removes measured FPM contention), with XML `p95` no worse than +5 % and a one-line rollback (URL change plus `reloadxml`).
- **Not worth changing** if `cdr-octane` shows no gain or worsens XML tail latency. If the bottleneck is the pipeline itself rather than its transport (digest rate, MariaDB writes, throttle), record it as a finding for a separate remediation plan — do not change it inside this series.

**Test-data hygiene:** posts use a `loadtest-` prefixed `call_uuid` against the seeded tenant and are deleted after each cell (`DELETE FROM cdrs WHERE call_uuid LIKE 'loadtest-%'`); drill quarantine files are removed; `XLEN` must be 0 before the next cell starts.

## 2. Deliverables

1. **New `docs/load-testing-results.md`** — complete rewrite in the structure of §7 below, containing only data produced by this series or explicitly labelled historical.
2. **Stage A gate record** (`storage/app/load-tests/<series>/stage-a-decision.md`) — the default / optional / eliminated classification, including the CDR ingestion transport verdict, the frozen Stage B configuration, and the number behind each verdict.
3. **Final decision matrix** — per hardware tier: default stack, optional stack, eliminated options, with the measured number that justifies each line after Stage B confirmation.
4. **Bottleneck and remediation register** — per tier: the first resource to saturate, the evidence that attributes it, remediation candidates (configuration, code, architecture), the measured gain where one was tested, cost/risk, and disposition (applied, separate plan, rejected).
5. **Cost/fragility register** — per optimisation: services added, files touched, failure modes introduced, rollback steps, maintenance burden.
6. **Link and metadata updates** — scaling guide, operations, architecture, README/AGENTS references, CHANGELOG.

## 3. File structure

| File | Responsibility |
|:--|:--|
| `config/load-test-matrix.php` | Declarative tiers, variants, profiles, scenarios, ladder, ablations |
| `scripts/load-test-matrix.sh` | Orchestrator: filter selection, per-cell apply/assert/run/restore, artifact layout |
| `scripts/load-test/lib/switch.sh` | Symmetric switch helpers (FPM pool, FS log level, FS DB mode, XML path, TTLs, bindings) with `assert_*` functions |
| `scripts/load-test/lib/manifest.sh` | Writes `manifest.json`: build, host, configuration, firewall state |
| `scripts/load-test/lib/collect.sh` | CPU/Redis/MariaDB/network sampling around a run |
| `app/Console/Commands/PbxLoadTestManifestCommand.php` | `tpbx pbx:load-test:manifest` — machine-readable environment snapshot used by the scripts and by tests |
| `tests/Feature/Pbx/LoadTestMatrixConfigTest.php` | Asserts matrix completeness and ladder/SIP-scenario parity with `tools/sipp/` |
| `tests/Feature/Pbx/LoadTestManifestCommandTest.php` | Asserts the manifest contains every required field and redacts secrets |
| `storage/app/load-tests/<series>/` | Raw artifacts (never committed) |
| `docs/load-testing-results.md` | Rewritten results document |

## 4. Task 0 — Preconditions verification

**Files:** none (verification only).

- [ ] **Step 1: Confirm the scaling-guide changes are live**

```bash
mountpoint /var/lib/freeswitch/db
fs_cli -x "fsctl loglevel"
systemctl status tallpbx-xml-worker --no-pager
grep -A4 'binding name="tallpbx-octane"' /etc/freeswitch/autoload_configs/xml_curl.conf.xml
grep -c "CHANNEL_EXECUTE" config/freeswitch.php
```

Expected: tmpfs mounted; `notice`; worker active; Octane binding first with the FPM fallback present; the broad ESL events are no longer subscribed.

- [ ] **Step 2: Record the pre-refresh commit** (the old results document is preserved only in git history)

```bash
git rev-parse HEAD | tee storage/app/load-tests/pre-refresh-sha.txt
```

- [ ] **Step 3: Provision the first Stage A tier and verify the install**

Follow `docs/load-testing-guide.md` → *Provisioning a Remote Test Server*. Expected: installer exits 0, `tpbx app:test --smoke` passes, full `nftables` ruleset active. Stage B hosts are provisioned only after the Task 10 gate, and only the frozen configuration is installed on them.

- [ ] **Step 4: Prove the worker serves the CDR route**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8090/api/v1/cdr -H 'Content-Type: application/json' -d '{}'
```

Expected: `401` — the route is reachable on the worker and the token is enforced. A `404` means the worker is not loading `routes/api.php`; drop the `cdr-octane` variant before Stage A starts rather than testing an endpoint that does not exist.

## 5. Phase 1 — Harness

### Task 1: Declarative matrix

**Files:**
- Create: `config/load-test-matrix.php`
- Test: `tests/Feature/Pbx/LoadTestMatrixConfigTest.php`

**Interfaces:**
- Produces: `config('load-test-matrix.tiers')` — keyed by tier id, each `['stage' => 'a'|'b', 'vcpu' => int, 'ram_mb' => int, 'cpu' => 'shared'|'dedicated', 'swap_mb' => int, 'fpm' => string, 'fpm_alt' => ?string]`
- Produces: `config('load-test-matrix.variants')` — keyed by variant id, each `['label' => string, 'requires' => list<string>]`
- Produces: `config('load-test-matrix.cdr_transports')` — `['cdr-fpm' => ['url' => 'http://127.0.0.1/api/v1/cdr'], 'cdr-octane' => ['url' => 'http://127.0.0.1:8090/api/v1/cdr']]`
- Produces: `config('load-test-matrix.xml_profiles')`, `->sip_scenarios`, `->cps_ladder`, `->ablations`

- [ ] **Step 1: Write the failing test**

```php
test('defines every hardware tier and serving variant', function (): void {
    expect(array_keys(config('load-test-matrix.tiers')))
        ->toBe(['h1-1c1g', 'h1-1c2g', 'h2-2c2g', 'h2d-2c8g', 'h4d-4c16g'])
        ->and(collect(config('load-test-matrix.tiers'))->where('stage', 'a')->count())->toBe(3)
        ->and(collect(config('load-test-matrix.tiers'))->where('stage', 'b')->count())->toBe(2)
        ->and(array_keys(config('load-test-matrix.variants')))
        ->toBe(['v-stock-dynamic', 'v-stock-static', 'v-fast', 'v-lua-stock', 'v-lua-fast', 'v-octane']);
});

test('defines both CDR ingestion transports', function (): void {
    expect(array_keys(config('load-test-matrix.cdr_transports')))->toBe(['cdr-fpm', 'cdr-octane']);
});

test('keeps the capacity ladder ascending and complete', function (): void {
    $ladder = config('load-test-matrix.cps_ladder');
    expect($ladder)->toBe([2, 3, 5, 8, 10, 15, 20, 25, 30, 40])
        ->and($ladder)->toBe(collect($ladder)->sort()->values()->all());
});

test('lists every SIPp scenario that ships in tools/sipp', function (): void {
    $files = collect(glob(base_path('tools/sipp/uac-*.xml')))
        ->map(fn (string $path): string => basename($path, '.xml'))
        ->sort()->values()->all();

    expect(collect(config('load-test-matrix.sip_scenarios'))->sort()->values()->all())->toBe($files);
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `./vendor/bin/pest tests/Feature/Pbx/LoadTestMatrixConfigTest.php --parallel`
Expected: FAIL — config key missing.

- [ ] **Step 3: Create the config** with the exact tiers from §1.1 (including each tier's `stage`), variants from §1.2, profiles from §1.3, scenarios from §1.4, ablations from §1.5, and the two CDR transports from §1.8.

- [ ] **Step 4: Verify it passes**

Run: `./vendor/bin/pest tests/Feature/Pbx/LoadTestMatrixConfigTest.php --parallel`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add config/load-test-matrix.php tests/Feature/Pbx/LoadTestMatrixConfigTest.php
git commit -m "test(load-test): add declarative refresh matrix with tier and SIP scenario parity"
```

### Task 2: Environment manifest command

**Files:**
- Create: `app/Console/Commands/PbxLoadTestManifestCommand.php`
- Test: `tests/Feature/Pbx/LoadTestManifestCommandTest.php`

**Interfaces:**
- Produces: `tpbx pbx:load-test:manifest --output=<path>` writing JSON with keys `build`, `host`, `runtime`, `configuration`, `firewall`, `recorded_at`.
- Redacts every key matching `PASSWORD|TOKEN|SECRET|PAT` in the `.env` snapshot.

- [ ] **Step 1: Write the failing test** asserting all six top-level keys exist, `build.commit` is a 40-character SHA, and no redacted value appears verbatim.
- [ ] **Step 2: Run it — expect FAIL (command not found).**
- [ ] **Step 3: Implement the command** collecting `git rev-parse HEAD`, `php -v`, `fs_cli -x "version"`, `redis-server -v`, `mariadb --version`, `nproc`, `free -m`, `mountpoint /var/lib/freeswitch/db`, `fsctl loglevel`, FPM pool profile, and the cache TTLs.
- [ ] **Step 4: Run the test — expect PASS.**
- [ ] **Step 5: Commit** `feat(load-test): add environment manifest command`.

### Task 3: Symmetric switch helpers

**Files:**
- Create: `scripts/load-test/lib/switch.sh`
- Create: `tests/Feature/Pbx/LoadTestSwitchScriptTest.php`

**Interfaces:**
- Produces shell functions: `apply_fpm_profile <static|dynamic> <children>`, `assert_fpm_profile <static|dynamic> <children>`, `apply_fs_loglevel <debug|info|notice>`, `assert_fs_loglevel`, `apply_fs_db_mode <disk|tmpfs>`, `assert_fs_db_mode`, `apply_xml_path <stock|fast>`, `assert_xml_path`, `apply_lua_bindings <on|off>`, `assert_lua_bindings`, `apply_ttl <dialplan> <contributor>`, `assert_ttl`, `restore_baseline`, plus `apply_cdr_transport <fpm|octane>` / `assert_cdr_transport` (rewrite the `url` in `/etc/freeswitch/autoload_configs/json_cdr.conf.xml`, `fs_cli -x reloadxml`, then probe the endpoint).

- [ ] **Step 1: Write the failing script test** asserting each `apply_*` has a matching `assert_*`, that `restore_baseline` is called from a teardown trap, and that no function edits `nftables`.
- [ ] **Step 2: Run it — expect FAIL.**
- [ ] **Step 3: Implement the helpers.** Each `apply_*` edits its file, reloads the affected service, then `assert_*` reads the effective value back; a mismatch prints `SWITCH-FAILED <name>` and returns 1.
- [ ] **Step 4: Run the test and `bash -n` — expect PASS.**
- [ ] **Step 5: Commit** `feat(load-test): add assertable symmetric switch helpers`.

### Task 4: Matrix runner

**Files:**
- Create: `scripts/load-test-matrix.sh`
- Create: `scripts/load-test/lib/manifest.sh`
- Create: `tests/Feature/Pbx/LoadTestMatrixRunnerTest.php`

**Interfaces:**
- Produces: `bash scripts/load-test-matrix.sh --list`, `--dry-run`, `--tier=<id>`, `--variant=<id>`, `--phase=xml|sip|ablation`, `--repeats=<n>`.
- Artifact layout: `storage/app/load-tests/<series>/<tier>/<variant>/<profile>-r<n>.json` plus `manifest.json`.

- [ ] **Step 1: Write the failing test** asserting `--list` prints every tier × variant × phase, `--dry-run` prints the exact `pbx:load-test:dialplan` / `pbx-sipp-validate.sh` command per cell without executing, and an unknown `--tier` exits non-zero.
- [ ] **Step 2: Run it — expect FAIL.**
- [ ] **Step 3: Implement the runner.** It reads the config, creates the series directory, writes the manifest, applies the cell's switch, asserts it, seeds the tenant once per tier, runs the requested profiles, restores the baseline, and fails the cell (not the series) on switch or run error.
- [ ] **Step 4: Verify `--dry-run` against the matrix and `bash -n`.**
- [ ] **Step 5: Commit** `feat(load-test): add matrix runner with assertable cells and artifact layout`.

## 6. Phase 2 — Measurement

### Task 5: XML throughput battery

**Files:** none (runs the harness).

**Stage A**

- [ ] **Step 1: For each of the six variants in §1.2, on each Stage A tier, run the profiles in §1.3 at 3 repeats.**
- [ ] **Step 2: Resolve the FPM baseline on the Stage A tiers.** Compare dynamic vs static on `h1-1c1g`, and record the static profile for `h1-1c2g` and `h2-2c2g` under the same load profiles. The current document's 10.95 req/s for a 12-worker 4-core pool cannot be reproduced on these tiers: if stock FPM survives the gate, run that exact configuration once on `h4d-4c16g` (`pm = static`, 12 workers, `100×5`) during Stage B and record the result; otherwise record the old figure as retired with the reason.
- [ ] **Step 3: Run the failure ramp** at fixed concurrency, increasing total requests by 2× until >1 % errors or `p99` > 5 s; record the boundary.
- [ ] **Step 4: Run the cache-policy sweep** (`bash scripts/run-cache-sweep.sh`) for the best variant per Stage A tier, plus `directory-mixed`.
- [ ] **Step 5: Verify every cell has a manifest and a matching assertion record; re-run any cell with `SWITCH-FAILED`.**

**Stage B (after the Task 10 gate)**

- [ ] **Step 6: Run only the frozen configuration** on `h2d-2c8g` and `h4d-4c16g`: the same XML profiles at 3 repeats plus its failure ramp. The runner reads `stage-a-decision.md` and rejects any variant that is not in the frozen set.
- [ ] **Step 7: Record whether the Stage A ranking holds** on both dedicated tiers; a reversal is a finding, not a reason to re-run eliminated variants.

### Task 6: SIPp feature validation and capacity ladder

**Stage A**

- [ ] **Step 1: Re-register endpoints** (`tools/sipp/register.xml`, `Expires: 3600`) before each variant.
- [ ] **Step 2: For every Stage A variant, run the 14 feature scenarios** (`bash scripts/pbx-sipp-validate.sh`), recording pass/fail per scenario.
- [ ] **Step 3: Run the ladder from §1.4** for every Stage A variant; stop per the rule, then repeat the boundary step three times.
- [ ] **Step 4: Record the exact failure cause per failing step** (SIP code, FreeSWITCH log line, worker/queue state).

**Stage B (after the Task 10 gate)**

- [ ] **Step 5: Run the 14 scenarios and the ladder for the frozen configuration only** on `h2d-2c8g` and `h4d-4c16g`.
- [ ] **Step 6: Record whether the failure boundary moves as expected** with dedicated cores; a smaller-than-expected gain is a result, not a retry trigger.

### Task 7: Ablations

- [ ] **Step 1: On the three Stage A tiers (`h1-1c1g`, `h1-1c2g`, `h2-2c2g`), run each ablation in §1.5 against the tier's best variant**, 3 repeats, interleaved on/off.
- [ ] **Step 2: Restore and assert the baseline knob after each pair.**
- [ ] **Step 3: Record the delta per ablation** (throughput, `p95`, CPU split, error rate).

### Task 8: Saturation profiling

- [ ] **Step 1: During every boundary run, capture 1-second `top -b`, `pidstat -u -p ALL`, `vmstat 1`, `redis-cli INFO`, `SHOW GLOBAL STATUS`, `ss -s`, and `dmesg | tail`.**
- [ ] **Step 2: Attribute CPU per subsystem** (FreeSWITCH core, ESL listener, Reverb, MariaDB, Redis, softirq) and record the first saturated resource.
- [ ] **Step 3: Stage B: capture the same profile for the frozen configuration on both dedicated tiers**, so the bottleneck register can show which constraint the extra cores removed.
- [ ] **Step 4: Remediation experiment.** For each tier's first bottleneck, apply the single most promising remediation (from the ablation results or a configuration change), re-run the boundary step three times, and record the delta. Revert and assert the baseline afterwards; if no remediation was tested, label it "untested" and say why.
- [ ] **Step 5: Store the capture next to the run artifact and reference it from the manifest.**

### Task 9: CDR pipeline transport benchmarks

**Files:** none (runs the harness); uses `apply_cdr_transport` from Task 3.

**Interfaces:**
- Consumes: `apply_cdr_transport <fpm|octane>`, `assert_cdr_transport`, the SIPp ladder from Task 6, `tpbx cdr:work --once`, `tpbx cdr:drain-spool`.
- Produces: `storage/app/load-tests/<series>/<tier>/cdr/<transport>-<cps>.json` plus a reconciliation record per cell.

**Stage A**

- [ ] **Step 1: Capacity-cost ladder.** On each Stage A tier, run the CPS ladder three times — pipeline **off**, `cdr-fpm`, `cdr-octane` — recording max sustainable CPS, call-setup `p50/p95`, XML `p95`, and CPU attribution.
- [ ] **Step 2: Bottleneck threshold.** Record the offered CPS at which the §1.8 threshold first appears, per transport.
- [ ] **Step 3: Ingestion rate ladder.** Synthetic posts at 100/600/1,200/2,400/6,000/12,000 per minute per transport; record `p50/p95/p99`, error classes, `XLEN`, spool files.
- [ ] **Step 4: Digestion throughput.** `tpbx cdr:work --once` drain rate and post-to-row lag at each ladder step.
- [ ] **Step 5: Backpressure drill.** Closed-port burst during a call run, confirm the spool captures every record, restore, `tpbx cdr:drain-spool`, reconcile accepted = digested + spooled + still-queued with zero loss and zero duplicates.
- [ ] **Step 6: Reconcile and clean up** — `DELETE FROM cdrs WHERE call_uuid LIKE 'loadtest-%'`, remove drill quarantine files, confirm `XLEN` is 0 before the next cell.

**Stage B (after the Task 10 gate)**

- [ ] **Step 7: Run only the frozen CDR transport** on `h2d-2c8g` and `h4d-4c16g`: the capacity-cost ladder and the backpressure drill.

## 7. Phase 3 — Analysis and rewrite

### Task 10: Stage A decision gate

**Files:**
- Create: `storage/app/load-tests/<series>/stage-a-decision.md` (working document, not committed)

- [ ] **Step 1: Apply the disposition rules** (decided before looking at the data):
  - **Default** — runs on `h1-1c1g`, adds no daemon, introduces no new failure mode, is within 5 % of the best measured throughput on that tier, and has equal or better `p95`.
  - **Optional** — delivers ≥ 25 % throughput or ≥ 50 % tail improvement on at least two Stage A tiers, with a documented rollback and a named owner of its failure modes.
  - **No longer considered** — delivers < 10 % on every Stage A tier while adding a service, a config file, or a new failure class; or carries a data-integrity risk without a cheap guard.
- [ ] **Step 2: Write one verdict per optimisation**, including the CDR ingestion transport, each with the single Stage A number that justifies it (throughput, `p95`, or failure boundary).
- [ ] **Step 3: Freeze the Stage B set.** List the default stack, the retained optional variants, and the frozen CDR transport by id; every other variant is excluded. From here on, `stage-a-decision.md` is the only accepted input for Stage B.
- [ ] **Step 4: Record the operator decision.** Continue to Stage B, or stop after Stage A. If stopping, note the reason; Tasks 11–14 then run on Stage A data only.

### Task 11: Final decision matrix and registers

**Files:**
- Create: `storage/app/load-tests/<series>/decision-matrix.md` (working document, not committed)

- [ ] **Step 1: Confirm or revise the Stage A verdicts** with Stage B evidence; a verdict that changes must cite the dedicated-tier number that changed it.
- [ ] **Step 2: Fill the bottleneck and remediation register** (per tier: first saturated resource, attributed cause, remediation candidate, measured or estimated gain, cost/risk, disposition).
- [ ] **Step 3: Fill the cost/fragility register** (services, files, failure modes, rollback, maintenance).
- [ ] **Step 4: Write the one-line verdict per tier** and per optimisation, including the explicit "no longer considered" list and the remediation list with its disposition (applied here, separate plan, rejected).

### Task 12: Rewrite `docs/load-testing-results.md`

**Files:**
- Modify (replace): `docs/load-testing-results.md`

**Structure of the new document (fixed):**

1. **How to read this document** — freshness table: section, measured date, build SHA, host tier, stage (`A` / `B`), status (`current` / `historical` / `superseded`).
2. **Recommended configuration per hardware tier** — default, optional, and no-longer-considered options, each with the number that justifies it, plus the frozen Stage B set from the gate.
3. **Test environment and build provenance** — per tier, from the manifests.
4. **Method** — notation, metrics, scenarios, switch assertions, failure-ramp rules, interleaving.
5. **XML throughput results** — Stage A: all six variants on the three shared tiers (stock dynamic vs static on h1 only, fast, Lua, Lua+fast, Octane); Stage B: the frozen configuration only on the dedicated tiers. Eliminated variants appear once, with the Stage A number that eliminated them.
6. **Cache-policy results** — TTL profiles and hit rates per tier.
7. **Live call capacity** — 14-scenario validation and CPS ladders per Stage A variant, then for the frozen configuration on the Stage B tiers, with exact failure causes.
8. **CDR ingestion** — `cdr-fpm` versus `cdr-octane`: rate ladders, latency, XML coupling, backlog, digestion lag and the data-integrity drill.
9. **Bottleneck analysis and remediation** — first saturated resource per tier, attributed cause, remediation candidates with measured or estimated gain, cost/risk, and disposition.
10. **Optimisation cost/benefit register** — decision per optimisation with complexity and fragility.
11. **Appendix A** — artifact index; **Appendix B** — environment snapshots; **Appendix C** — glossary.

- [ ] **Step 1: Confirm the pre-refresh SHA is recorded** (Task 0, Step 2); the old document survives in git history only.
- [ ] **Step 2: Write sections 1–4 from the matrix config and manifests.**
- [ ] **Step 3: Generate sections 5–9 from `decision-matrix.md` and the artifacts; every table cites its series directory. Any 2026-11 measurement that is not re-run (the four-way comparison and the Octane worker run) is published under the freshness table as `historical` with its original date, never presented as current.**
- [ ] **Step 4: Write section 10 from the cost/fragility register.**
- [ ] **Step 5: Verify** every scenario in §1.6 and §1.8 appears or is explicitly retired; every number traces to an artifact; no public IP is un-obfuscated.

### Task 13: Reconcile dependent documents

**Files:**
- Modify: `docs/telephony-capacity-scaling-guide.md` — replace the duplicated CPS ladder with a link to the results anchor; reconcile the "150 req/s" claim with the measured ceiling.
- Modify: `docs/operations.md` — if the default PHP-FPM/Octane posture changes.
- Modify: `README.md` / `AGENTS.md` — only if links or defaults change.
- Modify: `CHANGELOG.md` — record the refresh.

- [ ] **Step 1: Replace the duplicated ladder in the scaling guide** with the link and delete its local copy (results document is the owner).
- [ ] **Step 2: Update the 150 req/s sentence** to the measured figure from section 5.
- [ ] **Step 3: Update operations defaults** only for decisions that change the shipped configuration.
- [ ] **Step 4: Verify no dead links** (`grep` for every old anchor) and run `tpbx optimize:clear`.

### Task 14: Close the plan

- [ ] **Step 1: Record the decision summary** in the reproduction section of the new results document and in `CHANGELOG.md`.
- [ ] **Step 2: Produce the removal list** for the separate disposition plan (do not delete code here).
- [ ] **Step 3: Delete this plan file** after the results document is merged, and note in `CHANGELOG.md` that the refresh plan was retired (git history retains it).

## 8. Risks and guards

| Risk | Guard |
|:--|:--|
| Switch silently not applied | `assert_*` after every `apply_*`; cell aborts on `SWITCH-FAILED` |
| Shared-CPU noise | Interleaved rounds, medians of ≥ 3, ping-floor subtraction for FreeSWITCH-side timings |
| Stale numbers inherited | Every tier re-baselined; pre-refresh SHA recorded; no figure copied forward |
| Cross-cell contamination | `restore_baseline` trap + teardown assertion; log level, FPM pool, tmpfs, bindings, TTLs |
| Invalid failure boundary | Exact cause taxonomy per step; three attempts at the boundary |
| Firewall/lockout | Full default ruleset stays active; no `nft` mutation outside the application pipeline |
| Artifact loss | Manifest + raw files per cell under `storage/app/load-tests/<series>/`; index in Appendix A |
| Test-series cost | Funnel: 3 repeats on all Stage A XML profiles and ramps; Stage B limited to the frozen configuration; ablations on the 3 shared tiers only |
| Stage B tests an eliminated variant | The runner reads `stage-a-decision.md` and refuses any variant id not in the frozen set on a B tier |
| Gate decided on thin data | ≥ 3 medians per cell, 3 attempts at each boundary, and exactly one written number behind each verdict |
| CDR cells pollute or lose records | `loadtest-` prefix, accepted counts before and after, per-cell reconciliation, cleanup step, and `XLEN` = 0 before the next cell |
| CDR ceiling blamed on the pipeline when the database is the constraint | CPU attribution separates `mod_json_cdr`, the endpoint and `cdr:work`; MariaDB wait counters are captured with every boundary run |
| Bottleneck blamed on the wrong layer | Per-process and per-queue evidence before any change; remediation experiments change one knob at a time and revert |

## 9. Time budget (rough)

| Phase | Estimate |
|:--|:--|
| Harness (Tasks 1–4) | 2–3 days |
| Stage A XML battery (Task 5) | 2–3 days across 3 shared tiers |
| Stage A SIPp validation + ladders (Task 6) | 3–4 days |
| Stage A ablations + profiling (Tasks 7–8) | 2 days |
| Remediation experiments (Task 8) | 1–2 days |
| CDR pipeline benchmarks (Task 9) | 2 days |
| Stage A gate (Task 10) | 1 day |
| Stage B, frozen configuration only (Tasks 5–9) | 2–3 days |
| Final analysis + rewrite (Tasks 11–13) | 2–3 days |
| **Total** | **~3–4 weeks of test windows** |

## 10. Out of scope

- Implementing the scaling-guide changes (prerequisite, separate work).
- Removing any optimisation from the codebase (separate disposition plan).
- RTP media quality/transcoding load testing (signalling capacity only).
- Multi-host clustering, HA failover, and cross-region latency.
- CI integration: the suite needs real hardware FreeSWITCH and SIPp and stays manual/scheduled.
