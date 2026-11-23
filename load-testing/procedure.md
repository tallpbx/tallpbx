# Procedure

The ordered run. Each step ends with a checkpoint that must pass before the next step; a failed checkpoint is a stop, not something to work around.

Run the commands from the root of your TallPBX checkout on the orchestrator. The `ssh` commands run their payloads on the specified host.

---

## Step 0 — Orchestrator prerequisites

The orchestrator runs the HTTP load client (through the PBX over SSH) and SIPp (locally).

```bash
php -v            # 8.5
command -v sipp   # required for the SIP phases
command -v git
```

**Checkpoint:** `sipp` and `php` resolve, and `git -C . rev-parse --abbrev-ref HEAD` prints `5.x`.

---

## Step 1 — Host reachability and build parity

```bash
ssh "$PBX_HOST" 'hostname; nproc; free -m | head -2'
ssh "$PBX_HOST" 'cd /var/www/tallpbx && git rev-parse --short HEAD && git branch --show-current && git status --porcelain'
ssh "$SIPP_HOST" 'hostname; nproc; command -v sipp'
```

**Checkpoint:**
- The PBX commit equals `origin/5.x` and `git status --porcelain` is empty.
- The PBX carries `load-testing/scripts/lib/switch.sh`; the runner's preflight refuses to start otherwise.
- SIPp is installed on the orchestrator, not required on the PBX.

---

## Step 2 — Host baseline

Apply every setting in `remote-setup.md` §3–§10 and keep the proof from each section. At minimum confirm:

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && \
  echo "log=$(grep -E "^FS_LOG_LEVEL=" .env)"; \
  echo "esl=$(grep -E "^FS_ESL_EVENT_PROFILE=" .env)"; \
  echo "fpm=$(grep -E "^pm( |\.max_children)" /etc/php/8.5/fpm/pool.d/www.conf | tr "\n" " ")"; \
  echo "cdr=$(systemctl is-active tallpbx-cdr-worker) mod_json_cdr=$(fs_cli -x "module_exists mod_json_cdr")"'
```

**Checkpoint:** `log=notice`, `esl=essential`, `fpm= pm = dynamic pm.max_children = 3` (the installer default for the minimal tier: `pm.start_servers = 1`, `pm.min_spare_servers = 1`, `pm.max_spare_servers = 2`, `pm.max_children = 3`), `cdr=active`, `mod_json_cdr=true`.

**What the shipped default serves:** XML through the fast controller on PHP-FPM (`public/xml-handler-fast.php`), with the two-tier `mod_xml_curl` ladder above it (the [**Laravel Octane**](https://laravel.com/docs/octane) worker — a persistent PHP application server that keeps Laravel booted in memory across requests — on `:8090` first when enabled, then the fast controller); CDR through `public/cdr-fast.php` on PHP-FPM, with the stock block commented underneath for rollback. Octane is an opt-in performance mode, not part of the default, and CDR never uses it. A host whose site file still serves XML or CDR through `index.php` is running the old default — refresh the nginx template before measuring.

**Security posture on the measurement host:** internet SIP scanners consume the switch's session budget and log bandwidth, so the host runs three layers before a series: SIP scanner auto-ban (Attack Protection, 24 h bans), the VoIPBL external blocklist, and geo-blocking in whitelist mode for the countries the generator and the operator live in. **Whitelist the orchestrator address before arming whitelist-mode geo** — the lockout guard refuses otherwise, and forcing it drops your own session.

---

## Step 3 — Seed the tenant

The runner seeds once per tier. To do it by hand:

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && php artisan pbx:load-test:seed \
  --tenant=load-test-beta --domain="$PBX_HOST" \
  --extensions=20 --start=2000 --password=LoadTest1234 --reset'
```

Omit `--domain` entirely to use the appliance's configured realm
(`FS_DEFAULT_SIP_REALM`). A literal realm such as `load.test.local` is a
different SIP domain from the one the PBX serves, so every generated REGISTER
is rejected and the tier reports zero successful calls.

**Checkpoint:** the command reports the tenant, realm, extension count and the SIPp CSV path. Use an alphanumeric password — an unescaped `!` survives a shell differently over SSH and produces a `403` on REGISTER.

---

## Step 4 — Write the manifest

