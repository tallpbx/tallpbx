# TallPBX Load Testing

Everything needed to reproduce the TallPBX capacity series lives in this directory: the procedure, the host configuration, the switch reference, the scripts, the engineering decisions, and the consolidated cross-series CSV datasets under `results/consolidated/`. Raw per-cell run outputs stay local and git-ignored.

This directory is the **reproducible benchmark harness** (structured so an AI agent or engineer can run a full capacity matrix cleanly). For human-facing documentation, see:

- [`docs/load-testing-guide.md`](../docs/load-testing-guide.md) — Administrator manual for ad-hoc XML/SIPp testing, two-host lab setup, and troubleshooting.
- [`docs/load-testing-results.md`](../docs/load-testing-results.md) — Published benchmark summaries, hardware sizing guidance, and dated series reports.

If a harness rule or capacity-measurement detail conflicts with another document, this directory wins.

TallPBX uses a fast PHP-FPM handler by default to answer FreeSWITCH's XML lookups for accounts and call routes. It loads only the telephony modules. [Laravel Octane](https://laravel.com/docs/octane) is an optional performance mode that loads the same list and keeps the application in memory between requests. On a standard install, FreeSWITCH falls back to PHP-FPM when Octane is unavailable. If the fast PHP-FPM handler catches an application error, its worker runs the full Laravel application used by the web panel. [XML worker setup](../docs/operations.md#octane-xml-worker-optional-performance-mode).

---

## Start Here — Running the Unified Comparison Series

To run a complete, apples-to-apples **PHP-FPM** vs. **Laravel Octane** benchmark series across all three standard hardware tiers (`h1-1c1g`, `h2-2c2g`, `h4-4c8g`), retire legacy multi-date data, and replace the published tables and charts in [`docs/load-testing-results.md`](../docs/load-testing-results.md), follow the single self-contained runbook:

👉 **[`load-testing/run-unified-series.md`](run-unified-series.md)**

---

## If you are an AI agent, read these in this order

| Order | File | Why |
|:--|:--|:--|
| 1 | `README.md` (this file) | What exists, where results go, and the rules you must not break |
| 2 | [`run-unified-series.md`](run-unified-series.md) | **Primary end-to-end execution checklist** for running all standard tiers under one `SERIES`, validating parity with `consolidate-series.py`, retiring legacy data, and replacing `docs/load-testing-results.md` |
| 3 | `decisions.md` | Why each default is what it is, and which alternative was rejected |
| 4 | `status.md` | What has actually been measured, what is pending, and what is still open |
| 5 | `remote-setup.md` | Every host setting, with the command that applies it and the command that proves it |
| 6 | `configurations.md` | What each switch physically changes, and what its assertion reads back |
| 7 | `procedure.md` | Detailed step-by-step reference and failure-marker troubleshooting (`Step 10`) |
| 8 | `reference.md` | Flags, environment variables, artifact layout, JSON fields, and a **glossary** defining every abbreviation used in this directory |

Read the scripts only when the documents do not answer a question:

| Script | Purpose |
|:--|:--|
| `load-testing/scripts/load-test-matrix.sh` | Orchestrator: lists, dry-runs and executes cells; applies and asserts each switch remotely; pulls artifacts; restores the baseline |
| `load-testing/scripts/lib/switch.sh` | The switches themselves. One `apply_*` per setting, each with a matching `assert_*` that reads the value back |
| `load-testing/scripts/lib/manifest.sh` | Writes and reads `manifest.json`; verifies host sizing against the matrix |
| `load-testing/scripts/drivers/` | Execution drivers for paired tier comparisons (`run-tier-comparison.sh`), automated call-rate ladders (`run-ladder.sh`), and SQLite ladders (`run-sqlite-ladder.sh`) |
| `load-testing/scripts/pbx-sipp-validate.sh` | End-to-end SIPp scenario validation harness |
| `load-testing/scripts/run-cache-sweep.sh` | Dialplan cache TTL policy sweep runner |
| `load-testing/scripts/tools/cpuwatch.sh` | Attributes CPU to each service over a time window |
| `load-testing/scripts/tools/rtt-stats.py` | Summarizes per-call SIPp `setup` and `teardown` latency (`p50`/`p90`/`p95`/`max`) from `*_rtt.csv` |
| `load-testing/scripts/tools/consolidate-series.py` | Validates cross-tier build/completeness parity across `h1-1c1g`, `h2-2c2g`, and `h4-4c8g` and emits one uniform consolidated CSV + Markdown tables/charts |
| `load-testing/scripts/tools/ab-esl-reverb.sh` | Alternating on/off runs of the ESL listener and Reverb |

---

## Rules you must not break

