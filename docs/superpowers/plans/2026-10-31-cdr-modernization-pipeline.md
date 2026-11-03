# CDR Modernization & Stream Ingestion Implementation Plan (5.x Series)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Durability prerequisites (resolved):** the three gaps this plan does not close itself — the accepted-then-crashed record window, `vm.overcommit_memory`, and `appliance:sync-host` flushing every database — are implemented in `scripts/resources/redis-durability.sh` and `ApplianceSyncHostCommand`, and documented in [architecture](../../architecture.md) §17.4.

**Goal:** Implement a production-grade, zero-data-loss Call Detail Record (CDR) ingestion pipeline for TallPBX 5.x using FreeSWITCH `mod_json_cdr`, Pulse-style Redis Stream buffering on isolated Database 3, atomic micro-batch digestion into MariaDB (`cdrs`), disk failover reconciliation, secure audio recording playback, and raw JSON technical diagnostics, with a clean module rename to `app-modules/call-detail-records` and table `cdrs`.

**Architecture:** FreeSWITCH `mod_json_cdr` logs both call legs (A and B) and HTTP-POSTs raw JSON payloads to `/api/v1/cdr`. The API controller validates a timing-safe authentication token, appends payloads to an isolated Redis Stream in Database 3 (`pbx:cdr:stream`) via `CdrRedisIngest`, and returns HTTP 200 in <0.5ms. A supervised background worker (`tpbx cdr:work`, managed by `tallpbx-cdr-worker.service`) digests chunks of up to 100 entries via `XRANGE`, generates ordered UUIDs, parses fields with `CdrPayloadParser`, performs an atomic transaction upsert into `cdrs`, and deletes processed stream IDs via `XDEL`. If Redis or the API is unavailable, FreeSWITCH automatically writes failed JSON files to disk (`/var/log/freeswitch/json_cdr/failed/`), where `tpbx cdr:drain-spool` reconciles them without data loss.

**Tech Stack:** FreeSWITCH v1.11 (`mod_json_cdr`), Redis 7 (Database 3), Laravel 13 / PHP 8.5, Livewire 4, MariaDB 11, Systemd, Pest.

**Spec:** Modernized CDR Pipeline & Ingestion Architecture (5.x Major Release)

## Global Constraints

- **5.x Series Modernization (Breaking Clean Break)**: The module directory is `app-modules/call-detail-records`, root namespace `Modules\CallDetailRecords`, database table `cdrs`, and permissions `call-detail-records.*` (`call-detail-records.view`, `call-detail-records.delete`, `call-detail-records.export`). Legacy `xml-cdr` references are cleanly migrated without carrying legacy tech debt.
- **Naming (confirmed)**: The module, directory, namespace, and permission prefix are `call-detail-records` / `Modules\CallDetailRecords` / `call-detail-records.*`. Console commands stay `cdr:*`, the model stays `Cdr`, the table stays `cdrs`, and the panel route stays `/panel/cdr`, so menu keys, routes, and translations are unchanged.
- **Dedicated Redis DB 3**: CDR stream ingestion must strictly target isolated Redis Database 3 (`REDIS_CDR_DB=3`), completely segregated from general background queues (DB 0) and application routing cache (DB 1).
- **Dual-Leg Recording & Idempotency**: Both A-leg and B-leg must be preserved, tagged with `leg = 'a'` and `leg = 'b'`, correlated via `b_leg_uuid`, and deduplicated via a unique composite index on `['call_uuid', 'leg']`. The `call_uuid` column is **required**: a database treats two blanks as two different values, so a blank would slip past that unique key and the row could never be replayed safely.
- **Zero-Loss Disk Fallback**: FreeSWITCH `json_cdr.conf.xml` must configure `err-log-dir` to `/var/log/freeswitch/json_cdr/failed` with `encode="false"` (raw JSON body). On Redis backpressure or HTTP 503, FreeSWITCH spools to disk, drained safely by `tpbx cdr:drain-spool`.
- **Atomic Digestion Guarantee**: Stream entries must only be deleted via `XDEL` *after* the MariaDB transaction commit succeeds. Malformed JSON payloads must be quarantined to `/var/log/freeswitch/json_cdr/quarantine/`, never silently dropped.
- **Timing-Safe Ingestion Authentication**: `POST /api/v1/cdr` must enforce timing-safe token verification (`hash_equals`) and fail closed if unconfigured, with strict rate limiting.
- **Verification Discipline**: All PHP code must pass `./vendor/bin/pint --dirty` and targeted Pest tests. The pre-commit verification checklist in `AGENTS.md` must be observed.

## Review Focus

1. **Explicit UUID Generation**: Because bulk inserts bypass Eloquent's `HasUuids` model events, `CdrPayloadParser` must explicitly assign `(string) Str::orderedUuid()` to each row to prevent `Column 'id' cannot be null` database exceptions.
2. **Transaction-Bound Deletion**: Verify that `CdrRedisIngest::digest()` wraps the multi-row insert in `DB::transaction()` and only executes `XDEL` on processed IDs after a successful commit.
3. **Raw Body Streaming (`encode=false`)**: FreeSWITCH's `json_cdr.conf.xml` must set `encode="false"` so payloads arrive as raw `application/json` rather than URL-encoded `cdr=%7B...`.
4. **Audio Recording Containment**: The recording playback streaming route must enforce tenant ownership and verify canonical paths (`realpath()`) to strictly prevent directory traversal attacks (`..`).
5. **Worker Supervision**: The background daemon must be supervised via a dedicated systemd service (`scripts/tallpbx-cdr-worker.service`) with auto-restart and `--memory=512` memory ceiling.

---

### Task 1: Module Rename to `call-detail-records` & Migration to `cdrs` Table