The runner does this automatically at the start of every tier, but it can be run alone:

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && php artisan pbx:load-test:manifest --output=/tmp/manifest.json'
```

**Checkpoint:** the JSON carries a 40-character `build.commit`, `build.dirty=false`, host sizing that matches the tier, and no unredacted secret.

---

## Step 5 — Measure the baseline variant first

Always start with the configuration that has had nothing optimised: `v-stock-dynamic`.

```bash
export PBX_HOST=<pbx-address>
export SSH_OPTS="-i <identity> -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10"

bash load-testing/scripts/load-test-matrix.sh \
  --tier=h1-1c1g --variant=v-stock-dynamic --phase=xml \
  --profile=100x5 --ttl=production-60 --section=dialplan --repeats=3
```

**Expected output:** one `[switch] applied …` / `[switch] asserted …` pair per switch, one cell per repeat, then `baseline restored`, then `Archived raw artifacts …` if an archive root is configured.

**Checkpoint:**
- `load-testing/results/<series>/<tier>/failures.log` is empty.
- Every report in `<tier>/<variant>/` shows `success_rate_percent: 100`.
- The teardown printed `baseline restored` and, if it corrected a mismatch, `baseline-corrected`.
- Re-running the host check from Step 2 returns the same values.

---

## Step 6 — Compare the default and optional XML handlers

The default fast PHP-FPM handler loads only the telephony modules needed to answer FreeSWITCH's routing and account lookups. Optional Laravel Octane loads the same modules and keeps the application in memory between requests. In production, FreeSWITCH can fall back to PHP-FPM if Octane is unavailable. The fast PHP-FPM handler itself can hand a request to the full Laravel application used by the web panel if it catches an application error.

Run both handlers on **every** standard hardware tier (`h1-1c1g`, `h2-2c2g`, `h4-4c8g`, plus any provisioned dedicated tier) under **one shared `SERIES` name** with the exact same Git commit SHA, database, XML workload (`100x5` and `1000x25` `mixed`, `production-60`, 3 repeats — declared in `standard_comparison` in `load-testing/matrix.php`), and dynamic PHP-FPM pool. Never run different XML profiles or sections on different tiers. From the TallPBX checkout root on the orchestrator, preview or run:

```bash
export SERIES="series-$(date -u +%Y%m%d)"

# Preview the exact standard comparison plan for one tier:
bash load-testing/scripts/drivers/run-tier-comparison.sh --tier=h4-4c8g --series="$SERIES" --dry-run

# Run XML lookup comparisons alone for one tier:
PBX_HOST="$PBX_HOST" bash load-testing/scripts/drivers/run-tier-comparison.sh \
  --tier=h4-4c8g --series="$SERIES" --skip-sip --skip-extended
```

Repeat with the matching `PBX_HOST` and `--tier` for each hardware configuration (`h1-1c1g`, `h2-2c2g`, `h4-4c8g`) using the **same `SERIES`**. Use `--variant` only for separate diagnostic runs. Check that both comparison handlers produced all requested reports (including `latency_ms.p50`, `latency_ms.p95`, and `latency_ms.max`) and that `failures.log` is empty. The Octane reports use `127.0.0.1:8090`, and the PHP-FPM reports use the nginx endpoint.

On the test host, the harness starts or stops the Octane service and temporarily selects one XML endpoint in `/etc/freeswitch/autoload_configs/xml_curl.conf.xml`. Isolating the endpoint keeps a SIP test from silently falling back to the other handler. The harness restarts FreeSWITCH whenever it changes that file and restores the original settings and worker state on exit. On a standard appliance, starting or stopping Octane uses the bindings already in memory. [Operations guide for binding changes](../docs/operations.md#stoppingstarting-the-worker-vs-editing-bindings).

> Repeat noise on a 1 vCPU host is about 10 %. Differences smaller than that between two variants are not findings. Raise `--repeats` before drawing a conclusion from a small gap.

---

## Step 7 — Watch the services while the cells run

The XML tests generate no SIP events, so the ESL listener and Reverb must be idle. That is worth checking rather than assuming: a service quietly consuming CPU would put a floor under every number and make the variant comparison meaningless.

Set `SERIES` to your series name in the orchestrator shell and export it. Start the sampler on the PBX, then run the cells and copy the sample into the local series directory.

```bash
ssh "$PBX_HOST" 'nohup bash /var/www/tallpbx/load-testing/scripts/tools/cpuwatch.sh --sample 2 420 "xml-run" > /root/xml-monitor.csv 2>/dev/null &'

