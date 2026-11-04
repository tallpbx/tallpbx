# Decisions

Every decision that shapes a measurement, with the reasoning and the alternative that was rejected. Read this before changing a default or re-running a phase: most of these were reached by finding a defect, and reversing one without knowing why it exists tends to reintroduce the defect.

Status values: **settled** (do not revisit without new evidence), **provisional** (works, may change when the next tier is available), **open** (no decision yet).

---

## D1 — Two hosts, and the generator must be unconstrained

**Decision:** All load generation runs on a separate host from the PBX. The generator has no firewall, no traffic shaping, raised socket buffers and a high file-descriptor limit.

**Why:** a generator that filters or shapes its own packets becomes the bottleneck and silently caps every variant at the same ceiling.

**Rejected:** running SIPp on the PBX (measures the measurement), or keeping a firewall on the generator "to be realistic" (constrains the client, not the server). Functional-only runs may keep a firewall; capacity runs may not.

**Status:** settled.

---

## D2 — The baseline host configuration

**Decision:** A run is only comparable when these are what the manifest says:

| Setting | Baseline |
|:--|:--|
| FreeSWITCH log level | `notice` |
| ESL profile | `essential` |
| Broadcast throttle | unset (500 ms) |
| PHP-FPM | `pm = static`, `max_children = 5` on 1 GB |
| XML entry file | stock (`index.php`) |
| Lua proxy | `.env false` + bindings commented |
| Octane binding | as installed (both gateways declared) |
| FreeSWITCH runtime DB | disk |
| Cache TTLs | unset (config defaults) |

**Why:** these are what the installer ships on a fresh appliance, so they are what a customer actually gets.

**Rejected:** treating the scaling-guide optimisations as prerequisites. A prerequisite that is itself being measured cannot also be the baseline.

**Status:** settled.

---

## D3 — Disk-backed SQLite is the baseline; tmpfs is a variant

**Decision:** `/var/lib/freeswitch/db` on disk is the baseline. tmpfs is one configuration under comparison, not a setup step.

**Why:** this reversed an earlier recommendation that scored "not a mountpoint" as a broken precondition. Disk is the shipped default. Measuring tmpfs as a variant answers the question; enforcing it as a prerequisite destroys the comparison.

**Rejected:** making tmpfs a prerequisite (no baseline left to compare against).

**Status:** settled.

---

## D4 — Octane cells are measured on port 8090

**Decision:** The runner posts `v-octane` load at `http://127.0.0.1:8090/api/v1/xml-handler` and every other variant at nginx.

**Why:** the persistent worker is a separate HTTP server serving the same route. Posting an Octane cell at nginx measures PHP-FPM and would have been published as an Octane result. That bug existed and produced six labelled-but-wrong cells, which were discarded.

**Rejected:** explaining the difference away as "Octane is not observable by an HTTP client". It is observable; the runner was pointing at the wrong server.

**Status:** settled.

---

## D5 — Lua cache hits are measured through FreeSWITCH, not the HTTP client

**Decision:** Each XML cell also records a `find_user_xml` probe (minus the `status` overhead). Lua variants are judged from that probe plus the SIP phases, not from the HTTP client's numbers.

**Why:** a Lua hit is answered inside FreeSWITCH and never reaches nginx or PHP, so an HTTP client that posts directly to PHP cannot see one. The probe asks the component that actually answers.

**Rejected:** concluding "Lua gives no benefit" from an HTTP measurement. That conclusion was in the making and would have been an artifact of the driver, not a property of the proxy.

**Caveat:** the probe reports the configured binding state (`lua_bindings=on|off`), not which component served a given lookup. Proving a hit needs the mirror-TTL check.

**Status:** settled for timing; attribution still provisional.

> **Finding (2026-11-04):** the proxy was measured with the proxy genuinely enabled and still served no hit, because of an epoch mismatch in product code — PHP publishes the identity key under `max(current, stored)` while `pbx:fsxml:ver:global` is only seeded and never updated, so the Lua handler builds a key that PHP never wrote. The proxy cannot be classified until that is resolved; see `status.md` → *The Lua proxy: two defects*.