**Files:**
- Move: `app-modules/xml-cdr/` $\rightarrow$ `app-modules/call-detail-records/`
- Modify: `app-modules/call-detail-records/module.json`
- Modify: `app-modules/call-detail-records/src/Providers/ModuleServiceProvider.php`
- Modify: `app-modules/call-detail-records/src/Models/Cdr.php`
- Create: `app-modules/call-detail-records/database/migrations/2026_11_01_000001_create_cdrs_table.php`
- Test: `app-modules/call-detail-records/tests/CdrTest.php`

**Interfaces:**
- Produces: `Modules\CallDetailRecords\Models\Cdr`
- Table: `cdrs`
  - `id` (uuid, primary)
  - `tenant_id` (foreignId, indexed)
  - `call_uuid` (string, required, indexed)
  - `b_leg_uuid` (string, nullable, indexed)
  - `leg` (char(1), default 'a', indexed)
  - `caller_id` (string, nullable)
  - `caller_id_name` (string, nullable)
  - `destination` (string, nullable)
  - `duration` (unsignedInteger, default 0)
  - `billsec` (unsignedInteger, default 0)
  - `hangup_cause` (string, nullable)
  - `direction` (string, nullable)
  - `record_path` (string, nullable)
  - `json_data` (longtext or json, nullable)
  - `start_stamp` (timestamp, nullable)
  - `answer_stamp` (timestamp, nullable)
  - `end_stamp` (timestamp, nullable)
  - `timestamps`
  - Unique composite index: `['call_uuid', 'leg']`

- [x] **Step 1: Move directory and rename namespace**

```bash
git mv app-modules/xml-cdr app-modules/call-detail-records
```
Update namespace references from `Modules\XmlCdr` to `Modules\CallDetailRecords` across all files in `app-modules/call-detail-records/`. Also update the PSR-4 map in `composer.json`, the three `\Modules\XmlCdr\Models\Cdr` references in `resources/views/components/dashboard/stats.blade.php`, and the high-volume index list in `database/migrations/2026_10_28_000002_add_tenant_created_at_indexes_to_high_volume_tables.php` (`xml_cdr` / `xml_cdrs` to `cdrs`).
Update `module.json`:
- `"name": "call-detail-records"`
- `"namespace": "Modules\\CallDetailRecords"`
- `"display_name": "Call Detail Records"`
- `"providers": ["Modules\\CallDetailRecords\\Providers\\ModuleServiceProvider"]`

- [x] **Step 2: Create canonical `cdrs` table migration**

Create `app-modules/call-detail-records/database/migrations/2026_11_01_000001_create_cdrs_table.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 5.x canonical table (fresh install; not backwards compatible with 4.x xml_cdr)
        Schema::create('cdrs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('call_uuid')->index();
            $table->string('b_leg_uuid')->nullable()->index();
            $table->char('leg', 1)->default('a')->index();
            $table->string('caller_id')->nullable();
            $table->string('caller_id_name')->nullable();
            $table->string('destination')->nullable();
            $table->unsignedInteger('duration')->default(0);
            $table->unsignedInteger('billsec')->default(0);
            $table->string('hangup_cause')->nullable();
            $table->string('direction')->nullable();
            $table->string('record_path')->nullable();
            $table->timestamp('start_stamp')->nullable();
            $table->timestamp('answer_stamp')->nullable();
            $table->timestamp('end_stamp')->nullable();
            $table->longText('json_data')->nullable();
            $table->timestamps();
            $table->unique(['call_uuid', 'leg'], 'cdrs_call_uuid_leg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cdrs');
    }
};
```

- [x] **Step 3: Update `Cdr` Eloquent Model**

In `app-modules/call-detail-records/src/Models/Cdr.php`:
- Namespace: `namespace Modules\CallDetailRecords\Models;`
- `$table = 'cdrs';`
- Add to `$fillable`: `'b_leg_uuid'`, `'leg'`, `'record_path'`, `'json_data'`
- Add cast: `'json_data' => 'array'`

- [x] **Step 4: Update Module Service Provider permissions and routes**

In `app-modules/call-detail-records/src/Providers/ModuleServiceProvider.php`:
- Set `moduleName(): string { return 'call-detail-records'; }`
- Set `moduleNamespace(): string { return 'Modules\CallDetailRecords'; }`
- Update menu items to use permission `call-detail-records.view` instead of `xml-cdr.view`.
In `app-modules/call-detail-records/routes/web.php`:
- Update routes middleware to `admin.can:call-detail-records.view`.

- [x] **Step 5: Run migration and sync module registry**

Run:
```bash
tpbx migrate
tpbx module:sync --only-local
tpbx db:seed --class=AdminSeeder
./vendor/bin/pest app-modules/call-detail-records/tests/CdrTest.php
```
Expected: PASS.

- [x] **Step 6: Commit**

```bash
git add app-modules/call-detail-records/
git commit -m "refactor(cdr): modernize module to cdr and table to cdrs for 5.x series"
```

---

### Task 2: FreeSWITCH `mod_json_cdr` Package Installation & Configuration

**Files:**
- Create: `scripts/freeswitch/json_cdr.conf.xml` (versioned template with a token placeholder — A-R1/M1)
- Create: `scripts/resources/freeswitch-json-cdr.sh` (render + spool-directory helpers, fixture-tested)
- Modify: `scripts/resources/freeswitch.sh` (`configure_json_cdr()`, called from the configure-only path and the full install path)
- Modify: `scripts/resources/tall.sh` (provision `FREESWITCH_CDR_TOKEN` next to `FS_XML_HANDLER_TOKEN`)
- Modify: `scripts/resources/nginx.sh` (forward `HTTP_AUTHORIZATION` to PHP-FPM — A-R8)
- Deploy to: `/etc/freeswitch/autoload_configs/json_cdr.conf.xml` and `modules.conf.xml`

