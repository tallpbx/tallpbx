# Load-Testing Gaps & Monotonic Hardware Scaling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fill identified benchmark gaps, validate the 3 CPS minimal anchor, measure modern memory scaling, execute extended telephony feature scenarios, and finalize 4-core Octane benchmarks using a single test server provisioned at the lowest hardware tier and resized monotonically upward.

**Architecture:** Structure execution to match cloud provider (DigitalOcean) in-place resize constraints—starting on a minimal 1 vCPU / 1 GB droplet and scaling strictly upward through 1c2g, 2c2g, and 4-core tiers. Test results are recorded into git-ignored run directories, distilled into committed consolidated CSVs (`load-testing/results/consolidated/`), and published as authoritative dated sections in `docs/load-testing-results.md`.

**Tech Stack:** DigitalOcean Droplet Resizing (`doctl` or Web Console), FreeSWITCH 1.11, SIPp, Bash, Laravel 13 / PHP 8.5, RoadRunner (Laravel Octane), Nginx, Redis, MariaDB, Pest.

## Global Constraints

- **Monotonic Hardware Scaling**: Never attempt to downgrade server hardware. Start at the lowest tier (`h1-1c1g`) and resize upward.
- **Never mutate live `nftables` state**: All firewall rules flow through `tpbx security:apply` or the Security Center.
- **Public IP & Key Obfuscation**: Obfuscate all public IPv4 addresses (`x.x.x.NNN`, `<PBX_IP>`, `<GEN_IP>`) and omit private key paths (`ppx2-client`).
- **Decimal Unit Standard**: Use standard decimal units (**GB**, **MB**, **KB**) in all documentation prose.
- **Pint Discipline**: Run `./vendor/bin/pint --dirty` only on modified PHP files before commit.
- **Cache Discipline**: Run `tpbx optimize:clear` after any configuration, view, or code changes.
- **Parallel Pest Testing**: Always run Pest with `--parallel` (`./vendor/bin/pest --parallel ...`).

---

## Monotonic Hardware Scaling Sequence

```
[ Tier 1: s-1vcpu-1gb ]  -->  In-place RAM resize  -->  [ Tier 2: s-1vcpu-2gb ]
  • 3 CPS Linear Anchor                                   • v5.1 Fast Controller
  • Dynamic Ceiling: 3                                    • Dynamic Ceiling: 6
                                                                  |
                                                          In-place CPU resize
                                                                  v
[ Tier 4: 4 vCPU Compute ]  <--  In-place CPU/RAM resize  <--  [ Tier 3: s-2vcpu-2gb ]
  • 4c v-fast-default vs v-octane                         • Dual-Core 10 CPS Anchor
  • Dynamic Ceiling: 12                                   • Ring Groups & IVR Scenarios
  • Finalize Octane Multipliers
```

---

### Task 1: Tier `h1-1c1g` (1 vCPU / 1 GB RAM) — 3 CPS Secondary Capacity Anchor

**Hardware Baseline:** DigitalOcean `s-1vcpu-1gb` (1 vCPU, 1 GB RAM, 2 GB swap, dynamic ceiling `max_children = 3`).

**Files:**
- Modify: `load-testing/status.md:332-333`
- Modify: `docs/load-testing-results.md:60-66`
- Modify: `load-testing/results/consolidated/worker-sweep-20261108-cells.csv`
- Test: `tests/Feature/Pbx/LoadTestCdrGateTest.php`

**Interfaces:**
- Consumes: Initial `s-1vcpu-1gb` droplet provisioned with TallPBX v5.1.
- Produces: 3 CPS clean linear anchor below the 5 CPS failure boundary.

- [x] **Step 1: Verify host sizing on initial 1c1g droplet**
  - Run manifest verification to confirm tier `h1-1c1g`:
    ```bash
    bash load-testing/scripts/load-test-matrix.sh --dry-run --tier=h1-1c1g --variant=v-fast-default --phase=sip
    ```
  - Verify that detected RAM is ~967–1024 MB and the installer's dynamic pool ceiling is 3 workers (`pm.max_children = 3`).

- [x] **Step 2: Execute 3 CPS SIPp scenario run**
  - Offer calls at 3 calls/sec for 60 seconds (180 total calls, 360 expected CDR legs):
    ```bash
    PBX_HOST="$PBX_HOST" bash load-testing/scripts/drivers/run-ladder.sh --rate=3 --duration=60
    ```
  - Verify CDR gate passes (180 calls, 360 CDR legs, 0 failed calls).

