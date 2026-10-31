# CDR Modernization & Stream Ingestion Implementation Plan (5.x Series)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a production-grade, zero-data-loss Call Detail Record (CDR) ingestion pipeline for TallPBX 5.x using FreeSWITCH `mod_json_cdr`, Pulse-style Redis Stream buffering on isolated Database 3, atomic micro-batch digestion into MariaDB (`cdrs`), disk failover reconciliation, secure audio recording playback, and raw JSON technical diagnostics, with a clean module rename to `app-modules/cdr` and table `cdrs`.

**Architecture:** FreeSWITCH `mod_json_cdr` logs both call legs (A and B) and HTTP-POSTs raw JSON payloads to `/api/v1/cdr`. The API controller validates a timing-safe authentication token, appends payloads to an isolated Redis Stream in Database 3 (`pbx:cdr:stream`) via `CdrRedisIngest`, and returns HTTP 200 in <0.5ms. A supervised background worker (`tpbx cdr:work`, managed by `tallpbx-cdr-worker.service`) digests chunks of up to 100 entries via `XRANGE`, generates ordered UUIDs, parses fields with `CdrPayloadParser`, performs an atomic transaction upsert into `cdrs`, and deletes processed stream IDs via `XDEL`. If Redis or the API is unavailable, FreeSWITCH automatically writes failed JSON files to disk (`/var/log/freeswitch/json_cdr/failed/`), where `tpbx cdr:drain-spool` reconciles them without data loss.

**Tech Stack:** FreeSWITCH v1.11 (`mod_json_cdr`), Redis 7 (Database 3), Laravel 13 / PHP 8.5, Livewire 4, MariaDB 11, Systemd, Pest.

**Spec:** Modernized CDR Pipeline & Ingestion Architecture (5.x Major Release)

## Global Constraints

- **5.x Series Modernization (Breaking Clean Break)**: The module directory is `app-modules/cdr`, root namespace `Modules\Cdr`, database table `cdrs`, and permissions `cdr.*` (`cdr.view`, `cdr.delete`, `cdr.export`). Legacy `xml-cdr` references are cleanly migrated without carrying legacy tech debt.
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

### Task 1: Module Rename to `cdr` & Migration to `cdrs` Table

**Files:**
- Move: `app-modules/xml-cdr/` $\rightarrow$ `app-modules/cdr/`
- Modify: `app-modules/cdr/module.json`
- Modify: `app-modules/cdr/src/Providers/ModuleServiceProvider.php`
- Modify: `app-modules/cdr/src/Models/Cdr.php`
- Create: `app-modules/cdr/database/migrations/2026_10_31_000001_create_cdrs_table.php`
- Test: `app-modules/cdr/tests/CdrModelTest.php`

**Interfaces:**
- Produces: `Modules\Cdr\Models\Cdr`
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

- [ ] **Step 1: Move directory and rename namespace**

```bash
mv app-modules/xml-cdr app-modules/cdr
```
Update namespace references from `Modules\XmlCdr` to `Modules\Cdr` across all files in `app-modules/cdr/`.
Update `module.json`:
- `"name": "cdr"`
- `"namespace": "Modules\\Cdr"`
- `"display_name": "Call Detail Records"`
- `"providers": ["Modules\\Cdr\\Providers\\ModuleServiceProvider"]`

- [ ] **Step 2: Create `cdrs` table migration (with 4.x upgrade support)**

