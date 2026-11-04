# Reference

Flags, environment variables, artifact layout, JSON fields, and the mechanics that are easy to get wrong.

---

## Runner flags

```bash
bash load-testing/scripts/load-test-matrix.sh [options]
```

| Flag | Effect |
|:--|:--|
| `--list` | Print every tier × variant × phase and exit. Writes nothing |
| `--dry-run` | Print the exact command per cell without executing it |
| `--tier=<id>` | Restrict to one hardware tier. Required to execute |
| `--variant=<id>` | Restrict to one XML-serving variant |
| `--phase=xml\|sip\|ablation` | Restrict to one phase |
| `--section=dialplan\|directory\|mixed` | Restrict the XML battery to one request section |
| `--profile=<id>` | Restrict to one load profile (`25x1`, `100x5`, `500x25`, `1000x50`) |
| `--ttl=<id>` | Restrict to one cache policy (`production-60`, `cold`, …) |
| `--repeats=<n>` | Repetitions per cell (default 3) |
| `--cps=<n>` | Offered calls per second for SIP and ablation cells (default 5) |

An unknown tier, variant, phase or cache policy exits non-zero before anything runs. That validation exists so a typo cannot silently produce a smaller series.

---

## Environment variables

| Variable | Default | Purpose |
|:--|:--|:--|
| `PBX_HOST` | `local` | SSH target of the PBX under test; `local` runs cells in place |
| `SSH_PORT` | `22` | SSH port |
| `SSH_OPTS` | `-o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=10` | Extra SSH options; put `-i <identity>` here |
| `REMOTE_APP_DIR` | `/var/www/tallpbx` | Application root on the PBX |
| `REMOTE_ARTIFACT_ROOT` | `<REMOTE_APP_DIR>/storage/app/load-tests/<series>` | Where the PBX writes its copy (git-ignored, disposable) |
| `ARTIFACT_ROOT` | `<repo>/load-testing/results/<series>` | Where the orchestrator keeps the durable copy |
| `ARCHIVE_ROOT` | *(empty)* | Optional third copy elsewhere |
| `PBX_URL` | `http://127.0.0.1/api/v1/xml-handler` | Endpoint for every variant served by nginx |
| `PBX_OCTANE_URL` | `http://127.0.0.1:8090/api/v1/xml-handler` | Endpoint used automatically by `v-octane` |
| `PBX_XML_TOKEN` | *(empty)* | XML handler token; when empty the PBX reuses its configured token |
| `SERIES` | `refresh-YYYYMMDD` | Series directory name |
| `TENANT` / `DOMAIN` | `load-test-beta` / `load.test.local` | Seeded tenant and SIP realm |
| `EXTENSIONS` / `START` | `20` / `2000` | Generated extension count and first number |
| `PASSWORD` | `LoadTest1234` | SIP password (keep it alphanumeric) |
| `REPEATS` / `CPS` | `3` / `5` | Defaults for the matching flags; flags win |
| `MATRIX_CACHE` | `${TMPDIR:-/tmp}/tallpbx-load-matrix-<series>.json` | Realised matrix JSON; scratch, never evidence |

`LOAD_*` aliases (`LOAD_TENANT`, `LOAD_DOMAIN`, …) remain available for programmatic callers; the unprefixed names above are the documented ones and match `scripts/pbx-sipp-validate.sh`.

---

## SIPp runner variables

`scripts/pbx-sipp-validate.sh` runs **on the orchestrator**, not on the PBX. The matrix runner supplies `PBX_HOST`, `TENANT`, `DOMAIN`, `EXTENSIONS`, `START`, `PASSWORD`, `SKIP_SEED=1`, `CALL_RATE` (from `--cps`) and `RUN_DIR`; everything else keeps its default and can be overridden for a hand-run.

