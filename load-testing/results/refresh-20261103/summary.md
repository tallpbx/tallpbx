# refresh-20261103 — TallPBX capacity series (1 vCPU / 967 MB)

- **Tier:** `h1-1c1g` — 1 vCPU, 967 MB RAM, 2 GB swap, shared CPU, full default `nftables` firewall active
- **Build:** `5.x` (commits `a82b29e` → `05afa05` during the series)
- **Window:** 2026-11-04 10:48 UTC → 2026-11-05 08:05 UTC
- **Indexes:** `INDEX.csv` / `INDEX.jsonl` — one row per measured cell
- **Raw SIPp transcripts:** `raw-refresh-20261103.tar.gz` (+ `SHA256SUMS`)

## 1. Call-capacity ladder — CDR via PHP-FPM (the shipped default)

60-second windows, calls completed / attempted, `uac-extension-capacity` scenario:

| Variant | 2/sec | 3/sec | 5/sec | 8/sec | 10/sec |
|:--|--:|--:|--:|--:|--:|
| `v-stock-dynamic` | 120/120 | 165/179 | 89/300 | 63/480 | 72/600 |
| `v-stock-static` | 120/120 | 179/180 | 85/300 | 44/480 | 46/600 |
| `v-fast` | 120/120 | 180/180 | 102/300 | 95/480 | 61/600 |
| `v-lua-stock` | 120/120 | 180/180 | 300/300 | 401/480 | 506/600 |
| `v-lua-fast` | 120/120 | 180/180 | 300/300 | 411/480 | 515/600 |
| `v-octane` | 120/120 | 180/180 | 300/300 | 480/480 | 426/600 |

Boundaries: PHP-FPM family 3 → 5 calls/sec; Lua family 5 → 8; Octane 8 → 10. One `v-stock-dynamic` cell at 3/sec lost 14 calls to a FreeSWITCH restart mid-cell (not repeated). One `v-lua-fast` 10/sec cell was rejected at the switch (serve probe) and re-run cleanly.

## 2. CDR transport comparison — the cost of call detail recording

Calls completed / attempted at the boundary rates; `mod_json_cdr` verified unloaded before every
"off" cell. The "off" leg lives in `../refresh-20261103-cdr-off/`.

| Variant | Rate | CDR via PHP-FPM | CDR via worker | CDR disabled |
|:--|:--|--:|--:|--:|
| `v-octane` | 8/sec | 480/480 (100 %) | 480/480 (100 %) | 480/480 (100 %) |
| `v-octane` | 10/sec | 426/600 (71 %) | 383/578 (66 %) | 600/600 (100 %) |
| `v-lua-stock` | 8/sec | 401/480 (84 %) | 480/480 (100 %) | 480/480 (100 %) |
| `v-lua-stock` | 10/sec | 506/600 (84 %) | 600/600 (100 %) | 600/600 (100 %) |
| `v-lua-fast` | 8/sec | 411/480 (86 %) | 480/480 (100 %) | 480/480 (100 %) |
| `v-lua-fast` | 10/sec | 515/600 (86 %) | 600/600 (100 %) | 600/600 (100 %) |

Findings: CDR ingestion is the remaining capacity cost at these rates in every configuration.
Moving it to the persistent worker restores 100 % for the Lua variants (their lookups are served
inside FreeSWITCH) but not for `v-octane`, whose XML lookups and CDR posts then share the worker.
The legs ran in separate time windows — treat differences under ~10 % as noise.

## 3. XML throughput battery (per-request lookups)

Variant means at `production-60`, dialplan section (3 interleaved repeats):

| Variant | req/s | vs baseline |
|:--|--:|:--|
| `v-octane` | 168.5 | ×9.7 |
| `v-fast` | 25.5 | +62 % |
| `v-lua-fast` | 25.4 | +61.5 % |
| `v-stock-static` | 17.6 | +11.5 % |
| `v-lua-stock` | 16.8 | +6.8 % |
| `v-stock-dynamic` | 15.7 | baseline |

Cache policy sweep and per-lookup probes are in the per-variant directories; the Lua proxy adds
nothing on the HTTP path (a Lua hit never reaches PHP) but answers FreeSWITCH lookups in
~1.5 ms versus ~48.5 ms through PHP-FPM and ~8 ms through the worker (see `latency-probes/`).

## 4. Root-cause measurements

Per lookup request, measured on the idle host (20 sequential requests each):
- nginx → PHP-FPM: **48.5 ms CPU, 46 ms p50** — of which the handler work is ~0.8 ms; the rest is
  per-request PHP/Laravel startup.
- Octane worker: **8.0 ms CPU, 5.6 ms p50**.
- Lua proxy (in-process): **~1.5 ms**.

The call ceiling on this tier follows directly: ~20 lookups/sec ÷ ~4 lookups per call ≈ 5
calls/sec for the PHP-FPM family, measured collapse between 3 and 5.

## 5. Data integrity

Every SIP cell is gated: the CDR gate compares the calls SIPp completed with the rows the database
gained, and every variant switch is applied *and asserted* before the cell. A failed switch fails
the cell. Per-cell CPU attribution (`cpu-octane-cps<N>.csv`), transport proofs
(`transport-octane-cps<N>.log`) and the runner logs (`logs/`) travel with each cell.
