# Slim Telephony Boot — before/after measurement (2026-11-05)

Same-host, same-session A/B of the slim telephony boot work (commits `f585c68`
and `7b4233c`): `TALLPBX_WORKER_ROLE` gate for module boot, and the fast
controller provider trim (translation + Livewire dropped).

- **Host**: h1-1c1g reference tier (1 vCPU / 967 MB), public IP obfuscated as
  x.x.x.138. Full default nftables firewall enabled, as in every series.
- **Code states**: BEFORE = `f35a6f6`, AFTER = `5cbc700`. Each state checked
  out on the PBX with `optimize:clear` before measuring; FPM opcache
  (`validate_timestamps=On`, `revalidate_freq=2`) picks up file changes in ≤2 s.
- **Method**: the same harness and cells as the `refresh-20261103` XML battery —
  `load-test-matrix.sh --phase=xml --profile=100x5 --ttl=production-60
  --section=dialplan`, 100 requests at concurrency 5, `pbx:load-test:dialplan`
  running on the PBX, every cell checked by `pbx:load-test:verify`.
- **Series dirs**: `refresh-20261105-slimboot-baseline` (BEFORE battery),
  `refresh-20261105-slimboot` (AFTER battery), `refresh-20261105-rc2-old` /
  `refresh-20261105-rc2-new` (confirmation pair, distinct dirs),
  `refresh-20261105-slimboot-recheck` (mixed: its two invocations shared one
  artifact path space, so its v-fast files cannot be attributed to a code
  state — its v-octane probe is valid and used below).

## XML battery throughput (requests/second, higher is better)

| Cell | BEFORE `f35a6f6` | AFTER `5cbc700` | Change |
|---|---|---|---|
| v-fast 100x5 (via nginx) | 29.5 / 30.1 / 31.3 · 27.8 / 31.0 (median **30.1**) | 42.7 / 44.0 / 43.1 · 43.3 / 42.5 (median **43.1**) | **+43 %** |
| v-fast 25x1 warmups (concurrency 1) | 27.2 / 27.7 · 26.7 / 27.5 (median 27.3) | 37.1 / 41.5 · 37.0 / 41.0 (median 39.2) | **+44 %** |
| v-octane 100x5 (worker :8090) | 172.0 / 168.4 / 189.7 (median 172.0) | 164.7 / 187.0 / 198.1 (median 187.0) | +9 % (samples overlap — noise) |

Per-request wall time at concurrency 5: v-fast **33.2 ms → 23.2 ms**. The
worker's request path was untouched by the gate (only boot-time work is
skipped), and its numbers agree: no significant change.

Historical reference (same cells, 2026-11-04 series): v-fast median 27.2 rps,
v-octane median 177.9 rps — consistent with the BEFORE state here.

## Memory (steady state after boot; and per-request peak)

| Item | BEFORE | AFTER | Change |
|---|---|---|---|
| Worker RSS (long-lived process) | 85,988 kB (84.0 MB) | 82,960 kB (81.0 MB) | −3.0 MB (−3.6 %) |
| Worker PSS (true attributed footprint) | 61,898 kB (60.4 MB) | 59,831 kB (58.4 MB) | −2.1 MB (−3.4 %) |
| Fast controller peak per request (CLI, `memory_get_peak_usage(true)`) | 33,554,432 B (32 MB) | 27,262,976 B (26 MB) | **−6.3 MB (−19 %)** |
| Fast controller cold bootstrap wall time (CLI, no opcache) | 207–223 ms | 149–170 ms | **−27 %** |

The BEFORE worker numbers match the historical monitoring from the
`refresh-20261103` series (~86 MB RSS / ~58–60 MB PSS).

## FreeSWITCH-side lookup probe (`find_user_xml`, 30 lookups, net ms/lookup)

| Variant | BEFORE | AFTER |
|---|---|---|
| v-fast | 35.3 ms · 5.6 ms | 28.0 ms · 3.5 ms |
| v-octane | 8.1 ms | 8.5 ms · 3.5 ms (one 53.9 ms outlier in the first AFTER run — re-measured 8.5 ms in the recheck series) |

The probe is noisy (host-state dependent); paired runs consistently favour
AFTER on the fast path and show the worker unchanged. The battery is the
reliable metric.

## Conclusions

1. The fast controller trim delivers **+43 % throughput / −30 % per-request
   work** on the FreeSWITCH lookup path, and **−19 % peak memory per request**.
2. The shared slim gate reduces the XML worker's resident footprint by ~3 MB
   (≈2 MB true) with no throughput change, as expected — its request path was
   never touched.
3. Both renderers still produce byte-identical XML (the byte-equality suites
   gate every cell's code state).