**Interfaces:**
- FreeSWITCH configuration: `<param name="url" value="http://127.0.0.1/api/v1/cdr"/>` (the module appends `?uuid=` itself, so the secret travels in the Basic auth header)
- FreeSWITCH configuration: `<param name="cred" value="tallpbx:__TALLPBX_CDR_TOKEN__"/>` rendered by the installer; the repo never carries a literal token
- FreeSWITCH configuration: `<param name="encode" value="false"/>` (raw JSON body) and `encode-values=false` (values stay readable, not percent-encoded)
- FreeSWITCH configuration: no `log-dir` at all, so successful posts never touch disk (verified against the module reference); failures reach `err-log-dir=/var/log/freeswitch/json_cdr/failed`
- FreeSWITCH configuration: `log-b-leg=true`, `retries=3`, `delay=1`, `timeout=5`

- [x] **Step 1: Install `freeswitch-mod-json-cdr` package**

`configure_json_cdr()` runs `apt_get_with_lock_wait install -y freeswitch-mod-json-cdr` (apt skips it when present). Verified at `/usr/lib/freeswitch/mod/mod_json_cdr.so`.

- [x] **Step 2: Create the `json_cdr.conf.xml` template and render helper**

`scripts/freeswitch/json_cdr.conf.xml` keeps `__TALLPBX_CDR_TOKEN__`; `scripts/resources/freeswitch-json-cdr.sh::render_json_cdr_config()` substitutes the token and **refuses to render on an empty token** (fail closed, A-R1). `tall.sh` provisions `FREESWITCH_CDR_TOKEN` exactly like `FS_XML_HANDLER_TOKEN`. The template deliberately omits `log-dir` and sets `encode-values=false`.

- [x] **Step 3: Set up failover spool and quarantine directories**

`ensure_json_cdr_spool_dirs` creates `failed/` and `quarantine/` under `/var/log/freeswitch/json_cdr`, owned `freeswitch:tallpbx-media`, mode 775 with the set-group-id bit so files written by FreeSWITCH stay readable and removable by the drain command (www-data).

- [x] **Step 4: Enable `mod_json_cdr` in FreeSWITCH**

`ensure_freeswitch_module_enabled "$modules_conf" "mod_json_cdr" "<!-- XML Interfaces -->"` (the stock Debian file has no line for it, so one is appended). Runtime load: `fs_cli -x "load mod_json_cdr"`; verified `fs_cli -x "module_exists mod_json_cdr"` returns `true`.

- [x] **Step 5: Forward the Authorization header and commit**

The deployed `cred` only authenticates when nginx passes the header: the nginx template and the live site now carry `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` in the `index.php` location (A-R8).

---

### Task 3: Ingestion API Endpoint with Token Verification & Redis DB 3 Stream Buffer

**Files:**
- Modify: `config/database.php` (add the `'cdr'` connection: DB 3, `prefix => ''`)
- Modify: `config/freeswitch.php` (add the `'cdr'` block: token + throttle)
- Modify: `.env.example` (`REDIS_CDR_DB=3`, `FREESWITCH_CDR_TOKEN=`, `FREESWITCH_CDR_THROTTLE_*`)
- Modify: `app/Providers/AppServiceProvider.php` (`RateLimiter::for('cdr-ingestion')`)
- Create: `app/Services/Cdr/CdrRedisIngest.php`
- Create: `app/Http/Controllers/Api/CdrIngestionController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/CdrIngestionControllerTest.php`

**Interfaces:**
- Produces: `CdrRedisIngest::ingest(string $rawJson): string` (stream message ID)
- Produces: `CdrRedisIngest::getPendingCount(): int` (health/monitoring consumer lands with Task 4)
- Redis Connection: `config('database.redis.cdr')` $\rightarrow$ DB 3, unprefixed, Stream: `pbx:cdr:stream`

- [x] **Step 1: Add the `'cdr'` connection, `.env.example` keys, and the token block**

The connection carries `prefix => ''` (like `lua_proxy`) so the stream key stays literally `pbx:cdr:stream`, and uses the same `decorrelated_jitter` backoff default as the other connections. `config/freeswitch.php` gains a `'cdr'` block whose token has **no default** — the endpoint fails closed until the installer provisions one — plus throttle keys (A-R1, A-R3).

- [x] **Step 2: Write test for CdrIngestionController**

`tests/Feature/Http/CdrIngestionControllerTest.php` (RED before implementation): valid Basic/Bearer/query token buffers the payload and answers 200; missing, mismatched, wrong-username, or unconfigured tokens answer 401; a buffer failure answers 503; an empty body answers 422; the connection is DB 3 with an empty prefix; the `cdr-ingestion` limiter defaults to 6,000/min per IP; the route carries `throttle:cdr-ingestion`.

- [x] **Step 3: Implement `CdrRedisIngest`**

`ingest()` appends the untouched payload under the `data` field; `getPendingCount()` reports `XLEN` for the Task 4 health consumer.

- [x] **Step 4: Implement `CdrIngestionController`**

Timing-safe `hash_equals()` with fail-closed on an empty configured token; the token is accepted from Basic credentials (`tallpbx:<token>`, the transport mod_json_cdr uses), a Bearer header, or the query string. The body is read raw, with the `cdr=<url-encoded>` form decoded as a fallback. On a Redis exception the endpoint logs and answers 503 so FreeSWITCH spools to disk.

- [x] **Step 5: Register route and limiter**

`Route::post('/v1/cdr', ...)->middleware('throttle:cdr-ingestion')->name('api.v1.cdr.store')`. The named limiter is env-configurable (`FREESWITCH_CDR_THROTTLE_*`) and defaults to 6,000/min per IP — aligned with the xml handler, since two legs per call at the documented 15–20 calls/sec ceiling is 1,800–2,400 posts/min (A-R3 resolved).

