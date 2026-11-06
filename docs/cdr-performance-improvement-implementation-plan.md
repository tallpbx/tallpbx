# CDR Ingestion Fast Entry — Implementation Plan

**Status:** proposed, not implemented. **Sequencing:** this change comes **after** the committed three-tier XML ladder (`4fe5951`) and **before** the PHP-FPM worker pool split — the pool split must not be considered until this entry has been measured (see `docs/php-fpm-worker-pool-split-proposal.md`).

## 1. Why

CDR ingestion always posts to PHP-FPM (`http://127.0.0.1/api/v1/cdr`), and the endpoint runs through the **full application**: every post boots ~90 service providers and the whole route table to do three things — verify a shared token, append the JSON document to a Redis stream, return an empty 200. That is roughly the same boot cost class as a stock XML lookup (~40–48 ms), and the measurements show where it hurts:

- With the trimmed fast controller serving XML at 5–8 calls/sec, the CDR gate failed in **all** fast-path cells: rows reconciled 46 of ~552 at 5 CPS, **0** at 8 CPS — the posts returned 499 (client gone) while the FPM pool was saturated by lookups, and the records only arrived after the spool drain.
- The CDR matrix (2026-11-03) measured the same coupling: at 10 CPS, XML on the worker + CDR via FPM = 71 % call completion versus 100 % with CDR off; the Lua path lost 14–16 points to CDR through FPM.
- Routing CDR to the Octane worker was **rejected** by policy ("CDR always uses PHP-FPM") and by measurement (when the worker also serves XML: 66 % versus 71 %).

The XML trim proved the fix pattern: most of the per-request cost is the boot, not the work. The same trim applies here — with an even shorter provider list, because the CDR request path touches no database, no encryption and no module code.

## 2. Design

### 2.1 Shared minimal bootstrap (code reuse, no duplication)

Extract the machinery both fast entries share into `app/Support/MinimalBootstrap` (abstract class):

- `handle(Request $request): Response` — save/restore the container, the facade application, and the `TALLPBX_WORKER_ROLE` telephony env flag (the slim telephony gate);
- `boot(...)` — `require bootstrap/app.php`, environment + configuration, `RegisterFacades`, config reuse, provider registration from the entry's allowlist, `db` reuse when a previous container has one, the boot loop that deliberately skips `Application::boot()` (no route table);
- `dispatch()` helper — register exactly one route by hand (path, methods, controller, middleware alias, name) and run it through the real router.

`XmlHandlerFastBootstrap` is refactored onto this base with **no behavior change**, guarded by its existing byte-equality suites (fast path and Octane parity).

### 2.2 CDR entry — `app/Support/CdrFastBootstrap`

Provider allowlist — the shared **core**, and nothing else:

| Provider | Why |
|---|---|
| FoundationServiceProvider | Router, events, the request lifecycle |
| CacheServiceProvider | The named `cdr-ingestion` rate limiter |
| RedisServiceProvider | The stream append (DB 3, unprefixed connection) |
| FilesystemServiceProvider | Kept for parity with the proven XML allowlist; cheap |
| AppServiceProvider | Registers the `cdr-ingestion` limiter; shared bindings |

Explicitly **not** registered: `DatabaseServiceProvider` (no DB work on the request path), `EncryptionServiceProvider` (no encrypted casts), `ViewServiceProvider` (see 2.3), and **no module providers** (the slim telephony gate is irrelevant here — there is nothing to slim).

One hand-registered route: `POST /api/v1/cdr` → `CdrIngestionController@store`, middleware `throttle:cdr-ingestion`, name `api.v1.cdr.store` — identical to `routes/api.php`.

### 2.3 Controller change (removes the view dependency)

`CdrIngestionController` returns `response()->json(...)` in five places. The `response()` helper builds the `ResponseFactory` with the **view factory injected**, which would force `ViewServiceProvider` into the CDR entry (the trap the XML trim already hit). Replace the five sites with `new JsonResponse([...], $status)` — byte-identical output, no view factory, and the full-stack path is unaffected.

### 2.4 Front-controller shim — `public/cdr-fast.php`