Create `app-modules/cdr/database/migrations/2026_10_31_000001_create_cdrs_table.php`:
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
        if (Schema::hasTable('xml_cdr')) {
            // Upgrade path from 4.x: rename and alter in-place
            Schema::rename('xml_cdr', 'cdrs');
            Schema::table('cdrs', function (Blueprint $table) {
                $table->string('b_leg_uuid')->nullable()->after('call_uuid')->index();
                $table->char('leg', 1)->default('a')->after('b_leg_uuid')->index();
                $table->string('record_path')->nullable()->after('hangup_cause');
                $table->longText('json_data')->nullable()->after('end_stamp');
                $table->unique(['call_uuid', 'leg'], 'cdrs_call_uuid_leg_unique');
            });
        } else {
            // Fresh 5.x path: create modern table
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
    }

    public function down(): void
    {
        Schema::dropIfExists('cdrs');
    }
};
```

- [ ] **Step 3: Update `Cdr` Eloquent Model**

In `app-modules/cdr/src/Models/Cdr.php`:
- Namespace: `namespace Modules\Cdr\Models;`
- `$table = 'cdrs';`
- Add to `$fillable`: `'b_leg_uuid'`, `'leg'`, `'record_path'`, `'json_data'`
- Add cast: `'json_data' => 'array'`

- [ ] **Step 4: Update Module Service Provider permissions and routes**

In `app-modules/cdr/src/Providers/ModuleServiceProvider.php`:
- Set `moduleName(): string { return 'cdr'; }`
- Set `moduleNamespace(): string { return 'Modules\Cdr'; }`
- Update menu items to use permission `cdr.view` instead of `xml-cdr.view`.
In `app-modules/cdr/routes/web.php`:
- Update routes middleware to `admin.can:cdr.view`.

- [ ] **Step 5: Run migration and sync module registry**

Run:
```bash
tpbx migrate
tpbx module:sync --only-local
tpbx db:seed --class=AdminSeeder
./vendor/bin/pest app-modules/cdr/tests/CdrModelTest.php
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app-modules/cdr/
git commit -m "refactor(cdr): modernize module to cdr and table to cdrs for 5.x series"
```

---

### Task 2: FreeSWITCH `mod_json_cdr` Package Installation & Configuration

**Files:**
- Create: `scripts/resources/freeswitch/autoload_configs/json_cdr.conf.xml`
- Copy to: `/etc/freeswitch/autoload_configs/json_cdr.conf.xml`
- Modify: `/etc/freeswitch/autoload_configs/modules.conf.xml`

**Interfaces:**
- FreeSWITCH configuration: `<param name="url" value="http://127.0.0.1/api/v1/cdr"/>`
- FreeSWITCH configuration: `<param name="encode" value="false"/>` (raw JSON body)
- FreeSWITCH configuration: `<param name="err-log-dir" value="/var/log/freeswitch/json_cdr/failed"/>`
- FreeSWITCH configuration: `<param name="log-b-leg" value="true"/>`

- [ ] **Step 1: Install `freeswitch-mod-json-cdr` package**

Run:
```bash
sudo apt-get update && sudo apt-get install -y freeswitch-mod-json-cdr
```
Verify shared library exists at `/usr/lib/freeswitch/mod/mod_json_cdr.so`.

- [ ] **Step 2: Create `json_cdr.conf.xml` template**

Create `scripts/resources/freeswitch/autoload_configs/json_cdr.conf.xml`:
```xml
<configuration name="json_cdr.conf" description="JSON CDR Gateway">
  <settings>
    <param name="url" value="http://127.0.0.1/api/v1/cdr"/>
    <param name="auth-scheme" value="basic"/>
    <param name="cred" value="tallpbx:1315715055ea76869a894ee4af86e178b89169027c4ac620e9dd084f2f4bff87"/>
    <param name="log-dir" value="/var/log/freeswitch/json_cdr"/>
    <param name="err-log-dir" value="/var/log/freeswitch/json_cdr/failed"/>
    <param name="retries" value="3"/>
    <param name="delay" value="1"/>
    <param name="log-b-leg" value="true"/>
    <param name="encode" value="false"/>
  </settings>
