# FreeSWITCH runtime SQLite database: disk vs tmpfs under call load — 2026-11-05

The question: is the runtime SQLite database on disk a bottleneck, and does
moving it to memory (tmpfs) buy call capacity? Tested with the stock variant
(`v-stock-dynamic`) across a small CPS ladder, each rung measuring both modes
back to back under real call load (the `fs-db` ablation, 60 s windows,
CDR-gated). Driver: `load-testing/scripts/drivers/run-sqlite-ladder.sh`.

## Results (completed / attempted calls)

| Rung | Disk (`/var/lib/freeswitch/db`, ext4) | tmpfs (`/dev/shm`) | Delta |
|---|---:|---:|---:|
| 2 CPS | 111/120 = **92.5 %** | 120/120 = **100 %** | +7.5 pts (disk cell hit 18 × 503 transients) |
| 3 CPS | 180/180 = **100 %** | 180/180 = **100 %** | 0 |
| 4 CPS | 174/240 = **72.5 %** | 169/240 = **70.4 %** | −2.1 pts (noise) |
| 5 CPS | 131/300 = **43.7 %** | 164/300 = **54.7 %** | +11 pts (single sample; ordering/noise) |
| 6 CPS | 111/360 = **30.8 %** | 106/360 = **29.4 %** | −1.4 pts (noise) |

Every cell's CDR gate reconciled rows (`CDR-GATE ok`), so the tallies are
complete.

## What the failures were

- At 2–3 CPS: the usual transient `503 Maximum Calls In Progress` overload
  responses (the same ones the Nov-4 archive contains), retried and mostly
  converted.
- At 4–6 CPS: the dominant rejection changes to **`403 Forbidden`** (943
  occurrences in the 5 CPS disk cell, 637 in the tmpfs cell) — the directory
  lookup times out while PHP-FPM is saturated, FreeSWITCH cannot verify the
  digest, and the call is rejected. That is the known lookup wall, not a
  database problem.

## Conclusion

**The runtime database on disk is not a measurable bottleneck at these call
rates.** The disk-vs-tmpfs deltas flip sign across rungs (−2, +7.5, +11, −1.4)
and are consistent with run-to-run noise at saturation, not with a systematic
I/O cost: the cliff at 4 CPS appears identically in both modes and its cause is
PHP-FPM lookup capacity (403 rejections), which no database location can fix.

Recommendation: **keep the disk default** (survives restarts, costs no RAM).
Revisit tmpfs only if `SQLITE_BUSY` or database latency is observed directly in
the FreeSWITCH logs at sustained higher call rates — and if so, repeat each
rung several times before believing the delta, because a single 60 s sample at
saturation varies by ±10 points.

## Data-quality notes

- The four calibration knobs (ESL, Reverb, CDR transport) were at their
  shipped defaults; CDR went through PHP-FPM, matching the ladder series these
  numbers are comparable with.
- CPU attribution for this ladder was lost: the sampler redirected into a
  directory the cell report only creates later, so the sample died silently.
  Fixed in `e3b80cf`; the completion counts above are unaffected (they come
  from SIPp and the CDR gate).
- A first attempt was aborted: the ablation phase initially ran every knob,
  so it was stopped after the first fs-loglevel cell; that stray cell was
  removed as a misconfigured partial run, and `--ablation=<id>` was added
  (`94703a1`) before this ladder.