---

## D6 — Cache TTL is measured once, at the baseline

**Decision:** The full cache-policy sweep (six policies) runs only against the baseline variant. Every other variant is measured at `production-60` alone (`--ttl=production-60`).

**Why:** the sweep answers "how much is caching worth", which is a property of the workload, not of the variant. Repeating it per variant multiplies runtime without a new question, and on the dialplan section `uniform-30` was shown to duplicate `production-60` because directory and ACL TTLs do not affect dialplan XML.

**Rejected:** six policies × six variants × three repeats as the default.

**Status:** settled.

---

## D7 — Three repeats, and differences under 10 % are not findings

**Decision:** Three repeats per cell. A cross-variant gap smaller than ~10 % is treated as noise until repeats are raised.

**Why:** the same configuration produced 17.6 and 19.6 rps in consecutive repeats (11 %). Any ranking finer than the observed noise is invented.

**Rejected:** single-run comparisons, which is how the earlier 14.2-vs-19.6 "difference" appeared before a warm-up cell removed it.

**Status:** settled.

---

## D8 — Raw results are committed inside the repository

**Decision:** `load-testing/results/<series>/` is the durable copy and is committed. The PBX keeps an ignored scratch copy under `storage/app/load-tests/`.

**Why:** the measurement hosts are deleted when the series ends, and `storage/` is git-ignored and removable by `git clean -fdx`. Results kept only there are not results.

**Rejected:** an external `/srv` archive as the only durable copy (machine-specific, invisible to a clone), and keeping results uncommitted (lost on cleanup).

**Consequence:** every run grows repository history permanently. Commit results once per series, not per cell.

**Status:** settled.

---

## D9 — A series publishes a new report; the old results document is not rewritten

**Decision:** `docs/load-testing-results.md` stays untouched as the record of the earlier series. A new report is written in a new file, citing the `load-testing/results/<series>/…` paths behind every table.

**Why:** overwriting makes it impossible to tell a re-measured number from a copied one, and the earlier series' provenance disappears.

**Status:** settled.

---

## D10 — ESL profile and Reverb debouncing are SIP-phase experiments only

**Decision:** ESL stays at `essential` and broadcast throttling at its 500 ms default for XML work. `full` and `FS_BROADCAST_THROTTLE_MS=0` are measured only where events exist — the SIP phases.

**Why:** measured, not assumed. Under XML-only load both the listener and Reverb reported **zero** CPU ticks across a 20-second window, and an alternating on/off experiment produced 20.4 / 20.1 rps (running) against 20.0 / 19.3 (stopped) — inside the noise band. With no calls there are no events for either to process.

**Rejected:** running a full-vs-essential XML comparison that would have shown ~0 % by construction and been reported as "no effect".

**Status:** settled for XML; the SIP-phase comparison is still to run.

---

## D11 — Five hardware tiers, two stages; only one tier is provisioned

**Decision:** Stage A covers three shared-CPU tiers (`h1-1c1g`, `h1-1c2g`, `h2-2c2g`); Stage B confirms on two dedicated tiers (`h2d-2c8g`, `h4d-4c16g`). Tier ids are `<generation>-<cores>c<ram>g`, with `d` meaning dedicated.

**Why:** a configuration that wins on a small host can lose on a large one; the stages separate ranking from scaling.

**Rejected:** extrapolating from 1 vCPU to 4 dedicated cores.

**Consequence:** only `h1-1c1g` exists today, so at most one third of Stage A can run. That is a resource limit, not a design choice.

**Status:** provisional.

---

## D12 — The firewall is verified, never changed

**Decision:** Load-test tooling only reads `nft list ruleset`. It never adds, inserts, deletes, flushes or creates chains.

**Why:** two production outages were caused by live `nftables` mutation — one removing loopback accept and hanging every local database connection, one leaving a drop-policy chain hooked to input. A measurement harness has no business creating kernel state.

