# PHP-FPM Worker Pool Split — Proposal

**Status:** proposal only. **Do not implement before the CDR fast entry is measured** (`docs/cdr-performance-improvement-implementation-plan.md`). The three-tier XML ladder is already committed (`4fe5951`); this is the next infrastructure change *after* the CDR work, and the two must not be combined in one change.

## 1. Problem

One static PHP-FPM pool (5 workers on the 1 vCPU / 967 MB tier) serves everything: the panel, the XML handler locations, and CDR ingestion. The measurements show the coupling, not just the load:

- **CDR posts starve behind XML lookups.** With the fast controller serving 5–8 calls/sec, CDR posts returned 499 while the pool was saturated (46 of ~552 rows reconciled at 5 CPS, 0 at 8 CPS) — calls completed, their records did not.
- **The panel shares the same queue.** A call burst can occupy every worker; the historical panel 502/504 symptom is the same coupling from the other side. The panel never needed many workers — it needs *workers that phone traffic cannot take*.
- **The Octane worker already proves the principle.** Moving XML out of FPM removed the contention entirely — but costs 81 MB resident. A pool split achieves the queue separation without a resident application.

## 2. Proposal

Add a dedicated pool for the **XML locations only**; the panel and CDR stay on the default (`www`) pool.

```
PHP-FPM
├── www pool            (default): panel, CDR ingestion, queue workers
└── xml-handler pool    (new):     /api/v1/xml-handler (fast)
                                  /api/v1/xml-handler-stock (third tier)
```

The split is about **queue isolation, not extra CPU**: on one core the total capacity is fixed; the win is that phone traffic can exhaust its own lane and never the panel's or CDR's.

### Split options (total workers kept at 5 unless stated)

| Option | XML pool | www pool | Trade-off |
|---|---|---|---|
| **A (recommended start)** | 3 static | 2 static | Best XML headroom; panel phase has 2 workers (adequate for one admin); CDR gets 2 |
| B | 2 static | 3 static | More panel/CDR headroom; XML ceiling may drop below the measured 8 CPS — must be re-measured |
| C | 4 static | 2 static (6 total) | Keeps XML where it is and still frees the panel, at the cost of one extra worker's memory |

Option A is the starting point for measurement; the gate (section 5) decides whether A or B is the keeper. Option C only if RAM headroom is confirmed and the XML ceiling must not move.

## 3. Mechanics

1. **New pool file** `/etc/php/8.5/fpm/pool.d/xml-handler.conf`: `[xml-handler]`, `user/group www-data`, `listen = /run/php/php8.5-fpm-xml.sock`, `pm = static`, `pm.max_children` per option, same `php_admin_value` overrides as the `www` pool (opcache settings, upload limits irrelevant here), same `chdir`.
2. **nginx templates** (HTTP + both TLS blocks): `fastcgi_pass` on `location = /api/v1/xml-handler` and `location = /api/v1/xml-handler-stock` switches to the new socket. The `/api/v1/cdr` location and the panel stay on the default socket.
3. **Installer**: deploy the pool file (idempotent), reload FPM, and `nginx -t` before reload — the same pattern as the other resource scripts. The pool file ships as a template under `scripts/resources/`.
4. **Harness alignment**: `load-testing/scripts/lib/switch.sh` gains the socket assertion so variant runs prove which pool served the cell; the XML battery then measures the split pools correctly.
5. **Permissions**: socket `0660 www-data:www-data`; generated-file repair already covers FPM pool files' ownership through the installer's permission pass.

## 4. Risks and costs

| Risk | Mitigation |
|---|---|
| Fewer XML workers lowers the call ceiling | Re-run the 5/8 CPS cells and the XML battery; Option B is rejected if XML drops >5 % |
| Panel starved on the other side | www pool keeps 2 workers (single-admin appliance); verify with the panel probe under load |
| Total worker count grows (Option C) | Only with measured RAM headroom on the 967 MB tier (~30–60 MB per worker) |
| Two pools to monitor | One extra `systemctl status php8.5-fpm` line; document in `docs/operations.md` |
| Installer/harness churn | Sockets asserted in both; `nginx -t` gates the switch |

## 5. Measurement plan and decision gates

Baseline = the committed configuration (single 5-worker pool, fast controller default, CDR fast entry when implemented).

For each option (A, then B if A fails a gate):

1. **XML battery** `100x5` dialplan: rps and p50/p95 must stay within **5 %** of baseline (43.1 req/sec, 108 ms p50).
2. **Call cells** at 5 and 8 CPS: completion and — the point of the split — **CDR gate passes with rows reconciled** on every cell.
3. **Panel responsiveness under load**: a scripted login/page request probe during the 8 CPS cell; p95 must be materially better than baseline (the expected win) and never time out.
4. **FPM health**: error log clean, no queue-timeout warnings, no socket errors.
5. **RAM**: `free` headroom after steady state; Option C only if ≥150 MB available.

The split ships only if gates 1–4 pass; it is abandoned if the XML ceiling drops beyond the tolerance or the panel/CDR gains are not measurable.

## 6. Rollback

Restore the templates and remove the pool file (or re-run the installer from the previous revision); reload FPM and nginx. No application, database, or FreeSWITCH change is involved.

## 7. Effort

~1.5–2 days: pool template + installer (0.5), nginx/templates + tests (0.5), harness alignment (0.25), measurement series (0.5), docs (0.25).

## 8. Sequencing (binding)

1. **Now / next:** implement and measure the **CDR fast entry** (`docs/cdr-performance-improvement-implementation-plan.md`).
2. **Only after** its gates pass: implement this split, measure, and decide A vs B (vs C).
3. If the CDR fast entry already makes CDR gates pass at the target rates, this proposal becomes an *optional* improvement for panel isolation — evaluate on the measured panel responsiveness numbers, not pre-emptively.