1. **Never mutate live `nftables` state.** Verify with `nft list ruleset`; never `add`, `insert`, `delete` or `flush`. Firewall changes go through `tpbx security:apply`.
2. **Never start a cell without a manifest.** Every tier run begins by writing `manifest.json`; a number without a manifest is not evidence.
3. **Never trust an `apply_*` without its `assert_*`.** A switch that silently did not take effect produces a plausible but meaningless number. `SWITCH-FAILED` fails that cell on purpose.
4. **Never commit host credentials.** No IP addresses, key names, key paths or passwords belong in this directory. The operator supplies `PBX_HOST` and the SSH identity; the runner uses the ambient SSH configuration.
5. **Never ignore a failed test or bypass a validation gate.** When any cell, ladder rung, extended scenario, or `consolidate-series.py` check fails, stop, diagnose and fix the root cause, and re-run cleanly. Never use `--skip-*` or `--allow-incomplete` to publish a partial or broken series; if a blocker cannot be resolved, stop and report it to the user.
6. **Write the series record into `docs/load-testing-results.md`** as a new dated section, and distill the series data into the consolidated CSVs under `load-testing/results/consolidated/`. Keep the committed record to a few documents — the results document plus the consolidated data files — so future re-analysis has one comparable dataset instead of scattered reports.
7. **Never commit raw measurement fragments.** One series produces thousands of per-cell files — SIPp traces, CSVs, JSON reports, transport logs — that are unreviewable and bloat every clone. `/load-testing/results/` and `/storage/app/load-tests/` are git-ignored; keep them that way. What IS repository content: the distilled, cross-scenario comparison data under `/load-testing/results/consolidated/` — `*-cells.csv` (per-cell metrics), `*-services.csv` (per-service CPU and RAM) and `*-setup-latency.csv` (per-call setup times) — appended to by every series, plus the written record in `docs/`. Staging a run with `git add -A` is how this rule gets broken: stage the consolidated data, the report and the code, never the raw run directory.

   Three guards enforce this, and removing any of them is a defect:
   - `.gitignore` ignores both trees except `/load-testing/results/consolidated/`;
   - `scripts/git-hooks/pre-commit` refuses a commit that stages raw fragments (the consolidated directory is allow-listed) — enable it once per clone with `git config core.hooksPath scripts/git-hooks`;
   - `tests/Feature/Pbx/LoadTestArtifactHygieneTest.php` fails the suite if either guard disappears.

---

## The short version

Run these commands from the root of your TallPBX checkout on the orchestrator, with `PBX_HOST` set to the PBX address.

```bash
# 1. Confirm the host under test carries this branch and a clean tree
ssh root@"$PBX_HOST" 'cd /var/www/tallpbx && git rev-parse --short HEAD && git status --porcelain'

# 2. See exactly what a cell would run
bash load-testing/scripts/load-test-matrix.sh --dry-run \
  --tier=h1-1c1g --compare-xml-handlers --phase=xml --profile=100x5 \
  --ttl=production-60 --section=dialplan --repeats=1

# 3. Measure it
PBX_HOST="$PBX_HOST" bash load-testing/scripts/load-test-matrix.sh \
  --tier=h1-1c1g --compare-xml-handlers --phase=xml \
  --profile=100x5 --ttl=production-60 --section=dialplan --repeats=1
```

The run writes a manifest, applies each switch and asserts it, runs the cell, records a lookup probe, pulls the artifacts, restores the baseline, and prints where the data went.

Run the **single uniform comparison contract** (`standard_comparison` in `load-testing/matrix.php`) under **one shared `SERIES` name** on **one clean Git commit SHA** across **every** standard tier — **`h1-1c1g`**, **`h2-2c2g`**, and **`h4-4c8g`** (plus dedicated tiers `h2d-2c8g` / `h4d-4c16g` when provisioned):

```bash
export SERIES="series-$(date -u +%Y%m%d)"

# Repeat once per tier against its matching PBX_HOST, keeping the SAME SERIES and build SHA:
PBX_HOST="$PBX_HOST" SIPP_HOST="$SIPP_HOST" \
  bash load-testing/scripts/drivers/run-tier-comparison.sh --tier=h1-1c1g --series="$SERIES"
PBX_HOST="$PBX_HOST" SIPP_HOST="$SIPP_HOST" \
  bash load-testing/scripts/drivers/run-tier-comparison.sh --tier=h2-2c2g --series="$SERIES"
PBX_HOST="$PBX_HOST" SIPP_HOST="$SIPP_HOST" \
  bash load-testing/scripts/drivers/run-tier-comparison.sh --tier=h4-4c8g --series="$SERIES"

# Verify cross-tier build parity & completeness, and emit one unified CSV + Markdown report:
python3 load-testing/scripts/tools/consolidate-series.py \
  "load-testing/results/${SERIES}" \
  --csv-out="load-testing/results/consolidated/${SERIES}-comparison.csv" \
  --markdown-out="/tmp/${SERIES}-tables.md"
```

