# Test Isolation & Cache Safety Verification

## Scope

This report verifies the following statement from `README.md` (line 198):

> All automated tests execute inside an isolated in-memory SQLite database and temporary cache, **never touching your live MariaDB database** or active calls.

It also verifies the narrower claim that the cache used by tests is not shared with the cache used by the live production server and MariaDB.

This is a report-only review. No application, test, or configuration files were changed.

---

## Verdict

| Claim | Verdict | Severity of the gap |
|---|---|---|
| Tests use an isolated in-memory SQLite database | **True in normal runs**, not guaranteed with cached config | High |
| Tests never touch the live MariaDB database | **True with cleared config**, false as an unconditional guarantee | High |
| Tests use a temporary cache that is not shared with production | **True for Laravel's cache store**, false for Redis state used by the security module | Medium |
| Tests never touch active calls | **True for call-mutating operations in reviewed tests**; tests do query the live switch read-only | Low |

The statement is directionally correct, but its absolute wording ("all", "never") is not accurate for every supported execution path.

---

## 1. What Is Confirmed True

### 1.1 In-memory SQLite is enforced

- `phpunit.xml` (lines 25-41) forces `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and clears `DB_URL`.
- `app/Console/Commands/SafeTestCommand.php` (lines 50-67) applies the same values to child and parallel test processes. Verified at runtime: `Artisan::all()['test']` resolves to `App\Console\Commands\SafeTestCommand`.
- `app/Providers/AppServiceProvider.php` (lines 286-288) invokes `App\Support\TestDatabaseSafety::enforce()` when the application environment is `testing`. That guard (`app/Support/TestDatabaseSafety.php`, lines 17-24) throws `LogicException('Tests must use an in-memory SQLite database.')` for any other connection or database.
- `php artisan app:test` removes inherited `.env` values before starting Pest (`app/Console/Commands/TestCommand.php`, lines 190-237).
- Feature tests are covered by `LazilyRefreshDatabase`, which runs migrations against the configured SQLite connection.
- The Pest 4 browser HTTP server is in-process and handles requests through the same Laravel kernel (`vendor/pestphp/pest-plugin-browser/src/Drivers/LaravelHttpServer.php`, lines 219-288), so browser tests share the same in-memory database as the feature suite.

### 1.2 Laravel's cache store is isolated

`phpunit.xml` (lines 30-32) forces:

- `CACHE_STORE=array`
- `FS_XML_HANDLER_CACHE_STORE=array`
- `FS_XML_HANDLER_ACL_CACHE_STORE=array`

Sessions use the `array` driver, queues use `sync`, and broadcasting uses `null`. As a result, `Cache::*`, rate limiters, XML handler caches, and ACL caches do not reach production Redis during tests.

### 1.3 Call-mutating actions are mocked or sent to a fake server

- Operator panel and active-calls tests bind a Mockery mock and assert exact ESL commands (`app-modules/active-calls/tests/ActiveCallsTest.php`, lines 95-111; `app-modules/operator-panel/tests/OperatorPanelTest.php`, lines 95-112).
- ESL protocol tests use a fake server on a random port, including `reloadxml`, `sofia profile ...`, and `hupall` (`tests/Feature/Services/FreeSwitchServiceIntegrationTest.php`, lines 118-156).
- `app/Services/FreeSwitchService.php` (lines 345-358) blocks `reloadxml`, `reloadacl`, and `sofia profile*` during a test run when the service targets the configured live ESL endpoint.

---

## 2. What Is False or Not Guaranteed

### 2.1 Cached config bypasses every test guard, including the MariaDB guard

`bootstrap/cache/config.php` takes precedence over all environment variables (`vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/LoadConfiguration.php`, lines 41-61). The installer creates that file by running `php artisan optimize` (`scripts/resources/tall.sh`, lines 298-299), and the cache-sweep script does the same.

With a cached config present:

- `environment('testing')` is false, so `TestDatabaseSafety::enforce()` never runs.
- `runningUnitTests()` is false, so the ESL mutation guard, firewall executor guard, broadcast suppression, and `optimize:clear` skip are also disabled.
- The default database connection is the live MariaDB configuration stored in the cache.

Mitigation: `PrimaryDatabaseSafety` (wired at `app/Providers/AppServiceProvider.php`, line 233) prohibits `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, and `db:wipe` when the active connection targets the primary database. A wipe is therefore blocked, but a test run could still open live MariaDB connections and read or write data within test transactions.

`php artisan app:test` only clears caches when `--clear-cache` is passed (`app/Console/Commands/TestCommand.php`, lines 46-50). Neither `app:test` nor `SafeTestCommand` clears config by default. Only the `composer test` script clears config first (`composer.json`, lines 127-130). The README directs operators to run `php artisan app:test --full`, which is unsafe on a freshly installed host where config is cached.

### 2.2 Tests share the live Redis server through direct Redis calls

The security module bypasses Laravel's cache abstraction and talks to Redis directly:

- `app-modules/security/src/Services/Bans/SecurityIncidentService.php` (lines 60-66) uses `Redis::incr()` and `Redis::expire()` for failed-attempt counters.
- `app-modules/security/src/Listeners/LogSipScannerListener.php` (lines 201-210) uses `Redis::exists()`, `Redis::set()`, and `Redis::expire()` for scanner audit throttles.
- `app-modules/security/src/Services/Bans/SecurityBanService.php` (line 319) deletes counter keys.

The tests explicitly acknowledge the sharing:

- `app-modules/security/tests/SecurityBanServiceTest.php` (lines 31-35): "Parallel Pest workers and consecutive runs share one live Redis".
- `app-modules/security/tests/SecurityEventListenersTest.php` (lines 43-79) deletes keys matching `tallpbx:security:sip_scanner_low:*` and `tallpbx:security:sip_scanner_audited:*` before and after each test.

`phpunit.xml` does not override `REDIS_HOST`, `REDIS_PORT`, or `REDIS_DB`, so these calls use the live Redis at the host and port configured in `.env`, database 0, with the production key prefix.

Production's cache store uses the Redis **cache** connection/database 1 (`config/database.php`, lines 169-180). The cache-store keyspace is therefore separate, but the Redis server, database 0, and production security/session state are shared. The tests mutate live security counters and delete live scanner-throttle keys. The flood test also writes counters for more than 100 synthetic IPs into live Redis; those keys expire through their TTLs.

### 2.3 Compiled Blade views are shared on-disk cache

Tests render views into the same `storage/framework/views` directory used by the live panel (framework view config, `vendor/laravel/framework/config/view.php`, lines 31-34). This is a known project pitfall: root-run tests can leave root-owned compiled views that break the live panel with `touch(): Utime failed`. It is not the application cache store, but it is cache state shared with production.

### 2.4 The ESL mutation guard covers only three command families

`isLiveMutationCommand()` blocks `reloadxml`, `reloadacl`, and `sofia profile*` only. It does not classify `uuid_kill`, `originate`, `hupall`, `conference ... kick`, `vm_fsdb_msg_delete`, `vm_fsdb_msg_purge`, or `load mod_*` as mutations. The current tests avoid those commands by mocking, but the guard itself would not stop a future test that resolves the real service and calls one.

Browser smoke tests render live ESL-backed monitoring pages (`tests/Browser/PanelTelephonySmokeTest.php`, lines 161-190). Those components execute read-only commands such as `show channels as json`, `sofia status`, `sofia status profile internal reg`, and `conference list` against the configured live switch. This does not alter call state, but "never touching active calls" is only true for call-mutating operations.

---

## 3. Empirical Probe

A temporary config cache was generated outside `bootstrap/cache` and used to boot the application with test-oriented environment variables:

```text
APP_CONFIG_CACHE=<temp path> APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:
```

The booted application reported:

```json
{"env":"local","runningUnitTests":false,"db":"mysql","dbname":"tallpbx","cached":true}
```

This confirms that a cached config overrides the forced test environment and disables the `testing` gate on `TestDatabaseSafety`. The temporary cache file was deleted afterwards; the production `bootstrap/cache/config.php` was not created.

Current state on this host: `bootstrap/cache/config.php` does not exist, so a normal test run today starts from file-based configuration and uses SQLite `:memory:` as intended.

---

## 4. Recommendations

1. **Make test runners clear or reject cached config.** Add `config:clear` at the start of `app:test` and `SafeTestCommand`, or fail fast when `app('config_loaded_from_cache')` is true. Do not leave this behind an optional flag.
2. **Make `TestDatabaseSafety` unconditional under PHPUnit/Pest.** Gate it on a PHPUnit signal (for example, the `APP_ENV` value set by `phpunit.xml` or a Pest constant) instead of `$app->environment('testing')`, so cached config cannot bypass it.
3. **Isolate Redis for tests.** Set `REDIS_DB` and `REDIS_CACHE_DB` to dedicated test databases in `phpunit.xml` and `SafeTestCommand`, provide a separate Redis service in CI, or bind a fake for the security Redis paths.
4. **Widen the ESL test guard.** Cover all mutating commands (`uuid_*`, `originate`, `hupall`, `conference *`, `vm_fsdb_*`, `load`/`unload`), or switch to an allowlist of read-only commands.
5. **Correct the README wording.** A precise version would read:
   > Feature and browser tests use an isolated in-memory SQLite database and an in-process array cache. They do not write to the application MariaDB database when caches are clear, and call-control actions are mocked. The security module uses the local Redis instance for attempt counters, and monitoring pages may query FreeSWITCH read-only.
6. **Document the safe command.** Either make `--clear-cache` the default for `app:test` on installed hosts, or document `php artisan app:test --clear-cache` as the required form.

---

## 5. Bottom Line

- **"isolated in-memory SQLite database"**: true in the normal workflow; not guaranteed while `bootstrap/cache/config.php` exists.
- **"temporary cache"**: Laravel's cache store is isolated; Redis state used by the security subsystem is shared with production.
- **"never touching your live MariaDB database"**: true with cleared config; false as an unconditional guarantee. Destructive migrations remain blocked by `PrimaryDatabaseSafety`.
- **"or active calls"**: true for call mutations in the reviewed tests; tests do query the live switch read-only.
