# refresh-20261103-cdr-off — call capacity with the CDR pipeline disabled

The third leg of the CDR transport comparison (see `../refresh-20261103/summary.md` §2).
`mod_json_cdr` was removed from FreeSWITCH's boot list and unloaded before every cell, verified
(`module_exists mod_json_cdr` = false) before each run, and restored afterwards.

- **Tier:** `h1-1c1g` — 1 vCPU / 967 MB, shared CPU, full default `nftables` firewall active
- **Window:** 2026-11-05 07:34 → 08:05 UTC (clean run)
- **Index:** `INDEX.csv` / `INDEX.jsonl`; runner logs in `driver-logs/`; CPU samples per variant

| Variant | 8/sec | 10/sec |
|:--|--:|--:|
| `v-octane` | 480/480 (100 %) | 600/600 (100 %) |
| `v-lua-stock` | 480/480 (100 %) | 600/600 (100 %) |
| `v-lua-fast` | 480/480 (100 %) | 600/600 (100 %) |

**Conclusion:** with call detail recording disabled, every measured configuration is perfect at
both boundary rates — so the CDR ingestion path, not the serving paths, is the remaining capacity
cost at 8–10 calls/sec on this tier.

Notes and provenance:
- An earlier attempt at this leg (03:52–04:30) is invalid: unloading the module per cell while the
  variant switches restarted FreeSWITCH re-loaded it, and a shell pattern bug double-commented
  `modules.conf.xml` (invalid XML) which stopped FreeSWITCH from booting at a restart. The host was
  repaired, the patterns made idempotent (commit `05afa05` gate change plus anchored patterns in
  `load-testing/scripts/drivers/run-cdr-off-leg.sh`), and the cells re-run under timeout guards.
- One cell in the earlier attempt stalled 2.5 h on a hung ssh probe; every cell of the clean run is
  capped at 15 minutes.
- This configuration is a measurement, not a deployable option: production always records call
  detail records.