| Variable | Default | Meaning |
|:--|:--|:--|
| `PBX_HOST` | `127.0.0.1` | SIP target — the PBX under test |
| `PBX_PORT` | `5060` | SIP port on the PBX |
| `LOAD_GENERATOR_IP` | first local IPv4 | Address the PBX must be able to reach |
| `TENANT` / `DOMAIN` | `load-test-beta` / `load.test.local` | Seeded tenant and realm |
| `EXTENSIONS` / `START` | `20` / `2000` | Accounts to use and the first number |
| `PASSWORD` | `LoadTest1234` | SIP password for those accounts |
| `SKIP_SEED` | `0` | `1` reuses accounts that are already seeded |
| `SIPP_UAS_PORT` | `5088` | Auto-answer UAS port |
| `UAC_LOCAL_PORT` | `5064` | Caller source port for simple scenarios |
| `EXTENSION_UAS_LOCAL_PORT` | `5066` | Extension scenario listener |
| `REGISTER_LOCAL_PORT` | = `EXTENSION_UAS_LOCAL_PORT` | Registration listener |
| `OUTBOUND_UAC_LOCAL_PORT` | `5068` | Outbound gateway scenario |
| `EXTENDED_UAS_PORT` | `5090` | Extended scenario UAS |
| `EXTENDED_UAC_LOCAL_PORT` | `5100` | Base for six extended callers, stepping by 2 |
| `REGISTER_RATE` | `5` | Registrations per second |
| `REGISTER_COUNT` | = `EXTENSIONS` | Registrations to send |
| `EXTENSION_UAS_COUNT` | = `EXTENSIONS` | Extension UAS instances |
| `CALL_RATE` | `2` | Offered calls per second |
| `MAX_SIMULTANEOUS` | `5` | Concurrent calls permitted |
| `CALLS` | `10` | Total calls per scenario |
| `OUTBOUND_CALL_RATE` | `1` | Gateway round-trip pacing |
| `RUN_DIR` | — | Where the scenario logs and SIPp statistics are written |

The generator's kernel and socket preparation, the port map in context, and the two pitfalls (stray UAS on rebind, short registration lease) are in [`remote-setup.md` §13](remote-setup.md).

---

## What one tier invocation does

1. **Preflight** — refuses to continue if the PBX is missing `load-testing/scripts/lib/switch.sh`, the matrix config, the manifest command, `fs_cli` or `nginx`.
2. **Manifest** — writes `manifest.json` for the tier, and checks the host sizing against the matrix entry.
3. **Seed** — creates the tenant, extensions, SIP accounts and SIPp CSV once for the tier.
4. **Per variant** — applies every switch in the variant's `requires` list, asserting each one.
5. **Readiness** — probes the XML handler up to 15 times so a reload window is not recorded as a result.
6. **Cells** — one command per profile × cache policy × section × repeat, with stdout kept in `logs/`.
7. **Probe** — one `find_user_xml` measurement per profile × cache policy.
8. **Teardown** — restores the baseline from a trap, pulls artifacts from the PBX, optionally archives them, and reports any failure.

A cell failure is recorded in `failures.log` and the series continues. Only a preflight or teardown failure stops the run.

---

## Artifact layout

```
load-testing/results/<series>/<tier>/
├── manifest.json
├── failures.log
├── logs/<tier>_<variant>_<profile>-<ttl>-<section>-r<n>.log
└── <variant>/
    ├── <profile>-<ttl>-<section>-r<n>.json     one cell
    └── probe-<profile>-<ttl>.txt               one lookup probe
```

Example: `h1-1c1g/v-stock-dynamic/100x5-production-60-dialplan-r2.json` is repeat 2 of the 100-request/5-concurrent dialplan cell under the production cache policy.

---

## Cell JSON fields

| Field | Meaning |
|:--|:--|
| `run.id`, `run.label`, `run.git_commit`, `run.git_dirty` | Identity of the run |
| `target.total_requests`, `target.concurrency`, `target.section`, `target.scenario_counts` | What was asked for |
| `environment.hostname`, `cpu_cores`, `available_ram_mb`, `swap_used_mb`, `redis.*` | Host state at run time |
| `successful_responses`, `failed_responses`, `success_rate_percent`, `status_counts` | Outcome |
| `requests_per_second`, `elapsed_seconds` | Throughput |
| `latency_ms.p50/p90/p95/p99/average/std_dev` | Latency summary |
| `latency_ms.raw_samples` | **Every** per-request timing, in milliseconds |
| `timing.enabled` | Whether app-side timing headers were collected |
| `threshold_results`, `passed` | Threshold evaluation |
| `sample_failures` | Up to a few failed requests with their cause |

`raw_samples` is why a cell can be re-analysed later without re-running it. It is also the main driver of repository size.

---

## Probe file fields

```
lookup_count=30 find_user_ms=1594 status_ms=66 net_ms=1528 lua_bindings=off
```

| Field | Meaning |
|:--|:--|
| `find_user_ms` | Total time for *n* `find_user_xml` calls, including process startup |
| `status_ms` | Total time for *n* `status` calls — the fixed CLI overhead |
| `net_ms` | The difference: what FreeSWITCH actually spent on the lookups |
| `lua_bindings` | `on` or `off`, read from `lua.conf.xml` — the configured state, not proof of who served |