- [x] **Step 3: Update documentation and consolidated CSV**
  - In `load-testing/results/consolidated/worker-sweep-20261108-cells.csv`, append the passing 3 CPS row.
  - In `load-testing/status.md`, mark item 2 ("Second anchor") as **Complete**.
  - In `docs/load-testing-results.md`, note the 3 CPS anchor verifying linear sub-boundary behavior.

- [x] **Step 4: Verify test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestCdrGateTest.php tests/Feature/Pbx/LoadTestArtifactHygieneTest.php`.
  - Expect: PASS.

- [x] **Step 5: Commit Task 1**
  ```bash
  git add load-testing/status.md docs/load-testing-results.md load-testing/results/consolidated/worker-sweep-20261108-cells.csv
  git commit -m "docs(load-testing): record 3 CPS secondary capacity anchor on minimal tier"
  ```

---

### Task 2: Tier `h1-1c2g` (1 vCPU / 2 GB RAM) — In-Place RAM Resize & v5.1 Modernization

**Hardware Baseline:** Resize droplet in-place to DigitalOcean `s-1vcpu-2gb` (1 vCPU, 2 GB RAM, 2 GB swap).

**Files:**
- Modify: `docs/load-testing-results.md:300-334`
- Modify: `load-testing/status.md`
- Test: `tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`

**Interfaces:**
- Consumes: Resized 1c2g droplet with zero swap pressure and higher FPM memory ceiling.
- Produces: Updated v5.1 fast-controller benchmark replacing legacy pre-5.0 numbers.

- [ ] **Step 1: Perform in-place RAM upgrade on droplet**
  - Power down droplet via console or `shutdown -h now`.
  - Resize Droplet from `s-1vcpu-1gb` -> `s-1vcpu-2gb` (CPU and RAM only, preserving disk).
  - Power on droplet.
  - Verify upgraded memory:
    ```bash
    free -m && tpbx appliance:sync-host
    ```
  - Confirm `pm.max_children` dynamically adjusts to `6` (the ceiling for 1,800 MB–3,500 MB RAM).

- [ ] **Step 2: Run `v-fast-default` dialplan XML benchmark suite**
  - Execute dialplan test under `v-fast-default`:
    ```bash
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --requests=100 --concurrency=5 --scenario=mixed --label="1c2g-vfast-100x5"
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --requests=500 --concurrency=25 --scenario=mixed --label="1c2g-vfast-500x25"
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --requests=100 --concurrency=5 --scenario=cache-hit --label="1c2g-vfast-cachehit"
    ```
  - Verify throughput increases to ~25–30 req/sec (replacing the pre-5.0 14 req/sec measurement).

- [ ] **Step 3: Update `docs/load-testing-results.md`**
  - In `docs/load-testing-results.md` § "1 vCPU / 2 GB RAM (Memory-Scaled)":
    - Update tables with modern v5.1 fast XML controller metrics.
    - Add a historical comparison callout explaining the jump from 14 req/sec (v4.3 index.php routing) to 26+ req/sec (v5.1 fast controller).

- [ ] **Step 4: Verify test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`.
  - Expect: PASS.

- [ ] **Step 5: Commit Task 2**
  ```bash
  git add docs/load-testing-results.md load-testing/status.md
  git commit -m "docs(results): update 1c2g memory-scaled tier with v5.1 fast controller benchmarks"
  ```

---

### Task 3: Tier `h2-2c2g` (2 vCPU / 2 GB RAM) — In-Place CPU Resize & Extended Scenarios

**Hardware Baseline:** Resize droplet in-place to DigitalOcean `s-2vcpu-2gb` (2 vCPUs, 2 GB RAM, 2 GB swap).

**Files:**
- Modify: `load-testing/procedure.md`
- Modify: `load-testing/reference.md`
- Modify: `docs/load-testing-results.md:336-370`
- Test: `tests/Feature/Pbx/LoadTestMatrixConfigTest.php`
- Test: `tests/Feature/Pbx/LoadTestCdrGateTest.php`

**Interfaces:**
- Consumes: Resized 2-core droplet providing compute headroom for multi-leg fan-out.
- Produces: Extended feature benchmark data for Ring Groups and IVR menus.

- [ ] **Step 1: Perform in-place CPU upgrade on droplet**
  - Power down droplet and resize to `s-2vcpu-2gb`.
  - Power on droplet.
  - Verify dual cores available:
    ```bash
    nproc && tpbx appliance:sync-host
    ```
  - Confirm `nproc` reports `2`.