- [x] **Step 6: Run tests and verify**

`./vendor/bin/pest tests/Feature/Http/CdrIngestionControllerTest.php` — 12 passed. Live verification on the reference host: a real POST with the provisioned Basic token through nginx answered 200 and produced stream entry `1793580217323-0` on DB 3 containing the exact raw payload; a tokenless POST answered 401 (also proving nginx forwards `HTTP_AUTHORIZATION`, A-R8). The test entry was deleted afterwards.

- [x] **Step 7: Commit**

Code and docs committed separately on `5.x` (`feat(cdr)` ingestion endpoint, then the plan/changelog update).

---

### Task 4: Continuous Stream Worker (`tpbx cdr:work`) with Explicit UUIDs & Transaction Isolation

**Files:**
- Create: `app/Services/Cdr/CdrPayloadParser.php`
- Modify: `app/Services/Cdr/CdrRedisIngest.php` (add `digest()`, the lock, and a testable connection seam)
- Create: `app/Console/Commands/CdrWorkCommand.php`
- Test: `tests/Feature/Services/CdrPayloadParserTest.php`
- Test: `tests/Feature/Services/CdrRedisIngestDigestTest.php`
- Test: `tests/Feature/Commands/CdrWorkCommandTest.php`
- Fixtures: `tests/Fixtures/cdr/{a,b}-leg.json` (captured from a real bridged call)

**Interfaces:**
- `CdrPayloadParser::parse(string $rawJson): ?array` (includes `'id' => (string) Str::orderedUuid()`)
- `CdrPayloadParser::quarantine(string $entryId, string $rawJson): bool`
- `CdrRedisIngest::digest(int $chunkSize = 100): int` (transactional upsert + `XDEL` only after commit)

- [x] **Step 1: Write tests for `CdrPayloadParser`**

Real fixtures replace guessed field names (A-R11): `tests/Fixtures/cdr/{a,b}-leg.json` were captured on the reference host from a bridged call (`null` endpoints, `bridge()`), sanitized for the repository. Tests assert the full mapping, the explicit non-null UUID in `id`, and that malformed / uuid-less / unattributable payloads return null and quarantine.

- [x] **Step 2: Implement `CdrPayloadParser`**

Mapping as verified against the captured payloads: `variables.uuid` → `call_uuid`; `bridge_uuid`/`signal_bond` → `b_leg_uuid`; the a-side is identified by `last_bridge_role=originator`, `originated_legs`, or `last_app=bridge` (a bridged call has exactly one of those on its originating leg), and a bonded payload without them is the b-side. Caller identity comes from `callflow[0].caller_profile`; stamps from the `*_epoch` variables; `record_path`/`record_name` are combined. The tenant comes from `variables.domain_name` through an **uncached** `TenantDomain` lookup — a cached answer could outlive a domain moving between tenants and misattribute records. Anything unmappable returns null and is quarantined by the digest cycle, never inserted.

- [x] **Step 3: Implement atomic `digest()` in `CdrRedisIngest`**

Shipped with three hardening additions over the original sketch: a `Cache::lock` (`pbx:cdr:digest-lock`) so only one worker digests at a time, explicit `created_at`/`updated_at` in every batch row (upsert adds no timestamps), and `json_data` kept as a JSON string (upsert bypasses Eloquent casts). Stream entries are deleted only after the MariaDB transaction commits or the quarantine copy lands — a failed transaction keeps every entry, so a crash between commit and `XDEL` is a replay, never data loss.

- [x] **Step 4: Implement `CdrWorkCommand` (`tpbx cdr:work`)**

`--chunk`, `--sleep`, `--memory`, `--once`, and `--warn-pending` options; SIGTERM/SIGINT finish the current cycle before exit so a shutdown cannot cut a batch in half. The backlog warning is `getPendingCount()`'s consumer (closing the A-R12 monitoring gap): above the threshold the count is logged and printed at most once a minute while idle.

- [x] **Step 5: Run tests to verify**

`tests/Feature/Services/CdrPayloadParserTest.php` (6 passed), `tests/Feature/Services/CdrRedisIngestDigestTest.php` (5 passed), and `tests/Feature/Commands/CdrWorkCommandTest.php` (3 passed) — all written first and watched fail. The digest suite also proves the two failure paths: a broken MariaDB transaction leaves every stream entry in place, and an unwritable quarantine directory keeps the unparseable entry instead of dropping it.

- [x] **Step 6: Commit and live-verify**

Live on the reference host: `tpbx cdr:work --once` digested 5 entries — 2 rows in `cdrs` (legs a and b, ordered UUIDs, bidirectional `b_leg_uuid` links, correct caller/destination/duration/billsec/hangup_cause) and 3 quarantined payloads (2 without a resolvable domain, 1 malformed), leaving `pbx:cdr:stream` empty.
---

### Task 5: Worker Supervision Unit & Failover Disk Spool Drainer

**Files:**
- Create: `scripts/tallpbx-cdr-worker.service`
- Create: `app/Console/Commands/CdrDrainSpoolCommand.php`
- Create: `app/Services/Cdr/CdrBatchWriter.php` (shared transactional upsert for digest and drain)
- Modify: `app/Services/Cdr/CdrRedisIngest.php` (digest now delegates to the batch writer)
- Modify: `routes/console.php` (schedule drain every 5 minutes)
- Modify: `scripts/tallpbx-scheduler.service` (spool whitelist — A-R2)
- Modify: `scripts/resources/tall.sh` (deploy + enable the worker unit — A-R10)
- Test: `tests/Feature/Commands/CdrDrainSpoolCommandTest.php`
- Test: `tests/Feature/InstallerJsonCdrTest.php` (unit and wiring assertions)

