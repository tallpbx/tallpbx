# Octane & RoadRunner XML Worker — Load Verification & Benchmark Report

**Date:** 2026-11-02  
**Plan:** [`docs/octane-performance-improvement-plan.md`](../../octane-performance-improvement-plan.md) — Task 6  
**Status:** **PASSED / COMPLETED**  

This report documents the load validation, benchmark measurements, and end-to-end verification of the persistent RoadRunner Octane lean telephony XML worker operating on `127.0.0.1:8090`.

---

## 1. Environment

| Item | Value |
|:--|:--|
| Host | Appliance Server (`fspbx1`) |
| OS | Debian GNU/Linux 13 (trixie), amd64 (`Linux 6.12.95+deb13-amd64`) |
| CPU | 4 cores |
| RAM | 4 GB |
| Swap | 2 GB |
| PHP Runtime | PHP 8.5.12 CLI under RoadRunner Go Supervisor (`rr` 2024.3) |
| Framework | Laravel 13.38.0 + Laravel Octane 2.x |
| Telephony | FreeSWITCH 1.11.4 (SignalWire packages) |
| Storage & Cache | MariaDB 11, Redis 7 (Cache DB 1, CDR stream DB 3) |
| Endpoint Target | `http://127.0.0.1:8090/api/v1/xml-handler` |
| FreeSWITCH Gateway Binding | `/etc/freeswitch/autoload_configs/xml_curl.conf.xml` pointing to port 8090 |
| Baseline Endpoint | `http://127.0.0.1/api/v1/xml-handler` (Nginx + PHP-FPM static pool) |

---

## 2. Configuration Matrix & Verification

1. **Persistent Lean Worker Service (`tallpbx-xml-worker.service`)**:
   - Running under `TALLPBX_WORKER_ROLE=telephony` with `TelephonyWorkerProviders` allowlist (32 providers vs 90+ full stack).
   - Listening strictly on local loopback `127.0.0.1:8090`.
   - Resident memory: ~162 MB across 4 auto-scaled workers (~40 MB per worker).
   - Terminating request lifecycle via `ResetTenantState` listener enforcing cross-tenant cleanup (`TenantManager::clear()`, connection rollback safety, tainted connection resets).

2. **FreeSWITCH `mod_xml_curl` Binding**:
   - `xml_curl.conf.xml` gateway URL: `http://127.0.0.1:8090/api/v1/xml-handler?token=<token>`.
   - Verified active via `reloadxml` and live `find_user_xml id 1001 192.168.1.76`.
   - Requests logged in `tallpbx-xml-worker` systemd journal with 200 OK.

3. **Disabled Rollback-Safe Layers**:
   - Fast controller (`/api/v1/xml-handler-fast`): routes and bootstrap intact for rollback, but production Nginx traffic repointed to standard pipeline and FreeSWITCH repointed to port 8090.
   - Lua proxy (`scripts/freeswitch/tallpbx_xml_handler.lua`): bindings disabled in `lua.conf.xml`.

---

## 3. Load Benchmark Measurements

All benchmarks executed via `tpbx pbx:load-test:dialplan` using synthetic load test tenant `load-test-vbox` with 100 configured extensions.

### 3.1 Octane RoadRunner Lean Worker (`http://127.0.0.1:8090`)