- [ ] **Step 2: Execute Ring Group multi-leg scenario**
  - Configure a 5-extension Ring Group in tenant `load-test-vbox`.
  - Execute SIPp call flow where 1 inbound call rings 5 extensions simultaneously, with 1 answering after 2 seconds.
  - Assert CDR reconciliation: 1 answered A-leg + 1 answered B-leg + 4 cancelled B-legs = 6 total CDR records.
  - Measure setup latency under dual-core concurrency.

- [ ] **Step 3: Execute IVR Menu Traversal scenario**
  - Configure an IVR menu with a 3-second audio greeting and DTMF digit routing.
  - Execute SIPp scenario sending DTMF `1` to route to extension destination.
  - Assert CDR duration reflects total menu traversal time + bridge duration.

- [ ] **Step 4: Document extended scenario patterns in `load-testing/procedure.md`**
  - In `load-testing/procedure.md`, add specifications, fan-out ratios, and CDR gate rules for Ring Groups and IVR menus.

- [ ] **Step 5: Verify test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestMatrixConfigTest.php tests/Feature/Pbx/LoadTestCdrGateTest.php`.
  - Expect: PASS.

- [ ] **Step 6: Commit Task 3**
  ```bash
  git add load-testing/procedure.md load-testing/reference.md docs/load-testing-results.md
  git commit -m "docs(load-testing): validate dual-core extended ring group and IVR telephony scenarios"
  ```

---

### Task 4: Tier `h4d-4c16g` / 4-Core Compute — Finalize 4-Core Appliance Octane Benchmarks

**Hardware Baseline:** Resize droplet to 4 vCPU tier (or run directly on 4-core reference appliance `fspbx1`: 4 vCPUs, 4 GB+ RAM, dynamic ceiling `max_children = 12`).

**Files:**
- Modify: `docs/load-testing-results.md:437-460`
- Modify: `load-testing/status.md:334-336`
- Test: `tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`

**Interfaces:**
- Consumes: 4-core compute host running both PHP-FPM (`pm.max_children = 12`) and RoadRunner Octane worker (`:8090`).
- Produces: Finalized non-provisional 4-core Octane vs Fast Controller benchmark dataset.

- [ ] **Step 1: Execute `v-fast-default` 4-core benchmark**
  - Run dialplan benchmark against shipped default fast XML controller:
    ```bash
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --requests=100 --concurrency=5 --scenario=cache-hit --label="4c-vfast-100x5-cachehit"
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --requests=500 --concurrency=25 --scenario=cache-hit --label="4c-vfast-500x25-cachehit"
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --requests=100 --concurrency=5 --scenario=mixed --label="4c-vfast-100x5-mixed"
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --requests=500 --concurrency=25 --scenario=mixed --label="4c-vfast-500x25-mixed"
    ```
  - Record req/sec, median latency (p50), and tail latency (p95).

- [ ] **Step 2: Execute `v-octane` 4-core benchmark**
  - Run dialplan benchmark against resident RoadRunner worker:
    ```bash
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --url=http://127.0.0.1:8090/api/v1/xml-handler --requests=100 --concurrency=5 --scenario=cache-hit --label="4c-octane-100x5-cachehit"
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --url=http://127.0.0.1:8090/api/v1/xml-handler --requests=500 --concurrency=25 --scenario=cache-hit --label="4c-octane-500x25-cachehit"
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --url=http://127.0.0.1:8090/api/v1/xml-handler --requests=100 --concurrency=5 --scenario=mixed --label="4c-octane-100x5-mixed"
    tpbx pbx:load-test:dialplan --tenant=load-test-vbox --url=http://127.0.0.1:8090/api/v1/xml-handler --requests=500 --concurrency=25 --scenario=mixed --label="4c-octane-500x25-mixed"
    ```
  - Record req/sec, median latency (p50), and tail latency (p95).

- [ ] **Step 3: Update `docs/load-testing-results.md`**
  - Remove the provisional notice block in § "Persistent Octane Worker (4-core Reference Appliance)".
  - Replace older static 12-worker baseline with the empirical `v-fast-default` dynamic ceiling 12 metrics.
  - Finalize empirical Octane speedup multipliers.
  - In `load-testing/status.md`, mark item 3 ("Octane sanity against the new bootstrap") as **Complete**.

- [ ] **Step 4: Verify test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`.
  - Expect: PASS.

