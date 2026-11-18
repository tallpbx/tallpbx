# Load Testing Plan: FreeSWITCH MariaDB vs. SQLite Contention, Baseline Refresh & Gap Closure

> **Hardware topology (Option B):**
> - **SIPp Load Generator:** 1 × **2 vCPU / 2 GB shared** VM (Debian 13, `sipp`, unconstrained — no firewall, no shaping).
> - **PBX Under Test:** 1 × Debian 13 VM starting at **`h1-1c1g` (1 vCPU / 1 GB shared)**, then **upgraded in-place to `h2-2c2g` (2 vCPU / 2 GB shared)** after Stage 1 completes.
>
> **Target active execution time:** ~8–10 minutes on `1 vCPU / 1 GB` + ~10–12 minutes on `2 vCPU / 2 GB`  
> **Applies to:** TallPBX `5.x` (`v5.4.0`+ including `55cad6c` MariaDB runtime DB support and `421f4f9` CDR bridge attribution fixes)  
> **Harness:** [`load-testing/README.md`](../../../load-testing/README.md)

---

## 1. Objectives & Scope Discipline

This plan is intentionally compact. Instead of re-running multi-hour full-matrix sweeps, it runs only the highest-signal cells needed to:

1. **Benchmark `FS_DB_DRIVER=mariadb` vs. `FS_DB_DRIVER=sqlite`** under workloads that actually trigger SQLite file/table lock contention (`SQLite is BUSY`).
2. **Refresh the shipped baseline (`v-fast-default`) and opt-in worker (`v-octane`)** after `v5.4.0`, `55cad6c`, and `421f4f9`.
3. **Close the remaining open gaps** in [`load-testing/status.md`](../../../load-testing/status.md) (`directory`/`mixed` XML sections, Octane sanity + `p95` tail check, and optionally >10 CPS multi-core Octane measurement).

### Explicitly Excluded (Time Savers)
- **No retired variants** (`v-stock-dynamic`, `v-stock-static`, `v-fast`).
- **No 6-policy cache sweeps** (already settled by Decision D6 in [`load-testing/decisions.md`](../../../load-testing/decisions.md)).
- **No 3-repeat 10-rung ladders** — use **1 repeat** for contention, boundary, and parity cells, and **3 repeats** only on the fast `100x5` XML baseline (~5 seconds per repeat).

Note: XML serving only varies across stock PHP-FPM, fast PHP-FPM, and Octane (`:8090`); CDR ingestion remains fixed on its dedicated fast PHP-FPM entry (`public/cdr-fast.php`).

---

## 2. Track 0 — Harness Readiness (Implemented)

1. **Runtime DB switch (`fs-db`):** [`load-testing/scripts/lib/switch.sh`](../../../load-testing/scripts/lib/switch.sh) (`apply_fs_db_mode` / `assert_fs_db_mode`) and [`load-testing/matrix.php`](../../../load-testing/matrix.php) (`ablations.fs-db`) support `sqlite` vs. `mariadb` (`FS_DB_DRIVER=mariadb`, `freeswitch.sh --configure-only`, `mod_mariadb` + `SHOW TABLES` verification, with automatic `.env` and `pre_load_modules.conf.xml` restoration).
2. **Concurrent SQLite contention workload:** [`load-testing/scripts/tools/fs-db-contention.sh`](../../../load-testing/scripts/tools/fs-db-contention.sh) drives simultaneous multi-writer load across `core.db` (`uac-extension-capacity.xml` on extensions `2000–2019`), `sofia_reg_internal.db` (concurrent `register.xml` churn on `2020–2029` over dedicated port `5072`), `voicemail_default.db` (`uac-voicemail.xml`), and 1s `fs_cli` read polling (`show channels count` + `sofia status profile internal reg`), then scans `/var/log/freeswitch/freeswitch.log` for `SQLite is BUSY` / `database is locked` errors.
   - **Seed requirement:** seed `30` extensions (`EXTENSIONS=30`) so `2020–2029` churn registrations without overwriting the `5066` UAS Contact port for `2000–2019`.

---

## 3. Execution Plan

### Stage 1 — `h1-1c1g` (1 vCPU / 1 GB Shared, ~8–10 min)

Run [`load-testing/procedure.md`](../../../load-testing/procedure.md) Steps 0–4 first (build parity, manifest, seed `EXTENSIONS=30`).