| Metric | 100×5 Cache-Hit | 500×25 Cache-Hit | 100×5 Mixed | 500×25 Mixed | 100×5 Directory |
|:--|--:|--:|--:|--:|--:|
| **Total Requests** | 100 | 500 | 100 | 500 | 100 |
| **Successful Responses** | 100 | 500 | 100 | 500 | 100 |
| **Failed / Timeouts** | 0 | 0 | 0 | 0 | 0 |
| **Elapsed Time** | 2.95 s | 7.50 s | 4.55 s | 11.26 s | 5.16 s |
| **Client Throughput** | **33.9 req/s** | **66.6 req/s** | **22.0 req/s** | **44.4 req/s** | **19.4 req/s** |
| **Client Latency (Min)** | 19.5 ms | 33.7 ms | 29.7 ms | 24.5 ms | 57.8 ms |
| **Client Latency (p50)** | 58.2 ms | 180.7 ms | 67.5 ms | 224.4 ms | 165.2 ms |
| **Client Latency (Avg)** | 88.9 ms | 193.1 ms | 104.8 ms | 277.4 ms | 172.3 ms |
| **App-Side `handler_ms` (p50)** | **10.8 ms** | **10.2 ms** | **12.0 ms** | **10.6 ms** | **14.2 ms** |
| **App-Side `handler_ms` (p95)** | **28.4 ms** | **26.2 ms** | **187.0 ms** | **204.5 ms** | **42.8 ms** |
| **Database Queries (Cached)** | **0** | **0** | **0 (hits)** | **0 (hits)** | **0** |

*Note: Client-side latency includes CLI Guzzle `Http::pool` chunking and TCP connection overhead per batch; app-side `handler_ms` reflects the internal processing window within the persistent worker.*

---

### 3.2 Comparison: Persistent Octane Worker vs. PHP-FPM Baseline

| Metric | PHP-FPM Stock (100×5) | Octane Lean Worker (100×5) | Improvement / Delta |
|:--|--:|--:|:--|
| **Elapsed Time (100 reqs)** | 9.13 s | 2.95 s | **3.1× faster elapsed** |
| **Throughput (100×5)** | 10.95 req/s | 33.91 req/s | **+210% throughput (+3.1×)** |
| **Client Latency (Min)** | 169.99 ms | 19.47 ms | **8.7× faster response** |
| **Client Latency (p50)** | 347.17 ms | 58.15 ms | **6.0× lower latency** |
| **Client Latency (Avg)** | 355.48 ms | 88.85 ms | **4.0× lower latency** |
| **App-Side Total `request_ms` (p95)** | 436.95 ms | ~28.38 ms (handler) | **Eliminates ~400 ms boot** |
| **Throughput Under Saturation (500×25)** | 3.59 req/s (FPM-12 starvation) | 66.65 req/s (0 errors) | **18.5× throughput under load** |

---

## 4. Key Architectural Findings

1. **Complete Elimination of PHP Process-Boot Overhead**:
   - In PHP-FPM, every dynamic request paid 170–450 ms in bootstrap latency (autoloading Composer, booting Eloquent, registering service providers).
   - In the Octane RoadRunner resident worker, the framework remains resident in memory. App-side handler execution on cache hits drops to ~10 ms.

2. **Zero Worker Starvation & Concurrency Resilience**:
   - At concurrency 25 across 500 requests, stock PHP-FPM historically starved workers and accumulated request queues, causing total run times of ~139 seconds and latencies exceeding 8 seconds.
   - The persistent worker cleared all 500 requests in 7.5 seconds (66.6 req/s) with **0 failed requests** and **0 socket timeouts**.

3. **Zero Database Queries on Cache Hits**:
   - Redis DB 1 cache lookups return full XML payloads without touching MariaDB (`X-TallPBX-DB-Queries: 0`).
   - Atomic lock thundering-herd protection prevents cache stampedes on cold starts or key expirations.

4. **100% Byte Parity & Clean Tenant Isolation**:
   - Full Pest test suite (97 tests) passed including `XmlHandlerControllerTest`, `XmlHandlerOctaneParityTest`, and `TenantStateIsolationTest`.
   - Verified that `TenantManager::clear()` deterministically purges tenant identity between subsequent requests on the same worker process.

---

## 5. Verification Gate Summary

- [x] Application boot clean (`tpbx optimize:clear`): PASS
- [x] Pint code style checks (`./vendor/bin/pint --dirty --test`): PASS (0 style violations)
- [x] Targeted Pest test suite (97 tests, 379 assertions): PASS (100%)
- [x] FreeSWITCH live XML curl integration (`find_user_xml`, `reloadxml`): PASS
- [x] High-concurrency load benchmark (100×5 and 500×25): PASS (0 failures, 100% success)
- [x] Documentation & measurement results recorded: PASS