**Interfaces:**
- Systemd: `systemctl status tallpbx-cdr-worker`
- Console: `tpbx cdr:drain-spool [--spool-dir=] [--quarantine-dir=] [--batch=100] [--grace=2]`

- [x] **Step 1: Create systemd service unit**

`scripts/tallpbx-cdr-worker.service` mirrors the queue unit's shape (`User=www-data`, `Restart=always`, `ProtectSystem=strict`, journal logging) with `NoNewPrivileges=yes` added (A-R12 — unlike the scheduler, this worker never elevates privileges). `ReadWritePaths` covers `storage/`, `bootstrap/cache/`, and the CDR spool tree. The installer deploys and enables the unit alongside the queue and scheduler services (A-R10).

- [x] **Step 2: Implement `CdrDrainSpoolCommand`**

Scans the failure spool, skips files younger than `--grace` seconds (a fresh file may still be mid-write), batches up to `--batch` files, and runs them through the same parser and transactional batch writer as the stream digest. Each file is removed only after its row commits or its payload lands in quarantine — an interrupted drain retries instead of losing records. The batch writer was extracted so the digest and the drain can never diverge on upsert semantics.

- [x] **Step 3: Register in scheduler**

`Schedule::command('cdr:drain-spool')->everyFiveMinutes()->withoutOverlapping();`. The scheduler's `ProtectSystem=strict` sandbox now whitelists `/var/log/freeswitch/json_cdr` (A-R2) — without it the scheduled drain would appear healthy while files piled up.

- [x] **Step 4: Run tests to verify**

`tests/Feature/Commands/CdrDrainSpoolCommandTest.php` — 6 passed (drain, quarantine, domain-less quarantine, grace skip, failed-quarantine retention, empty spool). Installer assertions cover the unit, the `tall.sh` wiring, and the scheduler whitelist. **Live on the reference host**: the enabled worker digested a seeded stream entry into `cdrs` within 4 seconds, and `tpbx cdr:drain-spool` drained a planted spool file into a `cdrs` row and removed it.

- [x] **Step 5: Commit**

Code and docs committed separately on `5.x`.

---

### Task 6: UI Enhancements — Leg Filtering, Audio Playback & Raw JSON Diagnostics

**Files:**
- Modify: `app-modules/call-detail-records/src/Livewire/CdrList.php`
- Modify: `app-modules/call-detail-records/src/Livewire/CdrDetail.php`
- Modify: `app-modules/call-detail-records/resources/views/cdr-list.blade.php`
- Modify: `app-modules/call-detail-records/resources/views/cdr-detail.blade.php`
- Create: `app-modules/call-detail-records/src/Http/Controllers/CdrRecordingController.php`
- Modify: `app-modules/call-detail-records/routes/web.php`
- Test: `app-modules/call-detail-records/tests/CdrUiTest.php`

**Interfaces:**
- View: `cdr-list.blade.php` displays DaisyUI `badge-xs font-mono` for A/B legs and audio indicator
- View: `cdr-detail.blade.php` features `<audio controls>` player and Alpine collapsible JSON diagnostics
- Route: `GET /panel/cdr/{cdrId}/recording` (secure stream with auth guard and path traversal check)

- [x] **Step 1: Write tests for recording streaming and the UI**

`app-modules/call-detail-records/tests/CdrRecordingTest.php` (6) and `CdrUiTest.php` (4), written first and watched fail. Cross-tenant access answers **404, not 403** — the module's convention is that a foreign record id is invisible rather than acknowledged.

- [x] **Step 2: Implement `CdrRecordingController`**

Route `GET /panel/cdr/{cdrId}/recording` (`panel.cdr.recording`) behind `admin.can:call-detail-records.view`. The controller re-applies tenant scope (admins see all) and resolves the stored path the A-R9 way: join relative paths to `media-storage.store_root`, canonicalize with `realpath()`, and require the result inside `store_root` / `spool_root` before `response()->file()` streams it with an audio content type and `nosniff`. Traversal attempts, absolute paths outside the roots, and missing files all answer 404.

- [x] **Step 3: Leg filtering and the recording shortcut in `CdrList`**

`$legFilter` (all/a/b) with an `updatedLegFilter` reload hook, a `select select-bordered select-sm` toolbar matching peer form conventions, a `badge badge-xs font-mono` leg column, and a playback shortcut per recorded row — a plain anchor without `wire:navigate`, following the fax-inbox media-link precedent so playback hits the file URL directly.

- [x] **Step 4: Audio player and raw diagnostics in `CdrDetail`**

An Audio Recording card with `<audio controls preload="none">` (or a plain-language explanation when unrecorded) and a "Raw Technical Diagnostics (JSON)" card using the `collapse collapse-arrow` pattern from the modules list, showing the pretty-printed payload. Leg and Linked Leg fields join the detail grid. The view is wrapped in one root element, as Livewire requires.

- [x] **Step 5: Run UI tests and verify**

`./vendor/bin/pest app-modules/call-detail-records/tests/` — 25 passed. Translation keys added to `admin.*` in `en`, `es`, and `fr` to keep root dictionary parity. The recording route answers 302 unauthenticated on the reference host.

- [x] **Step 6: Commit UI enhancements**

Code and docs committed separately on `5.x`.
---

### Task 7: Full Pipeline Integration & SIPp End-to-End Verification Gate

**Gate results (reference host):**