Every tier runs the exact same matched PHP-FPM (`v-fast-default`) vs. Octane (`v-octane`) battery with identical dynamic PHP-FPM pools:
1. **XML lookup throughput & latency:** `100x5`, `500x25`, and `1000x25` (`mixed`, `production-60`, 3 repeats) recording `req/sec`, `p50`, `p95`, and `max`.
2. **60-second SIP call capacity & per-call latency ladder (`run-ladder.sh`, `EXTENSIONS=30`):** completion %, CDR gate, per-service CPU, and individual call `setup` (`INVITE` → `200 OK`) + `teardown` (`BYE` → `200 OK`) `p50`/`p95`/`max` latency in `ladder.csv`.
3. **14-scenario functional & media pass (`EXTENDED=1 MEDIA_FLOW=1`).**

Once `consolidate-series.py` passes with zero errors across all standard tiers, **replace** older piecemeal multi-date tables in `docs/load-testing-results.md` with that single consistent dataset so comparisons are never cobbled together from different dates, pools, or builds. [Call capacity procedure](procedure.md#step-8--compare-call-capacity).

---

## Where results live

```
storage/app/load-tests/                        (git-ignored, PBX scratch space)
    sipp-users.csv         seeded endpoint accounts and credentials
    dialplan.xml           temporary XML dumps from single commands

storage/app/load-tests/sipp-e2e-<timestamp>/   (git-ignored, generator scratch space)
    sipp-users-auth.csv    auth CSV for this SIPp run
    summary.md            outcome and paths to the run's artifacts

load-testing/results/<series>/<tier>/          (git-ignored, local working data)
    manifest.json          what host, build and configuration the run had
    <variant>/*.json        one file per cell, with per-request timings
    <variant>/probe-*.txt  what FreeSWITCH waited for on one lookup
    logs/*.log             the stdout of each cell command
    failures.log           one line per failed cell (empty means clean)

load-testing/results/consolidated/             (committed repository content)
    *-cells.csv            per-cell metrics across series
    *-services.csv         per-service CPU and RAM
    *-setup-latency.csv    per-call setup latency distributions
```

Strict separation of artifact storage:
- `storage/app/load-tests/`: Scratch space on the PBX for single Artisan commands (`pbx:load-test:seed`, `pbx:load-test:dialplan`). Always git-ignored.
- `load-testing/results/<series>/`: Per-cell raw run artifacts from matrix runs. Always git-ignored.
- `load-testing/results/consolidated/`: Clean, multi-series consolidated CSV comparison datasets (`*-cells.csv`, `*-services.csv`, `*-setup-latency.csv`). Committed repository content.

Series narrative reports live in `docs/load-testing-results.md` (as a new dated section) or dedicated series narrative files like `docs/load-testing-<series>.md`. Keeping a series means updating the consolidated CSVs and writing its report into `docs/`, and — if the raw data must survive the host — copying the run directory to external storage outside the repository. Archived series from before this convention remain tracked in git history; that is history, not a pattern to follow.

---

## What a series measures

| Axis | Options | Where defined |
|:--|:--|:--|
| Hardware tier | 1 vCPU/1 GB shared, 1 vCPU/2 GB shared, 2 vCPU/2 GB shared, 4 vCPU/8 GB shared, 2 vCPU/8 GB dedicated, 4 vCPU/16 GB dedicated | `load-testing/matrix.php` → `tiers` |
| XML-serving variant | `v-stock-dynamic`, `v-stock-static`, `v-fast`, `v-fast-default`, `v-octane` | → `variants` |
| Load profile | `25x1`, `100x5`, `500x25`, `1000x25` (`--rates=5,10,15,20,25`), `1000x25-8020` | → `xml_profiles` |
| Cache policy / TTL presets | `default` (`300s / 60s`), `tuned` (`1800s / 600s`), `production-60` (`60s / 60s`), `extended-30` (`30s / 30s`), `cold`, `dialplan-off`, `cache-hit-60`, `uncached-redis` (`0s / 0s`, store `redis`), `uncached-db` (`0s / 0s`, store `null`) | → `cache_ttl_profiles` |
| SIP scenarios | 14 feature scenarios plus a call-rate ladder | → `sip_scenarios` |

The matrix is the single source: the runner, the tests and the documents all cite it.

---

## Known limits, stated up front

- **Repeat noise is roughly 10 %.** A difference smaller than that between two variants is not a finding at three repeats.
- **`manifest.json` describes the invocation, not each cell.** It is rewritten at the start of every run, so a tier measured across several invocations keeps only the last manifest.
- **Octane cells are measured on port 8090**, a different HTTP server from nginx. That is intentional and is recorded in the report path.