Same shape as `public/xml-handler-fast.php`: define `LARAVEL_START`, require the autoloader, call `CdrFastBootstrap::handle(Request::createFromGlobals())` and `send()`; on any `Throwable`, `error_log` and `require index.php` (fail-open). Fail-open is the record-safety contract: a fault costs speed, never a record — FreeSWITCH retries and spools regardless.

### 2.5 nginx

In both templates (HTTP + both TLS blocks):

```nginx
location = /api/v1/cdr {
    access_log off;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root/cdr-fast.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;   # Basic tallpbx:<token>
    fastcgi_read_timeout 2s;
    fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
}
```

plus the stock block commented underneath (same rollback convention as the XML path). No FreeSWITCH change: `mod_json_cdr` keeps posting to the same URL.

## 3. TDD steps

1. **RED** — write-equivalence: post the same JSON payload through the normal route and through `CdrFastBootstrap::handle()` against a fake/real Redis stream; assert the **stream entry bytes** and status/headers are identical. Fails (class missing).
   **GREEN** — MinimalBootstrap + CdrFastBootstrap + shim; suite green.
2. **RED** — negative paths through the fast entry: missing token → 401, empty payload → 422, Redis unavailable → 503 (same codes as the full path). **GREEN** as needed.
3. **RED** — allowlist pin: the entry's provider list contains exactly the core set and does **not** contain Database/Encryption/View/module providers. **GREEN**.
4. **RED** — fail-open: a bootstrap that throws is served by `index.php` (same result as the full path). **GREEN**.
5. **RED** — template assertions: `cdr-fast.php` location, `HTTP_AUTHORIZATION` forwarding, read timeout, commented stock block — in all three template sections. **GREEN**.
6. Refactor `XmlHandlerFastBootstrap` onto `MinimalBootstrap`; the XML fast-path and Octane-parity suites must stay green unchanged.
7. Docs (`docs/operations.md`: fast CDR entry + rollback) and `CHANGELOG.md` entry.

## 4. Verification gates

- `bash -n` on every changed resource script; `./vendor/bin/pint --dirty`; `tpbx optimize:clear`.
- Targeted Pest set: the new CDR entry tests, `XmlHandlerFastPathTest`, `XmlHandlerOctaneParityTest`, `InstallerJsonCdrTest`, `InstallerDefaultsTest`, `SystemdWorkerConfigTest`.
- `nginx -t` passes at deploy (the installer already gates on it).
- Live drill on a disposable host: post a payload (200, stream grows), stop Redis (503, no partial write), restore; simulate a broken entry (temporarily rename the file) and confirm FreeSWITCH spools and the drain reconciles.

## 5. Measurement (definition of done)

1. **POST latency A/B** — same payload set through the full path and the fast entry (≥500 posts at concurrency 5): record avg/p50/p90/p95 and per-post CPU; expect the fast entry to land in the ~10–20 ms class versus ~40–48 ms.
2. **Call cells** — SIPp cells at **5 and 8 CPS** with CDR through the fast entry. **Done means the CDR gate passes with rows reconciled** (the recorded baseline is failure: 46 rows at 5 CPS, 0 at 8 CPS).
3. **No XML regression** — the XML battery at `100x5` must stay within 5 % of 43.1 req/sec / 108 ms p50.

## 6. Rollback

Restore the stock CDR block (ships commented in both templates) and reload nginx. No FreeSWITCH change, no data change — the stream and drain are untouched.

## 7. Non-goals

- Not moving CDR off PHP-FPM (policy).
- Not the FPM pool split (separate proposal, sequenced after this).
- No changes to `cdr:work`, the spool drain, or the Redis stream format.

## 8. Risks

| Risk | Mitigation |
|---|---|
| The view-factory chain creeps back in | `new JsonResponse` + allowlist pin test |
| Rate-limiter state divergence | Same named limiter and cache store; the limiter is registered by the shared `AppServiceProvider` |
| Stream connection missing in the minimal container | Core includes config + Redis; the equivalence test proves it |
| A broken entry drops records | Fail-open shim to `index.php`; FreeSWITCH retries then spools |
| Duplication drift between the two entries | Shared `MinimalBootstrap` + per-entry allowlist pin tests |

## 9. Effort

~1.5–2 focused days: shared bootstrap + CDR entry (0.5–1), shim + controller + nginx (0.25), tests incl. write-equivalence (0.5), docs (0.25), measurement (0.5).