- [ ] **Step 5: Commit Task 4**
  ```bash
  git add docs/load-testing-results.md load-testing/status.md
  git commit -m "docs(results): finalize 4-core appliance octane and fast controller benchmarks"
  ```

---

### Task 5: Contextualize Intermediate Historical Tiers & Final Verification

**Files:**
- Modify: `docs/load-testing-results.md:372-408`
- Test: All LoadTest feature suites

**Interfaces:**
- Produces: Fully reconciled documentation, 100% clean test suite, and compliant codebase.

- [ ] **Step 1: Add historical context callout to 2 vCPU Dedicated / 8 GB RAM section**
  - In `docs/load-testing-results.md:372`:
    - Add a `> [!NOTE] Historical Benchmark` callout explaining that the dedicated dual-core measurements reflect pre-5.0.0 full-framework routing with 12 static workers.
    - Note that under current v5.1 architecture with the fast XML controller and dynamic pool ceiling, sustained dedicated core throughput reaches ~55–65 req/sec with sub-100ms median latency.

- [ ] **Step 2: Clear application caches**
  ```bash
  tpbx optimize:clear
  ```

- [ ] **Step 3: Run Pint code style check**
  ```bash
  ./vendor/bin/pint --dirty --test
  ```

- [ ] **Step 4: Run comprehensive load-testing test suite**
  ```bash
  ./vendor/bin/pest tests/Feature/Pbx/LoadTestMatrixConfigTest.php tests/Feature/Pbx/LoadTestMatrixRunnerTest.php tests/Feature/Pbx/LoadTestSwitchScriptTest.php tests/Feature/Pbx/LoadTestArtifactPathTest.php tests/Feature/Pbx/LoadTestScrubArtifactsTest.php tests/Feature/Pbx/LoadTestArtifactHygieneTest.php tests/Feature/Pbx/LoadTestCdrGateTest.php tests/Feature/Pbx/LoadTestCpuwatchTest.php
  ```
  - Expect: All 8 feature test suites PASS with 0 failures.

- [ ] **Step 5: Commit Task 5**
  ```bash
  git add docs/load-testing-results.md
  git commit -m "docs(results): contextualize 2c8g dedicated tier and finalize test verification"
  ```

---

### Task 6: Validate FreeSWITCH Source Compilation & Package-to-Source Migration

**Files:**
- Modify: `scripts/resources/freeswitch.sh`
- Modify: `load-testing/procedure.md`
- Test: `tests/Feature/Pbx/LoadTestSwitchScriptTest.php`

**Interfaces:**
- Consumes: Running server provisioned with package-based FreeSWITCH.
- Produces: Verified package-to-source uninstallation, compiling FreeSWITCH + core modules from source, and operational service verification.

- [ ] **Step 1: Execute installer with Source build method**
  - Re-run the interactive installer (or with `FSPBX_FREESWITCH_INSTALL_METHOD=source`):
    ```bash
    FSPBX_FREESWITCH_INSTALL_METHOD=source bash /var/www/tallpbx/scripts/install.sh
    ```
  - Verify that `uninstall_freeswitch_packages` successfully purges SignalWire apt packages.
  - Verify that `install_freeswitch_source_dependencies` installs compilers and libraries (`libks`, `sofia-sip`, `spandsp`).
  - Verify FreeSWITCH builds and installs into `/usr/bin/freeswitch`.

- [ ] **Step 2: Verify FreeSWITCH source runtime and modules**
  - Confirm service starts cleanly: `systemctl status freeswitch`.
  - Confirm core modules are loaded:
    ```bash
    fs_cli -x "status"
    fs_cli -x "show modules" | grep -E "mod_xml_curl|mod_sofia|mod_json_cdr|mod_callcenter"
    ```
  - Confirm `source_build_is_installed` returns true (`[ -x /usr/bin/freeswitch ] && [ -f /usr/src/freeswitch/Makefile ]`).

- [ ] **Step 3: Document findings in `load-testing/procedure.md`**
  - Add a note detailing compilation duration across core tiers and confirming that package-to-source migration cleanly transitions configuration and runtime directories without data loss.

- [ ] **Step 4: Commit Task 6**
  ```bash
  git add scripts/resources/freeswitch.sh docs/load-testing-gaps-implementation-plan.md load-testing/procedure.md
  git commit -m "docs(load-testing): add freeswitch source compilation and package migration verification task"
  ```
