# Review: Livewire Session Expiration & Redis Isolation Implementation Plan

> [!NOTE]
> **Status: Evaluation Report (report-only, no code changes)**
> Independent review of [`session-expiration-and-update-resilience-plan.md`](session-expiration-and-update-resilience-plan.md), performed against the working tree at commit `e674fb65` plus the plan document's uncommitted edits. All claims were verified line-by-line against the codebase, the installed Livewire v4.4.7 vendor source, the live Redis instance (read-only queries), and the project documentation set.
>
> **Review date: 2026-10-22.**

---

## 1. Executive Summary

**Verdict: Technically sound and implementation-ready, with an unusually high factual accuracy rate — but with two substantive gaps (one unhandled native popup path, one operational script that contradicts the plan's own keyspace taxonomy) and several accuracy nits that will slow an implementer down.**

The plan's core diagnosis is correct and fully verified: sessions and cache genuinely share Redis DB 1 today, `optimize:clear` genuinely flushes it via `FLUSHDB`, and the git-update pipeline genuinely triggers it mid-poll. The proposed Livewire 4 `interceptRequest` mechanism is verified against the actual vendor source — including the subtle `preventDefault()` / `sessionExpired` flag interaction and the polling-storm rationale. The Redis DB-2 isolation strategy is the right fix.

| Category | Rating |
| :--- | :--- |
| Technical accuracy of claims | High — ~90% of verifiable claims confirmed exactly (see Section 2) |
| Completeness | Medium — misses the second native `confirm()` path and `run-cache-sweep.sh` |
| Session-management best practices | Good on isolation; overstated on eviction safety (see 4.3) |
| Architecture alignment | Strong — follows installer/docs/i18n/test conventions; TDD ordering deviates (see Section 5) |
| Risk identification | Partial — upgrade-path no-op and eviction caveats underdeveloped |

---

## 2. Verified Accurate Claims (Evidence)

Every load-bearing claim that could be checked was confirmed:

### Problem statement / infrastructure

- `config/session.php` line 78 is `'connection' => env('SESSION_CONNECTION', 'cache')`; `config/database.php` line 175 maps the `cache` connection to Redis DB 1 — sessions and volatile cache share DB 1 exactly as described.
- `optimize:clear` does include `cache:clear` (`OptimizeClearCommand`, vendor), and `RedisStore::flush()` does call `flushdb()` on the store connection — the eviction mechanism is real.
- `app/Services/GitUpdateService.php` line 457 (`runPipeline()`) and the rollback path (line 495) both run `optimize:clear`. Bonus context: `app/Services/ModuleLifecycleService.php` lines 236 and 355 also clear caches on module lifecycle, so the DB-2 isolation fixes more than just git-update — the plan's global approach is correct even though it only names the updater.
- `.env.example` line 34 (`SESSION_CONNECTION=cache`), line 69 (`REDIS_PORT`), `scripts/resources/tall.sh` lines 236–238, `AGENTS.md` line 621, `README.md`'s "14/15" text, `docs/env-reference.md` rows and line 656 — all exist at essentially the cited locations. The local `.env` still has `SESSION_CONNECTION=cache`, consistent with the "Ready for Implementation" status.

### Frontend mechanism (verified against installed Livewire v4.4.7 dist)

- `Livewire.interceptRequest(callback)` exists and is exposed on the global object; the callback receives `{ request, onFailure, onResponse, onError, onSuccess }` — the plan's destructuring `({ request, onError })` is correct.
- `onError` receives `{ response, body, preventDefault }` — the plan's `({ response, preventDefault })` is correct.
- `request.cancel()` exists on the request object (aborts the `AbortController` and cancels all queued messages).
- The native fallback is exactly `confirm("This page has expired.\nWould you like to refresh the page?") && window.location.reload()`, gated by an internal `sessionExpired` flag that is set **only after** the `if (preventDefault) return;` early exit — so the plan's claim that `preventDefault()` bypasses Livewire's stop flag, and therefore that a 1 s `wire:poll` would keep firing doomed requests, is **verified true**. The client-side cancel guard is necessary, not paranoia.
- `wire:confirm` resolves to native `window.confirm(message)` (plus a `wire:confirm.prompt` → `window.prompt()` variant) in the dist — as claimed.
- All 5 remaining `wire:confirm` occurrences are at the exact files and line numbers cited: certificates 265/591, geo-blocking 69, firewall-rules 256, external-blocklists 110.
- `wire:poll.1s="pollProgress"` exists in `app-modules/admin/resources/views/git-update.blade.php` line 1.

### Supporting architecture

- `resources/views/components/confirmation-modal.blade.php` uses exactly the visual tokens the plan cites (`bg-black/50`, `h-12 w-12 text-warning`, `mt-2 text-lg font-semibold`, `mt-3 text-sm text-base-content/70`); `x-heroicon-o-clock` resolves (blade-heroicons ^2.7 installed); `[x-cloak]` CSS exists in `resources/css/custom.css`.
- The shared-layout claim is correct: tenant components use `#[Layout('layouts.app')]`, and both `panel.login` and `panel.login.tenant` routes exist — so `window.location.reload()` letting `AuthPanelMiddleware` route by guard is the right call over hardcoding a login route.
- Script ordering works: `@vite` (app.js, deferred module) is in `<head>` at line 15 while `@livewireScripts` is at body end (line 234), so the `livewire:init` listener will register before Livewire boots.
- Keyspace taxonomy claims check out: `cache.stores.redis.lock_connection` → `default` (DB 0), RateLimiter → default store → DB 1, `tallpbx:security:attempts:*` via `Redis::` facade default connection → DB 0, phpunit.xml uses DBs 14/15 today.

---

## 3. Findings — Gaps and Risks

### 3.1 HIGH — The second native `confirm()` fallback ("empty response") is unhandled

This undermines the "100% popup-free" goal. In the *same* Livewire handler, immediately after the 419 branch, there is another native popup:

```javascript
if (response.ok && responseBody.trim() === "") {
    confirm("The server returned an empty response.\nWould you like to refresh the page?")
}
```

The plan's `onError` only matches `response.status === 419`, so this popup still fires natively. This is not theoretical for this exact use case: during a git update, `vendor/`, view caches, and built assets are replaced while the page polls every second — a transient `200` with an empty body (e.g., mid-swap of compiled views or asset chunks) is precisely the kind of response this fallback exists for. The plan's own acceptance criterion ("No Native Popups") is therefore unachievable as specified.

**Fix**: extend the interceptor to also `preventDefault()` and open the modal when `response.ok` and the body is empty (a `body` argument is already provided to `onError`), and add a test asserting the interception covers both conditions.

### 3.2 HIGH — `scripts/run-cache-sweep.sh` runs a bare `redis-cli flushdb`, flushing DB 0

This contradicts the plan's own "DB 0 = persistent" taxonomy. `scripts/run-cache-sweep.sh` line 67 executes `redis-cli flushdb` with no `-n` flag, which flushes **DB 0** — the database the plan classifies as persistent coordination (intrusion-detection sliding windows, scanner dedup keys, mutex locks). Two problems:

1. The script apparently intends to reset the XML cache, which lives in DB 1 (`CACHE_STORE=redis` → `cache` connection), so it flushes the wrong database — a latent bug today.
2. The plan's preamble explicitly promises "web updates and **cache sweeps** never evict active administrator logins", yet its task list never touches this script. Post-plan, sessions (DB 2) do survive, but DB 0 security state is destroyed mid-sweep.

**Fix**: change to `redis-cli -n 1 flushdb` and add the script to Task 1's file list (with a guard comment explaining the DB separation), or replace the raw flush with a targeted cache-store purge.

### 3.3 MEDIUM — The memory/eviction safety argument is asserted, not enforced

Section 2.3 of the plan claims "Redis applies `maxmemory 256mb` and `maxmemory-policy volatile-lru` across all databases globally … zero memory contention or eviction risk." Verified facts:

- On **this server**: `maxmemory = 0` (unlimited) and `maxmemory-policy = noeviction` — everything in `/etc/redis/redis.conf` is commented out. `docs/operations.md` (§"Redis Memory Limit & Eviction Policy") documents 256 MB/volatile-lru, but the live host does not match the docs. So the plan's premise is false on the very host it targets.
- On a host **actually configured per docs**: `volatile-lru` evicts keys with TTLs — and Laravel session keys carry TTLs, so sessions **are evictable by design** under memory pressure. "Zero eviction risk" is a capacity argument (~1.15 MB used, verified live), not a structural guarantee.

**Fix**: restate Section 2.3 accurately (either "unbounded memory today, so eviction cannot occur" or "volatile-lru can evict sessions; safety is capacity-based, monitor `used_memory`"), and reconcile `docs/operations.md` with the actual `/etc/redis/redis.conf` in the same task. If session survival under pressure is a hard requirement, consider `volatile-ttl` (evicts shortest-TTL keys first — sessions outlive 5 s XML fragments) or a dedicated note in Task 1's docs updates.

### 3.4 MEDIUM — Silent no-op upgrade path for existing installs

The web Git Updater pulls new code (where the config default becomes `session`), but an existing server's `.env` still says `SESSION_CONNECTION=cache` — **env wins, and sessions stay in DB 1, so the headline fix silently does nothing** until an operator manually edits `.env` after reading the CHANGELOG note. Installer re-runs fix it (tall.sh sed), but nothing detects or surfaces the stale state.

**Fix**: add a low-cost runtime signal — e.g., a health-check assertion or a `php artisan about` / Git Updater pre-flight warning when `config('session.connection') === 'cache'` — so the resilience fix is verifiable, not just documented.

### 3.5 MEDIUM — Inaccurate documentation anchors will trip the implementer

- `docs/env-reference.md` has **no** "Table 5.1" or "Table 9.1" numbering (tables are unnumbered under headings); the rows to update do exist (~line 292 and ~line 475), but the cited anchors are invented.
- `docs/operations.md` has **no numbered "Section 7"** — its headings are unnumbered; the two target headings exist (lines 291, 308).
- `docs/architecture.md` §11 is titled "Real-Time Attack Neutralization & WebSocket Alert Flow", not "Real-Time Intrusion Prevention Architecture" as the plan states.
- The certificates test is `CertificateManagerLivewireTest.php`, not `CertificateManagerTest.php` (the plan hedges with "(or corresponding module test)").

**Fix**: replace anchors with exact heading titles and correct the test filename. Small edits, but they matter for a plan whose value proposition is precision.

### 3.6 LOW — Task 3 omits the new certificate-module translation keys

The two certificate confirmations currently use hardcoded inline English (for example `"Are you sure you want to delete certificate '{{ $cert->name }}'?..."`). Replacing them with `<x-confirmation-modal>` requires new i18n keys, which Task 3 does not enumerate — it only defines `session_expired_*`. Per project i18n policy (100% en/es/fr parity, module dictionaries), specify the keys (e.g., `cert.delete_confirm_title` / `cert.delete_confirm_message`, `cert.delete_credential_confirm_title` / `cert.delete_credential_confirm_message`) and confirm they go in `app-modules/certificates/lang/{en,es,fr}/admin.php` (which exists).

### 3.7 LOW — Minor UI-parity nits in the proposed modal

The peer `confirmation-modal.blade.php` (lines 44–55) uses unsized buttons (`btn`, `btn btn-error`); the plan proposes `btn btn-primary btn-sm px-6` — a one-off sizing variant. Also the peer includes an Escape-key dismiss (`x-on:keydown.escape.window`); the session modal intentionally has none (correct for expiry semantics — worth a one-line rationale comment so future reviewers don't "fix" it). The Alpine `x-show` approach vs. the peer's server-rendered `@if ($open)` is a justified deviation (the modal must appear precisely when Livewire is broken) — also worth stating explicitly in the plan.

### 3.8 LOW — Cluster/deployment caveat missing

Redis databases 0–15 do not exist in Redis Cluster mode and are limited on some managed providers. Fine for the current standalone topology, but a one-line caveat in the plan's Section 2.2 protects the doc's longevity.

### 3.9 LOW — The unit test asserts configuration, not behavior

`RedisSessionIsolationTest` checks config values resolve correctly (`'13'` in tests, `'2'` default). Since `phpunit.xml` forces `SESSION_DRIVER=array`, no test ever exercises a real Redis session round-trip. Acceptable, but given the project already runs real-Redis tests against dedicated DBs (security tests), an optional behavioral assertion (write a session via the Redis handler, assert the key lands under the DB-13 prefix and survives `cache:clear`) would directly prove the acceptance criterion "Cache Clear Isolation" rather than inferring it.

### 3.10 LOW — Session-security observations adjacent to the plan

Worth folding into the CHANGELOG Upgrade Note:

- The live `.env` has `SESSION_ENCRYPT=false` while `.env.example`, `docs/env-reference.md`, and stated defaults say `true` ("protects session payloads"). Moving unencrypted session payloads into a dedicated DB doesn't change exposure, but since the plan already touches session infrastructure and writes an Upgrade Note, it's a natural place to flag the divergence for operators.
- `SESSION_SECURE_COOKIE` is absent from the live `.env` (the installer raises it post-TLS per template comments) — fine, just confirm during cutover.

---

## 4. Session-Management Best-Practices Assessment

### What the plan does well

- Isolating stateful sessions from disposable cache is textbook separation of concerns — and strictly better than alternatives like relying on key prefixes (which `FLUSHDB` obliterates regardless).
- The one-time re-authentication trade-off on cutover is correctly identified and communicated (orphaned DB 1 session keys carry TTLs and expire naturally — no cleanup hazard).
- Cookie hardening is already sound and untouched by the plan: `http_only=true`, `same_site=lax`, JSON serialization (not PHP), lottery sweeping; session IDs rotate on login via standard Laravel behavior.
- The reload-based recovery (rather than a hardcoded login redirect) correctly preserves guard multiplicity (admin/tenant).

### Where it falls short of best practice

- The eviction-safety claim (Finding 3.3) — "immune to cache:clear" is true; "zero eviction risk" is not, under the documented volatile-lru policy.
- No idle-timeout vs absolute-lifetime discussion (120 min idle default is reasonable for an admin panel, and out of scope — but the plan positions itself as the session-resilience document, so a sentence anchoring this would complete it).
- No consideration of Redis persistence (RDB/AOF): a Redis restart without persistence loses all sessions in DB 2 — the same one-time re-auth as the cutover. Worth one sentence in the operations docs task so operators provisioning sessions-only-in-memory understand the restart behavior.

---

## 5. Architecture & Process Alignment

### Aligned (verified)

- Follows the project's documentation-parity discipline (env-reference, architecture, operations, README, CHANGELOG Upgrade Note, AGENTS.md env-string sync) — exactly the convention set.
- Task 7 mirrors the AGENTS.md pre-commit gate (pint --dirty, optimize:clear, npm build, targeted Pest tests) and correctly avoids invoking the full suite.
- UI tasks follow the mandatory pre-inspection rule — the proposed modal demonstrably matches the peer component's tokens.
- The `phpunit.xml` DB 13 proposal is consistent with the existing 14/15 scheme and the `tallpbx-test-` prefix.
- The keyspace 3-tier design matches actual config wiring (locks → default/DB 0, cache → DB 1, rate limiter → DB 1).

### Deviations

- **TDD ordering**: Tasks 1–5 implement, Task 6 tests. The project's mandated workflow is failing-test-first per change. Recommend restructuring so each task carries its RED test (e.g., Task 1's `RedisSessionIsolationTest` and `InstallerDefaultsTest` assertions written before the config change; Task 2/4's `SessionExpirationModalTest` before the JS/component work; Task 5's module tests before the blade rewrites).
- **Browser-test coverage absent**: the acceptance criteria are all client-behavioral (modal appears on 419, polls stop), and the project has a Pest 4 + Playwright harness. At least one browser test forcing a 419 (logout-then-trigger pattern within the sequential-identity constraint) would verify the one thing unit tests cannot.
- **Uncommitted state**: the reviewed document is itself mid-edit (134 insertions / 66 deletions beyond commit `e674fb65`) — this report evaluates the working-tree version.

---

## 6. Actionable Recommendations (Prioritized)

1. **Extend the 419 interceptor to also suppress the empty-200-response `confirm()`** (`onError` receives `body`; match `response.ok && body.trim() === ''`), and cover it in `SessionExpirationModalTest` — otherwise the headline acceptance criterion fails. (Finding 3.1)
2. **Fix and include `scripts/run-cache-sweep.sh`** in Task 1: `redis-cli -n 1 flushdb`, with a comment documenting the DB separation; this closes the "cache sweeps" promise made in the plan's own status note. (Finding 3.2)
3. **Rewrite Section 2.3's memory claims to match reality** (unlimited/noeviction on this host; volatile-lru evictability on documented hosts) and reconcile `docs/operations.md` with the actual `/etc/redis/redis.conf` in the same task. (Finding 3.3)
4. **Add an upgrade-path detection signal** (health check or updater pre-flight warning when `session.connection === 'cache'`) so existing installs visibly converge instead of silently no-oping. (Finding 3.4)
5. **Correct documentation anchors** (env-reference table titles, operations.md heading titles, architecture.md §11 title, `CertificateManagerLivewireTest.php` filename). (Finding 3.5)
6. **Enumerate the certificate-module confirmation keys** in Task 3 with en/es/fr parity. (Finding 3.6)
7. **Reorder tasks into RED-GREEN pairs** and add one Playwright browser test for the modal. (Section 5)
8. **Minor polish**: button sizing parity with the peer modal, one-line rationale comments for intentional deviations (no Escape dismiss, Alpine-driven vs server-rendered), Redis Cluster caveat, optional behavioral session-isolation test, and flag the `SESSION_ENCRYPT=false` divergence in the CHANGELOG Upgrade Note. (Findings 3.7–3.10)

---

## 7. Conclusion

This is one of the more rigorously grounded implementation plans in this codebase — its causal chain (pipeline → optimize:clear → cache:clear → FLUSHDB → session wipe → 419 → native confirm) is real and verified end-to-end, its Livewire 4 API usage matches the installed v4.4.7 source including subtle internals, and its file/line citations are almost all exact. The Redis DB-2 isolation is the correct architectural fix. Before implementation, the two HIGH items (the unhandled empty-response popup and the `run-cache-sweep.sh` DB-0 flush) should be folded in — both sit directly inside the plan's own stated scope — and the memory-safety section should be rewritten to survive contact with actual server configuration. With those amendments and a TDD reordering, the plan is ready to execute.