---

## Manifest fields

`build` (commit, dirty flag, branch), `host` (hostname, OS, kernel, cores, RAM, swap), `runtime` (PHP, FreeSWITCH, Redis, MariaDB versions), `configuration` (FPM pool, log level, XML path, cache TTLs, `.env` with secrets redacted), `firewall` (table present, policy, accepted ports), `recorded_at`.

The manifest is rewritten at the start of every invocation. A tier measured across several invocations keeps only the last one.

---

## Mechanics that are easy to get wrong

- **SSH connection reuse drains stdin.** Every remote call uses `ssh -n`. Without it the first cell silently ends the `while read` loop that is driving it, and the remaining profiles never run.
- **Each switch is a separate remote process.** `apply_*` and `assert_*` are independent invocations; the snapshot they write lives in `/var/lib/tallpbx/load-test-state`, which is what lets a later process restore the baseline.
- **The restore trap only arms on request.** `SWITCH_RESTORE_ON_EXIT=1` is set by the runner for the teardown; a single-shot `switch.sh` call never restores implicitly, so applying one switch by hand stays applied.
- **Per-variant URL.** `v-octane` posts to `PBX_OCTANE_URL`; every other variant posts to `PBX_URL`. Posting an Octane cell at nginx measures PHP-FPM and would be mislabelled.
- **`v-octane` still applies `fpm:static`** for consistency, although FPM is not in its path.
- **`--ttl` and `--profile` narrow the battery, not the matrix.** The matrix still lists all six cache policies; the flag only selects which run in this invocation.

---

## Tooling scripts

### `scripts/tools/cpuwatch.sh`

Attributes CPU to each service over a window. It classifies processes by command line, because every PHP service shares the same binary name.

```bash
bash load-testing/scripts/tools/cpuwatch.sh 20 "XML cell"
```

Output is one line per class — `esl-listener`, `reverb`, `octane-worker`, `cdr-worker`, `queue-worker`, `freeswitch`, `php-fpm`, `mariadb`, `redis` — in ticks and percent of one core. A class reporting `ticks=0` spent no measurable CPU in that window.

### `scripts/tools/ab-esl-reverb.sh`

Runs the same XML cell alternating between the ESL listener and Reverb running and stopped, with a warm-up cell first. Ordering matters: PHP-FPM workers start cold after a reload, and a single cold cell looks like a large difference that is not there.

Interpretation: if both services report `ticks=0` under XML-only load and the alternating runs land inside the noise band, they do not affect XML measurement.

---

## Testing the harness itself

| Test | What it keeps true |
|:--|:--|
| `tests/Feature/Pbx/LoadTestMatrixConfigTest.php` | Every tier, variant, profile and scenario exists and covers the plan |
| `tests/Feature/Pbx/LoadTestManifestCommandTest.php` | The manifest has all sections, a 40-character SHA, and no verbatim secret |
| `tests/Feature/Pbx/LoadTestSwitchScriptTest.php` | Every `apply_*` has an `assert_*`, the restore is trapped, the Lua pair is reconciled, the probe exists, and nothing touches `nftables` |
| `tests/Feature/Pbx/LoadTestMatrixRunnerTest.php` | Listing completeness, dry-run never executes, filter validation, per-variant URL, the probe is wired in, `--ttl` narrows the battery |

```bash
./vendor/bin/pest tests/Feature/Pbx --parallel
bash -n load-testing/scripts/load-test-matrix.sh
bash -n load-testing/scripts/lib/switch.sh
```

---

## Matrix configuration keys

`config/load-test-matrix.php`:

| Key | Contents |
|:--|:--|
| `tiers` | Hardware tier id → vCPU, RAM, CPU type, swap, FPM mode and children, stage (`a`/`b`) |
| `variants` | Variant id → label and the `requires` switch list |
| `xml_profiles` | Load profile id → requests and concurrency |
| `cache_ttl_profiles` | Cache policy id → the four TTLs and the request scenario |
| `xml_sections` | `dialplan`, `directory`, `mixed` |
| `sipp_scenarios` | The 14 SIPp feature scenarios |
| `ablations` | Single-knob experiments and their values |
| `cdr_transports` | `fpm` and `octane` |

Adding a variant means editing this file only; the runner, the tests and these documents read from it.