1. **Both legs recorded without encoding corruption — PASS.** A real bridged call (`null` endpoints + `bridge()`) posted two documents with `encode=false`; the raw payloads arrived byte-exact and were captured as the test fixtures in Task 4.
2. **Endpoint validates the token and buffers on DB 3 — PASS with a deviation.** Valid Basic credentials answered 200 and the payload landed in `pbx:cdr:stream` on DB 3; a missing token answered 401. Measured wall time through the full framework boot is ~0.4 s, not the <0.5 ms in the criterion — that target describes handler time and needs the fast-path front controller (xml-handler precedent) as a separate optimization. FreeSWITCH's 5 s timeout keeps the real path safe meanwhile.
3. **Worker digests both legs atomically — PASS.** The running `tallpbx-cdr-worker.service` digested a live call's two legs within ~4 s: two `cdrs` rows with ordered UUIDs, legs a/b, bidirectional `b_leg_uuid`, stream back to 0.
4. **Failover and drain — PASS with a safer substitute.** Redis was deliberately **not** stopped (it also serves sessions and cache); instead the module was pointed at a closed local port for one call, which exercises the same FreeSWITCH failure path. Both legs spooled to `/var/log/freeswitch/json_cdr/failed/`, the config was restored, and `tpbx cdr:drain-spool` reconciled both files into `cdrs` and removed them.
5. **Panel — PASS via component tests.** `CdrUiTest` proves leg badges, the leg filter, the recording shortcut, the audio player, and the collapsible diagnostics; the recording route answers 302 unauthenticated and 404 for traversal/cross-tenant paths.
6. **Style — PASS.** Scoped Pint over all 59 touched PHP files is clean.

