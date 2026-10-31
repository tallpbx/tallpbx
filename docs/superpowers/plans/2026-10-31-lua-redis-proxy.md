# Lua Redis Proxy (Approach A) Implementation Plan — Version-Aware Architecture

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement an in-process, version-aware Lua Redis Proxy (`tallpbx_xml_handler.lua`) in FreeSWITCH so that dynamic XML lookups (`directory`, `dialplan`, `configuration`) are resolved out of Redis Database 1 in <0.5 milliseconds via `mod_hiredis`, eliminating HTTP/PHP-FPM latency (77–144 ms) on cache hits while maintaining strict parity with Laravel's `RoutingCacheVersion` invalidation and seamless fallback to `mod_xml_curl`.

**Architecture:** FreeSWITCH registers `mod_lua` as the primary XML search binding (loaded before `mod_xml_curl` in `modules.conf.xml`). FreeSWITCH's `hiredis.conf.xml` connects to **Redis Database 1** (`REDIS_CACHE_DB=1`) with `REDIS_PASSWORD` using a dedicated profile `tallpbx_cache` with a 16-connection pool. On each lookup, Lua resolves the `tenant_id` from a Redis domain hash (`freeswitch:tenants`), fetches the current `RoutingCacheVersion`, constructs the version-tagged key, and performs an in-process `GET`. On a cache hit, Lua returns the XML directly; on a cache miss, Lua returns `nil`, allowing FreeSWITCH to fall through to `mod_xml_curl` (which executes Laravel, returns the XML, and warms Redis). When an administrator updates extensions or routing, Laravel calls `RoutingCacheVersion::bump($tenantId)`, automatically invalidating Lua's cache without stale-route risks.

**Tech Stack:** FreeSWITCH v1.11 (`mod_lua`, `mod_hiredis`, `mod_xml_curl`), Redis 7 (Database 1), Lua 5.2, Laravel 13 on PHP 8.5, Pest.

