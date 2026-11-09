# TallPBX Load Testing

Everything needed to reproduce the TallPBX capacity series lives in this directory: the procedure, the host configuration, the switch reference, the scripts, the decisions behind them, and the raw results.

**This directory is the single source of truth for load testing.** If a fact about capacity measurement exists somewhere else in the repository, this directory wins; the older copies are kept for history and are reconciled later.

It is written so that a human operator **or** an AI agent can be pointed at this directory and follow it without reading anyone's earlier session.

---

## If you are an AI agent, read these in this order

| Order | File | Why |
|:--|:--|:--|
| 1 | `README.md` (this file) | What exists, where results go, and the rules you must not break |
| 2 | `decisions.md` | Why each default is what it is, and which alternative was rejected |
| 3 | `status.md` | What has actually been measured, what is pending, and what is still open |
| 4 | `remote-setup.md` | Every host setting, with the command that applies it and the command that proves it |
| 5 | `configurations.md` | What each switch physically changes, and what its assertion reads back |
| 6 | `procedure.md` | The ordered run, with a verification checkpoint after each step |
| 7 | `reference.md` | Flags, environment variables, artifact layout, JSON fields, failure markers, and a **glossary** defining every abbreviation used in this directory |

Read the scripts only when the documents do not answer a question:

| Script | Purpose |
|:--|:--|
| `scripts/load-test-matrix.sh` | Orchestrator: lists, dry-runs and executes cells; applies and asserts each switch remotely; pulls artifacts; restores the baseline |
| `scripts/lib/switch.sh` | The switches themselves. One `apply_*` per setting, each with a matching `assert_*` that reads the value back |
| `scripts/lib/manifest.sh` | Writes and reads `manifest.json`; verifies host sizing against the matrix |
| `scripts/tools/cpuwatch.sh` | Attributes CPU to each service over a time window |
| `scripts/tools/ab-esl-reverb.sh` | Alternating on/off runs of the ESL listener and Reverb |

---

## Rules you must not break

1. **Never mutate live `nftables` state.** Verify with `nft list ruleset`; never `add`, `insert`, `delete` or `flush`. Firewall changes go through `tpbx security:apply`.
2. **Never start a cell without a manifest.** Every tier run begins by writing `manifest.json`; a number without a manifest is not evidence.
3. **Never trust an `apply_*` without its `assert_*`.** A switch that silently did not take effect produces a plausible but meaningless number. `SWITCH-FAILED` fails that cell on purpose.
4. **Never commit host credentials.** No IP addresses, key names, key paths or passwords belong in this directory. The operator supplies `PBX_HOST` and the SSH identity; the runner uses the ambient SSH configuration.
5. **Write the series record into `docs/load-testing-results.md`** as a new dated section, and distill the series data into the consolidated CSVs under `load-testing/results/consolidated/`. Keep the committed record to a few documents — the results document plus the consolidated data files — so future re-analysis has one comparable dataset instead of scattered reports.
6. **Never commit raw measurement fragments.** One series produces thousands of per-cell files — SIPp traces, CSVs, JSON reports, transport logs — that are unreviewable and bloat every clone. `/load-testing/results/` and `/storage/app/load-tests/` are git-ignored; keep them that way. What IS repository content: the distilled, cross-scenario comparison data under `/load-testing/results/consolidated/` — `*-cells.csv` (per-cell metrics), `*-services.csv` (per-service CPU and RAM) and `*-setup-latency.csv` (per-call setup times) — appended to by every series, plus the written record in `docs/`. Staging a run with `git add -A` is how this rule gets broken: stage the consolidated data, the report and the code, never the raw run directory.

   Three guards enforce this, and removing any of them is a defect:
   - `.gitignore` ignores both trees except `/load-testing/results/consolidated/`;
   - `scripts/git-hooks/pre-commit` refuses a commit that stages raw fragments (the consolidated directory is allow-listed) — enable it once per clone with `git config core.hooksPath scripts/git-hooks`;
   - `tests/Feature/Pbx/LoadTestArtifactHygieneTest.php` fails the suite if either guard disappears.

---

## The short version

```bash
# 1. Confirm the host under test carries this branch and a clean tree
ssh root@"$PBX_HOST" 'cd /var/www/tallpbx && git rev-parse --short HEAD && git status --porcelain'

# 2. See exactly what a cell would run
bash load-testing/scripts/load-test-matrix.sh --dry-run \
  --tier=h1-1c1g --variant=v-stock-static --phase=xml --profile=100x5 \
  --ttl=production-60 --section=dialplan --repeats=1

# 3. Measure it
PBX_HOST="$PBX_HOST" bash load-testing/scripts/load-test-matrix.sh \
  --tier=h1-1c1g --variant=v-stock-static --phase=xml \
  --profile=100x5 --ttl=production-60 --section=dialplan --repeats=1
```

The run writes a manifest, applies each switch and asserts it, runs the cell, records a lookup probe, pulls the artifacts, restores the baseline, and prints where the data went.

---

## Where results live

```
load-testing/results/<series>/<tier>/          (git-ignored, local working data)
    manifest.json          what host, build and configuration the run had
    <variant>/*.json        one file per cell, with per-request timings
    <variant>/probe-*.txt  what FreeSWITCH waited for on one lookup
    logs/*.log             the stdout of each cell command
    failures.log           one line per failed cell (empty means clean)
```

The run directory is **working data, not repository content**: `load-testing/results/` and `storage/app/load-tests/` are ignored by git. Keeping a series means writing its report into `docs/` (a new file, never a rewrite of an earlier one), and — if the raw data must survive the host — copying the run directory to external storage outside the repository. Archived series from before this convention remain tracked in git history; that is history, not a pattern to follow.

---

## What a series measures

| Axis | Options | Where defined |
|:--|:--|:--|
| Hardware tier | 1 vCPU/1 GB shared, 1 vCPU/2 GB shared, 2 vCPU/2 GB shared, 2 vCPU/8 GB dedicated, 4 vCPU/16 GB dedicated | `load-testing/matrix.php` → `tiers` |
| XML-serving variant | stock dynamic, stock static, fast controller, Lua over stock, Lua over fast, Octane worker | → `variants` |
| Load profile | 25×1, 100×5, 500×25, 1000×50 | → `xml_profiles` |
| Cache policy | cold, dialplan-off, production-60, cache-hit-60, uniform-30, uncached | → `cache_ttl_profiles` |
| SIP scenarios | 14 feature scenarios plus a call-rate ladder | → `sipp_scenarios` |

The matrix is the single source: the runner, the tests and the documents all cite it.

---

## Known limits, stated up front

- **Repeat noise is roughly 10 %.** A difference smaller than that between two variants is not a finding at three repeats.
- **`manifest.json` describes the invocation, not each cell.** It is rewritten at the start of every run, so a tier measured across several invocations keeps only the last manifest.
- **Octane cells are measured on port 8090**, a different HTTP server from nginx. That is intentional and is recorded in the report path.
