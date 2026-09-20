# Firewall Ban-Set Reconciliation & Auto-Heal Policy (Phase 3) — Implementation Plan (Scope)

**Status:** Scoped design — **do not implement before Phase 2 ships** (`docs/firewall-sync-phase2-implementation-plan.md` provides the sidecar, canonical digest, `security:verify`, and the UI sync indicator this phase builds on). Phase 3 deliberately defers several **policy decisions** about automated firewall actions; they must be resolved with the user at the start of its implementation session (see `## 4. Open Decisions`).

**How to use this document (fresh session):** Start by resolving the open decisions (ask, don't assume — several involve automated privileged actions). Then implement the chosen scope with the project's mandatory TDD rules. Helpers must stay hermetic in tests (stub `nft` via `TALLPBX_NFT_BIN`, isolated `TALLPBX_FIREWALL_CONF_DIR` — see `tests/Feature/Modules/Security/SecurityExecutorTest.php`).

## 1. Why This Exists

Phase 1 detects policy drift. Phase 2 verifies content provenance and *reports* drift (badge + scheduled `security:verify`) — but only detection; a human must click "Re-apply Ruleset". Two runtime realities still produce unhealed drift:

- **Kernel ban sets fall out of step with the database.** A host reboot clears the dynamic sets; a helper `ban`/`unban` call can fail after the database write (no retry); kernel timeouts can expire earlier than the stored `expires_at` (clock skew, shorter timeout applied at load); and an administrator can add elements manually with `nft` outside the panel.
- **The firewall can be absent after an unattended reboot.** The kernel boots with no `tallpbx_filter` table and stays unprotected until someone applies the ruleset — exactly the scenario where nobody is watching the panel.

Phase 3 defines what the system may fix **by itself**, and what it must only alert on. The guiding principle from the earlier design discussion: heals must be provably within the saved configuration's intent (never invent new policy), idempotent, and reversible.

## 2. Prerequisites

- Phase 2 shipped and deployed (sidecar + digest + `security:verify` + indicator).
- A machine-readable way to read the kernel ban sets. Today the helper's `status` action lists the full table including per-element `timeout`/`expires` values (verified on the live host), but it is a human-oriented listing. Prefer adding a read-only `bans` action to the helper (stable output lines, e.g. `<ip> <family> <remaining_seconds>`), which keeps sudoers unchanged because the bounded binary already exists.
- Existing building blocks to reuse: family-aware helper `ban`/`unban` actions (single-address, strict regexes), `SecurityBan::active()` scope (`is_active` + not expired), `SecurityAuditLog::record()`, the private security alerts channel (`security.alerts`) for realtime notices.

## 3. Candidate Scope (what reconciliation could cover)

- **(a) Ban-set reconciliation** — compare kernel `@banned_ips` / `@banned_ips6` elements against `SecurityBan::active()`:
  - DB-active ban missing in the kernel → re-add via the helper `ban` action with the remaining seconds (`timeRemaining()`).
  - Kernel element with no DB-active ban (expired-but-present, or manually added) → remove via the helper `unban` action.
  - Timeout skew (kernel expiry significantly shorter than stored `expires_at`) → re-time via delete + re-add with remaining seconds.
- **(b) Boot recovery** — kernel table absent (`liveFirewallPolicy === 'absent'`) while the Phase 2 sidecar digest equals the current desired digest (nothing changed in the database; the kernel simply lost state) → re-applying the saved ruleset is semantically safe and restores protection without changing any configuration.
- **(c) Full auto-heal on content drift** — scheduled re-apply whenever `security:verify` reports drift, without a human click.
- **(d) Alert-only** — never act automatically; keep Phase 2 behavior (indicator + log).

## 4. Open Decisions (resolve FIRST with the user)

- **D1 — Auto-heal scope.** Recommendation: enable **(b) boot recovery** and **(a) ban-set reconciliation**; keep **(c) content drift alert-only**. Rationale: (b) restores only the saved, already-verified configuration after provable kernel state loss; (a) touches only dynamic ban sets with a blast radius of one address per action; (c) can silently rewrite rules/services/policy semantics and warrants a human click.
- **D2 — Kernel ban-read mechanism.** Recommendation: new read-only helper action `bans` emitting stable machine-readable lines, instead of parsing the human `status` listing. (Alternative: parse `status` and pin the format with tests — acceptable but more brittle.)
- **D3 — Cadence & cooldown.** Recommendation: scheduler cadence every 15 minutes with `withoutOverlapping()`; per-address cooldown (e.g. one remediation attempt per address per 10 minutes) and a per-run action cap to prevent flap loops.
- **D4 — Alerting.** Recommendation: audit-log every automated action, dispatch a realtime event on the private `security.alerts` channel on heal and on refused/failed heal, and `Log::warning` on failures. No email in v1.
- **D5 — Un-ban scope.** Only kernel elements with **no** DB-active ban may be removed. Reconciliation never touches blacklist or whitelist sets, never auto-bans, and never auto-whitelists.
- **D6 — Lockout guard interaction for (b).** Boot recovery re-applies the saved content, so the lockout posture cannot change relative to the last verified apply — document this rather than re-deriving ad-hoc. Confirm during implementation whether the CLI path should assert `LockoutGuardService` semantics the same way `security:apply` does.

## 5. Safety Rails (non-negotiable)

- Never auto-change the default inbound policy, the whitelist, or the blacklist.
- Never act when the desired digest cannot be computed (uncompilable database) — alert instead.
- File-level applies keep the existing preflight (`nft -c`); element operations keep the helper's strict per-address validation.
- Every automated action must be idempotent, cooldown-bounded, and audited.

## 6. Test Sketch (when implemented)

- **Planner unit tests** — a side-effect-free reconciler class (pure decision function: DB bans + kernel elements → list of add/remove/retime actions) covers most logic without any process execution.
- **Helper `bans` action tests** — stub `nft` via `TALLPBX_NFT_BIN`, isolated conf dir, trace-based assertions like the existing family-routing test.
- **Scheduler command tests** — cooldown behavior, per-run cap, "never touch policy/whitelist" assertions, hermetic fakes for the executor.
- **Audit/event assertions** — `SecurityAuditLog` rows and event dispatch on heal; `Log::spy()` on failure paths.

## 7. Risks

- **Flap loops** between reconciliation and expiry — mitigate with cooldowns and a minimum remaining-seconds threshold (treat sub-threshold remaining times as expired).
- **Fighting a human** — an administrator who deliberately edits kernel banned sets by hand will see the change reverted; document that kernel banned-set edits outside the panel are considered drift.
- **Clock skew** between host and application makes remaining-time math unreliable near expiry — always compute remaining seconds from the database's `expires_at` at action time.
- **Auto-heal under `drop` policy** must never remove the only whitelisted admin path — reconciliation never touches whitelist sets (see rails).