**Spec:** [Approach A: Version-Aware Lua Redis-First Cache Proxy Spec](#global-constraints)

## Global Constraints

- **Parity with `RoutingCacheVersion` Invalidation**: Lua cache keys must explicitly embed the tenant routing revision (`RoutingCacheVersion::get($tenantId)`) and global revision (`RoutingCacheVersion::getGlobal()`). Lua must never use unversioned static keys.
- **Dedicated `hiredis` Profile for Database 1**: FreeSWITCH `hiredis.conf.xml` must configure profile `tallpbx_cache` matching Laravel's `REDIS_CACHE_DB=1`, authenticated with `REDIS_PASSWORD`, and configured with `max-connections=16` for high-concurrency registration sweeps.
- **Strict FreeSWITCH Fallback Chain**: If a Redis key is missing, empty, or returns an error, the Lua script must return `nil` so FreeSWITCH immediately falls through to `mod_xml_curl` without dropping or stalling the SIP transaction.
- **Module Load Order Guarantee**: In `/etc/freeswitch/autoload_configs/modules.conf.xml`, `mod_lua` must be loaded *before* `mod_xml_curl` so its XML search hook takes priority in the C core binding list.
- **Human-Readable Key Derivation (No SHA-1 in Lua)**: To avoid third-party C library dependencies in Lua, key formatting in `DirectoryXmlBuilder` and `DialplanXmlBuilder` must use deterministic composite keys:
  - Directory: `freeswitch:xml:dir:{tenant_id}:{domain}:{user}:v{version}`
  - Dialplan: `freeswitch:xml:dp:{tenant_id}:{domain}:{context}:v{version}:g{global_version}`
  - Sofia Configuration: `freeswitch:xml:cfg:sofia.conf:{profile_or_gateways}`
- **Source of Truth for Scripts**: All FreeSWITCH Lua scripts and config templates must be versioned in the repository under `scripts/freeswitch/` and deployed to `/etc/freeswitch/`.
- **Code Standards & Scoping**: All PHP code must pass `./vendor/bin/pint --dirty` and existing Pest test suites (`tests/Feature/Http/XmlHandlerControllerTest.php`).

## Review Focus

1. **Redis Down / Connection Failure**: If Redis service is stopped or unreachable, `hiredis_raw` returns an error string (`-ERR` or `-NOAUTH`). Lua must detect this and return `nil`, allowing calls to seamlessly route through `mod_xml_curl`.
2. **Empty or Malformed Cached XML**: If a Redis key holds an incomplete string, FreeSWITCH parser should not crash; Lua must check `string.len(reply) > 20` and look for `<document` before assigning `XML_STRING`.
3. **Multi-Domain / Multi-Tenant Isolation**: Ensure directory lookups for extension `1001` on tenant domain `a.com` (tenant 1) never collide with extension `1001` on tenant domain `b.com` (tenant 2).
4. **Instant Invalidation on Version Bump**: When any service calls `RoutingCacheVersion::bump($tenantId)` (extensions, dialplans, tenant limits, number translations, scanner signatures), the next Lua lookup must automatically miss and rebuild without stale credentials.
5. **Calibrated Baseline Performance Gate**: The benchmark must be measured against the documented project baseline (77 ms at 4 vCPU to 144 ms at 2 vCPU mean latency) and confirm drop to <1 ms on in-process hits.

---

### Task 1: Configure FreeSWITCH `hiredis.conf.xml` Profile for Redis DB 1 & Auth

**Files:**
- Create: `scripts/resources/freeswitch/autoload_configs/hiredis.conf.xml`
- Copy to: `/etc/freeswitch/autoload_configs/hiredis.conf.xml`
- Test: CLI validation via `fs_cli -x "hiredis_raw tallpbx_cache PING"`

**Interfaces:**
- FreeSWITCH profile: `tallpbx_cache`
- Target: `127.0.0.1:6379`, Database 1, Password `{REDIS_PASSWORD}`, max-connections 16, timeout 250ms

- [ ] **Step 1: Create `hiredis.conf.xml` template**

Create `scripts/resources/freeswitch/autoload_configs/hiredis.conf.xml`:
```xml
<configuration name="hiredis.conf" description="mod_hiredis">
  <profiles>
    <profile name="default">
      <connections>
        <connection name="primary">
          <param name="hostname" value="127.0.0.1"/>
          <param name="port" value="6379"/>
          <param name="timeout_ms" value="500"/>
          <param name="max-connections" value="3"/>
        </connection>
      </connections>
      <params>
        <param name="ignore-connect-fail" value="true"/>
        <param name="ignore-error" value="true"/>
      </params>
    </profile>

    <!-- Dedicated cache profile aligned with Laravel REDIS_CACHE_DB=1 and REDIS_PASSWORD -->
    <profile name="tallpbx_cache">
      <connections>
        <connection name="primary">
          <param name="hostname" value="127.0.0.1"/>
          <param name="port" value="6379"/>
          <param name="password" value="{REDIS_PASSWORD}"/>
          <param name="database" value="1"/>
          <param name="max-connections" value="16"/>
          <param name="timeout_ms" value="250"/>
        </connection>
      </connections>
      <params>
        <param name="ignore-connect-fail" value="true"/>
        <param name="ignore-error" value="true"/>
      </params>
    </profile>
  </profiles>
</configuration>
```

- [ ] **Step 2: Deploy to `/etc/freeswitch/autoload_configs/hiredis.conf.xml`**

Inject `.env`'s `REDIS_PASSWORD` (or empty if unconfigured) into the template and copy to `/etc/freeswitch/autoload_configs/hiredis.conf.xml`. Ensure `chown freeswitch:tallpbx-media /etc/freeswitch/autoload_configs/hiredis.conf.xml`.

- [ ] **Step 3: Reload `mod_hiredis` and verify connection**

Run:
```bash
fs_cli -x "reload mod_hiredis"
fs_cli -x "hiredis_raw tallpbx_cache PING"
```
Expected: `PONG`.

- [ ] **Step 4: Commit configuration template**

```bash
git add scripts/resources/freeswitch/autoload_configs/hiredis.conf.xml
git commit -m "chore(freeswitch): add tallpbx_cache hiredis profile targeting redis db 1 with auth"
```

---

### Task 2: Implement Domain-to-Tenant Mapping in Redis DB 1

**Files:**
- Create: `app/Services/Tenant/TenantRedisResolver.php`
- Modify: `app/Models/Tenant.php` (or tenant observer to maintain Redis map)
- Modify: `app/Console/Commands/TenantsSyncCommand.php` (or add sync method)
- Test: `tests/Feature/Services/TenantRedisResolverTest.php`

**Interfaces:**
- Produces: Redis Hash in DB 1: `freeswitch:tenants` (`domain` $\rightarrow$ `tenant_id`)
- `TenantRedisResolver::sync(): void`
- `TenantRedisResolver::getTenantId(string $domain): ?int`

- [ ] **Step 1: Write test for `TenantRedisResolverTest`**

Create `tests/Feature/Services/TenantRedisResolverTest.php`:
```php
<?php

use App\Models\Tenant;
use App\Services\Tenant\TenantRedisResolver;
use Illuminate\Support\Facades\Cache;

test('syncs domain to tenant id mappings in redis db 1', function () {
    $tenant = Tenant::factory()->create(['domain' => 'pbx1.test']);

    TenantRedisResolver::sync();

    expect(TenantRedisResolver::getTenantId('pbx1.test'))->toBe($tenant->id);
});
```

- [ ] **Step 2: Implement `TenantRedisResolver`**

Create `app/Services/Tenant/TenantRedisResolver.php`:
- `sync()` loads all `Tenant::select('id', 'domain')->get()` and populates Redis DB 1 hash `freeswitch:tenants`.
- Listens to Tenant created/updated/deleted events to keep the hash synchronized in real-time.

- [ ] **Step 3: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Services/TenantRedisResolverTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Tenant/TenantRedisResolver.php tests/Feature/Services/TenantRedisResolverTest.php
git commit -m "feat(tenant): add domain-to-tenant redis hash resolver in db 1"
```

---

### Task 3: Refactor XML Cache Keys in Builders to Deterministic Versioned Strings

**Files:**
- Modify: `app/Services/Xml/DirectoryXmlBuilder.php`
- Modify: `app/Services/Xml/DialplanXmlBuilder.php`
- Test: `tests/Feature/Http/XmlHandlerControllerTest.php`

**Interfaces:**
- `DirectoryXmlBuilder`: key format `freeswitch:xml:dir:{tenantId}:{domain}:{username}:v{version}`
- `DialplanXmlBuilder`: key format `freeswitch:xml:dp:{tenantId}:{domain}:{context}:v{version}:g{globalVersion}`

- [ ] **Step 1: Update `directoryCacheKey()` in `DirectoryXmlBuilder.php`**

Replace SHA-1 hash with deterministic composite string:
```php
private function directoryCacheKey(string $tenantId, string $tagName, string $domain, string $username, string $keyValue): string
{
    $version = (string) RoutingCacheVersion::get((int) $tenantId);
    return "freeswitch:xml:dir:{$tenantId}:{$domain}:{$username}:v{$version}";
}
```

- [ ] **Step 2: Update `dialplanCacheKey()` in `DialplanXmlBuilder.php`**

Replace SHA-1 hash with deterministic composite string:
```php
private function dialplanCacheKey(string $tenantId, string $context, string $destination, string $callerId): string
{
    $version = (string) RoutingCacheVersion::get((int) $tenantId);
    $global = (string) RoutingCacheVersion::getGlobal();
    return "freeswitch:xml:dp:{$tenantId}:{$context}:{$destination}:v{$version}:g{$global}";
}
```

- [ ] **Step 3: Run existing XML Handler test suite**

Run: `./vendor/bin/pest tests/Feature/Http/XmlHandlerControllerTest.php`
Expected: All 68 tests pass (246 assertions).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Xml/DirectoryXmlBuilder.php app/Services/Xml/DialplanXmlBuilder.php
git commit -m "refactor(xml): update directory and dialplan cache keys to deterministic versioned strings"
```

---

### Task 4: Author the FreeSWITCH Version-Aware Lua XML Cache Handler Script

**Files:**
- Create: `scripts/freeswitch/tallpbx_xml_handler.lua`
- Copy to: `/etc/freeswitch/scripts/tallpbx_xml_handler.lua`
- Test: Syntax check via `luac -p` and unit test script `tests/Unit/Scripts/test_tallpbx_xml_handler.sh`

**Interfaces:**
- FreeSWITCH Global Input: `XML_REQUEST` (`section`, `tag_name`, `key_name`, `key_value`)
- FreeSWITCH Global Input: `params` (`getHeader()`)
- FreeSWITCH API: `api:execute("hiredis_raw", "tallpbx_cache ...")`
- FreeSWITCH Global Output: `XML_STRING`

- [ ] **Step 1: Write `scripts/freeswitch/tallpbx_xml_handler.lua`**

```lua
-- /etc/freeswitch/scripts/tallpbx_xml_handler.lua
-- TallPBX Version-Aware Lua Redis XML Cache Proxy

if not XML_REQUEST then
    return
end

local section   = XML_REQUEST["section"] or ""
local tag_name  = XML_REQUEST["tag_name"] or ""
local key_name  = XML_REQUEST["key_name"] or ""
local key_value = XML_REQUEST["key_value"] or ""

local user      = (params and params:getHeader("user")) or ""
local domain    = (params and (params:getHeader("domain") or params:getHeader("domain_name") or params:getHeader("variable_domain_name"))) or ""
local context   = (params and (params:getHeader("context") or params:getHeader("Caller-Context") or params:getHeader("Hunt-Context"))) or ""

local api = freeswitch.API()
local cache_key = nil

if section == "directory" then
    if domain ~= "" and user ~= "" then
        -- 1. Resolve tenant_id from domain in Redis DB 1 (<0.05ms)
        local tenant_id = api:execute("hiredis_raw", "tallpbx_cache HGET freeswitch:tenants " .. domain)
        if tenant_id and string.sub(tenant_id, 1, 4) ~= "-ERR" and tenant_id ~= "(nil)" and tenant_id ~= "" then
            -- 2. Fetch active routing version (<0.05ms)
            local version = api:execute("hiredis_raw", "tallpbx_cache GET freeswitch:routing-version:" .. tenant_id)
            if not version or version == "(nil)" or version == "" then
                version = "1"
            end
            cache_key = "freeswitch:xml:dir:" .. tenant_id .. ":" .. domain .. ":" .. user .. ":v" .. version
        end
    end
elseif section == "dialplan" then
    local dp_context = context ~= "" and context or domain
    if dp_context ~= "" then
        local tenant_id = api:execute("hiredis_raw", "tallpbx_cache HGET freeswitch:tenants " .. (domain ~= "" and domain or dp_context))
        if tenant_id and string.sub(tenant_id, 1, 4) ~= "-ERR" and tenant_id ~= "(nil)" and tenant_id ~= "" then
            local version = api:execute("hiredis_raw", "tallpbx_cache GET freeswitch:routing-version:" .. tenant_id)
            local global_ver = api:execute("hiredis_raw", "tallpbx_cache GET freeswitch:routing-version:global")
            if not version or version == "(nil)" then version = "1" end
            if not global_ver or global_ver == "(nil)" then global_ver = "1" end
            local dest = (params and params:getHeader("Caller-Destination-Number")) or ""
            cache_key = "freeswitch:xml:dp:" .. tenant_id .. ":" .. dp_context .. ":" .. dest .. ":v" .. version .. ":g" .. global_ver
        end
    end
end

if not cache_key then
    XML_STRING = nil
    return
end

-- Query Redis in-process via tallpbx_cache profile
local reply = api:execute("hiredis_raw", "tallpbx_cache GET " .. cache_key)

-- Verify reply is valid XML and not a Redis error or nil
if reply and string.sub(reply, 1, 4) ~= "-ERR" and reply ~= "(nil)" and string.len(reply) > 20 then
    if string.find(reply, "<document") then
        XML_STRING = reply
        return
    end
end

-- On cache miss, return nil to allow FreeSWITCH to fall through to mod_xml_curl
XML_STRING = nil
```

- [ ] **Step 2: Syntax check and deploy script**

Run:
```bash
luac -p scripts/freeswitch/tallpbx_xml_handler.lua
sudo cp scripts/freeswitch/tallpbx_xml_handler.lua /etc/freeswitch/scripts/
sudo chown -R freeswitch:tallpbx-media /etc/freeswitch/scripts/tallpbx_xml_handler.lua
sudo chmod 644 /etc/freeswitch/scripts/tallpbx_xml_handler.lua
```

- [ ] **Step 3: Commit Lua script**

```bash
git add scripts/freeswitch/tallpbx_xml_handler.lua
git commit -m "feat(freeswitch): add version-aware tallpbx_xml_handler.lua cache proxy"
```

---

### Task 5: Configure `lua.conf.xml` and Module Load Order Priority

**Files:**
- Modify: `/etc/freeswitch/autoload_configs/lua.conf.xml`
- Modify: `/etc/freeswitch/autoload_configs/modules.conf.xml`

**Interfaces:**
- Module load order: `<load module="mod_lua"/>` placed *before* `<load module="mod_xml_curl"/>`
- Search binding: `<param name="xml-handler-script" value="tallpbx_xml_handler.lua"/>`
- Search binding: `<param name="xml-handler-bindings" value="directory,dialplan"/>`

- [ ] **Step 1: Ensure `mod_lua` loads before `mod_xml_curl`**

In `/etc/freeswitch/autoload_configs/modules.conf.xml`, verify that `<load module="mod_lua"/>` appears ahead of `<load module="mod_xml_curl"/>`.

- [ ] **Step 2: Activate Lua XML handler bindings**

In `/etc/freeswitch/autoload_configs/lua.conf.xml`:
```xml
<configuration name="lua.conf" description="LUA Configuration">
  <settings>
    <param name="xml-handler-script" value="tallpbx_xml_handler.lua"/>
    <param name="xml-handler-bindings" value="directory,dialplan"/>
  </settings>
</configuration>
```

- [ ] **Step 3: Restart FreeSWITCH to bind search functions**

Run: `sudo systemctl restart freeswitch`.
Verify FreeSWITCH boots cleanly and logs `mod_lua` binding to `directory` and `dialplan`.

---

### Task 6: Calibrated Benchmark Gate & End-to-End Verification

**Files:**
- Execute: `tpbx pbx:load-test:seed`
- Execute: `scripts/pbx-sipp-validate.sh`

**Verification Criteria:**
1. **End-to-End Cache Hit**:
   - Seed test user into Redis:
     `redis-cli -n 1 SET "freeswitch:xml:dir:1:test.domain:1001:v1" '<document type="freeswitch/xml"><section name="directory"><domain name="test.domain"><user id="1001"><params><param name="password" value="secret"/></params></user></domain></section></document>'`
     `redis-cli -n 1 HSET "freeswitch:tenants" "test.domain" "1"`
     `redis-cli -n 1 SET "freeswitch:routing-version:1" "1"`
   - Run: `fs_cli -x "find_user_xml id 1001 test.domain"`
   - Verify that FreeSWITCH immediately locates user 1001 in-process with 0 HTTP calls to Nginx!
2. **Instant Invalidation on Version Bump**:
   - Run: `tpbx tinker --execute 'App\Support\RoutingCacheVersion::bump(1);'`
   - Run: `fs_cli -x "find_user_xml id 1001 test.domain"`
   - Verify that FreeSWITCH misses the old `v1` key, falls through to `mod_xml_curl`, fetches fresh XML, and warms `v2` without stale credentials!
3. **Calibrated Latency Drop**:
   - Under SIP registration sweep, verify lookup latency drops from documented baseline of 77–144 ms down to **<1 ms**.
4. **Pint and Tests**:
   - Run `./vendor/bin/pint --dirty --test` (0 violations).
   - Run `./vendor/bin/pest tests/Feature/Http/XmlHandlerControllerTest.php` (all tests pass).