**Suite status (re-verified 2026-11-03):** the targeted CDR suites are green — **81 tests across the eleven CDR test files** (ingestion endpoint 12, payload parser 6, digest 5, worker command 3, spool drainer 6, installer wiring 8, module schema and behaviour 15, panel UI 6, recording playback 6, action permissions 7, CSV export 7), plus `tests/Feature/ModuleAutoloadTest.php` (10 tests) which asserts the `call-detail-records → Modules\CallDetailRecords\` PSR-4 mapping. Scoped Pint over the CDR files is clean.

The plan's earlier note about pre-existing failures elsewhere in the suite is **superseded**; the current state was re-measured rather than assumed. The full parallel suite was run twice on 2026-11-03 and reported **5 failures** and then **7 failures**; the count moves because `DialplanXmlMirrorTest` is sensitive to how many workers run at once.

| Suite | State recorded in the original gate | Actual state on 2026-11-03 | Cause |
|:--|:--|:--|:--|
| `tests/Feature/BootstrapInstallerTest.php` | 2 failing (expected `4.x`, branch documents `5.x`) | **9 passed** | Already fixed upstream — `ab97fea test(installer): assert the 5.x bootstrap defaults and install guide` |
| `tests/Feature/Services/DialplanXmlMirrorTest.php` | 4 failing (parallel-only shared-Redis interference) | **Flaky in the full parallel run — 2 to 4 failures per run; 8 passed when the file is run on its own** | Shared-Redis interference between parallel workers. Unchanged by this work: the failing assertions read and write the same Lua mirror keys on Redis DB 3 |
| `tests/Feature/Architecture/ModuleArchitectureTest.php` | 1 failing (`XmlHandlerFastBootstrap` provider import from `de084fe`) | **2 failing**, different root cause | Both are Octane-workstream issues, neither is CDR: (1) `app/Support/XmlHandlerFastBootstrap.php` and `app/Support/TelephonyWorkerProviders.php` import `Modules\NumberTranslations\Providers\ModuleServiceProvider` outside the documented shared-kernel allowlist; (2) the FreeSWITCH domain listener list now also contains `ClearTenantContext.php` and `VerifyCleanDatabaseState.php` |
| `tests/Feature/CodeDocumentationTest.php` | not recorded in the original gate | **1 failing** | Three Octane files declare a type without a PHPDoc comment: `app/Listeners/Octane/ClearTenantContext.php`, `app/Listeners/Octane/VerifyCleanDatabaseState.php`, `app/Support/TelephonyWorkerProviders.php`. None of them is a CDR file |
| `tests/Feature/Pbx/Services/PbxRoutingServicesTest.php` | not recorded in the original gate | **Intermittent** | A parallel-worker race: `tests/Feature/Seeders/DatabaseSeederTest.php` deletes the whole `custom-modules/demo-webhook` fixture tree in its `afterEach`, while another worker is inside `ModuleServiceProvider::detectRootTranslationCollisions()`. That method guards the *root* dictionary with `file_exists()` but requires the *module* dictionary unguarded, so a file that vanished between the `glob()` and the `require` fatals with "Failed to open stream". Reproduced deterministically with a fixture whose first language file deletes its sibling, and it needs no CDR code at all |

**None of the remaining failures are caused by the CDR pipeline.** Every failing assertion names a file outside `app-modules/call-detail-records/`, `lang/`, or this plan. The CDR work introduces no shared-kernel violation either: `app/Services/Cdr/CdrBatchWriter.php` is allowlisted in `tests/Feature/Architecture/ModuleArchitectureTest.php` with the justification "Shared CDR commit path used by the stream digest and the spool drain".

---

### Task 8: Export Permission and CSV Export

**Files:**
- Modify: `app-modules/call-detail-records/src/Providers/ModuleServiceProvider.php` (register `call-detail-records.export`)
- Modify: `app-modules/call-detail-records/src/Livewire/CdrList.php` (the export action and the shared query)
- Modify: `app-modules/call-detail-records/resources/views/cdr-list.blade.php` (the export button)
- Modify: `lang/{en,es,fr}/admin.php` (translation keys)
- Test: `app-modules/call-detail-records/tests/CdrExportTest.php`
- Test: `app-modules/call-detail-records/tests/CdrActionPermissionTest.php` (the denial and the positive control)

**Interfaces:**
- Permission: `call-detail-records.export` — "Export CDR records"
- Action: `CdrList::exportCdrs()` — a streamed CSV download
- Download name: `call-detail-records-YYYY-MM-DD.csv`

- [x] **Step 1: Write the failing tests first**

Seven tests in `CdrExportTest.php` (permission registered, the action's ability resolution, the download itself, tenant isolation, the leg filter, the spreadsheet-formula guard, and the button visibility) plus two in `CdrActionPermissionTest.php` (a viewing-only user is refused through the Livewire update endpoint; a user holding the export permission is allowed). All nine failed before the implementation existed.

- [x] **Step 2: Register the permission and map the action onto it**

`permissions()` gains `call-detail-records.export`. The component declares `livewireActionAbilities()` returning `['exportCdrs' => [['call-detail-records.export']]]`. Without that override the shared resolver would treat an unknown action as a plain interaction and demand only the view permission — but a CSV file leaves the panel and lands on the operator's own disk, where the tenant's access controls no longer apply, so it needs its own grant.

- [x] **Step 3: Implement the export**

`exportCdrs()` returns a `StreamedResponse` produced by `response()->streamDownload()`. Rows are read page by page with `lazy()`, so a large export never loads the table into memory, and the export is capped at 25,000 rows with a truncation notice. The list and the export share one private `scopedQuery()`, so they cannot drift apart on tenant scope or the leg filter — an export can never hand a tenant a record its own list would hide.

- [x] **Step 4: Guard the cells against spreadsheet formulas**

A caller display name is attacker-controlled. A spreadsheet runs a cell that starts with `=`, `+`, `-` or `@`, so those leading characters are defused with an apostrophe, which the spreadsheet reads as "this cell is plain text".

- [x] **Step 5: Add the button and the translations**

The button is a `btn btn-outline btn-sm` in the leg-filter toolbar, shown only when `PanelActor::can('call-detail-records.export')` is true, with the house loading pattern (`wire:loading.attr="disabled"`, `wire:loading.class="opacity-70 pointer-events-none"`, inline `loading loading-spinner loading-xs`). Translation keys `admin.cdr_export` and `admin.cdr_export_truncated` are added to `en`, `es` and `fr`, keeping the root dictionary parity-complete (548 keys in each locale).

- [x] **Step 6: Verify**

`./vendor/bin/pest app-modules/call-detail-records/tests/CdrExportTest.php` — 7 passed. `CdrActionPermissionTest.php` — 7 passed.

---

### Task 9: Listing Index Aligned With The Panel Query

**Files:**
- Modify: `app-modules/call-detail-records/database/migrations/2026_11_01_000001_create_cdrs_table.php`
- Create: `app-modules/call-detail-records/database/migrations/2026_11_03_000001_align_cdrs_listing_index_with_the_panel_query.php`
- Modify: `app-modules/call-detail-records/src/Livewire/CdrList.php` (a deterministic tiebreaker)
- Test: `app-modules/call-detail-records/tests/CdrTest.php`

**Interfaces:**
- Index: `cdrs_tenant_id_start_stamp_index` on `['tenant_id', 'start_stamp']`

- [x] **Step 1: Find the mismatch**

The panel lists a tenant's records newest first by `start_stamp`, and the dashboard shows the same five most recent records. The original index was built on `created_at` — the moment the worker stored the row — so the database sorted every panel page in memory. `EXPLAIN` confirmed it: `Using where; Using filesort`, with `cdrs_tenant_id_created_at_index` chosen only because it matched the tenant filter.

- [x] **Step 2: Write the failing test first**

`CdrTest.php` asserts the table carries `cdrs_tenant_id_start_stamp_index` over exactly `['tenant_id', 'start_stamp']`, and that `cdrs_tenant_id_created_at_index` is gone.

- [x] **Step 3: Fix the schema**

The create migration now defines `cdrs_tenant_id_start_stamp_index` directly, so a fresh install never builds the wrong index. A second migration reconciles a host that already ran the earlier version.

**The second migration is deliberately two statements, not one.** The tenant foreign key borrows an index that starts with the tenant column, and the old composite index happened to be the one it borrowed. Dropping it while nothing else covers that column is refused by the database:

```
SQLSTATE[HY000]: General error: 1553 Cannot drop index 'cdrs_tenant_id_created_at_index':
needed in a foreign key constraint
```

Laravel emits the drop *before* the add inside a single `Schema::table()` blueprint, so the combined form fails. The replacement index is therefore created first and the old one removed afterwards, in a second statement. The sequence was proven on a disposable MariaDB database before touching the live host, including a full `up()` → `down()` → `up()` round trip, and the `ON DELETE CASCADE` foreign key survives the swap untouched.

- [x] **Step 4: Make the ordering total**

`scopedQuery()` orders by `start_stamp` and then by `id`. Two calls that started in the same second now have a fixed order, which the paged export depends on and which lets the index serve the sort.

- [x] **Step 5: Verify**

`EXPLAIN SELECT * FROM cdrs WHERE tenant_id = 1 ORDER BY start_stamp DESC, id DESC LIMIT 26` on the live host now reports `Using where` with `key: cdrs_tenant_id_start_stamp_index` — the filesort is gone.

---

### Task 10: Required Call Reference Number

**Files:**
- Modify: `app-modules/call-detail-records/database/migrations/2026_11_01_000001_create_cdrs_table.php`
- Create: `app-modules/call-detail-records/database/migrations/2026_11_03_000002_require_cdrs_call_uuid.php`
- Modify: `app-modules/call-detail-records/src/Livewire/CdrList.php` (the deletion confirmation name)
- Test: `app-modules/call-detail-records/tests/CdrTest.php`

**Interfaces:**
- Column: `cdrs.call_uuid` — `varchar(255) NOT NULL`
- Migration: `2026_11_03_000002_require_cdrs_call_uuid`

- [x] **Step 1: Find the loophole**

The `(call_uuid, leg)` pair is what stops a replayed record from being stored twice. A database treats two blanks as two different values, so a blank call reference number slipped past that unique key: the upsert's conflict target never fired, and the row could never be replayed safely. Proven directly against MariaDB — inserting two rows with a blank reference number and the same leg was accepted.

The parser has always refused a payload without a call reference number and quarantined it instead, and `CdrBatchWriter` is the only writer of `cdrs` rows in the codebase, so the pipeline cannot produce such a row. The guarantee was nevertheless resting on one code path rather than on the schema.

- [x] **Step 2: Write the failing tests first**

Two tests in `CdrTest.php`: the column must not be nullable, and storing a record without a reference number must be refused by the database. With the schema changes removed, both failed — the second with `Exception "Illuminate\Database\QueryException" not thrown`, which is the loophole itself.

- [x] **Step 3: Make the column required**

The create migration now defines `call_uuid` without the blank allowance, so a fresh install never builds the nullable column. A second migration reconciles a host that already ran the earlier version.

**The migration refuses to continue if a record already carries a blank reference number.** Such a record can never be deduplicated, so it is a hazard rather than a record, and clearing it is an operator's decision — the migration does not remove call history on its own. The project's own migration safety guard independently forbids a data-removing statement in a migration's `up()`, so refusing is the only behaviour the guard permits.

The migration was proven on a disposable MariaDB database: `up()` with a blank record present refuses and leaves the column and the history untouched; `up()` with none present tightens the column and keeps every index and the `ON DELETE CASCADE` foreign key; a blank insert is then refused with error 1048 and a duplicate `(call_uuid, leg)` with error 1062; `down()` restores the blank allowance; and `up()` twice is idempotent.

- [x] **Step 4: Simplify the deletion confirmation**

The confirmation modal fell back through `call_uuid ?? destination ?? ''` because both columns were nullable. The call reference number is now required and always identifies the record, so the chain collapses to `call_uuid ?? ''`, and the matching test now covers the case that can still happen — a record with no destination.

- [x] **Step 5: Verify**

`CdrTest.php` — 15 passed. The reconciliation path is covered by a test that loosens the column deliberately, drives the migration against it, and proves the indexes carrying the deduplication key survive the tightening.

---

### Task 11: CDR CPU Cost Under Load — Measurement Deferred to the Load-Testing Series

**No code changes are made in this plan for this item.** Moving the CDR ingestion endpoint onto the persistent RoadRunner worker is **not** part of the CDR pipeline work, and the endpoint stays on PHP-FPM for now.

The decision is not "should we build it" but "is it worth moving". That question is answered by measurement, and the measurement is already specified in the load-testing refresh plan:

- [`docs/load-testing-refresh-implementation-plan.md`](../../load-testing-refresh-implementation-plan.md) §1.8 "CDR pipeline at higher call volumes — is it a bottleneck?" compares the two transports (`cdr-fpm` today, `cdr-octane` on port 8090) and defines the gate.
- The same document's Task 9 "CDR pipeline transport benchmarks" is the executable version: the capacity-cost ladder (pipeline **off** vs `cdr-fpm` vs `cdr-octane`), the ingestion rate ladder, digestion throughput, and the backpressure drill.

**What this plan adds is the question the measurement must answer.** The deciding metric is the CPU the CDR pipeline costs while the server is already under call load:

| Measurement | Where it is defined | What it decides |
|:--|:--|:--|
| CPU attribution per subsystem during the CPS ladder | §1.8 "Capacity cost of the pipeline"; Task 8 Step 2 | How much of a core the pipeline takes at each offered call rate |
| **CDR-related CPU as a share of a core** | §1.8 "Bottleneck threshold" — the pipeline's ceiling is the CPS at which **CDR-related CPU exceeds 15 % of a core** | Whether the endpoint is a meaningful capacity cost at all |
| CPU seconds per 100 calls, split between `mod_json_cdr`, the ingestion endpoint and `cdr:work` | §1.8 "Per-call cost" | Whether the cost sits in the endpoint (a transport problem) or in digestion and MariaDB writes (a pipeline problem) |
| XML `p95` with `cdr-fpm` versus `cdr-octane` at the same call rate | §1.8 "Coupling check" | Whether the CDR endpoint competes with the XML hot path inside the shared FPM pool |
| Ingestion `p50/p95/p99` and success rate per transport | §1.8 "Ingestion rate ladder"; Task 9 Step 3 | Whether the endpoint's own tail latency justifies a separate worker |

**How the answer is read.** The load-testing series classifies the transport at its Stage A gate:

- **Keep as-is (`cdr-fpm`)** if the off-versus-on capacity delta is inside the noise, the backlog does not grow, and nothing is lost. The pipeline is not the bottleneck and no architectural change is warranted.
- **Optional (`cdr-octane`)** if the pipeline measurably caps capacity or ingestion tail **and** moving the endpoint raises the call ceiling by ≥10 % (or removes measured FPM contention), with XML `p95` no worse than +5 %. The rollback is one line: the URL in `json_cdr.conf.xml` plus `fs_cli -x "reload mod_json_cdr"`.
- **Not worth changing** if `cdr-octane` shows no gain or worsens the XML tail latency.

**Why the pipeline is not simply moved now.** The endpoint already answers in one Redis append and no database work, so its own cost is small; the open question is whether the framework boot cost (~0.4 s measured, not the <0.5 ms target in the Global Constraints) matters *while the server is under load*, when the FPM pool is contended. That is exactly what the §1.8 ladder measures. Moving the endpoint before the measurement would guess at the answer and change the variable under test.

**Prerequisite already satisfied.** The RoadRunner worker loads `routes/api.php`, so the `cdr-octane` transport exists today; the switch is the URL in `json_cdr.conf.xml`. The series proves it under load and verifies the route is genuinely served by the worker rather than falling through to FPM.
