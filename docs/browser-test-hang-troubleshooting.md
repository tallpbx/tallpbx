# Browser Test Hang — Troubleshooting Handoff

**Status:** Unresolved. The single browser test `renders the security manager dashboard`
in `tests/Browser/PanelSmokeTest.php` hangs indefinitely on the dev server. This document
captures the evidence gathered so far so another agent can start fresh.

## Symptom

- `./vendor/bin/pest tests/Browser/PanelSmokeTest.php --filter="renders the security manager dashboard"`
  hangs forever (tested with 150s and 240s `timeout` kills — no output produced at all).
- The tiny sibling test `--filter="renders the monitoring dashboard"` **passes in ~13s**,
  so Playwright, the in-process HTTP server, auth (`loginAs`), and the browser harness are all fine.
- The hang is specific to the `/panel/security` page.

## Evidence captured at the moment of hang

Taken from a watchdog run (test killed ~21s in; diagnostics at kill time):

- The pest process is **single-threaded**, blocked in `select()` (`/proc/<pid>/wchan` = `do_select`) — the in-process React-style server loop is alive and waiting.
- The in-process HTTP server stops answering **every** request: `curl http://127.0.0.1:<port>/panel/security` and even `curl http://127.0.0.1:<port>/` both return `code=000` (timeout). Conclusion: the loop is busy handling the **first** request and never completes it.
- The pest process holds exactly **one ESTAB connection to FreeSWITCH ESL (127.0.0.1:8021)**.
- The last `storage/logs/laravel.log` line at hang time is:

  ```
  testing.INFO: FreeSWITCH ESL connected and authenticated. {"request_id":"...","host":"127.0.0.1","port":8021}
  ```

  So the first page request's handler blocked **after** a successful ESL connect/auth.

## Code-path facts

- `Modules\Security\Livewire\SecurityManager::mount()` does **not** touch ESL directly. Its steps:
  `checkAdminIpStatus` (DB), `loadSettings` (DB), `loadFeedState` (DB),
  `refreshLiveFirewallPolicy` (helper `status`), `refreshFirewallSyncState`
  (`FirewallSyncVerifier::verify` → helper `status`).
- The **layout** (`resources/views/layouts/app.blade.php`) uses
  `App\Support\FreeSwitchRuntimeVersion` on every panel page: `isConnected()` +
  `api('version')`, cached 5 minutes. This is the likely source of the ESL
  connect during the page request.
- In browser tests the **real** `SecurityExecutor` is bound; its `runCommand()`
  short-circuits during tests (`app()->runningUnitTests()`) and logs
  `Blocked privileged security helper execution during a test run` — so the
  helper is NOT the blocker in tests.
- Production timings measured via `php artisan tinker` (healthy FreeSWITCH):
  - firewall helper `status()`: **3.5s, ~2MB output** (real perf smell)
  - `FirewallSyncVerifier::verify()`: **3.9s**
  - ESL `api('sofia status')`: 0.05s
- `FreeSwitchService` socket reads use a 10s stream timeout
  (`stream_set_timeout`), and `readLine()`/`readUntilBlankLine()` return `null`
  on timeout/EOF — no obvious infinite loop in those paths (but see the note
  below about the total freeze, which is hard to explain with 10s timeouts alone).

## FreeSWITCH state on this box (possible environmental factor)

- FreeSWITCH had a recurring **reload/STUN storm** (log: `Timezone reloaded`,
  `ENUM Reloaded`, `External ip address detected using STUN` every ~2s in ~1-minute
  bursts). Root cause found and fixed: the feature test suite's queued reload jobs
  (`ReloadFreeSwitchXml`, `ReloadSofiaProfile`, ACL reloads) executed against the
  **live** ESL (43+ reloads per suite run). See the uncommitted guard fix below.
- During the storm window, raw ESL probes connected but received **no auth banner
  for 12s** (later instant) — ESL starvation.
- FreeSWITCH was restarted (`systemctl restart freeswitch`) and still burns a
  constant ~15% of one core with **0 calls**, spread across many threads (no single
  hot thread; main runtime loop mostly sleeping). This is an unexplained, separate
  mystery that may or may not relate to the hang.

## Suggested next steps (not yet tried)

1. **Feature-level HTTP repro instead of the browser** — faster and instrumentable:
   a Pest feature test that does `$this->actingAs($admin, 'admin')->get('/panel/security')`
   (permission setup per `SecurityManagerLivewireTest::beforeEach`, but do **not** mock
   the executor, to mirror the browser context), wrapped in `pcntl_alarm(60)` +
   a `SIGALRM` handler that prints where it stopped. If this hangs, the browser is
   irrelevant and iteration is seconds instead of minutes.
2. Add temporary `Log::info` step markers around `SecurityManager::mount()` steps
   **and** around `FreeSwitchRuntimeVersion::current()` / the layout footer render,
   then re-run the feature repro once and read `storage/logs/laravel.log` — the last
   marker identifies the blocking step.
3. Suspects, in order: `FreeSwitchRuntimeVersion` (layout), one of the five mount
   steps, or PHP session-file locking (the browser makes overlapping requests with a
   file session driver).
4. Avoid the trap that burned most of the time: the browser test produces **no
   incremental output** (pest buffers everything until the test ends), so any
   watchdog must key off side-channel progress (a marker file written by the test),
   not stdout.

## Working-tree state (all uncommitted, no approval to commit was given)

- `resources/css/custom.css` — **DONE**: DaisyUI `tabs-lift` border visibility fix
  (unlayered `color-mix` override; verified compiled and cascading). Original user request.
- `app/Services/FreeSwitchService.php` — **DONE + tested**: test-run guard that blocks
  state-mutating ESL commands (`reloadxml`, `reloadacl`, `sofia profile ...`) when
  `isTestRun()` and the instance targets the configured ESL endpoint. Fixes the
  reload storm. Unit tests pass (15/15 in `tests/Unit/Services/FreeSwitchServiceTest.php`).
- `tests/Unit/Services/FreeSwitchServiceTest.php` — guard tests (TDD red→green).
- `tests/Pest.php` — scoped `TestCase` context to `Unit/Services/FreeSwitchServiceTest.php`
  only (sibling bare-container tests must stay isolated).
- `CHANGELOG.md` — two `### Fixed` entries (tab borders; test-run ESL guard).
- No temporary diagnostic code remains in the tree (mount logging and smoke-test
  progress markers were reverted). Leftover throwaway files: `/tmp/run-sec-test.sh`,
  `/tmp/browser-*.log` — safe to delete.

## Verification already run (all green)

- `php artisan test --compact --parallel tests/Unit` → 73 passed
- `tests/Feature/Services/FreeSwitchServiceIntegrationTest.php` (fake ESL server) → 15 passed
- `tests/Feature/Services/TenantDomainServiceTest.php` → 10 passed
- `tests/Feature/Http/XmlHandlerFixtureTest.php` → 40 passed
- `tests/Feature/Support/AclConfigurationCacheTest.php` → 5 passed
- `app-modules/acl/tests` → 3 passed; `app-modules/gateways/tests` → 39 passed
- `tests/Feature/Commands/PbxSoundsCommandTest.php` → 5 passed
- Live proof: during test runs, "Blocked FreeSWITCH ESL mutation" warnings appear in
  `laravel.log` and FreeSWITCH's log gains zero reload lines.