bash load-testing/scripts/load-test-matrix.sh \
  --tier=h1-1c1g --variant=v-stock-static --phase=xml \
  --profile=100x5 --ttl=production-60 --section=dialplan --repeats=3

monitoring_dir="load-testing/results/${SERIES}/h1-1c1g/monitoring"
mkdir -p "$monitoring_dir"
scp "$PBX_HOST":/root/xml-monitor.csv "$monitoring_dir/"
```

**Acceptance criteria:**

- `esl-listener` and `reverb` average well under 1 % of a core, and their resident memory is flat from the first sample to the last.
- `php-fpm` shows the load. A figure above 100 % means the pool was saturated across several workers at once, which is expected at this concurrency.
- Any service that is busy but should be idle is a finding, not a detail — it belongs in the report.

The recorded run is in `monitoring/` beside the tier artifacts.

---

## Step 8 — Compare call capacity

From the TallPBX checkout root on the orchestrator, set `SERIES` (shared across all tiers), `PBX_HOST`, and `SIPP_HOST`. Run the full uniform tier comparison (XML `100x5`/`1000x25` + 60-second SIP ladder with `EXTENSIONS=30` + 14-scenario extended/media validation on the baseline tier) once per hardware tier — covering **`h1-1c1g`**, **`h2-2c2g`**, and **`h4-4c8g`** on the **exact same commit SHA**. Both handlers place the same calls at each offered rate over the same 60-second window.

```bash
export SERIES="series-$(date -u +%Y%m%d)"

# Full uniform comparison for one tier (run for h1-1c1g, h2-2c2g, and h4-4c8g under the same SERIES):
PBX_HOST="$PBX_HOST" SIPP_HOST="$SIPP_HOST" \
  bash load-testing/scripts/drivers/run-tier-comparison.sh --tier=h4-4c8g --series="$SERIES"
```

The ladder uses each tier's targeted `cps_ladder` from `load-testing/matrix.php` (for example `[2, 4, 5, 6, 8]` on `h1-1c1g`, `[5, 8, 10, 12, 15, 20]` on `h2-2c2g`, and `[5, 10, 15, 20, 25, 30, 40]` on `h4-4c8g`), ensuring every tier captures a light baseline, a true 100%-completed apples-to-apples rate just below PHP-FPM's ceiling, the PHP-FPM/Octane crossover, and Octane's upper boundary. It checks each mode separately and stops once both handlers complete under 95% of offered calls at two consecutive rates, or immediately if both drop below 80%. A missing result, setup failure, or failed call-detail record check stops the ladder with an error. For one rate, use `load-testing/scripts/load-test-matrix.sh --tier=h4-4c8g --compare-xml-handlers --phase=sip --cps=10`.

Every SIP capacity cell records both **call setup latency** (`INVITE` → `200 OK`) and **call teardown latency** (`BYE` → `200 OK`) in SIPp's `*_rtt.csv` traces and summarizes `setup_p50_ms`, `setup_p95_ms`, `setup_max_ms`, `teardown_p50_ms`, `teardown_p95_ms`, and `teardown_max_ms` into each handler's `ladder.csv`. You can also summarize any cell directory directly with:

```bash
python3 load-testing/scripts/tools/rtt-stats.py --rtd=setup <cell-dir>
python3 load-testing/scripts/tools/rtt-stats.py --rtd=teardown <cell-dir>
```

Once all standard tiers (`h1-1c1g`, `h2-2c2g`, `h4-4c8g`) finish under the same `SERIES`, validate build parity and completeness and export the unified comparison CSV and Markdown tables:

```bash
python3 load-testing/scripts/tools/consolidate-series.py \
  "load-testing/results/${SERIES}" \
  --csv-out="load-testing/results/consolidated/${SERIES}-comparison.csv" \
  --markdown-out="/tmp/${SERIES}-tables.md"
