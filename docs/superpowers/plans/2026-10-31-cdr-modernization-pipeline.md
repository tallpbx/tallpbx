# CDR Modernization & Stream Ingestion Implementation Plan (5.x Series)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Prerequisite before the "zero data loss" goal below holds:** findings **A-R13**, **A-R14** and **A-R15** in [`implementation-plan-review.md`](implementation-plan-review.md) are unresolved, and no task in this plan covers them — the accepted-then-crashed record window (AOF is off), `vm.overcommit_memory = 0` turning a failed snapshot into refused writes, and `appliance:sync-host` calling `flushall()` across every database.

**Goal:** Implement a production-grade, zero-data-loss Call Detail Record (CDR) ingestion pipeline for TallPBX 5.x using FreeSWITCH `mod_json_cdr`, Pulse-style Redis Stream buffering on isolated Database 3, atomic micro-batch digestion into MariaDB (`cdrs`), disk failover reconciliation, secure audio recording playback, and raw JSON technical diagnostics, with a clean module rename to `app-modules/call-detail-records` and table `cdrs`.

**Architecture:** FreeSWITCH `mod_json_cdr` logs both call legs (A and B) and HTTP-POSTs raw JSON payloads to `/api/v1/cdr`. The API controller validates a timing-safe authentication token, appends payloads to an isolated Redis Stream in Database 3 (`pbx:cdr:stream`) via `CdrRedisIngest`, and returns HTTP 200 in <0.5ms. A supervised background worker (`tpbx cdr:work`, managed by `tallpbx-cdr-worker.service`) digests chunks of up to 100 entries via `XRANGE`, generates ordered UUIDs, parses fields with `CdrPayloadParser`, performs an atomic transaction upsert into `cdrs`, and deletes processed stream IDs via `XDEL`. If Redis or the API is unavailable, FreeSWITCH automatically writes failed JSON files to disk (`/var/log/freeswitch/json_cdr/failed/`), where `tpbx cdr:drain-spool` reconciles them without data loss.

**Tech Stack:** FreeSWITCH v1.11 (`mod_json_cdr`), Redis 7 (Database 3), Laravel 13 / PHP 8.5, Livewire 4, MariaDB 11, Systemd, Pest.

**Spec:** Modernized CDR Pipeline & Ingestion Architecture (5.x Major Release)

## Global Constraints

- **5.x Series Modernization (Breaking Clean Break)**: The module directory is `app-modules/call-detail-records`, root namespace `Modules\CallDetailRecords`, database table `cdrs`, and permissions `call-detail-records.*` (`call-detail-records.view`, `call-detail-records.delete`, `call-detail-records.export`). Legacy `xml-cdr` references are cleanly migrated without carrying legacy tech debt.
- **Naming (confirmed)**: The module, directory, namespace, and permission prefix are `call-detail-records` / `Modules\CallDetailRecords` / `call-detail-records.*`. Console commands stay `cdr:*`, the model stays `Cdr`, the table stays `cdrs`, and the panel route stays `/panel/cdr`, so menu keys, routes, and translations are unchanged.
- **Dedicated Redis DB 3**: CDR stream ingestion must strictly target isolated Redis Database 3 (`REDIS_CDR_DB=3`), completely segregated from general background queues (DB 0) and application routing cache (DB 1).
- **Dual-Leg Recording & Idempotency**: Both A-leg and B-leg must be preserved, tagged with `leg = 'a'` and `leg = 'b'`, correlated via `b_leg_uuid`, and deduplicated via a unique composite index on `['call_uuid', 'leg']`.
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
  - `call_uuid` (string, indexed)
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
            $table->string('call_uuid')->nullable()->index();
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

- [ ] **Step 6: Commit**

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
- Modify: `routes/console.php` (schedule drain every 5 minutes)
- Test: `tests/Feature/Commands/CdrDrainSpoolCommandTest.php`

**Interfaces:**
- Systemd: `systemctl status tallpbx-cdr-worker`
- Console: `tpbx cdr:drain-spool [--spool-dir=...]`

- [ ] **Step 1: Create systemd service unit**