**Enforced by:** a test asserting the switch library contains none of the mutating commands.

**Status:** settled.

---

## D13 — Credentials never enter the repository

**Decision:** Host addresses, SSH key names, key paths and passwords are supplied by the operator through the environment. The documents describe the mechanism, never the values.

**Why:** the repository is public and cloned; an address list ages into a map of live attack surface.

**Status:** settled.

---

## D14 — Every switch is applied by a script that also asserts it

**Decision:** A setting is changed only through `scripts/lib/switch.sh`, and every `apply_*` has an `assert_*` that reads the effective value back. A mismatch fails that cell (`SWITCH-FAILED`).

**Why:** three separate defects produced plausible numbers from configurations that had not actually been applied — a `sed` delimiter collision that silently failed the Lua switch, the restore leaving the Lua flag and bindings disagreeing, and the Octane variant measuring the wrong server. An assertion turns each of those from a wrong result into a failed cell.

**Status:** settled.

---

## D15 — The SIP realm comes from the host, not from the harness

**Decision:** The realm a tenant is seeded on is read from the PBX's own `FS_DEFAULT_SIP_REALM` unless the operator sets `DOMAIN` or `LOAD_DOMAIN` explicitly.

**Why:** FreeSWITCH resolves a registering user under the realm its profile names. A tenant seeded on any other realm cannot register at all, and the failure looks like a credential problem — 401/403 on the second REGISTER, with the directory reporting `Can't find user [2019@<appliance address>]`. The realm the host serves is a property of the host, so the harness reads it instead of assuming one.

**Rejected:** hard-coding `load.test.local`, which is what the guide said and which no host served.

**Status:** settled.

---

## D16 — A SIP cell is judged by the records its calls leave

**Decision:** Every SIP cell counts `cdrs` rows before and after, waits for the rows the completed calls require, and fails the cell when a completed call left no record, fewer records than calls, or when the cell placed no calls at all.

**Why:** the XML phases can only prove the CDR pipeline was left undisturbed. Nothing proved that a call was recorded, so a cell could report every call answered while the worker was dead — the failure would only surface when someone looked for a bill.

**Also:** the wait is driven by the outcome, not by the count looking stable. The pipeline does not necessarily start writing while the cell is still running, so three unchanged samples read as "settled" before the worker had done anything.

**Status:** settled.

---

## D17 — Registration is warmed and sent once

**Decision:** The runner prefetches every extension's directory entry before a SIP cell, and the REGISTER scenario sends its request once rather than retransmitting it.

**Why:** a cold directory lookup on this tier costs about 1.1 s against 72 ms warm. A client's default retransmit timer is 500 ms, so the request was sent twice and the server answered the second copy with a challenge of its own — which the scenario cannot place, so the step failed for a reason unrelated to what it measures. Registration is setup for the capacity measurement rather than part of it.

**Status:** settled.

---

## Open questions

| Question | Why it matters |
|:--|:--|
| Why does a directory lookup cost ~50 ms in the first sweep and ~6 ms in the second? | Answered in part: the first sweep measured a cold page cache on a fresh clone. The remaining question is how much of the 1 vCPU ceiling is swap pressure (228 MB of the 2 GB swap still in use) |
| Should the manifest be timestamped per invocation? | It is overwritten each run, so a tier measured across several invocations keeps only the last one |
| Can the probe prove *who served* a lookup rather than what is configured? | Answered for the first time: with the proxy genuinely enabled the lookup got **slower** (6.5 ms to 35–52 ms) because every lookup misses the mirror and pays for both paths. The mirror epoch mismatch is the open defect |
| Should app-side timing be enabled for XML cells? | `timing.enabled` is `false`, so no request/handler split exists |
| Does the rendered tenant profile actually reach FreeSWITCH? | The profile row now names the tenant realm, but the running profile still resolved registrations under the appliance realm. The reconciliation between the rendered configuration and the boot file is a product gap, not a harness one |