```

`consolidate-series.py` refuses to pass if any tier is missing, ran on a different commit SHA, had a dirty tree, had non-empty `failures.log`, missed an XML profile, or missed setup/teardown latency.

---

## Step 8a — Extended telephony feature scenarios

To validate complex telephony routing patterns beyond basic 1-to-1 extension calls (Ring Groups, IVR menus, Voicemail, Conferences, Call Forwarding, Follow-Me, Time Conditions, Emergency 911, and Call Blocking):

1. **Seed extended and media fixtures on the PBX:**
   ```bash
   ssh "$PBX_HOST" 'cd /var/www/tallpbx && tpbx pbx:load-test:seed \
     --tenant=load-test-beta --domain="$PBX_HOST" \
     --include-extended-fixtures --include-media-fixtures'
   ```

2. **Run extended scenario validation from the generator.** First copy the seeded CSV into `storage/app/load-tests/sipp-users.csv` relative to the generator's checkout at `/var/www/tallpbx`. [Copying the CSV from the PBX](../docs/load-testing-guide.md#best-practices-for-reliable-sip-load-testing).
   ```bash
   ssh "$SIPP_HOST" 'cd /var/www/tallpbx && \
     PBX_HOST="$PBX_HOST" LOAD_GENERATOR_IP="$SIPP_HOST" \
     TENANT=load-test-beta DOMAIN="$PBX_HOST" SKIP_SEED=1 EXTENDED=1 \
     bash load-testing/scripts/pbx-sipp-validate.sh'
   ```

**Multi-leg CDR reconciliation ratios:**
- **Simultaneous Ring Group (1:N):** 1 inbound call ringing $N$ endpoints produces $N+1$ CDR legs (1 answered A-leg + 1 answered B-leg + $N-1$ cancelled B-legs).
- **Call Forwarding (1:1):** 1 caller leg + 1 bridged forwarding leg (`NORMAL_CLEARING`).
- **Call Block:** 1 rejected caller leg with `hangup_cause=CALL_REJECTED`.

---

## Step 9 — Keep the results

Raw run directories are **never committed**. `/load-testing/results/` and `/storage/app/load-tests/` are git-ignored for a reason: one series produces thousands of per-cell files that are unreviewable and bloat every clone. Staging them with `git add -A` after a run is the failure mode this rule exists to prevent.

What a series leaves behind:

```bash
# 1. Update the consolidated comparison datasets and series report
$EDITOR docs/load-testing-results.md

