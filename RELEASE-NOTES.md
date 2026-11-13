# TallPBX Release Notes

Plain-language notes for every release, newest first. Each section is a short
summary written for administrators and end users; the complete record, with
every operational detail (migrations, `.env` keys, service restarts), lives in
[CHANGELOG.md](CHANGELOG.md), which remains the single source of truth. A new
release prepends its section here at cut time; the GitHub Release body for a
tag is its section from this file.

## v5.2.0 (2026-11-12)

- New optional `.env` key `PHP_FPM_MAX_CHILDREN` pins the PHP worker ceiling so a published benchmark tier can be reproduced on differently sized hardware; left unset, nothing changes.
- After a restore, `appliance:sync-host` now brings the whole PHP-FPM pool to the documented shape — and reports failure honestly instead of showing a green tick while skipping bad writes. It also keeps `.env` ownership intact when it rewrites a value.
- Load-test cells labelled "shipped default" now really apply the shipped worker-recycling bound, so measurements match what they claim.
- All Composer dependencies refreshed to their latest stable releases on PHP 8.5.
- The optional telephony worker now runs on the current RoadRunner v3 engine (Octane gained RoadRunner 3 support in v2.23); it previously ran a 2024-era binary.
- Every published tag now becomes a GitHub Release automatically, with plain-language notes (this file); changelog history older than 4.3.0 moved to an archive file.

## v5.1.2 (2026-11-10)

- Added a side-by-side comparison of the default configuration against the opt-in performance mode to the load-testing results, covering the 1, 2 and 4 vCPU tiers, with three explanations of the same architecture: an everyday analogy for non-technical readers, a process-and-backlog model for sysadmins, and an in-memory lifecycle model for PHP developers.
- Fixed the ordering of two helper functions in the FreeSWITCH source installer so automated installs detect an already-built source tree correctly.

## v5.1.1 (2026-11-09)

- The call record ingestion path now reports its own health: a fallback counter and a health check make a broken ingestion path visible instead of hiding behind a normal-looking response.
- PHP-FPM pools now default to a dynamic configuration with a measured worker ceiling, so small hosts no longer reserve memory for idle workers.
- Aligned the agent instructions, IDE rules and project skills across every tool configuration file.
- Fixed the Let's Encrypt port-80 verification vhost and brought the source-built `mod_json_cdr` module to parity with the packaged one.
- Fixed staging paths in the load-testing harness that broke remote SIPp runs and artifact collection.

## v5.1.0 (2026-11-08)

- Call records can now be posted to a fast entry point that does not boot the full application, lowering the cost of every CDR post.
- The fast entry point fails open again: a fault re-serves the request through the normal application instead of losing the record.
- The installer now refuses to write XML bindings that would crash FreeSWITCH at load.
- Retired two implementation-plan documents whose work had shipped and been measured.

## v5.0.0 (2026-11-05)

TallPBX 5.0 is a **breaking major release**. It requires a fresh install; there is no in-place upgrade from 4.x.

**Performance and call capacity**

- FreeSWITCH XML lookups now run through a three-tier ladder: the shipped fast front controller first, an optional always-on telephony worker second, and the full application as a logged last resort — a slow tier can no longer stall call setup.
- The optional resident telephony worker (`tallpbx-xml-worker`) ships disabled; enabling it is one command and roughly quadruples lookup throughput on a 1 vCPU host.
- The FreeSWITCH event listener subscribes only to essential call events by default, freeing 20–25% of the listener's CPU, and production logging defaults reclaim a further 15–20% during call bursts.
- The capacity-scaling guide and the whole load-testing harness now live under `load-testing/` with reproducible configuration matrices and committed results.

**Call detail records**

- The module is renamed to call-detail-records, records one row per call leg, plays back recordings through a secure tenant-scoped route, filters the list by call side, and exports CSV behind its own permission — with protection against spreadsheet formula injection.
- The listing now sorts on a matching database index instead of sorting every page in memory.

**Reliability**

- A cached tenant identity can no longer outlive an ownership change: moving a SIP account between tenants takes effect immediately.
- A host sync after a restore no longer wipes every Redis database — signed-in administrators stay signed in and no call records are lost.
- Dashboard active-call counts decrement on hangup again, and call records are required to carry a call reference number, enforced by the database.

**Removed**

- The experimental Lua XML cache proxy was retired after benchmarking; its design and measurements are kept in the documentation and its code remains in the repository history.
