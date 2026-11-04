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
4. **Never leave a half-applied pair.** The Lua proxy is a flag in `.env` *and* two commented params in `lua.conf.xml`. One without the other measures nothing. `restore_baseline` reconciles them and prints `baseline-corrected`.
5. **Never commit host credentials.** No IP addresses, key names, key paths or passwords belong in this directory. The operator supplies `PBX_HOST` and the SSH identity; the runner uses the ambient SSH configuration.
6. **Never edit `docs/load-testing-results.md`** as part of a series. The previous series record stays untouched; a new report is written in a new file.

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
load-testing/results/<series>/<tier>/
    manifest.json          what host, build and configuration the run had
    <variant>/*.json        one file per cell, with per-request timings
    <variant>/probe-*.txt  what FreeSWITCH waited for on one lookup
    logs/*.log             the stdout of each cell command
    failures.log           one line per failed cell (empty means clean)
```

`load-testing/results/` is the **durable** copy: it is inside the repository, so it survives the measurement hosts being deleted and travels with a clone.

The PBX under test also keeps its own copy under `storage/app/load-tests/<series>/`. That one is deliberately ignored by git so a measurement can never dirty the host's working tree, and it is disposable.

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
- **An HTTP XML client cannot see a Lua cache hit.** A hit is answered inside FreeSWITCH and never reaches nginx or PHP. The lookup probe (`probe_lookup`) is the only measurement on that path.
- **The probe reports the configured state, not proof of who served.** `lua_bindings=on` means the binding is active; the mirror-TTL check in `docs/load-testing-guide.md` is what proves a hit.
- **`manifest.json` describes the invocation, not each cell.** It is rewritten at the start of every run, so a tier measured across several invocations keeps only the last manifest.
- **Octane cells are measured on port 8090**, a different HTTP server from nginx. That is intentional and is recorded in the report path.
