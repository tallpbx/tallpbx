# Unified Load-Testing Series Runbook (Single-Entry Agent Guide)

Follow this runbook start to finish to execute one clean, apples-to-apples **PHP-FPM** vs. [**Laravel Octane**](https://laravel.com/docs/octane) (optional persistent PHP worker keeping the telephony XML handler in memory between FreeSWITCH lookups) benchmark series across all three standard shared-CPU tiers — **`h1-1c1g`**, **`h2-2c2g`**, and **`h4-4c8g`** — on **one clean Git commit SHA**, validate completeness, retire legacy multi-date capacity data, and replace the published tables and charts in [`docs/load-testing-results.md`](../docs/load-testing-results.md).

> **Non-negotiable safety & completion rules (from [`load-testing/README.md`](README.md#rules-you-must-not-break)):**
> 1. **Stop at the first execution failure.** Preserve the failed tier directory and inspect its logs. Fix the cause before retrying. Move the failed tier directory outside the series and retry that tier only when the pinned commit is unchanged. If the fix changes the PBX or harness commit, start a new series. [Failure diagnostics](procedure.md#step-10--when-something-fails).
> 2. **Require a clean default export for publication.** Use the default tier set and omit `--allow-incomplete`. Run the extended suite on the first tier. Later tiers can use `--skip-extended` after a complete pass has been saved under the same series and commit.
> 3. **Never mutate live `nftables` state** (`nft add/insert/delete/flush`). Manage firewall rules only through `tpbx security:apply` / Security Center.
> 4. **Never commit raw per-cell artifacts** (`load-testing/results/<series>/` or `storage/app/load-tests/`). Never use `git add -A`. Commit only consolidated CSVs under `load-testing/results/consolidated/` and docs/code.
> 5. **Never expose full public IP addresses** in docs or committed logs (obfuscate to last octet, e.g. `x.x.x.218`).

---

## Step 1 — Pre-Flight & Shared Series Setup (Orchestrator)

Run from the root of your TallPBX checkout on the orchestrator. Pick **one shared `SERIES` name** and reuse it across all three tiers:

```bash
export SERIES="series-$(date -u +%Y%m%d)"
export PBX_HOST="<pbx-ip-or-host>"
export SIPP_HOST="<sipp-generator-ip-or-host>"
# Optional if using a dedicated SSH key:
# export SSH_IDENTITY="$HOME/.ssh/<key>"
```

Verify orchestrator, PBX, and generator parity before running any cell:

```bash
# 1. Orchestrator tools & clean branch
php -v && command -v sipp && python3 --version && git branch --show-current

# 2. PBX clean commit SHA (must match origin/5.x and be identical across all 3 tiers)
ssh "$PBX_HOST" 'cd /var/www/tallpbx && git rev-parse HEAD && git status --porcelain'

# 3. PBX baseline settings (see remote-setup.md §3–§10 if any check fails)
ssh "$PBX_HOST" 'cd /var/www/tallpbx && \
  echo "log=$(grep -E "^FS_LOG_LEVEL=" .env)" && \
  echo "esl=$(grep -E "^FS_ESL_EVENT_PROFILE=" .env)" && \
  echo "db=$(grep -E "^FS_DB_DRIVER=" .env || echo sqlite)" && \
  echo "fpm=$(grep -E "^pm( |\.max_children)" /etc/php/8.5/fpm/pool.d/www.conf | tr "\n" " ")" && \
  echo "cdr=$(systemctl is-active tallpbx-cdr-worker) mod_json_cdr=$(fs_cli -x "module_exists mod_json_cdr")" && \
  test -x /var/www/tallpbx/rr && echo "rr=ok"'

# 4. Unconstrained SIPp load generator (separate host, no firewall throttling SIP/RTP)
ssh "$SIPP_HOST" 'hostname && nproc && command -v sipp'
```

**Checkpoint:** `git status --porcelain` is empty on the PBX, `FS_LOG_LEVEL=notice`, `FS_ESL_EVENT_PROFILE=essential`, `cdr=active`, `mod_json_cdr=true`, `/var/www/tallpbx/rr` is executable, and `sipp` resolves on `SIPP_HOST`.

---

## Step 2 — Run the Identical Standard Comparison on Every Tier

Every tier runs the exact same declarative contract (`standard_comparison` in [`load-testing/matrix.php`](matrix.php)):

- **Matched XML lookup battery (`v-fast-default` vs. `v-octane`):** `100x5` and `1000x25` (`mixed`, `production-60`, 3 repeats) recording `req/sec`, `p50`, `p95`, and `max`.
- **Matched 60-second SIP capacity and latency ladder** with 30 accounts. Each rung records completion, CDR reconciliation, service CPU, and per-call setup and teardown latency.
  - `h1-1c1g` (1 vCPU / 1 GB): `[2, 4, 5, 6, 8]` CPS
  - `h2-2c2g` (2 vCPU / 2 GB): `[5, 8, 10, 12, 15, 20]` CPS
  - `h4-4c8g` (4 shared vCPU / 8 GB): `[5, 10, 15, 20, 25, 30, 40]` CPS
  - Each handler stops after two consecutive rungs below 95% completion or one rung below 80%. A measured overload rung stays in the comparison.
- **14-scenario functional + media verification (`EXTENDED=1 MEDIA_FLOW=1`).**

### Checks before the capacity ladder

1. Run the tier driver with `--dry-run` to preview the matrix without placing calls. Use the same clean commit on the orchestrator and PBX. The driver creates a fresh tier attempt and fingerprints the SIPp runner and scenarios on the generator.
2. The driver runs XML validation and, on the tier selected for the extended suite, checks the named functional and media verdicts before starting the ladder. A log line starting with `Running` records only the start. A pass is recorded as `- <step>: passed`.
3. Before either handler starts the ladder, the driver places two test calls through each handler. The capacity responder waits for BYE immediately after ACK. Both calls must receive `200 OK` for the initial BYE. If the check fails, inspect `<variant>/capacity-preflight/extension-calls.log` and the SIP message trace before retrying. The short calls carry no capacity result.

### Evidence and retries

- `comparison.json` binds the tier attempt to the series, PBX commit, and generator fingerprint. The driver marks it complete after the requested phases finish.
- `driver-logs/sip-events.tsv` records preflights, started and measured rungs, and early stops. A started rung without a measurement is an execution failure. The orchestrator keeps the event log and failure records separate from PBX artifact copies.
- `<variant>/sip-cps<rate>/harness.txt` identifies the generator files used for each rung. Each rung must account for `rate × 60` offered calls and record setup and teardown p50, p95, and maximum latency.
- To check a completed 1-vCPU attempt before running another tier, run `python3 load-testing/scripts/tools/consolidate-series.py "load-testing/results/${SERIES}" --tiers=h1-1c1g`. This diagnostic expects the extended suite to have run on that tier.
- For historical extended runs, recover the original generator `summary.md` if the local log contains only progress. Preserve the original artifacts and any operator-confirmed result. A missing local copy alone does not justify repeating a known successful test.

### 2A — Tier 1: `h1-1c1g` (1 vCPU / 1 GB RAM)

```bash
bash load-testing/scripts/drivers/run-tier-comparison.sh --tier=h1-1c1g --series="$SERIES"
```

### 2B — Tier 2: `h2-2c2g` (2 vCPU / 2 GB RAM)

If resizing the same PBX VM in place (`1 vCPU / 1 GB` → `2 vCPU / 2 GB`), resize the VM, update `PBX_HOST` if its IP changed, and re-derive the installer's dynamic PHP-FPM ceiling (`pm.max_children = 6`) before running:

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && bash scripts/resources/php-fpm-pool.sh && nproc && free -m | head -2'
bash load-testing/scripts/drivers/run-tier-comparison.sh --tier=h2-2c2g --series="$SERIES"
```

### 2C — Tier 3: `h4-4c8g` (4 shared vCPU / 8 GB RAM)

If resizing in place (`2 vCPU / 2 GB` → `4 shared vCPU / 8 GB`), resize the VM and re-derive the dynamic PHP-FPM ceiling (`pm.max_children = 12`) before running:

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && bash scripts/resources/php-fpm-pool.sh && nproc && free -m | head -2'
bash load-testing/scripts/drivers/run-tier-comparison.sh --tier=h4-4c8g --series="$SERIES"
```

*(Optional: if dedicated tiers `h2d-2c8g` or `h4d-4c16g` are provisioned, run the exact same command with `--tier=h2d-2c8g` / `--tier=h4d-4c16g` under the same `SERIES`.)*

---

## Step 3 — Validate Cross-Tier Parity & Export Consolidated Data

Run [`consolidate-series.py`](scripts/tools/consolidate-series.py) against the completed `SERIES` directory. It enforces six hard pass/fail gates and exits non-zero if **any** gap exists:

1. All three tiers have completed attempt records, clean PBX manifests on one commit, and matching generator fingerprints. Each XML report and SIP measurement belongs to its tier attempt.
2. Each SIP ladder has a passing two-call preflight for both handlers, a complete record of each attempted rung, and no execution failure.
3. Both `v-fast-default` and `v-octane` completed both XML profiles (`100x5` and `1000x25` `mixed`, `production-60`, 3 repeats, 100% HTTP 200) with `p50`, `p95`, and `max` latency.
4. Both handlers have matched SIP `ladder.csv` rungs starting at each tier's light baseline, including **at least one 100%-completed (non-dropping) apples-to-apples rate** on every tier.
5. Every rung accounts for the configured offered call count and has setup and teardown p50, p95, and maximum latency. Any early stop follows the configured thresholds.
6. At least one tier records a passing verdict for every named extended and media step, including registration setup, on the same pinned harness.

```bash
python3 load-testing/scripts/tools/consolidate-series.py \
  "load-testing/results/${SERIES}" \
  --csv-out="load-testing/results/consolidated/${SERIES}-comparison.csv" \
  --markdown-out="/tmp/${SERIES}-tables.md"
```

**Checkpoint:** Exit code is `0` with zero `ERROR:` lines. Inspect both `load-testing/results/consolidated/${SERIES}-comparison.csv` and `/tmp/${SERIES}-tables.md`, confirming:
- All three tiers (`h1-1c1g`, `h2-2c2g`, `h4-4c8g`) share the exact same 40-char `git_commit`.
- Both **Non-Dropping Call Setup Latency (100% Completed for Both Handlers)** and **XML Lookup Latency** two-color Mermaid charts (🟧 Octane `#f97316` / 🟦 PHP-FPM `#2563eb`) are generated with real `p50` and `p95` figures for every tier.
- Teardown latency (`BYE` → `200 OK`) `p50`/`p95` is populated for every SIP rung.

---

## Step 4 — Retire Legacy Data & Replace Published Documentation

Once Step 3 exits `0`:

1. **Replace [`docs/load-testing-results.md`](../docs/load-testing-results.md)** using the single unified series in `/tmp/${SERIES}-tables.md` and `load-testing/results/consolidated/${SERIES}-comparison.csv`:
   - Replace all multi-date cobbled tables and the legacy overload-only call-setup chart with the clean unified tables and charts (including the **Non-Dropping Call Setup Latency** apples-to-apples chart, individual call **setup & teardown** latency across light/busy/boundary loads, and **XML Lookup Speed & Latency** across `100x5` and `1000x25`).
   - Follow [`.agents/skills/tallpbx-documentation/SKILL.md`](../.agents/skills/tallpbx-documentation/SKILL.md): introduce **Laravel Octane** in full on first mention linked to `https://laravel.com/docs/octane`, use `median / p95` consistently across all latency tables, and cite the exact build SHA, date, hardware, account count (`30`), duration (`60s`), and cache policy (`production-60`).
2. **Retire legacy capacity CSVs** (keep Git history as the archive):
   - Remove `load-testing/results/consolidated/worker-sweep-20261108-cells.csv`, `worker-sweep-20261108-services.csv`, `worker-sweep-20261108-setup-latency.csv`, and `worker-sweep-20261108-panel-probe.csv` once no published table in `docs/load-testing-results.md` cites them.
   - **SQLite vs. MariaDB note:** `docs/load-testing-2026-11-17.md` and `load-testing/results/consolidated/refresh-20261117-cells.csv` also carry the November 17 `FS_DB_DRIVER=mariadb` vs. `sqlite` contention benchmark. Either keep that short standalone SQLite-vs-MariaDB section (if not re-run), or re-run `load-testing/scripts/tools/fs-db-contention.sh` on `h2-2c2g` / `h4-4c8g` and retire `docs/load-testing-2026-11-17.md` + `refresh-20261117-cells.csv` too so only the new unified consolidated CSV remains.
3. **Update [`load-testing/status.md`](status.md):** Mark the unified replacement series complete with the series name, date, and commit SHA.
4. **Update [`docs/telephony-capacity-scaling-guide.md`](../docs/telephony-capacity-scaling-guide.md)** and [`INSTALL.md`](../INSTALL.md) if the newly measured non-dropping capacities change any published sizing figures.

---

## Step 5 — Verify & Commit

Verify there are no broken links, leaked public IPs, or raw artifacts staged, then commit **explicitly named files only** (never `git add -A`):

```bash
./vendor/bin/pest tests/Feature/Pbx/LoadTestScrubArtifactsTest.php tests/Feature/Pbx/LoadTestArtifactHygieneTest.php
git status --short
```

Confirm `git status --short` shows **no** raw files under `load-testing/results/<series>/` or `storage/app/load-tests/`, stage only the consolidated CSV(s) and updated docs, commit to `5.x`, and push.
