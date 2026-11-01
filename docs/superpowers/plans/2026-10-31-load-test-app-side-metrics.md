# Load-Test App-Side Metrics & Directory Scenario Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every future XML performance run record Laravel-side latency (including framework boot) and exercise directory lookups, so proxy/invalidation decisions are data-driven without one-off manual measurement.

**Architecture:** Three additive changes to existing tooling. The `LogXmlHandlerTiming` middleware emits opt-in timing response headers when a config flag and a request header are both present. `pbx:load-test:dialplan` gains a section mode (`dialplan`, `directory`, `mixed`) and a `--timing` flag that reads those headers into a new additive `timing` block in its JSON report (schema version 2). `run-cache-sweep.sh` gains one directory-mixed tier and prints an app-side p95 column. No runtime behavior changes when the flags are off.

**Tech Stack:** Laravel 13 / PHP 8.5, Pest, Guzzle HTTP pool (existing), Bash sweep runner.

**Spec:** [`2026-10-31-lua-redis-proxy-v2.md`](2026-10-31-lua-redis-proxy-v2.md) (Task 1 consumes this harness) and [`implementation-plan-review.md`](implementation-plan-review.md) (AO-B1…AO-B4).

## Global Constraints

- **Default-off**: timing headers require `FS_XML_HANDLER_TIMING_HEADERS=true` **and** the request header `X-TallPBX-Timing: 1`. Without both, responses are byte-identical to today.
- **Backward-compatible reports**: existing JSON keys, their order, and existing threshold behavior are unchanged. New data is additive under `timing`, and `schema_version` is added as `2`.
- **Two app-side numbers**: `handler_ms` is the middleware window (what the log already records); `request_ms` is `microtime(true) - LARAVEL_START`, including framework boot — the gate metric. `request_ms` is omitted (not zero) when `LARAVEL_START` is undefined.
- **Directory realism**: directory queries must carry `tag_name=user`, `key_name=name`, `key_value`, `domain`, `sip_auth_username`, and `user` — the same shape FreeSWITCH sends for REGISTER/INVITE auth.
- **Sweep safety**: the existing four tiers, the `.env` snapshot/restore trap, and the DB-1-only flush stay exactly as they are. Only append.
- **Verification**: `tpbx optimize:clear`, `./vendor/bin/pint --dirty`, targeted Pest files with `--parallel`, and `bash -n scripts/run-cache-sweep.sh`.

## Review Focus

1. **Timing headers must never appear** on responses unless the config flag is on and the caller sent `X-TallPBX-Timing: 1` — otherwise internal cost data leaks on the public xml-handler endpoint.
2. **`request_ms` must include framework boot** (`LARAVEL_START`), because the Lua proxy removes nginx, PHP-FPM, and boot entirely; a middleware-only number under-reports the saving and can produce a wrong DEFER.
3. **Directory mode must validate directory XML**, not dialplan XML — a stale validator would mark directory responses as failures and silently invalidate the run.
4. **Existing report consumers and tests must keep passing**: `latency_ms` keys and order, `scenario_counts`, thresholds, and exit codes are unchanged; `--section` defaults to `dialplan`.
5. **The sweep script must remain safe**: exactly one DB 1 flush per tier, `.env` restored on exit/interrupt, and the four historical tier names preserved so existing artifacts stay comparable.

---

### Task 1: Opt-In Timing Response Headers in the Middleware

**Files:**
- Modify: `config/freeswitch.php` (add `timing_headers` inside `xml_handler`)
- Modify: `.env.example` (add `FS_XML_HANDLER_TIMING_HEADERS=false`)
- Modify: `app/Http/Middleware/LogXmlHandlerTiming.php`
- Test: `tests/Feature/Http/XmlHandlerTimingHeadersTest.php`

**Interfaces:**
- Produces (response headers, only when enabled and requested): `X-TallPBX-Handler-Ms` (`float`, 2 decimals), `X-TallPBX-Request-Ms` (`float`, 2 decimals, omitted when `LARAVEL_START` is undefined), `X-TallPBX-DB-Queries` (`int`)

- [ ] **Step 1: Add the configuration flag**

In `config/freeswitch.php` inside `'xml_handler'`, add:

```php
'timing_headers' => filter_var(env('FS_XML_HANDLER_TIMING_HEADERS', false), FILTER_VALIDATE_BOOLEAN),
```