</configuration>
```
Copy to `/etc/freeswitch/autoload_configs/json_cdr.conf.xml`.

- [ ] **Step 3: Setup failover spool and quarantine directories**

Run:
```bash
sudo mkdir -p /var/log/freeswitch/json_cdr/failed /var/log/freeswitch/json_cdr/quarantine
sudo chown -R freeswitch:tallpbx-media /var/log/freeswitch/json_cdr
sudo chmod -R 775 /var/log/freeswitch/json_cdr
```

- [ ] **Step 4: Enable `mod_json_cdr` in FreeSWITCH**

Add `<load module="mod_json_cdr"/>` to `/etc/freeswitch/autoload_configs/modules.conf.xml`.
Load module in runtime: `fs_cli -x "load mod_json_cdr"` and verify `fs_cli -x "module_exists mod_json_cdr"`.

- [ ] **Step 5: Commit installer template**

```bash
git add scripts/resources/freeswitch/autoload_configs/json_cdr.conf.xml
git commit -m "chore(freeswitch): add json_cdr.conf.xml template with raw streaming and basic auth"
```

---

### Task 3: Ingestion API Endpoint with Token Verification & Redis DB 3 Stream Buffer

**Files:**
- Modify: `config/database.php` (add `'cdr'` connection targeting Redis DB 3)
- Modify: `config/freeswitch.php` (add `'cdr_token'`)
- Create: `app/Services/Cdr/CdrRedisIngest.php`
- Create: `app/Http/Controllers/Api/CdrIngestionController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/CdrIngestionControllerTest.php`

**Interfaces:**
- Produces: `CdrRedisIngest::ingest(string $rawJson): string` (stream message ID)
- Produces: `CdrRedisIngest::digest(int $chunkSize = 100): int`
- Redis Connection: `config('database.redis.cdr')` $\rightarrow$ DB 3, Stream: `pbx:cdr:stream`

- [ ] **Step 1: Add `'cdr'` connection in `config/database.php` and token in `config/freeswitch.php`**

In `config/database.php`:
```php
'cdr' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_CDR_DB', '3'),
    'max_retries' => 3,
],
```
In `config/freeswitch.php`:
```php
'cdr_token' => env('FREESWITCH_CDR_TOKEN', '1315715055ea76869a894ee4af86e178b89169027c4ac620e9dd084f2f4bff87'),
```

- [ ] **Step 2: Write test for CdrIngestionController**

Create `tests/Feature/Http/CdrIngestionControllerTest.php`:
- Verify valid Basic Auth / Bearer / query token pushes to Redis stream on DB 3 and returns HTTP 200.
- Verify invalid or missing token returns HTTP 401.
- Verify Redis connection failure returns HTTP 503 so FreeSWITCH triggers disk failover.

- [ ] **Step 3: Implement `CdrRedisIngest`**

Create `app/Services/Cdr/CdrRedisIngest.php`:
```php
namespace App\Services\Cdr;

use Illuminate\Support\Facades\Redis;

class CdrRedisIngest
{
    public const STREAM_KEY = 'pbx:cdr:stream';

    public function ingest(string $rawJson): string
    {
        return Redis::connection('cdr')->xadd(self::STREAM_KEY, '*', [
            'data' => $rawJson,
        ]);
    }

    public function getPendingCount(): int
    {
        return (int) Redis::connection('cdr')->xlen(self::STREAM_KEY);
    }
}
```

- [ ] **Step 4: Implement `CdrIngestionController`**

Create `app/Http/Controllers/Api/CdrIngestionController.php`:
- Check timing-safe token using `hash_equals()`. Fail closed if token is empty.
- Read `$request->getContent()` (supporting both raw JSON and fallback `cdr` input).
- Push to `CdrRedisIngest`.
- On Redis exception, log error and return `response()->json(['error' => 'Ingest unavailable'], 503)`.

- [ ] **Step 5: Register route in `routes/api.php`**

```php
Route::post('/v1/cdr', [CdrIngestionController::class, 'store'])
    ->middleware('throttle:1200,1')
    ->name('api.v1.cdr.store');