Create `scripts/tallpbx-cdr-worker.service`:
```ini
[Unit]
Description=TallPBX CDR Stream Ingestion Worker
Wants=network-online.target redis.service
After=network.target network-online.target redis.service mariadb.service
StartLimitIntervalSec=60
StartLimitBurst=5

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/tallpbx
ExecStart=/usr/bin/php /var/www/tallpbx/artisan cdr:work --chunk=100 --sleep=250000 --memory=512
Restart=always
RestartSec=3
EnvironmentFile=-/var/www/tallpbx/.env

# Sandbox hardening matching tallpbx-queue.service
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/tallpbx/storage /var/log/freeswitch/json_cdr
PrivateTmp=true

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 2: Implement `CdrDrainSpoolCommand`**

Create `app/Console/Commands/CdrDrainSpoolCommand.php`:
- Scans `/var/log/freeswitch/json_cdr/failed/*.json`.
- Safely skips open/locked files.
- Batches up to 100 files at a time.
- Parses with `CdrPayloadParser` (generating ordered UUIDs).
- Executes `Cdr::upsert()` inside a transaction and deletes (`unlink`) processed files.

- [ ] **Step 3: Register in scheduler**

In `routes/console.php`:
```php
Schedule::command('cdr:drain-spool')->everyFiveMinutes()->withoutOverlapping();
```

- [ ] **Step 4: Run test to verify**

Run: `./vendor/bin/pest tests/Feature/Commands/CdrDrainSpoolCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/tallpbx-cdr-worker.service app/Console/Commands/CdrDrainSpoolCommand.php routes/console.php tests/
git commit -m "feat(cdr): add systemd worker supervision unit and failover spool drainer"
```

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

- [ ] **Step 1: Write test for CdrRecordingController secure streaming**

Create test verifying:
1. Valid tenant admin can stream audio for their call recording.
2. Cross-tenant access is rejected with HTTP 403.
3. Path traversal attacks (e.g. `../../etc/passwd`) are rejected with HTTP 404.

- [ ] **Step 2: Implement `CdrRecordingController`**

Create `app-modules/call-detail-records/src/Http/Controllers/CdrRecordingController.php`:
- Verify user has permission `call-detail-records.view`.
- Verify `$cdr->tenant_id === auth()->user()->tenant_id` (or super-admin).
- Canonicalize path: `$realPath = realpath($cdr->record_path)`. Verify it begins with allowed recording directory.
- Stream file using `response()->file($realPath, ['Content-Type' => 'audio/wav'])`.

- [ ] **Step 3: Update `CdrList` Livewire component and Blade view**

In `app-modules/call-detail-records/src/Livewire/CdrList.php`:
- Add `$legFilter = 'all'` (options: `all`, `a`, `b`).
- Apply query scope: `when($this->legFilter !== 'all', fn($q) => $q->where('leg', $this->legFilter))`.
In `cdr-list.blade.php`:
- Add leg filter select input matching DaisyUI conventions.
- Display Leg badge (`badge-xs font-mono`) in table row.
- Display audio play icon if `record_path` is present.

- [ ] **Step 4: Update `CdrDetail` with Audio Player and JSON Diagnostics**

In `app-modules/call-detail-records/resources/views/cdr-detail.blade.php`:
- Add Audio Recording card with `<audio controls preload="none">` streaming from route `panel.cdr.recording`.
- Add "Raw Technical Diagnostics (JSON)" card with an Alpine collapsible tree showing formatted `json_data`.

- [ ] **Step 5: Run UI tests and verify**

Run: `./vendor/bin/pest app-modules/call-detail-records/tests/`
Expected: PASS.

- [ ] **Step 6: Commit UI enhancements**

```bash
git add app-modules/call-detail-records/
git commit -m "feat(cdr): add leg filtering, secure recording streaming, and raw json diagnostics"
```

---

### Task 7: Full Pipeline Integration & SIPp End-to-End Verification Gate

**Files:**
- Execute: `tpbx optimize:clear`
- Run: `./vendor/bin/pint --dirty --test`
- Run: `./vendor/bin/pest app-modules/call-detail-records/tests/ tests/Feature/Http/CdrIngestionControllerTest.php tests/Feature/Commands/`

**Verification Criteria:**
1. FreeSWITCH generates JSON CDR on call completion for both A and B legs without encoding corruption (`encode="false"`).
2. Ingestion endpoint validates token, pushes to Redis DB 3 stream (`pbx:cdr:stream`) in <0.5ms, and returns 200.
3. Worker `tpbx cdr:work --once` digests stream entries, generates ordered UUIDs, and atomically inserts both legs into `cdrs`.
4. Failover simulation: Stopping Redis causes FreeSWITCH to write to `/var/log/freeswitch/json_cdr/failed/`; running `tpbx cdr:drain-spool` drains and cleans the directory.
5. In panel `/panel/cdr`, calls display with A/B leg indicators, audio playback, and collapsible JSON diagnostics.
6. Pint code style check passes with 0 violations.