| Step | Configuration | Workload | Duration / Repeats | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **1A** | `v-fast-default` (`sqlite`) | XML `100x5` (`dialplan`) + `100x5` (`mixed`, `--timing`) | `100x5` × 3 each (~40s) | Refreshes 1 vCPU shipped XML baseline and captures `mixed` (`directory` + `dialplan`) timing. |
| **1B** | `v-fast-default` (`sqlite`) | SIP anchor cell at `5 CPS` (supported rate) | 60s × 1 (~1.5 min) | Re-validates 100% completion (300/300) and hardened two-leg CDR attribution (`421f4f9`). |
| **1C-1** | `v-fast-default` + `sqlite` | `fs-db-contention.sh` (`CALL_RATE=4`, `REGISTER_CHURN_RATE=5`, `VOICEMAIL_RATE=1`) + `cpuwatch.sh` | 60s × 1 (~2 min) | 1 vCPU SQLite contention baseline: completion %, `REGISTER` failures, `p50`/`p95`, lock log count, RAM/swap. |
| **1C-2** | `v-fast-default` + `mariadb` | Same `fs-db-contention.sh` back-to-back after `switch.sh apply_fs_db_mode mariadb` | 60s × 1 (~2 min) | Direct A/B on 1 GB RAM: confirms `mariadbd` memory headroom (no active swap thrashing) and lock-error elimination. |

### Resize Transition — Upgrade PBX VM to `h2-2c2g` (2 vCPU / 2 GB Shared)

1. Resize the PBX VM in the datacenter from `1 vCPU / 1 GB` to `2 vCPU / 2 GB`.
2. Re-derive the PHP-FPM dynamic pool ceiling (`pm.max_children = 6`) for 2 GB RAM and confirm the new host manifest:
   ```bash
   ssh root@"$PBX_HOST" 'cd /var/www/tallpbx && bash scripts/resources/php-fpm-pool.sh && php artisan pbx:load-test:manifest --output=/tmp/manifest.json'
   ```
3. Verify `manifest.json` reports `2` CPU cores, `~2048` MB RAM, `pm.max_children = 6`, and `FS_DB_DRIVER=sqlite` (restored baseline).

### Stage 2 — `h2-2c2g` (2 vCPU / 2 GB Shared, ~10–12 min)

| Step | Configuration | Workload | Duration / Repeats | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **2A-1** | `v-fast-default` (`sqlite`) | XML `100x5` × 3 + `500x25` × 1 (`mixed`, `--timing`) | ~45s | Refreshes 2 vCPU shipped XML baseline on realistic `2:1` directory/dialplan traffic. |
| **2A-2** | `v-octane` (`sqlite`) | XML `100x5` × 3 + `500x25` × 1 (`mixed`, `--timing`) | ~45s | Measures resident Octane worker on `mixed` (`directory` + `dialplan`) traffic. |
| **2B-1** | `v-fast-default` (`sqlite`) | SIP anchor cell at `10 CPS` (supported rate) | 60s × 1 (~1.5 min) | Re-validates 100% completion (600/600) and CDR bridge attribution on 2 vCPU. |
| **2B-2** | `v-octane` (`sqlite`) | SIP boundary cell at `12 CPS` (plus `15` / `20 CPS` if `12 CPS` passes 100%) | 60s × 1–3 (~2–4 min) | Closes `status.md` Octane sanity, checks `p95` tail at `12 CPS`, and measures >10 CPS Octane headroom. |
| **2C** | `v-fast-default` + `mariadb` | `pbx-sipp-validate.sh` with `MEDIA_FLOW=1 EXTENDED=1` | 1 pass (~2.5 min) | Proves 14/14 functional parity (voicemail, MOH, conference, ring group, forward, CDR gate) under `mod_mariadb`. |
| **2D-1** | `v-fast-default` + `sqlite` | `fs-db-contention.sh` (`CALL_RATE=8`, `REGISTER_CHURN_RATE=8`, `VOICEMAIL_RATE=1`) + `cpuwatch.sh` | 60s × 1 (~2 min) | 2 vCPU SQLite contention baseline (without 1-core CPU starvation masking DB locking). |
| **2D-2** | `v-fast-default` + `mariadb` | Same `fs-db-contention.sh` back-to-back after `switch.sh apply_fs_db_mode mariadb` | 60s × 1 (~2 min) | Definitive dual-core A/B: setup `p50`/`p95`, `REGISTER` success, `SQLite is BUSY` elimination, and `mariadbd` CPU share. |

---

## 4. Reporting & Consolidation (Post-Run)

Once execution finishes:
1. Export/append the distilled per-cell, per-service CPU, and setup latency rows into [`load-testing/results/consolidated/`](../../../load-testing/results/consolidated/) (never commit raw `load-testing/results/<series>/` directories).
2. Add a single dated section to [`docs/load-testing-results.md`](../../load-testing-results.md) covering the MariaDB vs. SQLite contention findings, refreshed `v-fast-default` / `v-octane` numbers, and `mixed` XML timings.
3. Update [`docs/telephony-capacity-scaling-guide.md`](../../telephony-capacity-scaling-guide.md) §3 (Phase 3: SQLite vs. MariaDB) and [`load-testing/status.md`](../../../load-testing/status.md) to reflect the newly measured facts and close completed open items.