Add `FS_XML_HANDLER_TIMING_HEADERS=false` to `.env.example`.

- [ ] **Step 2: Write the failing middleware test**

`tests/Feature/Http/XmlHandlerTimingHeadersTest.php` — follow the tenant/permission setup in `XmlHandlerControllerTest.php`:

```php
test('does not emit timing headers by default', function () {
    config(['freeswitch.xml_handler.timing_headers' => false, 'freeswitch.xml_handler.log_timing' => false]);
    $response = $this->get('/api/v1/xml-handler?section=directory&tag_name=user&key_value=1001&domain=example.test');
    expect($response->headers->has('X-TallPBX-Handler-Ms'))->toBeFalse()
        ->and($response->headers->has('X-TallPBX-Request-Ms'))->toBeFalse();
});

test('emits timing headers only when enabled and explicitly requested', function () {
    if (! defined('LARAVEL_START')) {
        define('LARAVEL_START', microtime(true));
    }
    config(['freeswitch.xml_handler.timing_headers' => true, 'freeswitch.xml_handler.log_timing' => true]);
    $response = $this->withHeaders(['X-TallPBX-Timing' => '1'])
        ->get('/api/v1/xml-handler?section=directory&tag_name=user&key_value=1001&domain=example.test');

    expect($response->headers->get('X-TallPBX-Handler-Ms'))->toBeNumeric()
        ->and($response->headers->get('X-TallPBX-Request-Ms'))->toBeNumeric()
        ->and($response->headers->get('X-TallPBX-DB-Queries'))->toBeNumeric();
});

test('does not emit headers when the caller omits the request header', function () {
    config(['freeswitch.xml_handler.timing_headers' => true]);
    $response = $this->get('/api/v1/xml-handler?section=directory&tag_name=user&key_value=1001&domain=example.test');
    expect($response->headers->has('X-TallPBX-Handler-Ms'))->toBeFalse();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Http/XmlHandlerTimingHeadersTest.php --parallel`
Expected: FAIL (headers absent in the enabled case).

- [ ] **Step 4: Implement the headers**

In `LogXmlHandlerTiming::handle()`:

- Compute `$logEnabled = (bool) config('freeswitch.xml_handler.log_timing', false)` and `$headersEnabled = (bool) config('freeswitch.xml_handler.timing_headers', false) && $request->header('X-TallPBX-Timing') === '1'`.
- Enable the `DB::listen` counter when `$logEnabled || $headersEnabled`.
- After `$response = $next($request)`, keep the existing log branch unchanged, then when `$headersEnabled`: set `X-TallPBX-Handler-Ms` (rounded to 2 decimals), `X-TallPBX-DB-Queries` (integer), and — only when `defined('LARAVEL_START')` — `X-TallPBX-Request-Ms` computed as `round((microtime(true) - LARAVEL_START) * 1000, 2)` clamped at 0.
- Extract the elapsed computation into a private `elapsedMs(float $startedAt): float` used by both branches.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Http/XmlHandlerTimingHeadersTest.php tests/Feature/Http/XmlHandlerControllerTest.php --parallel`
Expected: PASS, and the 68 existing controller tests unchanged.

- [ ] **Step 6: Commit**

```bash
git add config/freeswitch.php .env.example app/Http/Middleware/LogXmlHandlerTiming.php tests/Feature/Http/XmlHandlerTimingHeadersTest.php
git commit -m "feat(xml): add opt-in timing response headers for load tests"
```

---

### Task 2: Section Mode for the Load-Test Command

**Files:**
- Modify: `app/Console/Commands/PbxDialplanLoadTestCommand.php`
- Test: `tests/Feature/Pbx/PbxLoadTestValidationTest.php` (extend the `pbx dialplan load test command` describe block)

**Interfaces:**
- New options: `--section=dialplan` (`dialplan|directory|mixed`), `--domain=` (defaults to the tenant's first `TenantDomain` record)
- Produces: `buildQueries(...)` returns `array<int, array{section: string, params: array<string, string>}>`; `sendRequests()` validates by the item's own section

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Pbx/PbxLoadTestValidationTest.php`:

```php
it('sends directory auth lookups when section is directory', function (): void {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-directory', '--domain' => 'dir-load.test',
        '--extensions' => '4', '--start' => '4400', '--output' => PBX_LOAD_TEST_CSV_PATH,
    ])->assertSuccessful();

    Http::fake(fn () => Http::response(
        '<document type="freeswitch/xml"><section name="directory"></section></document>', 200));

    $this->artisan('pbx:load-test:dialplan', [
        '--tenant' => 'load-test-directory', '--url' => 'https://pbx.test/api/v1/xml-handler',
        '--section' => 'directory', '--requests' => '3', '--concurrency' => '1',
        '--report' => PBX_LOAD_TEST_REPORT_PATH,
    ])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'section=directory')
        && str_contains($request->url(), 'tag_name=user')
        && str_contains($request->url(), 'key_name=name')
        && str_contains($request->url(), 'key_value=4400')
        && str_contains($request->url(), 'sip_auth_username=4400')
        && str_contains($request->url(), 'domain=dir-load.test'));

    $report = json_decode((string) file_get_contents(base_path(PBX_LOAD_TEST_REPORT_PATH)), true);
    expect($report['successful_responses'])->toBe(3)
        ->and($report['target']['section'])->toBe('directory');
});

it('mixes two directory lookups per dialplan lookup in mixed section mode', function (): void {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-section-mixed', '--domain' => 'mixed-load.test',
        '--extensions' => '4', '--start' => '4500', '--output' => PBX_LOAD_TEST_CSV_PATH,
    ])->assertSuccessful();

    Http::fake(function (Request $request) {
        $isDirectory = str_contains($request->url(), 'section=directory');
        return Http::response($isDirectory
            ? '<document type="freeswitch/xml"><section name="directory"></section></document>'
            : '<document type="freeswitch/xml"><section name="dialplan"><context name="tenant_1_internal"></context></section></document>', 200);
    });

    $this->artisan('pbx:load-test:dialplan', [
        '--tenant' => 'load-test-section-mixed', '--url' => 'https://pbx.test/api/v1/xml-handler',
        '--section' => 'mixed', '--scenario' => 'internal', '--requests' => '6', '--concurrency' => '1',
        '--report' => PBX_LOAD_TEST_REPORT_PATH,
    ])->assertSuccessful();

    $report = json_decode((string) file_get_contents(base_path(PBX_LOAD_TEST_REPORT_PATH)), true);
    expect($report['target']['section'])->toBe('mixed')
        ->and($report['target']['section_counts'])->toBe(['directory' => 4, 'dialplan' => 2]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Pbx/PbxLoadTestValidationTest.php --filter=section --parallel`
Expected: FAIL (unknown option / missing report keys).

- [ ] **Step 3: Implement section mode**

In `PbxDialplanLoadTestCommand`:

- Add to the signature: `{--section=dialplan : Section mode: dialplan, directory, or mixed}` and `{--domain= : Domain used for directory lookups; defaults to the tenant's first SIP domain}`.
- Add a private `sectionOption(): string` validating `dialplan|directory|mixed`, and a private `resolveDomain(Tenant $tenant): string` that returns the `--domain` value when given, else `TenantDomain::where('tenant_id', $tenant->id)->value('domain')`, and throws when empty.
- Change `buildQueries()` to accept `$section` and `$domain` and return items shaped `['section' => 'directory'|'dialplan', 'params' => [...]]`.
  - Directory params: `section=directory`, `tag_name=user`, `key_name=name`, `key_value=<extension>`, `domain=<domain>`, `sip_auth_username=<extension>`, `user=<extension>`.
  - Mixed composition: for each 3-request cycle, two directory lookups then one dialplan lookup (reuse the existing scenario selection for the dialplan request).
