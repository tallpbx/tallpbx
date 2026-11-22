# TallPBX Load Testing Results

Use these TallPBX 5.x benchmarks to see how much call traffic different server sizes handled and how quickly they answered routing lookups. See the installation guide for operating system, storage, and minimum hardware requirements. [Server requirements](../INSTALL.md#1-create-the-server).

<a name="production-sizing--configuration-matrix"></a>
<a name="capacity-planning-rules"></a>

## Measured Call Capacity

FreeSWITCH asks TallPBX for phone account and call-routing information in XML. By default, **PHP-FPM** runs the fast XML handler, which loads only the telephony modules the lookup needs. **Laravel Octane** is an optional performance mode. It loads the same telephony module list and keeps the application loaded in memory between requests.

FreeSWITCH uses PHP-FPM when Octane is unavailable. If the fast handler catches an application error, the same PHP-FPM worker handles the request through the full application used by the web panel. [Enable, verify, or disable Octane](operations.md#octane-xml-worker-optional-performance-mode).

### Server sizes that completed every call

Use these as starting points for sizing with the default PHP-FPM handler. In the November 17, 2026 tests, each server completed every extension call offered over 60 seconds at the listed rate while handling phone registrations and one voicemail call per second. The tests used build `2fbac077`, Debian 13, and the default SQLite database for FreeSWITCH.

| Test server | Extension call rate with 100% completion | Calls completed | Phone registrations offered |
| --- | ---: | ---: | ---: |
| 1 vCPU / 1 GB RAM | **2 calls/sec** | 120 of 120 | 2/sec |
| 2 vCPU / 2 GB RAM | **8 calls/sec** | 480 of 480 | 8/sec |
| 4 shared vCPU / 8 GB RAM | No verified 60-second rate | — | — |

The 4 vCPU server passed functional call tests, and its XML lookup measurements are below. Use a separate 60-second or longer run with your expected call mix to establish a sizing target for it. The November 17 report includes short checks and higher rates where calls dropped. [November 17 test report](load-testing-2026-11-17.md#freeswitch-runtime-database) · [Run a capacity comparison](../load-testing/procedure.md#step-8--compare-call-capacity).

### PHP-FPM and Octane at the same call rate

These earlier November 8–9, 2026 runs offered extension calls for 60 seconds with 20 registered phone accounts. Each row compares the two handlers at the same offered rate on the same hardware. PHP-FPM used a dynamic worker pool when it handled XML and a fixed-size pool alongside Octane, so two settings changed. The November 17 series has no 1 vCPU Octane run.

| Test server | Offered rate | Calls completed with PHP-FPM | Calls completed with Octane |
| --- | ---: | ---: | ---: |
| 1 vCPU / 1 GB RAM | 6 calls/sec | 252 of 360 (70%) | **360 of 360 (100%)** |
| 2 vCPU / 2 GB RAM | 12 calls/sec | 299 of 720 (42%) | **720 of 720 (100%)** |

The 1 vCPU runs recorded build `dd14e57` with local changes. The 2 vCPU runs used build `1333389f`. In those sweeps, PHP-FPM also completed every call at 5 calls/sec on 1 vCPU (300 of 300) and 10 calls/sec on 2 vCPU (600 of 600). [Worker sweep data](../load-testing/results/consolidated/worker-sweep-20261108-cells.csv).

<a name="performance-metrics--statistics"></a>

## XML Lookup Speed

The default fast PHP-FPM handler loads just the telephony modules. Optional Octane keeps the same modules loaded in memory between requests. These tests send lookup requests directly to each handler and report completed requests per second. Call capacity also depends on FreeSWITCH and what each call does.

| Test server | Date | Workload | PHP-FPM | Octane |
| --- | --- | --- | ---: | ---: |
| 1 vCPU / 1 GB RAM | Nov 4 | 100 routing requests, 5 at a time | 25.5 req/sec | 168.5 req/sec |
| 2 vCPU / 2 GB RAM | Nov 17 | 100 mixed lookups, 5 at a time | 61.9–67.0 req/sec | 297.6–320.4 req/sec |
| 4 shared vCPU / 8 GB RAM | Nov 10 | 100 mixed lookups, 5 at a time | 20.12 req/sec | 67.20 req/sec |
| 4 shared vCPU / 8 GB RAM | Nov 10 | 1,000 mixed lookups, 25 at a time | 45.08 req/sec | 140.54 req/sec |

The November 4 numbers average three runs per handler on build `62661edf` with the 60-second cache profile. The November 17 ranges show three runs per handler on build `2fbac077` using the same cache profile. Mixed lookups combine phone account and routing requests. Compare handlers within each row. The dates, hardware, and request mixes differ across rows. [November 4 interleaved results](https://github.com/tallpbx/tallpbx/blob/e03160fa087d4675b7be6ad1764565d9b8211008/load-testing/status.md#variant-comparison--interleaved-series-3-rounds) · [November 17 lookup results](load-testing-2026-11-17.md#xml-lookup-throughput).

On 4 shared vCPU, PHP-FPM used a dynamic pool capped at 12 workers. At 1,000 requests, the median response took **331 ms** with PHP-FPM and **85 ms** with Octane. The November 10 report also records a pass across 14 calling and media scenarios. It gives neither the running build SHA nor a 60-second call-rate comparison for that host. [November 10 lookup tests](https://github.com/tallpbx/tallpbx/blob/5d643e31c7d5a06c27491d448103c834e4b4d9f3/docs/load-testing-results.md#persistent-octane-worker-vs-php-fpm-comparison-4-vcpu-shared--8-gb-ram) · [November 10 calling scenarios](https://github.com/tallpbx/tallpbx/blob/5d643e31c7d5a06c27491d448103c834e4b4d9f3/docs/load-testing-results.md#4-vcpu-shared--8-gb-ram-live-call-capacity--extended-validation).

<a name="november-2026-refresh-sqlite-vs-mariadb-mod_mariadb-option-1-2-tier-xml-and-2-vcpu-baselines"></a>

## Detailed Benchmark Reports

- [November 17, 2026](load-testing-2026-11-17.md) covers call completion, lookup speed, and SQLite versus MariaDB with concurrent phone registrations and voicemail. [Consolidated data](../load-testing/results/consolidated/refresh-20261117-cells.csv).
- The November 8–9 worker sweeps explain the installer's PHP-FPM worker limits on the [1 vCPU host](https://github.com/tallpbx/tallpbx/blob/2d6ca813902f016899f118edd5b3b22ac4fa6354/docs/load-testing-results.md#pre-2vcpu-verification-block-and-default-selection-1-vcpu--1-gb-2026-11-09) and the [2 vCPU host](https://github.com/tallpbx/tallpbx/blob/2d6ca813902f016899f118edd5b3b22ac4fa6354/docs/load-testing-results.md#2-vcpu--2-gb-capacity-ladder-and-worker-sweep-2026-11-09).
- [November 4–5, 2026](https://github.com/tallpbx/tallpbx/blob/dd14e5772428b675ef673f425447b31d09725fc0/load-testing/results/refresh-20261103/summary.md#1-call-capacity-ladder--cdr-via-php-fpm-the-shipped-default) records the earlier 1 vCPU call-rate ladder. Use it for historical context alongside the [November 5 follow-up](https://github.com/tallpbx/tallpbx/blob/dd14e5772428b675ef673f425447b31d09725fc0/load-testing/results/refresh-20261105-slimboot-sip/summary.md#results).

[Benchmark procedure](../load-testing/README.md) · [Metric definitions](load-testing-guide.md#interpreting-test-metrics).
