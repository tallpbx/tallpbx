# Load-Testing Subsystem Consolidation & Refresh Plan Retirement

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close out and retire `docs/load-testing-refresh-implementation-plan.md`, consolidate and streamline documentation across `load-testing/` and `docs/`, harmonize configuration baselines and artifact paths, and prune obsolete test configurations.

**Architecture:** Enforce a clean 3-pillar documentation model (operational admin guide in `docs/load-testing-guide.md`, automated harness and engineering logs in `load-testing/`, authoritative results and sizing in `docs/load-testing-results.md`), harmonize artifact paths and domain/realm derivation, and prune stale experimental variants.

**Tech Stack:** FreeSWITCH 1.11, SIPp, Bash, Laravel 13 / PHP 8.5, Pest, Nginx, PHP-FPM, RoadRunner/Octane.

## Global Constraints

- Never mutate live `nftables` state.
- Obfuscate all public IPv4 addresses (`x.x.x.NNN`, `<PBX_IP>`, `<GEN_IP>`) and ensure zero occurrences of private key paths (`ppx2-client`).
- Adhere to the decimal unit standard in documentation (`GB`, `MB`, `KB`, never `GiB`/`MiB` in prose).
- Pint discipline: `./vendor/bin/pint --dirty` only on modified PHP files.
- Cache discipline: `tpbx optimize:clear` after view, configuration, or class edits.
- Always run Pest with `--parallel` (`./vendor/bin/pest --parallel ...`).

---

### Task 1: Close Out and Retire `docs/load-testing-refresh-implementation-plan.md`

**Files:**
- Modify: `load-testing/matrix.php:11-13`
- Modify: `load-testing/scripts/load-test-matrix.sh:5-7`
- Modify: `load-testing/status.md:337-338`
- Modify: `CHANGELOG.md`
- Delete: `docs/load-testing-refresh-implementation-plan.md`
- Test: `tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`

**Interfaces:**
- Consumes: `docs/load-testing-refresh-implementation-plan.md`
- Produces: Cleaned documentation without dangling links to the deleted plan.

- [ ] **Step 1: Update comments referencing `docs/load-testing-refresh-implementation-plan.md`**
  - In `load-testing/matrix.php:11-13`: Replace reference with `load-testing/README.md` and `docs/load-testing-results.md`.
  - In `load-testing/scripts/load-test-matrix.sh:5-7`: Replace reference with `load-testing/README.md`.
  - In `load-testing/status.md:337-338`: Replace reference with `load-testing/decisions.md` and `docs/load-testing-results.md`.

- [ ] **Step 2: Update `CHANGELOG.md`**
  - Under `## [Unreleased]` `### Changed`, add an entry recording the retirement:
    ```markdown
    - **Load-Testing Refresh Implementation Plan Retired**: Removed `docs/load-testing-refresh-implementation-plan.md` following the full delivery of the automated matrix runner, 1 vCPU and 2 vCPU worker sweeps, fast front controllers (`public/xml-handler-fast.php` and `public/cdr-fast.php`), and consolidation of authoritative results into `docs/load-testing-results.md`.
    ```

- [ ] **Step 3: Delete `docs/load-testing-refresh-implementation-plan.md`**
  - Run `rm docs/load-testing-refresh-implementation-plan.md`.

