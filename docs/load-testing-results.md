# TallPBX Load Testing Results

Use these TallPBX 5.x benchmarks to compare tested server sizes and configurations. For operating system, storage, and minimum hardware requirements, see the [installation guide](../INSTALL.md#1-create-the-server).

<a name="production-sizing--configuration-matrix"></a>
<a name="capacity-planning-rules"></a>

## Measured Call Capacity

FreeSWITCH asks TallPBX for phone account and call-routing information in XML. By default, **PHP-FPM** runs the fast XML handler, which loads only the telephony modules the lookup needs. **Laravel Octane** is an optional performance mode. It loads the same telephony module list as the PHP-FPM fast XML handler and keeps the application loaded in memory between requests.

FreeSWITCH uses PHP-FPM when Octane is unavailable. If the fast handler catches an application error, the same PHP-FPM worker handles the request through the full application used by the web panel. [Enable, verify, or disable Octane](operations.md#octane-xml-worker-optional-performance-mode).

The [November 17, 2026 tests](load-testing-2026-11-17.md) used TallPBX build `2fbac077`, Debian 13, the default SQLite database for FreeSWITCH, and 30 registered phone accounts. The offered rate is how many new calls the test attempted each second.

| Test server | XML handler | Offered calls/sec | Duration | Calls completed |
| --- | --- | ---: | ---: | ---: |
| 1 vCPU / 1 GB RAM | PHP-FPM (default) | 4 | 15 sec | 60 of 60 |
| 1 vCPU / 1 GB RAM | PHP-FPM (default) | 4 | 60 sec | 117 of 240 |
| 1 vCPU / 1 GB RAM | PHP-FPM (default) | 5 | 60 sec | 90 of 300 |
| 2 vCPU / 2 GB RAM | PHP-FPM (default) | 10 | 15 sec | 150 of 150 |
| 2 vCPU / 2 GB RAM | PHP-FPM (default) | 10 | 60 sec | 218 of 600 |
| 2 vCPU / 2 GB RAM | Octane (optional) | 12 | 15 sec | 180 of 180 |
| 2 vCPU / 2 GB RAM | Octane (optional) | 12 | 60 sec | 613 of 720 |

The 15-second runs passed at rates where the 60-second runs lost calls. Use longer, repeatable runs that reflect your expected call patterns to size a production server. [Run a load test on your own server](load-testing-guide.md).

An [earlier November 9 test](https://github.com/tallpbx/tallpbx/blob/2d6ca813902f016899f118edd5b3b22ac4fa6354/docs/load-testing-results.md#2-vcpu--2-gb-capacity-ladder-and-worker-sweep-2026-11-09) on 2 vCPU and 2 GB RAM used build `1333389f` and 20 registered phone accounts. In 60-second runs, PHP-FPM completed 600 of 600 calls at 10 calls/sec and Octane completed 720 of 720 at 12 calls/sec. The build and workload differed from the November 17 tests.

<a name="november-2026-refresh-sqlite-vs-mariadb-mod_mariadb-option-1-2-tier-xml-and-2-vcpu-baselines"></a>

## Detailed Benchmark Reports

- [November 17, 2026 report](load-testing-2026-11-17.md) covers XML throughput, call completion, and tests with concurrent phone registrations and voicemail. [Consolidated data](../load-testing/results/consolidated/refresh-20261117-cells.csv).
- The November 8–9, 2026 PHP-FPM worker sweeps explain the installer's worker limits on the [1 vCPU host](https://github.com/tallpbx/tallpbx/blob/2d6ca813902f016899f118edd5b3b22ac4fa6354/docs/load-testing-results.md#pre-2vcpu-verification-block-and-default-selection-1-vcpu--1-gb-2026-11-09) and the [2 vCPU host](https://github.com/tallpbx/tallpbx/blob/2d6ca813902f016899f118edd5b3b22ac4fa6354/docs/load-testing-results.md#2-vcpu--2-gb-capacity-ladder-and-worker-sweep-2026-11-09). [Consolidated data](../load-testing/results/consolidated/worker-sweep-20261108-cells.csv) · [PHP-FPM settings](../INSTALL.md#php-fpm-worker-sizing).

<a name="performance-metrics--statistics"></a>

**Reading the measurements.** XML requests/sec measures how quickly TallPBX answers lookup requests. The call tests measure calls from setup through teardown. The [metrics guide](load-testing-guide.md#interpreting-test-metrics) explains call rate, completion, and latency percentiles. The [benchmark harness](../load-testing/README.md) documents how to reproduce the runs.
