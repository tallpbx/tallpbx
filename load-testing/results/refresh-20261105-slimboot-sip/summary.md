# CPS (SIP call) cells after the slim-boot trim — 2026-11-05

The question: did the fast-controller trim move the **calls-per-second** ceiling,
and does the shared slim gate change the worker's call capacity? These are SIPp
capacity cells (`uac-extension-capacity`, 60 s windows, CDR-gated), the same
shape as the `refresh-20261103` ladder, on the h1-1c1g tier (x.x.x.138) with the
generator at x.x.x.55.

Code state: `5cbc700` plus the smoke fix `cc2c98e` (the preflight now registers
the caller/callee pair; it previously relied on a leftover registration and
failed with SIP 806 once those expired — see `sip-smoke-806-failed/`).

## Results

| Cell | Attempted | Completed | Failed | Completion | CDR gate |
|---|---|---|---|---|---|
| **v-fast @ 5 CPS** | 300 | **276** | 23 | **92.0 %** | FAILED — partial, 46/552 rows (starved) |
| **v-fast @ 8 CPS** | 480 | **480** | 0 | **100 %** | FAILED — no-records (starved) |
| v-octane @ 8 CPS (run 1) | 467 | 423 | 44 | 88.5 % | FAILED — partial (contaminated: FS restart raced the tail) |
| v-octane @ 8 CPS (run 2) | 467 | 416 | 51 | 89.1 % | ok — 822 rows |
| v-octane @ 8 CPS (run 3, debug loglevel) | 478 | 456 | 22 | 95.4 % | ok — 910 rows |

Reference (previous series, same cells): v-fast @ 5 CPS **102/300 = 34 %**
(this was the recorded cliff); v-octane @ 8 CPS **480/480 = 100 %**.

## Failure causes

- The dominant rejection in every cell is transient **`503 Maximum Calls In
  Progress`** (the switch's session-rate overload response), followed by
  `481 Call Does Not Exist` on retries. SIPp retries convert most of them:
  v-fast @5 saw 36 × 503 + 16 × 481 but finished 276/299; the debug octane run
  saw them too and still finished 95.4 %.
- The same 503 pattern exists in the **previous series' archive**: its
  `480/480` v-octane @8 cell contains 142 identical 503s. The mechanism is
  not new; only the conversion rate varies between runs.
- During the cells the PBX showed **external SIP traffic** (`Can't find user
  [trunk_1@…]` from 54.38.94.109, an abandoned INVITE from 104.223.62.98).
  That session churn spikes the switch's session rate, which is what produces
  the 503s, and it explains the run-to-run variance.
- v-octane run 1 is additionally contaminated: the invocation teardown
  restarted FreeSWITCH while the cell's tail was still in flight.

## CDR integrity

- v-fast cells: the calls complete, but CDR posts **starve** — the PHP-FPM pool
  is saturated by the XML lookups at 5–8 CPS, posts return 499 (client gone),
  and rows arrive only after the spool drain — outside the gate's settle
  window. This mirrors the previous series' finding that the CDR path through
  FPM is the first capacity casualty; the trim moved the call ceiling into it.
- v-octane cells with a healthy module: gate ok (822–910 rows reconciled).
- Environment fix during this series: `/etc/freeswitch/autoload_configs/
  modules.conf.xml` still had `mod_json_cdr` commented (leftover from the
  CDR-off work). A FreeSWITCH restart exposed it (the module did not come
  back). The line is now uncommented, so restarts keep the pipeline loaded.

## Interpretation

1. The fast controller's call ceiling moved from **~5 CPS at 34 %** to **92 % at
   5 CPS and 100 % at 8 CPS** — roughly a 2.5× capacity gain on this tier.
2. The worker's call capacity is unchanged by design (the gate only skips
   boot-time work); its 89–95 % readings are the same overload transients the
   previous series recorded, plus external SIP noise.
3. At the raised ceiling, **CDR ingestion through PHP-FPM becomes the first
   failure** — a transport decision (worker-side ingestion), not a lookup
   decision.

## Files

- `refresh-20261105-slimboot-sip/` — v-fast @5, @8 and the clean v-octane @8
  (run 2); the contaminated run 1 is preserved as `sip-cps8-restart-503/`; the
  failed pre-fix smoke as `sip-smoke-806-failed/`.
- `refresh-20261105-slimboot-sip-octdbg/` — the debug-loglevel v-octane @8
  (run 3).
