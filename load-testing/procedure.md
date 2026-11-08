# Procedure

The ordered run. Each step ends with a checkpoint that must pass before the next step; a failed checkpoint is a stop, not something to work around.

Everything in `code font` is a command you run on the orchestrator unless it is prefixed with `ssh "$PBX_HOST"`.

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

Apply every setting in `remote-setup.md` §3–§11 and keep the proof from each section. At minimum confirm:

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && \
  echo "log=$(grep -E "^FS_LOG_LEVEL=" .env)"; \
  echo "esl=$(grep -E "^FS_ESL_EVENT_PROFILE=" .env)"; \
  echo "fpm=$(grep -E "^pm( |\.max_children)" /etc/php/8.5/fpm/pool.d/www.conf | tr "\n" " ")"; \
  echo "cdr=$(systemctl is-active tallpbx-cdr-worker) mod_json_cdr=$(fs_cli -x "module_exists mod_json_cdr")"'
```

**Checkpoint:** `log=notice`, `esl=essential`, `fpm= pm = static pm.max_children = 5`, `cdr=active`, `mod_json_cdr=true`.

**What the shipped default serves:** XML through the fast controller on PHP-FPM, with the three-tier ladder above it (Octane worker first when enabled, then the fast controller, then the stock full application); CDR through `public/cdr-fast.php` on PHP-FPM, with the stock block commented underneath for rollback. Octane is an opt-in performance mode, not part of the default, and CDR never uses it. A host whose site file still serves XML or CDR through `index.php` is running the old default — refresh the nginx template before measuring.

---

## Step 3 — Seed the tenant

The runner seeds once per tier. To do it by hand:

```bash
ssh "$PBX_HOST" 'cd /var/www/tallpbx && php artisan pbx:load-test:seed \
  --tenant=load-test-beta --domain=load.test.local \
  --extensions=20 --start=2000 --password=LoadTest1234 --reset'
```

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

## Step 6 — Remaining variants

One invocation per variant, each applying its own switches:

```bash
for variant in v-stock-static v-fast v-octane; do
  bash load-testing/scripts/load-test-matrix.sh \
    --tier=h1-1c1g --variant="$variant" --phase=xml \
    --profile=100x5 --ttl=production-60 --section=dialplan --repeats=3
done
```

**Checkpoint per variant:** same as Step 5, plus the report path proves where the load went — `v-octane` cells are labelled against `127.0.0.1:8090`, every other variant against nginx.

> Repeat noise on a 1 vCPU host is about 10 %. Differences smaller than that between two variants are not findings. Raise `--repeats` before drawing a conclusion from a small gap.

---

## Step 7 — Watch the services while the cells run

The XML tests generate no SIP events, so the ESL listener and Reverb must be idle. That is worth checking rather than assuming: a service quietly consuming CPU would put a floor under every number and make the variant comparison meaningless.

Start the sampler on the PBX, then run the cells, then stop it:

```bash
ssh "$PBX_HOST" 'nohup bash /root/cpuwatch.sh --sample 2 420 "xml-run" > /root/xml-monitor.csv 2>/dev/null &'

bash load-testing/scripts/load-test-matrix.sh \
  --tier=h1-1c1g --variant=v-stock-static --phase=xml \
  --profile=100x5 --ttl=production-60 --section=dialplan --repeats=3

scp "$PBX_HOST":/root/xml-monitor.csv load-testing/results/<series>/<tier>/monitoring/
```

**Acceptance criteria:**

- `esl-listener` and `reverb` average well under 1 % of a core, and their resident memory is flat from the first sample to the last.
- `php-fpm` shows the load. A figure above 100 % means the pool was saturated across several workers at once, which is expected at this concurrency.
- Any service that is busy but should be idle is a finding, not a detail — it belongs in the report.

The recorded run is in `monitoring/` beside the tier artifacts.

---

## Step 8 — SIP phases

```bash
bash load-testing/scripts/load-test-matrix.sh \
  --tier=h1-1c1g --variant=v-stock-static --phase=sip --cps=5
```

**Checkpoint:** the SIPp log under `<tier>/<variant>/sip-cps<n>/` shows the expected scenarios completing, and `failures.log` stays empty.

This is the only phase where ESL profile, broadcast throttling and CDR transport can change a number, because it is the only phase that produces events.

---

## Step 9 — Keep the results

```bash
git add load-testing/results/<series>
git commit -m "chore(load-test): record <series> raw artifacts"
```

**Checkpoint:** `git status` is clean and the results directory is inside the commit. Results kept only under `storage/` are ignored by git and can be removed by `git clean -fdx`.

---

## Step 10 — When something fails

| Marker | Meaning | Action |
|:--|:--|:--|
| `SWITCH-FAILED <detail>` | An `apply_*` did not read back as applied | Fix the host setting in `remote-setup.md`; do not re-run the cell until the assertion passes |
| `CELL-FAILED <label>` | The cell command exited non-zero | Read `logs/<label>.log`; a `502` means the pool reload window was not waited out |
| `XML-HANDLER-NOT-READY` | The readiness probe never answered after a switch | Confirm nginx and PHP-FPM are healthy, then re-run the variant |
| `UNKNOWN-REQUIREMENT` | The matrix names a switch the runner cannot drive | The matrix and `scripts/lib/switch.sh` are out of step — fix the code, not the run |
| `baseline-corrected …` | The restore found and fixed a mismatched Lua pair | Not an error; it means the previous state was incoherent. Investigate how it got that way |
| `WARNING: could not pull artifacts` | The `scp` back from the PBX failed | Artifacts still exist on the PBX; copy them before the host is deleted |
| `SMOKE-FAILED <tier>/<variant>` | The variant's own preflight failed; no cell ran behind it | Read `<tier>/<variant>/sip-smoke/summary.md` and fix the chain before re-running. The smoke is per variant **with that variant's switches applied** — a smoke that ran under the baseline proves nothing about the variant |
| `register: failed with exit code 124` | SIPp's register step timed out before the cell began | Almost always FreeSWITCH's session-rate limiter: scanners consume `sessions-per-second` and legitimate REGISTERs get `503 Maximum Calls In Progress` (then `481` on the retry). Check `fs_cli -x "show status"` — it prints `N session(s) per Sec out of max 60` and `1000 session(s) max` at the shipped defaults (`max-sessions` in `switch.conf.xml`, `FS_SESSIONS_PER_SECOND` in `.env`). Re-run once the host is idle; a repeatable failure under load is a finding about the host, not the runner |
| `CDR-GATE FAILED … reason=no-tally` | There is no SIPp tally to judge, because registration or the call never completed | Fix the earlier failure first; the gate is reporting a missing measurement, not a missing record |
| A location added by the installer disappears after the first cell | The runner restores `${file}.loadtest.bak`, which is captured once and never refreshed | After changing an nginx template, refresh the backup from the generated file (`cp /etc/nginx/sites-available/pbx /etc/nginx/sites-available/pbx.loadtest.bak`) so the baseline encodes the current default |
| Manual register test gets `401` twice | The scenario expects the generated auth CSV, not the call CSV | Drive registration through `scripts/pbx-sipp-validate.sh` (it writes `<run-dir>/sipp-users-auth.csv`), or point `-inf` at that file |

A cell failure never aborts the series: the runner records it in `failures.log` and continues. The series is only trustworthy when `failures.log` is empty.

---

## Step 11 — Write the report

A series produces a **new** report file. `docs/load-testing-results.md` is the record of an earlier series and is not rewritten.

Every table in the new report cites the `load-testing/results/<series>/…` path its numbers came from, so any figure can be traced back to a cell file and from there to a manifest.