- [ ] **Step 4: Verify test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`.
  - Expect: PASS (7 passed).

- [ ] **Step 5: Commit Task 1**
  ```bash
  git add load-testing/matrix.php load-testing/scripts/load-test-matrix.sh load-testing/status.md CHANGELOG.md docs/load-testing-refresh-implementation-plan.md
  git commit -m "docs(load-testing): retire load-testing refresh implementation plan after delivery"
  ```

---

### Task 2: Reconcile and Harmonize `load-testing/` Subsystem Documentation

**Files:**
- Modify: `load-testing/procedure.md:209`
- Modify: `load-testing/status.md`
- Modify: `load-testing/README.md`
- Modify: `load-testing/configurations.md`
- Test: `tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`

**Interfaces:**
- Produces: Consistent status, procedure, and README in `load-testing/` reflecting settled decisions.

- [ ] **Step 1: Reconcile `load-testing/procedure.md`**
  - Line 209 states: *"A series produces a new report file. `docs/load-testing-results.md` is the record of an earlier series and is not rewritten."*
  - Update line 209 to align with `load-testing/README.md` and Decision D9:
    ```markdown
    Series summaries and written analyses are recorded as new dated sections in `docs/load-testing-results.md` (or series-specific narrative files like `docs/load-testing-<series>.md`), citing the consolidated comparison datasets committed under `load-testing/results/consolidated/` and the backing run directory.
    ```

- [ ] **Step 2: Settle open decisions in `load-testing/status.md`**
  - In `load-testing/status.md`:
    - Update line 17: Change "Pool-split decision (UI vs v-fast+CDR): Open" to **Settled**. Record that the single dynamic pool with memory ceilings (`pm = dynamic`, `max_children = 3 / 6 / 12`, `pm.max_requests = 10000`) was evaluated and adopted in the installer, eliminating the need for a split socket.
    - Update line 44 to acknowledge both `h1-1c1g` (1 vCPU) and `h2-2c2g` (2 vCPU) benchmark suites have been completed.
    - Note clearly in the variant comparison table that `v-lua-fast` and `v-lua-stock` are retired historical configurations.

- [ ] **Step 3: Harmonize `load-testing/README.md` and `load-testing/configurations.md`**
  - Reconcile `load-testing/README.md` to clarify the strict separation:
    - `storage/app/load-tests/`: Scratch space on the PBX for single Artisan commands (`pbx:load-test:seed`, `pbx:load-test:dialplan`). Always git-ignored.
    - `load-testing/results/<series>/`: Per-cell raw run artifacts from matrix runs. Always git-ignored.
    - `load-testing/results/consolidated/`: Clean, multi-series consolidated CSV comparison datasets (`*-cells.csv`, `*-services.csv`, `*-setup-latency.csv`). Committed repository content.
  - Verify `load-testing/configurations.md` retains `v-fast-default` and `1000x25` per `LoadTestScrubArtifactsTest`.

- [ ] **Step 4: Verify test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`.
  - Expect: PASS.

- [ ] **Step 5: Commit Task 2**
  ```bash
  git add load-testing/procedure.md load-testing/status.md load-testing/README.md load-testing/configurations.md
  git commit -m "docs(load-testing): reconcile procedure, status, and artifact paths across load-testing docs"
  ```

---

### Task 3: Streamline Test Matrix & Driver Scripts

**Files:**
- Modify: `load-testing/matrix.php`
- Modify: `load-testing/scripts/drivers/run-ladder.sh`
- Modify: `load-testing/scripts/drivers/run-cdr-matrix.sh`
- Modify: `load-testing/scripts/drivers/run-sqlite-ladder.sh`
- Test: `tests/Feature/Pbx/LoadTestMatrixConfigTest.php`
- Test: `tests/Feature/Pbx/LoadTestMatrixRunnerTest.php`
- Test: `tests/Feature/Pbx/LoadTestSwitchScriptTest.php`

**Interfaces:**
- Produces: Cleaned `matrix.php` emphasizing modern variants and aligned dynamic FPM defaults.

- [ ] **Step 1: Reconcile tier defaults in `load-testing/matrix.php`**
  - In `load-testing/matrix.php`, update comments and ensure the `tiers` definition documents the canonical dynamic pool ceiling as the primary runtime mode, while retaining static profiles for sweep comparisons.
  - Keep the active variants: `v-stock-dynamic`, `v-stock-static`, `v-fast`, `v-fast-default`, `v-octane`.

- [ ] **Step 2: Streamline driver scripts**
  - In `load-testing/scripts/drivers/run-ladder.sh`, `run-cdr-matrix.sh`, and `run-sqlite-ladder.sh`:
    - Ensure comments clearly document required environment variables (`PBX_HOST`, `SIPP_HOST`, `SSH_IDENTITY`).
    - Standardize artifact output references to `load-testing/results/`.