- Update `sendRequests()` to send `$item['params']` and validate by `$item['section']`: directory requires `<section name="directory">`; dialplan keeps the existing `<section name="dialplan">` plus context check.
- Add `'section' => $section` and `'section_counts' => ['directory' => N, 'dialplan' => M]` to the report's `target` block, computed from the generated queries.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Pbx/PbxLoadTestValidationTest.php --parallel`
Expected: PASS, including the pre-existing dialplan and cache-hit tests.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/PbxDialplanLoadTestCommand.php tests/Feature/Pbx/PbxLoadTestValidationTest.php
git commit -m "feat(load-test): add directory and mixed section modes"
```

---

### Task 3: Record App-Side Metrics in the Report

**Files:**
- Modify: `app/Console/Commands/PbxDialplanLoadTestCommand.php`
- Test: `tests/Feature/Pbx/PbxLoadTestValidationTest.php`

**Interfaces:**
- New option: `--timing` (flag, sends `X-TallPBX-Timing: 1`)
- Produces: report `schema_version: 2` and additive `timing` block `{ enabled, request_ms: <summary>, handler_ms: <summary>, db_queries: { average, max }, by_section: { <section>: { count, p50, p95 } } }`
- Produces: private `summarizeValues(array $values): array` returning the same key order as `latency_ms`
- Explicitly **not** adding a threshold option: the gate consumes the JSON values, and adding a key to `thresholds` would break the existing fixed-key assertion

- [ ] **Step 1: Write the failing test**

```php
it('records app-side timing headers when the timing flag is used', function (): void {
    $this->artisan('pbx:load-test:seed', [
        '--tenant' => 'load-test-timing', '--domain' => 'timing-load.test',
        '--extensions' => '4', '--start' => '4600', '--output' => PBX_LOAD_TEST_CSV_PATH,
    ])->assertSuccessful();

    Http::fake(fn () => Http::response(
        '<document type="freeswitch/xml"><section name="dialplan"><context name="tenant_1_internal"></context></section></document>',
        200,
        ['X-TallPBX-Handler-Ms' => '4.20', 'X-TallPBX-Request-Ms' => '31.50', 'X-TallPBX-DB-Queries' => '2'],
    ));

    $this->artisan('pbx:load-test:dialplan', [
        '--tenant' => 'load-test-timing', '--url' => 'https://pbx.test/api/v1/xml-handler',
        '--scenario' => 'internal', '--requests' => '3', '--concurrency' => '1',
        '--timing' => true, '--report' => PBX_LOAD_TEST_REPORT_PATH,
    ])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-TallPBX-Timing', '1'));

    $report = json_decode((string) file_get_contents(base_path(PBX_LOAD_TEST_REPORT_PATH)), true);

    expect($report['schema_version'])->toBe(2)
        ->and($report['timing']['enabled'])->toBeTrue()
        ->and($report['timing']['request_ms']['p95'])->toBe(31.5)
        ->and($report['timing']['handler_ms']['p50'])->toBe(4.2)
        ->and($report['timing']['db_queries']['max'])->toBe(2)
        ->and($report['timing']['by_section']['dialplan']['count'])->toBe(3)
        ->and(array_keys($report['latency_ms']))->toBe([
            'sample_count', 'missing_count', 'average', 'min', 'max',
            'p50', 'p90', 'p95', 'p99', 'std_dev', 'raw_samples',
        ]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Pbx/PbxLoadTestValidationTest.php --filter=app-side --parallel`
Expected: FAIL (unknown `--timing` option / missing `timing` key).

- [ ] **Step 3: Implement timing capture**

- Add `{--timing : Request and record app-side timing headers}` to the signature.
- In `sendRequests()`, when `--timing` is set add `->withHeaders(['X-TallPBX-Timing' => '1'])` to each pending request; capture per response: `X-TallPBX-Handler-Ms`, `X-TallPBX-Request-Ms`, `X-TallPBX-DB-Queries` (cast to float/int or null).
- Extend each `$results[$key]` with `handler_ms`, `request_ms`, `db_queries`, and the item's `section`.
- Extract the existing latency summary body into `private function summarizeValues(array $values): array` (round, sort, compute stats, same key order) and use it for `latency_ms` and for `timing.request_ms` / `timing.handler_ms`.
- Build the `timing` block in `buildReport()`: `enabled` = flag, summaries from non-null samples, `db_queries` average/max, `by_section` counts and `request_ms` p50/p95 per section. Add `'schema_version' => 2` at the top level.
- Print an `App-side p95 ms` row in the command's summary table when timing is enabled.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Pbx/PbxLoadTestValidationTest.php --parallel`
Expected: PASS, including all pre-existing report-shape assertions.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/PbxDialplanLoadTestCommand.php tests/Feature/Pbx/PbxLoadTestValidationTest.php
git commit -m "feat(load-test): record app-side request and handler timing in reports"
```

---

### Task 4: Add the Directory-Mixed Tier to the Cache Sweep

**Files:**
- Modify: `scripts/run-cache-sweep.sh`
- Test: `tests/Feature/Pbx/PbxLoadTestValidationTest.php` (extend the `pbx load test scripts and scenarios` describe block)

**Interfaces:**
- RUNS format becomes `num|label|d_ttl|c_ttl|scenario|reqs|conc|section|timing` (existing four rows gain trailing `dialplan|` fields)
- New printed column: `App p95`, extracted from the report's `timing.request_ms.p95`

- [ ] **Step 1: Write the failing test**

```php
it('includes a directory-mixed tier that captures app-side timing', function (): void {
    $script = (string) file_get_contents(base_path('scripts/run-cache-sweep.sh'));

    expect($script)->toContain('directory-mixed')
        ->and($script)->toContain('--section=')
        ->and($script)->toContain('--timing')
        ->and($script)->toContain('App p95')
        ->and($script)->toContain('timing"]["request_ms"]["p95"]');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Pbx/PbxLoadTestValidationTest.php --filter=directory-mixed --parallel`
Expected: FAIL.

- [ ] **Step 3: Implement the fifth tier**

- Extend each `RUNS` entry with `section` and `timing` fields (existing four: `dialplan` and empty).
- Append: `"5|directory-mixed|60|60|mixed|100|5|mixed|--timing"`.
- Invoke the command with `--section="$section"` always, and append `--timing` only when the field is non-empty.
- Add an `App p95` column to both header `printf` lines and the row `printf`; extract the value with:

```bash
APP_P95="N/A"
if [ -f "$REPORT" ]; then
  APP_P95=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["timing"]["request_ms"]["p95"] ?? "N/A";' "$REPORT" 2>/dev/null || echo "N/A")
fi
```

- Keep the `.env` snapshot/restore trap, the DB-1-only flush, and the four existing tier names unchanged.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `bash -n scripts/run-cache-sweep.sh` and `./vendor/bin/pest tests/Feature/Pbx/PbxLoadTestValidationTest.php --parallel`
Expected: syntax valid; PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/run-cache-sweep.sh tests/Feature/Pbx/PbxLoadTestValidationTest.php
git commit -m "feat(load-test): add directory-mixed sweep tier with app-side p95"
```

---

### Task 5: Document the New Metrics

**Files:**
- Modify: `docs/load-testing-guide.md`
- Modify: `docs/load-testing-results.md`
- Modify: `CHANGELOG.md` (Unreleased → Changed)

- [ ] **Step 1: Update the guide**

In `docs/load-testing-guide.md`, extend the Quick Commands section with `--section=directory|mixed` and `--timing`, and add a short "App-side metrics" subsection: `handler_ms` is the middleware window; `request_ms` includes framework boot and is the metric used for proxy decisions; timing headers are opt-in (`FS_XML_HANDLER_TIMING_HEADERS=true` plus `X-TallPBX-Timing: 1`).

- [ ] **Step 2: Update the results reference**

In `docs/load-testing-results.md` under "Performance Metrics & Statistics", add definitions for **App-side request time (`request_ms`)** and **Handler time (`handler_ms`)**, and a note that pre-2026-11 artifacts contain client-side latency only.

- [ ] **Step 3: Update the changelog**

Add under `[Unreleased] → ### Changed`: load-test harness now records app-side request/handler timing and supports directory/mixed scenarios for XML performance runs.

- [ ] **Step 4: Verify and commit**

Run `tpbx optimize:clear` (no PHP changed in this task, so no Pint run is required), then:

```bash
git add docs/load-testing-guide.md docs/load-testing-results.md CHANGELOG.md
git commit -m "docs(load-test): document app-side metrics and directory scenarios"
```

---

## Out of Scope

- Running the actual 1 vCPU datacenter measurement (that is Task 1 of the Lua proxy plan v2 and consumes this harness).
- CI integration: these tests need real hardware, FreeSWITCH, and Redis; they stay manual/scheduled.
- Changing default timing behavior: headers remain opt-in and off by default.

## Risks and Guards

| Risk | Guard |
|:--|:--|
| Header leak on the public endpoint | Config flag **and** request header required; default off; test covers the omission case |
| Gate metric under-reports the proxy saving | `request_ms` uses `LARAVEL_START`; Task 3 test asserts it integrates through the report |
| Directory responses mis-validated as dialplan failures | Validator keyed by each query's own section; Task 2 tests directory and mixed |
| Report consumers break | Field order and existing keys unchanged; new data additive under `timing`; `schema_version` bumped |
| Sweep script corrupts `.env` or flushes wrong DB | Existing snapshot/restore trap and DB-1-only flush untouched; script test asserts the new tier plus `bash -n` |