```

- [ ] **Step 6: Run tests and verify**

Run: `./vendor/bin/pest tests/Feature/Http/CdrIngestionControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add config/database.php config/freeswitch.php app/Services/Cdr/CdrRedisIngest.php app/Http/Controllers/Api/CdrIngestionController.php routes/api.php tests/
git commit -m "feat(cdr): add secure redis stream ingestion controller on db 3 with 503 failover"
```

---

### Task 4: Continuous Stream Worker (`tpbx cdr:work`) with Explicit UUIDs & Transaction Isolation

**Files:**
- Create: `app/Services/Cdr/CdrPayloadParser.php`
- Modify: `app/Services/Cdr/CdrRedisIngest.php` (add `digest()` method)
- Create: `app/Console/Commands/CdrWorkCommand.php`
- Test: `tests/Unit/Services/CdrPayloadParserTest.php`
- Test: `tests/Feature/Commands/CdrWorkCommandTest.php`

**Interfaces:**
- `CdrPayloadParser::parse(string $rawJson): ?array` (must include `'id' => (string) Str::orderedUuid()`)
- `CdrRedisIngest::digest(int $chunkSize = 100): int` (transactional insert + `XDEL` on commit)

- [ ] **Step 1: Write test for `CdrPayloadParserTest`**

Create `tests/Unit/Services/CdrPayloadParserTest.php`:
- Assert parsed row contains an explicit non-null UUID string in `id`.
- Assert `call_uuid`, `b_leg_uuid`, `leg`, `caller_id`, `destination`, `duration`, `billsec`, `record_path`, `tenant_id`, and `json_data` are properly mapped.
- Assert malformed JSON returns null without throwing uncaught exceptions.

- [ ] **Step 2: Implement `CdrPayloadParser`**

Create `app/Services/Cdr/CdrPayloadParser.php`:
- Parse JSON with `json_decode(..., true, 512, JSON_THROW_ON_ERROR)`. Catch `JsonException` and quarantine.
- Extract `variables.uuid` as `call_uuid`.
- Extract `variables.bridge_uuid` or `variables.signal_bond` as `b_leg_uuid`.
- Determine leg: `'b'` if bridged leg or filename prefix, otherwise `'a'`.
- Resolve tenant: lookup `Tenant::where('domain', $domain)->value('id')` with cache.
- Extract `record_path` from `variables.record_path` or `variables.record_name`.
- Explicitly generate ordered UUID: `'id' => (string) Str::orderedUuid()`.
- Store entire payload in `json_data`.

- [ ] **Step 3: Implement atomic `digest()` in `CdrRedisIngest`**

In `app/Services/Cdr/CdrRedisIngest.php`:
```php
public function digest(int $chunkSize = 100): int
{
    $entries = Redis::connection('cdr')->xrange(self::STREAM_KEY, '-', '+', $chunkSize);
    if (empty($entries)) {
        return 0;
    }

    $batch = [];
    $processedIds = [];

    foreach ($entries as $id => $fields) {
        $raw = $fields['data'] ?? '';
        $parsed = CdrPayloadParser::parse($raw);

        if ($parsed) {
            $batch[] = $parsed;
            $processedIds[] = $id;
        } else {
            // Quarantine malformed JSON rather than dropping
            CdrPayloadParser::quarantine($id, $raw);
            $processedIds[] = $id;
        }
    }

    if (!empty($batch)) {
        DB::transaction(function () use ($batch) {
            Cdr::upsert(
                $batch,
                ['call_uuid', 'leg'],
                ['duration', 'billsec', 'hangup_cause', 'end_stamp', 'record_path', 'json_data', 'updated_at']
            );
        });
    }

    // Only delete stream IDs after MariaDB transaction commits successfully
    if (!empty($processedIds)) {
        Redis::connection('cdr')->xdel(self::STREAM_KEY, $processedIds);
    }

    return count($processedIds);
}
```

- [ ] **Step 4: Implement `CdrWorkCommand` (`tpbx cdr:work`)**

Create `app/Console/Commands/CdrWorkCommand.php`:
```php
public function handle(CdrRedisIngest $ingest): int
{
    $chunk = (int) $this->option('chunk');
    $sleep = (int) $this->option('sleep');
    $memory = (int) $this->option('memory');
    $once = (bool) $this->option('once');

    while (true) {
        $processed = $ingest->digest($chunk);

        if ($once) {
            break;
        }

        if ($processed === 0) {
            usleep($sleep); // 250ms pause when idle
        } else {
            usleep(1000); // 1ms cooperative yield under high load
        }

        if (memory_get_usage(true) / 1024 / 1024 >= $memory) {
            $this->components->warn('Memory limit reached. Cycling worker.');
            break;
        }
    }

    return self::SUCCESS;
}
```

- [ ] **Step 5: Run tests to verify**

Run: `./vendor/bin/pest tests/Unit/Services/CdrPayloadParserTest.php tests/Feature/Commands/CdrWorkCommandTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Cdr/CdrPayloadParser.php app/Services/Cdr/CdrRedisIngest.php app/Console/Commands/CdrWorkCommand.php tests/
git commit -m "feat(cdr): implement stream worker with ordered uuid and transaction isolation"
```

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
- Modify: `app-modules/cdr/src/Livewire/CdrList.php`
- Modify: `app-modules/cdr/src/Livewire/CdrDetail.php`
- Modify: `app-modules/cdr/resources/views/cdr-list.blade.php`
- Modify: `app-modules/cdr/resources/views/cdr-detail.blade.php`
- Create: `app-modules/cdr/src/Http/Controllers/CdrRecordingController.php`
- Modify: `app-modules/cdr/routes/web.php`
- Test: `app-modules/cdr/tests/CdrUiTest.php`

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

Create `app-modules/cdr/src/Http/Controllers/CdrRecordingController.php`:
- Verify user has permission `cdr.view`.
- Verify `$cdr->tenant_id === auth()->user()->tenant_id` (or super-admin).
- Canonicalize path: `$realPath = realpath($cdr->record_path)`. Verify it begins with allowed recording directory.
- Stream file using `response()->file($realPath, ['Content-Type' => 'audio/wav'])`.

- [ ] **Step 3: Update `CdrList` Livewire component and Blade view**

In `app-modules/cdr/src/Livewire/CdrList.php`:
- Add `$legFilter = 'all'` (options: `all`, `a`, `b`).
- Apply query scope: `when($this->legFilter !== 'all', fn($q) => $q->where('leg', $this->legFilter))`.
In `cdr-list.blade.php`:
- Add leg filter select input matching DaisyUI conventions.
- Display Leg badge (`badge-xs font-mono`) in table row.
- Display audio play icon if `record_path` is present.

- [ ] **Step 4: Update `CdrDetail` with Audio Player and JSON Diagnostics**

In `app-modules/cdr/resources/views/cdr-detail.blade.php`:
- Add Audio Recording card with `<audio controls preload="none">` streaming from route `panel.cdr.recording`.
- Add "Raw Technical Diagnostics (JSON)" card with an Alpine collapsible tree showing formatted `json_data`.

- [ ] **Step 5: Run UI tests and verify**

Run: `./vendor/bin/pest app-modules/cdr/tests/`
Expected: PASS.

- [ ] **Step 6: Commit UI enhancements**

```bash
git add app-modules/cdr/
git commit -m "feat(cdr): add leg filtering, secure recording streaming, and raw json diagnostics"
```

---

### Task 7: Full Pipeline Integration & SIPp End-to-End Verification Gate

**Files:**
- Execute: `tpbx optimize:clear`
- Run: `./vendor/bin/pint --dirty --test`
- Run: `./vendor/bin/pest app-modules/cdr/tests/ tests/Feature/Http/CdrIngestionControllerTest.php tests/Feature/Commands/`

**Verification Criteria:**
1. FreeSWITCH generates JSON CDR on call completion for both A and B legs without encoding corruption (`encode="false"`).
2. Ingestion endpoint validates token, pushes to Redis DB 3 stream (`pbx:cdr:stream`) in <0.5ms, and returns 200.
3. Worker `tpbx cdr:work --once` digests stream entries, generates ordered UUIDs, and atomically inserts both legs into `cdrs`.
4. Failover simulation: Stopping Redis causes FreeSWITCH to write to `/var/log/freeswitch/json_cdr/failed/`; running `tpbx cdr:drain-spool` drains and cleans the directory.
5. In panel `/panel/cdr`, calls display with A/B leg indicators, audio playback, and collapsible JSON diagnostics.
6. Pint code style check passes with 0 violations.