- [ ] **Step 3: Run targeted test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestMatrixConfigTest.php tests/Feature/Pbx/LoadTestMatrixRunnerTest.php tests/Feature/Pbx/LoadTestSwitchScriptTest.php`.
  - Expect: PASS (all tests pass).

- [ ] **Step 4: Commit Task 3**
  ```bash
  git add load-testing/matrix.php load-testing/scripts/drivers/run-ladder.sh load-testing/scripts/drivers/run-cdr-matrix.sh load-testing/scripts/drivers/run-sqlite-ladder.sh
  git commit -m "refactor(load-testing): streamline matrix tiers, variants, and driver scripts"
  ```

---

### Task 4: Streamline `docs/load-testing-guide.md` as Dedicated Administrator Manual

**Files:**
- Modify: `docs/load-testing-guide.md`
- Test: `tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`

**Interfaces:**
- Produces: Focused, non-redundant DevOps operational guide.

- [ ] **Step 1: Trim duplicated Automated Refresh Harness section**
  - In `docs/load-testing-guide.md` (§ Automated Refresh Harness, lines 252–305):
    - Replace the redundant 50-line summary of runner flags, environment variables, and tier invocations with a concise 10-line overview directing automated matrix benchmarking to [`load-testing/README.md`](../load-testing/README.md).

- [ ] **Step 2: Harmonize domain/realm instructions**
  - In `docs/load-testing-guide.md:113` and `316`:
    - Replace hardcoded `--domain=load.test.local` with `--domain=<PBX_IP>` (or `<DOMAIN>`), citing Decision D15 (`FS_DEFAULT_SIP_REALM`) so administrators don't encounter `403 Forbidden` registration errors on real appliances.

- [ ] **Step 3: Clarify scratch vs matrix artifact paths**
  - In `docs/load-testing-guide.md` (lines 49, 106, 334, 362, 459):
    - Clarify that `storage/app/load-tests/` is scratch space on the PBX filesystem used by individual Artisan seed/dialplan commands, while full matrix series runs archive to `load-testing/results/` and `load-testing/results/consolidated/`.

- [ ] **Step 4: Verify test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`.
  - Expect: PASS.

- [ ] **Step 5: Commit Task 4**
  ```bash
  git add docs/load-testing-guide.md
  git commit -m "docs: streamline load-testing-guide as dedicated administrator manual"
  ```

---

### Task 5: Reconcile Historical Sections in `docs/load-testing-results.md`

**Files:**
- Modify: `docs/load-testing-results.md`
- Test: `tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`

**Interfaces:**
- Produces: Unambiguous results document with clearly framed historical benchmark records.

- [ ] **Step 1: Add historical callout banners to pre-5.0.0 sections**
  - In `docs/load-testing-results.md`:
    - To older tables that mention static pools (e.g. `pm = static`, 5 or 6 workers) or the four-way comparison with Lua proxy, add a standard callout banner:
      ```markdown
      > [!NOTE] Historical Benchmark
      > The measurements in this section were recorded on earlier builds with static worker pools or the experimental Lua proxy (retired in 5.0.0). For current production baseline defaults and verified call capacity, see [Current Baseline: Measured Capacity & PHP-FPM Defaults](#current-baseline-measured-capacity--php-fpm-defaults).
      ```

- [ ] **Step 2: Verify consistency with consolidated CSVs**
  - Ensure all tables referencing worker sweeps cite `load-testing/results/consolidated/worker-sweep-20261108-*.csv`.

- [ ] **Step 3: Verify test suite**
  - Run `./vendor/bin/pest tests/Feature/Pbx/LoadTestScrubArtifactsTest.php`.
  - Expect: PASS.

- [ ] **Step 4: Commit Task 5**
  ```bash
  git add docs/load-testing-results.md
  git commit -m "docs(results): add historical callout banners to pre-5.0.0 benchmark tables"
  ```

---

### Task 6: Full Verification, Pint Linting, and Cache Clearing

**Files:**
- Verification only across all touched files.

- [ ] **Step 1: Application boot and cache clear**
  ```bash
  tpbx optimize:clear
  ```

- [ ] **Step 2: Pint code style check**
  ```bash
  ./vendor/bin/pint --dirty --test
  ```

- [ ] **Step 3: Run comprehensive load test feature test suite**
  ```bash
  ./vendor/bin/pest tests/Feature/Pbx/LoadTestMatrixConfigTest.php tests/Feature/Pbx/LoadTestMatrixRunnerTest.php tests/Feature/Pbx/LoadTestSwitchScriptTest.php tests/Feature/Pbx/LoadTestArtifactPathTest.php tests/Feature/Pbx/LoadTestScrubArtifactsTest.php tests/Feature/Pbx/LoadTestArtifactHygieneTest.php tests/Feature/Pbx/LoadTestCdrGateTest.php tests/Feature/Pbx/LoadTestCpuwatchTest.php
  ```
  - Expect: All tests PASS with zero failures.