# 2. Stage only consolidated CSVs, reports, and code
git add docs/load-testing-results.md load-testing/results/consolidated/*.csv
git commit -m "docs(load-test): record the <series> report and consolidated data"
```

**Checkpoint:** `git status` shows no raw `load-testing/results/**` or `storage/app/load-tests/**` per-cell paths staged. Consolidated CSVs under `load-testing/results/consolidated/` and documentation in `docs/` are committed. If the raw data must survive the host, copy it to external storage outside the repository — not into git.

---

## Step 10 — When something fails

| Marker | Meaning | Action |
|:--|:--|:--|
| `SWITCH-FAILED <detail>` | An `apply_*` did not read back as applied | Fix the host setting in `remote-setup.md`; do not re-run the cell until the assertion passes |
| `CELL-FAILED <label>` | The cell command exited non-zero | Read `logs/<label>.log`; a `502` means the pool reload window was not waited out |
| `XML-HANDLER-NOT-READY` | The readiness probe never answered after a switch | Confirm nginx and PHP-FPM are healthy, then re-run the variant |
| `UNKNOWN-REQUIREMENT` | The matrix names a switch the runner cannot drive | The matrix and `load-testing/scripts/lib/switch.sh` are out of step — fix the code, not the run |
| `WARNING: could not pull artifacts` | The `scp` back from the PBX failed | Artifacts still exist on the PBX; copy them before the host is deleted |
| `SMOKE-FAILED <tier>/<variant>` | The variant's own preflight failed; no cell ran behind it | Read `<tier>/<variant>/sip-smoke/summary.md` and fix the chain before re-running. The smoke is per variant **with that variant's switches applied** — a smoke that ran under the baseline proves nothing about the variant |
| `register: failed with exit code 124` | SIPp's register step timed out before the cell began | Almost always FreeSWITCH's session-rate limiter: scanners consume `sessions-per-second` and legitimate REGISTERs get `503 Maximum Calls In Progress` (then `481` on the retry). Check `fs_cli -x "show status"` — it prints `N session(s) per Sec out of max 60` and `1000 session(s) max` at the shipped defaults (`max-sessions` in `switch.conf.xml`, `FS_SESSIONS_PER_SECOND` in `.env`). Re-run once the host is idle; a repeatable failure under load is a finding about the host, not the runner |
| `CDR-GATE FAILED … reason=no-tally` | There is no SIPp tally to judge, because registration or the call never completed | Fix the earlier failure first; the gate is reporting a missing measurement, not a missing record |
| A location added by the installer disappears after the first cell | The runner restores `${file}.loadtest.bak`, which is captured once and never refreshed | After changing an nginx template, refresh the backup from the generated file (`cp /etc/nginx/sites-available/pbx /etc/nginx/sites-available/pbx.loadtest.bak`) so the baseline encodes the current default |
| Manual register test gets `401` twice | The scenario expects the generated auth CSV, not the call CSV | Drive registration through `load-testing/scripts/pbx-sipp-validate.sh` (it writes `<run-dir>/sipp-users-auth.csv`), or point `-inf` at that file |
| `FS-NOT-READY` | FreeSWITCH did not report ready (CLI, `mod_json_cdr`) within 90 s | Check `systemctl status freeswitch`. A half-booted switch accepts registrations with no CDR module and rejects the call as `CALL_REJECTED` |
| `FS-RESTARTED label=… pid=a->b` | FreeSWITCH restarted inside the cell window | The cell is invalid — the in-flight call is rejected and the registrations it depends on are rebuilt. Find the restart cause before re-running; never count the cell |
| FS CPU high, `Can't find user […]` at tens per second in `freeswitch.log` | Internet SIP scanners are consuming the session budget and the log | Ban the sources through the application pipeline (Security Center, or `SecurityBanService::ban()` followed by `security:reconcile`), flush their conntrack flows, then confirm the log is quiet for 15 s before starting a cell |

A cell failure never aborts the series: the runner records it in `failures.log` and continues. The series is only trustworthy when `failures.log` is empty.

---

## Step 11 — Write the report

Series summaries and written analyses are recorded as new dated sections in `docs/load-testing-results.md` (or series-specific narrative files like `docs/load-testing-<series>.md`), citing the consolidated comparison datasets committed under `load-testing/results/consolidated/` and the backing run directory.

Every table in the report cites the `load-testing/results/<series>/…` path its numbers came from, so any figure can be traced back to a cell file and from there to a manifest.

When a complete uniform series (`h1-1c1g`, `h2-2c2g`, `h4-4c8g`, plus any dedicated tier tested) passes `consolidate-series.py` on one clean commit SHA, **replace** the older piecemeal multi-date tables and charts in `docs/load-testing-results.md` with that single unified dataset (retiring older ad-hoc CSVs/tables to Git history instead of cobbling comparisons across dates, pool shapes, or mismatched metrics). Include the series name, date, exact build commit SHA, hardware sizing, registered account count (`30`), offered duration (`60s`), cache policy (`production-60`), runtime database, XML `req/sec` + `p50`/`p95`, and SIP completion + individual call `setup` and `teardown` `p50`/`p95` latency.

---

## Step 12 — FreeSWITCH Build & Package Migration Verification

TallPBX supports two installation methods for FreeSWITCH v1.11: pre-compiled SignalWire APT packages (`FSPBX_FREESWITCH_INSTALL_METHOD=packages`, the default) and compiling from source (`FSPBX_FREESWITCH_INSTALL_METHOD=source`).

When transitioning between package and source installations, or when evaluating compilation duration during host provisioning:

1. **Package-to-Source Transition Safety**:
   - The installer automatically detects existing package installations via `freeswitch_packages_are_installed()` and safely invokes `uninstall_freeswitch_packages()`.
   - Configuration files in `/etc/freeswitch/`, database state in `/var/lib/freeswitch/db/`, and media recordings in `/var/lib/freeswitch/recordings/` are preserved during migration.
   - Core source modules are automatically aligned with the required production manifest: `mod_sofia`, `mod_xml_curl`, `mod_hiredis`, `mod_callcenter`, `mod_voicemail`, `mod_local_stream`, and `mod_json_cdr`.

2. **Compilation Durations by Hardware Tier**:
   - **1 vCPU / 1–2 GB RAM**: ~25–35 minutes compilation time (`make -j 1`).
   - **2 vCPU / 2 GB RAM**: ~12–16 minutes compilation time (`make -j 2`).
   - **4 vCPU / 8 GB RAM**: ~5–8 minutes compilation time (`make -j 4`).

3. **Runtime Verification**:
   ```bash
   # Confirm service active and responding
   systemctl status freeswitch
   fs_cli -x "status"

   # Verify required telephony modules are loaded
   fs_cli -x "show modules" | grep -E "mod_xml_curl|mod_sofia|mod_json_cdr|mod_callcenter|mod_hiredis"
   ```

