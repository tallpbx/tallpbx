# TallPBX Benchmark Report — November 17, 2026

This report records how two server sizes handled call setup, how quickly they answered XML requests, and what changed when FreeSWITCH used MariaDB for its runtime database. [Hardware sizing summary](load-testing-results.md) · [Consolidated data](../load-testing/results/consolidated/refresh-20261117-cells.csv).

## Test Setup

The tests used a fresh Debian 13 install of TallPBX 5.x at commit `2fbac077`, with the event listener and Reverb running, FreeSWITCH logging at `notice`, and the default firewall rules active. The test host was resized from 1 vCPU / 1 GB RAM to 2 vCPU / 2 GB RAM. The default PHP-FPM pool had a limit of three workers on the smaller host and six on the larger host.

FreeSWITCH asks TallPBX for account and routing information in XML. The default fast PHP-FPM handler loads only the telephony modules needed for those requests. Optional Laravel Octane loads the same telephony module list and keeps the application loaded in memory between requests. FreeSWITCH uses PHP-FPM while Octane is unavailable. If the fast handler catches an application error, the same PHP-FPM worker handles the request through the full application used by the web panel. [How the XML handlers work](architecture.md#6-persistent-telephony-xml-worker-laravel-octane--backed-by-roadrunner).

## Call Completion

The call generator offered calls for 15 seconds in the quick checks and 60 seconds in the longer tests. Both used 30 registered phone accounts and SQLite as the FreeSWITCH runtime database. Setup latency is measured for completed calls. The median is the middle value, and p95 is the time within which 95% of those calls were set up.

| Test server | XML handler | Offered calls/sec | Duration | Calls completed | Setup median / p95 |
| --- | --- | ---: | ---: | ---: | ---: |
| 1 vCPU / 1 GB RAM | PHP-FPM | 4 | 15 sec | 60 of 60 | 212 / 1,156 ms |
| 1 vCPU / 1 GB RAM | PHP-FPM | 4 | 60 sec | 117 of 240 | 1,292 / 13,416 ms |
| 1 vCPU / 1 GB RAM | PHP-FPM | 5 | 60 sec | 90 of 300 | 448 / 13,528 ms |
| 2 vCPU / 2 GB RAM | PHP-FPM | 10 | 15 sec | 150 of 150 | 160 / 2,660 ms |
| 2 vCPU / 2 GB RAM | PHP-FPM | 10 | 60 sec | 218 of 600 | 788 / 16,080 ms |
| 2 vCPU / 2 GB RAM | Octane | 12 | 15 sec | 180 of 180 | 84 / 468 ms |
| 2 vCPU / 2 GB RAM | Octane | 12 | 60 sec | 613 of 720 | 1,868 / 8,028 ms |

## XML Lookup Throughput

These HTTP tests measured lookup responses using the `production-60` cache profile and SQLite for FreeSWITCH. “Mixed” combines directory and dialplan lookups. The original report records the request rates, and the consolidated CSV records the response counts and timing. [Original November 17 report](https://github.com/tallpbx/tallpbx/blob/2d6ca813902f016899f118edd5b3b22ac4fa6354/docs/load-testing-results.md#1-xml-throughput---sectionmixed-production-60-sqlite).

| Test server | XML handler | Workload | Requests/sec | Median / p95 |
| --- | --- | --- | ---: | ---: |
| 1 vCPU / 1 GB RAM | PHP-FPM | 100 dialplan requests, 5 at a time, 3 runs | 39.74–42.90 | 74.3–86.7 / 115.5–127.0 ms |
| 1 vCPU / 1 GB RAM | PHP-FPM | 100 mixed requests, 5 at a time, 3 runs | 39.20–41.16 | 76.7–90.7 / 121.4–129.9 ms |
| 2 vCPU / 2 GB RAM | PHP-FPM | 100 mixed requests, 5 at a time, 3 runs | 61.85–66.99 | 46.7–60.2 / 73.6–89.6 ms |
| 2 vCPU / 2 GB RAM | PHP-FPM | 500 mixed requests, 25 at a time | 82.37 | 181.4 / 293.8 ms |
| 2 vCPU / 2 GB RAM | Octane | 100 mixed requests, 5 at a time, 3 runs | 297.62–320.38 | 8.9–9.2 / 13.2–16.3 ms |
| 2 vCPU / 2 GB RAM | Octane | 500 mixed requests, 25 at a time | 381.45 | 31.9 / 56.3 ms |

Each HTTP request in this test is a lookup. Use the call completion table to evaluate the full calling workload.

## FreeSWITCH Runtime Database

The database tests offered calls for 60 seconds while generating phone registrations, one voicemail call per second, and polling FreeSWITCH status once per second. The 1 vCPU runs offered two registration requests per second. The 2 vCPU runs offered eight.

| Test server | XML handler | FreeSWITCH database | Offered calls/sec | Calls completed | SQLite busy warnings |
| --- | --- | --- | ---: | ---: | ---: |
| 1 vCPU / 1 GB RAM | PHP-FPM | SQLite | 2 | 120 of 120 | 0 |
| 1 vCPU / 1 GB RAM | PHP-FPM | MariaDB | 2 | 114 of 120 | 0 |
| 2 vCPU / 2 GB RAM | PHP-FPM | SQLite | 8 | 480 of 480 | 10 |
| 2 vCPU / 2 GB RAM | PHP-FPM | MariaDB | 8 | 480 of 480 | 0 |
| 2 vCPU / 2 GB RAM | PHP-FPM | SQLite | 10 | 333 of 600 | 4 |
| 2 vCPU / 2 GB RAM | PHP-FPM | MariaDB | 10 | 417 of 600 | 0 |
| 2 vCPU / 2 GB RAM | Octane | SQLite | 10 | 583 of 600 | 19 |
| 2 vCPU / 2 GB RAM | Octane | MariaDB | 10 | 600 of 600 | 27[^driver] |
| 2 vCPU / 2 GB RAM | Octane | MariaDB | 12 | 526 of 720 | 6[^driver] |

[^driver]: The original report flagged SQLite warnings in both Octane runs configured for MariaDB. Octane loads the database setting at startup, so an already running worker can continue serving FreeSWITCH configuration with the previous driver. After changing `FS_DB_DRIVER` in `/var/www/tallpbx/.env`, restart the Octane worker if it is running and restart FreeSWITCH. Check the active driver before comparing runs.

The 2 vCPU MariaDB configuration also passed the functional and audio checks, including registration, internal and outbound calls, recording, voicemail, ring groups, conferencing, and call forwarding. [Database setup](operations.md#freeswitch-runtime-database-sqlite-default-vs-mariadb) · [Data with latency, registrations, and call records](../load-testing/results/consolidated/refresh-20261117-cells.csv).
