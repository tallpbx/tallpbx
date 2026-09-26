# Changelog

All notable changes to TallPBX will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Automatic Telephony Dialplan Cache Invalidation**:
  - Implemented `App\Observers\RoutingCacheObserver` to automatically observe all 29 PBX telephony models across the modular architecture (including Extensions, SIP Accounts, Inbound and Outbound Routes, Ring Groups, IVR Menus, Time Conditions, Call Flows, Bridges, Follow Me, Voicemail, Conferences, Queues, Call Forwarding, Emergency Routes, Dialplans, Number Translations, and Tenant Limits).
  - Automatically bumps the tenant's atomic routing version (`RoutingCacheVersion::bump($tenantId)`) in Redis whenever any telephony record is created, updated, deleted, or restored.
  - Resolves nested parent tenant ownership automatically for related child models (such as `DialplanDetail` to `Dialplan`, `RingGroupExtension` to `RingGroup`, `IvrMenuOption` to `IvrMenu`, and `Tier` to `Queue`).
  - Detects dirty tenant reassignments on model updates, invalidating active caches for both the originating and destination tenants simultaneously.
  - Completely eliminates dialplan stale-cache windows and propagation delays when changes are saved in the web panel, ensuring new routing instructions take effect on the very next call attempt without waiting for the XML cache TTL to expire.
  - In-flight active calls continue uninterrupted because FreeSWITCH executes instructions pre-compiled into channel variables during call setup (`CS_ROUTING`).
- **Streamlined Telephony XML Cache Settings (`XML_CACHE_TTL` & `XML_CACHE_STORE`)**:
  - Streamlined telephony XML cache configuration in `config/freeswitch.php`, `.env`, and `.env.example` to use concise `XML_CACHE_*` naming (e.g. `XML_CACHE_TTL`, `XML_CACHE_STORE`, `XML_CACHE_DIALPLAN_TTL`, `XML_CACHE_CONTRIBUTOR_TTL`, `XML_CACHE_DIRECTORY_TTL`, `XML_CACHE_ACL_TTL`, `XML_CACHE_DIALPLAN_STORE`, `XML_CACHE_DIRECTORY_STORE`, `XML_CACHE_ACL_STORE`).
  - Introduced `XML_CACHE_TTL` as the master default cache TTL (in seconds) for all dynamically rendered FreeSWITCH XML responses (dialplans, directory auth, contributor fragments, and ACLs).
  - Cascades the master TTL automatically to all granular caches unless an explicit granular override is uncommented in `.env`.
  - Introduced `XML_CACHE_STORE` as the master cache store setting (defaulting to `redis`), automatically cascading across all XML handler caches.
  - Formatted `.env` and `.env.example` with the master TTL (`XML_CACHE_TTL=5`) and store (`XML_CACHE_STORE=redis`) active, leaving granular overrides cleanly commented out with plain-language explanations.
  - Updated installer (`scripts/resources/tall.sh`), cache benchmark sweep tool (`scripts/run-cache-sweep.sh`), test suites, and all documentation (`README.md`, `INSTALL.md`, `docs/load-testing-guide.md`, `docs/load-testing-results.md`, `AGENTS.md`) to reflect the streamlined naming convention.
- **Cloud Datacenter 4 vCPU Dedicated / 16 GiB RAM Empirical Benchmarks**:
  - Documented empirical September 24, 2026 single-server dynamic dialplan XML throughput ladder (`100 x 5`, `500 x 25`, `1,000 x 25`) and 5-tier cache optimization sweep results on the 4 vCPU Dedicated / 16 GiB enterprise node (`pm = static`, `pm.max_children = 24`).
  - Achieved **65.32 req/sec** dynamic XML throughput with tail latency capped under **448 ms** across 1,000 sustained requests (a >4x throughput increase over single/dual-core baselines).
  - Documented complete server-to-server SIP call capacity ladder (2, 5, 10, 15, 20, 25, 30 CPS) across 2,110 total calls, achieving a **100% completion rate with ZERO failed calls** (zero drops, zero timeouts, zero `403 Forbidden` errors).
  - Measured instantaneous sub-180ms median setup latency from 2 to 15 CPS (p95 under 472 ms), sustaining peaks of 301 concurrent sessions and 55 sessions/second without degradation.
- **Cloud Datacenter 2 vCPU / 2 GiB RAM Empirical Benchmarks**:
  - Documented empirical September 24, 2026 single-server dynamic dialplan XML throughput ladder (`100 x 5`, `500 x 25`, `1,000 x 25`) and 5-tier cache optimization sweep results on the resized dual-core cloud VPS (2 vCPU / 2 GiB RAM, `pm = static`, `pm.max_children = 6`).
  - Documented streamlined server-to-server SIP call capacity ladder (2, 5, 10, 15, 20 CPS) measuring call setup delay, queueing dynamics, and saturation boundaries under sustained concurrency.
  - Empirically demonstrated that adding a second compute core unlocked an 89% answer rate (178/200 answered calls) at 10 CPS under concurrency 50 (compared to 1-vCPU failure ceilings at 5–8 CPS), confirming multi-core concurrency scaling for telephony workloads.
- **Cloud Datacenter 1 vCPU (1 GiB & 2 GiB RAM) Empirical Benchmarks**:
  - Documented empirical September 24, 2026 single-server dynamic dialplan XML throughput ladder (`100 x 5`, `500 x 25`, `1,000 x 25`) and 5-tier cache optimization sweep results on both minimal (1 vCPU / 1 GiB RAM) and memory-scaled (1 vCPU / 2 GiB RAM, `pm = static`, `pm.max_children = 6`) cloud VPS instances.
  - Documented complete 14-scenario telephony feature parity (100% passed) and server-to-server SIP call capacity ladder (3, 5, 8, 10 CPS) on the memory-scaled 1 vCPU / 2 GiB cloud VPS.
  - Added progressive disclosure expandable appendices with complete percentile distributions (`p50`, `p90`, `p95`, `p99`, `std_dev`) and multi-run repetitions (`r1–r3`).
  - Added multi-interface comparison analyzing latency differences across public IPv4, private datacenter IPv4, and native dual-stack public IPv6.
  - Empirically proved that scaling physical RAM from 1 GiB to 2 GiB completely eliminates swap activity (0 MiB swap) but leaves dynamic XML throughput (~14–19 req/sec) and call setup capacity (3 CPS clean baseline, 5 CPS saturation boundary) constant, confirming single-core CPU compute saturation.

### Removed
- **1.x Backward Compatibility Fallbacks**:
  - Removed all legacy 1.x `FREESWITCH_XML_HANDLER_*` cache configuration fallbacks from `config/freeswitch.php`, standardizing exclusively on the canonical `XML_CACHE_*` settings (`XML_CACHE_TTL`, `XML_CACHE_STORE`, and granular subsystem overrides).
  - Removed legacy 1.x `/smtp-connector` redirect in the Email Connector module routes (`app-modules/email-connector/routes/web.php`).
  - Removed legacy `FREESWITCH_XML_HANDLER_*` environment variable sed mutations and assertions from `scripts/run-cache-sweep.sh` and `tests/Feature/PbxCacheSweepScriptTest.php`.
  - Removed legacy backwards-compatibility test assertions in `tests/Feature/XmlHandlerCacheConfigTest.php` and updated `phpunit.xml` to define canonical `XML_CACHE_STORE` and `XML_CACHE_ACL_STORE`.
- **VirtualBox Test Baseline Removal**:
  - Removed all legacy VirtualBox test tables, benchmarks, and VM references from `docs/load-testing-results.md` and `docs/load-testing-guide.md`, standardizing all documentation and sizing recommendations entirely on the comprehensive September 2026 cloud datacenter benchmarks.
  - Renamed and generalized the VirtualBox recovery runbook into a universal `SIPp Load Testing Preflight & Recovery Runbook` using standard environment placeholders (`<PBX_IP>`, `<GENERATOR_IP>`, `load-test-beta`).
- **Legacy Windows VM & WSL2 Reference Removal**:
  - Removed obsolete Windows-hosted VM test warnings and WSL2 load-generator references from `README.md`, `docs/load-testing-guide.md`, and `AGENTS.md`.
- **Environment B1 July–August 2026 Historical Archive**:
  - Removed superseded prototype benchmark runs from `docs/load-testing-results.md` (tested over high-jitter WAN WireGuard on older prototype software) in favor of the clean September 24, 2026 empirical production dataset.

### Changed
- **Major Version 2.x Incompatibility & Re-install Requirement**:
  - Explicitly documented in `INSTALL.md` and `scripts/bootstrap.sh` that TallPBX 2.x is **not 100% backwards compatible** with the 1.x release series (`1.0`, `1.1`).
  - Stated that in-place updates from 1.x to 2.x are unsupported and will require a clean re-install, while in-place updates remain supported strictly within the same release series.
- **Branching Model Adoption (Laravel Versioned-Series Model)**:
  - Formally adopted the Laravel framework versioned-branch model (`1.0`, `1.1`, `2.0`) in place of maintaining a perpetual `main` branch. Active development occurs directly on the active major/series branch (`2.0`), with previous branches serving as maintenance lines (`1.0`, `1.1`).
  - Added a dedicated "Versioning & Release Strategy" section to `README.md` explaining the Laravel versioned-series branch model and Semantic Versioning (`MAJOR.MINOR.PATCH`).
  - Updated bootstrap installer and documentation (`scripts/bootstrap.sh`, `INSTALL.md`, `tests/Feature/BootstrapInstallerTest.php`) to target the `2.0` release series by default.
  - Enhanced the web panel GitHub updater (`GitUpdate.php` and `git-update.blade.php`) to fall back to the highest stable release branch (`$this->stableBranches[0] ?? '2.0'`) and conditionally display the Development Channel card only when development branches exist.
  - Escaped git log format arguments in `GitUpdateService::incomingCommits()` to prevent shell pipe evaluation errors.
- **PHP-FPM Worker Tuning Guidance (1 GB, 2 GB, 4 GB Tiers)**:
  - Updated `README.md` to document the installer auto-configuration profiles and sizing table across 1 GB minimal (`pm = dynamic`, 5 workers), 2 GB small (`pm = static`, 6 workers), and 4 GB+ standard (`pm = static`, 12 workers) deployments.
- **Benchmark Summary Matrix Clarification (2 vCPU / 2 GiB Latency Context)**:
  - Updated Table 1 in `docs/load-testing-results.md` to distinguish calm baseline setup latency (~1.1s at 2 CPS) from high-concurrency burst queueing delay (~4.0s p50 / ~9.5s p95 at 5 CPS with concurrency limit 25).
  - Added an explanatory note detailing PHP-FPM worker queueing under 25 in-flight calls contending for 6 static workers.
- **Project Positioning & Audience Duality ("Modern Simplicity / Enterprise Engineering")**:
  - Embedded the core philosophy ("Modern simplicity on the surface, enterprise-grade telecommunications engineering under the hood") into the project guidelines within `AGENTS.md` and `.agents/skills/tallpbx-custom/SKILL.md`.
  - Added the official tagline to the guest landing page (`resources/views/pages/home.blade.php`, `lang/en/home.php`, `lang/es/home.php`, `lang/fr/home.php`).
  - Added tailored, context-specific introductions across `README.md` (Parity with FusionPBX & FreePBX®), `INSTALL.md` (guided setup for general IT staff), and `docs/load-testing-results.md` (clear capacity guidance for administrators).
- **Documentation Split: Operational Testing Guide vs. Empirical Benchmark Results**:
  - Extracted all empirical benchmark measurements, latency curves, multi-run repetition tables (`r1–r3`), 5-tier cache hit-rate sweeps, capacity ladders, and hardware sizing matrices from `docs/load-testing-guide.md` into a dedicated companion document: `docs/load-testing-results.md`.
  - Focused `docs/load-testing-guide.md` purely on operational testing procedures, prerequisites, lab topology, seeding commands, test runner parameters, and troubleshooting runbooks, retaining an executive sizing matrix with direct links to `docs/load-testing-results.md`.
  - Updated cross-references across `README.md`, `docs/operations.md`, and `AGENTS.md`.
- **Simplified Latency Terminology & Raw Sample Retention (Average, Min, Max)**:
  - Replaced statistical percentile terms (`p50`, `p95`, `p99`) across user-facing documentation, guides, sweep scripts, console summaries, and test assertions in favor of straightforward `average`, `min` (fastest), and `max` (slowest) metrics.
  - Preserved complete individual latency measurements under `latency_ms.raw_samples` in generated JSON reports, retaining full statistical reproducibility and enabling on-demand calculation of percentiles (`p50`, `p95`, `p99`) via `PbxDialplanLoadTestCommand::percentile()` whenever needed.
  - Updated `README.md` to recommend `--max-average-ms` and define metrics in plain language.
  - Updated `scripts/run-cache-sweep.sh` and `docs/load-testing-guide.md` to report and parse `Avg (ms)` instead of `p50`.
  - Updated `docs/load-testing-results.md` benchmark tables to standardize on `Average Latency`, `Fastest`, and `Slowest`.
  - Updated test fixtures in `tests/Feature/PbxDialplanLoadTestCommandTest.php` and `tests/Feature/PbxCacheSweepScriptTest.php` to assert average latency and verify raw sample preservation.
- **Load Testing Readability & Terminology Overhaul**:
  - Eliminated abstract stage, phase, and lettered environment codes (`Environment A1/B1/B2/B3/C1/C2/D`, `Stage 1–6`, `Phase 1–2`) across `docs/load-testing-results.md` and `docs/load-testing-guide.md` in favor of clear, domain-driven organization (`Local Lab: VirtualBox`, `Cloud VPS: Entry Baseline`, `Cloud VPS: Dual-Core`, `Dedicated Cloud Node`, etc.).
  - Replaced cryptic cache sweep notation (`C=5, D=0`, `D=5, C=5`) with explicit plain-language descriptions (`Contributor TTL: 5s, Dialplan TTL: 0s`).
  - Added expandable `<details><summary>` definitions tables under all benchmark and parity tables explaining metrics, headers, and `.env` cache settings in simple administrative terms.
  - Added a dedicated "How to Read These Benchmark Tables" glossary defining `<Total Requests> x <Concurrency>` notation, repetitions and median reporting, the purpose of `25 x 1` warm-up runs, and the difference between `mixed` and `cache-hit` scenarios.
  - Added an explanatory note in the 5-tier cache sweep explaining why Row 5 ("Pure Memory Cache-Hit Ceiling") measures pure memory throughput with 0 database queries compared to Row 3 ("Production Baseline") despite identical request counts.
  - Consolidated raw repetition rows (`r1`, `r2`, `r3`) across datacenter benchmarks into clean summary tables, preserving detailed per-repetition runs in collapsible detail blocks.
  - Streamlined the remaining benchmarking matrix by skipping the 2 vCPU / 2 GiB dedicated test to eliminate redundancy, focusing on the 2 vCPU / 2 GiB shared droplet and escalating directly to the 4 vCPU / 8 GiB dedicated tier.

## [1.1.4] - 2026-09-23

### Added
- **Automated Memory-Based PHP-FPM Pool Sizing**:
  - Added `configure_php_fpm_pool()` in `scripts/resources/php.sh` to automatically detect host RAM and configure static PHP-FPM worker pools in `/etc/php/<version>/fpm/pool.d/www.conf` (12 static workers for 4GB+ RAM, 6 static workers for 2GB RAM, 5 dynamic workers for 1GB RAM) to eliminate worker starvation and HTTP 502/504 gateway timeouts under concurrent FreeSWITCH call bursts.
  - Added automated test coverage in `tests/Feature/InstallerDefaultsTest.php` ensuring installer scripts configure static worker pools based on memory.
  - Added test coverage in `tests/Feature/PbxCacheSweepScriptTest.php` verifying the `scripts/run-cache-sweep.sh` runner syntax, standard sweep tiers, Redis metrics, and exit cleanup traps.
- **Default Telephony XML Cache TTLs**:
  - Updated `scripts/resources/tall.sh` to explicitly write `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5`, `FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=5`, and `FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_TTL=5` to `.env` during setup.
- **SIP Load Testing & Multi-Host Reproducibility Suite**:
  - Added `scripts/run-cache-sweep.sh` to automate the 5-tier XML handler cache hit-rate sweep (cold baseline, contributor cache, 5s burst, 30s call-center, and memory hit ceiling) with automated production `.env` restoration.
  - Added dedicated `SIP Load Testing & Multi-Host Reproducibility Guidelines` in `AGENTS.md` and `docs/load-testing-guide.md` documenting critical lab operational controls: public IP obfuscation, mandatory parallel Pest execution (`--parallel`), SSH remote password escaping, cross-host CSV synchronization, load generator firewall ingress whitelisting (`nftables`), FreeSWITCH loopback channels for internal bridges, background UAS port collision cleanup, safe UAC port allocation, PHP-FPM static pool sizing, and registration expiry lease management.
  - Added unit test coverage for all 17 custom SIPp XML scenarios in `tests/Feature/PbxSippValidationScriptTest.php`.

### Changed
- **Documentation Updates for Telephony Cache & Pool Tuning**:
  - Updated `README.md` with a new `Telephony XML Cache Tuning & Hit Rate Sweep` section explaining the 3-tier caching architecture, the `scripts/run-cache-sweep.sh` tool, Redis keyspace metrics, and `.env` profile recommendations.
  - Updated `INSTALL.md` with auto-configured PHP-FPM pool sizing details and XML handler caching in the Redis section.
  - Updated `docs/operations.md` with a `Telephony Performance & Cache Verification` subsection including Redis hit rate checks, cache sweep benchmarks, PHP-FPM saturation diagnostics, and SIPp validation commands.
  - Updated `docs/load-testing-guide.md` with a dedicated `Production Recommendations: XML Caching & PHP-FPM Worker Tuning` subsection, a clarifying note on why FreeSWITCH is bypassed in the XML test chain, and updated Phase 1 and Phase 2 status tables reflecting September 23, 2026 completion.
- **Alphanumeric Default Password for PBX Load Testing**: Changed default password from `LoadTest1234!` to alphanumeric `LoadTest1234` across `PbxLoadTestSeedCommand`, `scripts/pbx-sipp-validate.sh`, `README.md`, and load testing documentation to prevent Bash history expansion and subshell stripping over SSH that caused `403 Forbidden` on SIP `REGISTER`.
- **SIPp Registration Expiry & Refresh**: Increased SIP registration lease duration in `tools/sipp/register.xml` and `tools/sipp/register-uas-auto-answer.xml` from 300s to 3600s, and added a pre-extended re-registration step in `scripts/pbx-sipp-validate.sh` to prevent mid-run lease expiration during long multi-phase validation suites.
- **SIPp Blocked Call ACK Handling**: Updated `tools/sipp/uac-call-block.xml` to immediately acknowledge `603 Decline` with an `ACK`, allowing SIPp and FreeSWITCH to cleanly complete blocked call transactions without retransmission delays.
- **Load Testing Documentation & Sizing Guidance**: Updated `docs/load-testing-guide.md` with fresh September 23, 2026 VirtualBox baseline measurements, single-server XML throughput metrics, 5-run cache sweep results, and an executive Production Sizing & Configuration Matrix with PHP-FPM static worker formulas.

### Removed
- **Superseded Load Testing Documents**: Removed `docs/call-simulation-load-testing.md` and `docs/sipp-server-to-server-validation.md` after consolidating all testing methodologies, commands, runbooks, metrics, and troubleshooting data into the unified, self-contained `docs/load-testing-guide.md`.
- **Completed One-Line Installer Design & Implementation Plan**: Removed `docs/design-document-one-line-installer.md` and `docs/one-line-installer-implementation-plan.md` following the completed implementation, verification, and release of the one-line bootstrap installer in v1.1.3.

### Fixed
- **Call Forward Dialplan Bridge Channel Failure**: Changed internal FreeSWITCH dialplan bridges in `CallForwardService.php` from direct raw extensions to loopback channels (`loopback/${safeDest}/${context}`), resolving `Cannot create outgoing channel ... cause: [CHAN_NOT_IMPLEMENTED]` during call forwarding.
- **SIPp Validation Port Collisions**: Resolved `errno 98 (Address already in use)` port collisions in `scripts/pbx-sipp-validate.sh` by cleanly terminating prior UAS listeners between validation phases, and shifted `EXTENDED_UAC_LOCAL_PORT` default from 5076 to 5100 to avoid conflicting with FreeSWITCH Sofia external (5080) and gateway UAS (5088) ports on multi-role hosts.

## [1.1.3] - 2026-09-23

### Added
- **Explicit Password Requirements & Guidance in UI Forms**:
  - Explicitly displayed password requirements and confirmation guidance up front across all user and administrator forms, preventing users from having to encounter unexpected validation errors upon form submission.
  - Added "Minimum 8 characters" label badges (`label-text-alt`) and persistent helper copy ("Must be at least 8 characters.") on Administrator Create/Edit (`admins-edit`), User Create/Edit (`users-edit`), First Administrator Setup (`initial-admin-setup`), Profile Password Change (`profile-edit`), and Tenant User Register and Reset Password (`register`, `reset-password`).
  - Added "Leave blank to keep current password, or enter at least 8 characters to set a new one." guidance when editing administrators or users.
  - Added "Must match password" label badges and "Re-enter password to confirm." helper copy to password confirmation fields.
  - Added translated keys for all guidance strings across English (`lang/en/`), Spanish (`lang/es/`), and French (`lang/fr/`).
- **Administrative Target Audience & Plain-Language Copy Standards**:
  - Established project-wide guidance in `AGENTS.md` and `.agents/skills/tallpbx-custom/SKILL.md` requiring all user-facing copy — including installer prompts, Artisan commands, web UI labels, form helper text, tooltips, validation messages, and documentation — to be descriptive, friendly, and accessible for administrators who may not be technical telephony or Linux experts.
  - Codified the standardized interactive prompt architecture (`<Field Label> [<default_number>]: `) with Option 1 always designating the recommended production baseline.
- **FreeSWITCH Alternate Sound Prompt Languages & Management Commands**:
  - Added an interactive preflight sound prompt language questionnaire to `scripts/install.sh`, allowing operators to install additional language sound packs (Spanish Mario, French June) alongside default English Callie, and select the system-wide default prompt language.
  - Implemented `FreeSwitchSoundManager` and dedicated Artisan commands `pbx:sounds:list`, `pbx:sounds:install {language} [--default]`, and `pbx:sounds:default {language}` to inspect, install, and switch FreeSWITCH default prompt languages on running systems.
  - Added automatic installation of sound packages and grammar say modules (`freeswitch-sounds-es-ar-mario`, `freeswitch-sounds-fr-ca-june`, `freeswitch-mod-say-es`, `freeswitch-mod-say-fr`) in `scripts/resources/freeswitch.sh`, pre-enabled say modules during source builds in `enable_tallpbx_source_modules()`, and added `configure_freeswitch_sound_defaults()` to configure `vars.xml`.
  - Added comprehensive operational guides in `docs/operations.md` for sound prompt languages across package and source installations, and for transactional outgoing email delivery.
- **Standardized 5-Pillar Test Suites Across Top-10 Modules (Phases 1–3)**: Implemented comprehensive 5-pillar test suites across all 10 highest-risk PBX, routing, and telephony modules (`sip-trunks`, `gateways`, `inbound-routes`, `outbound-routes`, `extensions`, `dialplans`, `ring-groups`, `call-centers`, `conferences`, `voicemails`). Standardized coverage includes Service CRUD operations and FreeSWITCH XML generation, Livewire List and Edit component workflows, multi-tenant list scoping, cross-tenant edit IDOR defenses (`assertCanAccessTenantRecord`), `TenantMutationGuard` creation/mutation enforcement, and Livewire action permissions (327 tests, 854 assertions). Added `grantTenantUserPermissions()` test helper to `tests/Pest.php`.
- **One-Line Bootstrap Installer**: TallPBX can now be installed with a single command (`wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash`), which prepares the source code in `/var/www/tallpbx` and starts the standard installer. The latest `main` branch is installed by default; `--ref` pins a release branch or tag such as `1.1`. Re-running the command safely updates an existing working copy before re-running the installer. Replaces the inactive `scripts/bootstrap.sh.example` reference template.
- **Automatic IPv4 Preference When the Host Has No IPv6 Default Route**: The installer now detects hosts that advertise IPv6 without a working default route and activates the IPv4 precedence rule in `/etc/gai.conf` automatically, preventing Composer download timeouts that previously required a manual fix.

### Changed
- **Standardized Module Composer Package Names**:
  - Unified all 59 internal modules under the canonical `tallpbx/module-*` package naming scheme across their respective `composer.json` files and root `composer.json`.
  - Renamed the 25 legacy un-prefixed core modules (`tallpbx/<name>` to `tallpbx/module-<name>`), establishing complete naming consistency across the entire modular architecture and aligning with the `php artisan make:module` generator convention.
  - Synchronized `composer.lock` and updated path repository test assertions in `tests/Feature/ModuleAutoloadTest.php`.
- **Standardized Installer Interactive Prompt Formatting**:
  - Unified all 8 multiple-choice interactive prompts in `scripts/install.sh` to a consistent, intuitive `<Keyword 1> or <Keyword 2> [<default_number>]: ` (or `<Key phrase> [<default_number>]: `) convention (`Clean or demo [1]: `, `Production or development [1]: `, `Packages or source [1]: `, `Setup method [1]: `, `Voice prompt languages [1]: `, `Default language [1]: `, `Random or custom [1]: `, and `Keep or recompile [1]: `), ensuring opening option words match the prompt verbatim for instant at-a-glance clarity without requiring administrators to read dense paragraphs.
  - Added support for typed keyword aliases (`clean`, `demo`, `production`, `development`, `packages`, `source`, `setup`, `activation`, `trusted`, `random`, `custom`, `keep`, `recompile`) alongside standard numerical inputs while preserving unattended/headless environment variable workflows.
- **Documentation Restructuring for Outgoing Mail & Post-Installation Workflow**: Replaced the misplaced Outgoing Mail subsection in `INSTALL.md` with a clean 4-point Post-Installation Next Steps checklist (Panel Login, HTTPS, Outgoing Mail, and Carriers/Trunks), and moved in-depth operational guidance for SMTP, Google Workspace OAuth 2.0, Microsoft 365 OAuth 2.0, and the `tallpbx-queue` background worker to `docs/operations.md`.
- **Switchable Light & Dark Theme Documentation & Parity Comparisons**: Enhanced documentation across `README.md`, `docs/parity-comparison.md`, and `docs/ui-tour.md` detailing the switchable Light, Dark, and System theme architecture:
  - Documented zero-flicker client hydration via synchronous `localStorage` reading in document `<head>` prior to render (eliminating FOUC) and asynchronous database persistence to user/admin profiles.
  - Added architectural and ergonomic comparisons against FusionPBX and FreePBX, contrasting TallPBX's instant runtime theme toggles with legacy monolithic stylesheets and static light-only interfaces to support 24/7 Network Operations Centers (NOCs) and dispatchers working low-light shifts.
- **Security Architecture & Documentation Stale Content Audit**: Audited repository documentation to eliminate stale content and simplify technical terminology:
  - Replaced obsolete "stages" and "pipeline-aligned workbenches" designations across `docs/security-architecture.md` and `docs/ui-tour.md` with clear descriptions of active UI cards (Blacklist, Blocked Attackers, Whitelist, and Firewall Rules).
  - Added plain-language explanations of "atomic" (all-or-nothing) operations in `docs/security-architecture.md`, `README.md`, and `docs/parity-comparison.md`.
  - Replaced outdated `ufw` firewall troubleshooting commands in `docs/pbx-hello-world.md` with native TallPBX Security Command Center (`/panel/security`) and `nftables` instructions.
  - Corrected the CLI vs. Web comparison matrix in `docs/security-architecture.md` to reflect the modern unified single-page interface rather than obsolete tabs and modal wizards.
  - Removed redundant "Ingress" and "Egress" labels from packet flow section headings, and rewrote the sliding-window detection explanation in plain English.
- **Installation Guide Simplified Around the One-Line Command**: INSTALL.md presents the bootstrap command as the primary install method, then was greatly condensed: the manual-installation, headless-run, and installer-questionnaire sections plus the VM platform line were removed, the service-management table and health-check/test commands moved to `docs/operations.md` with a pointer from the guide, and the maintenance and tuning sections were shortened. The former roadmap note about a future bootstrap installer was removed.
- **Installer Streamlining & User-Friendliness Polish**: Improved the installation experience across terminal scripts and documentation:
  - Added numbered step indicators (`[1/6] --- Step Name ---`) and total elapsed execution time to `scripts/install.sh`.
  - Upgraded the installation completion banner to a clean bordered card highlighting the web panel URL, status verification commands, demo credentials, and actionable next steps.
  - Simplified interactive prompts for database password generation and FreeSWITCH install method with clear plain-language recommendations.
  - Improved `scripts/bootstrap.sh` non-root error messaging with exact command examples.
  - Streamlined `scripts/resources/tall.sh` by removing redundant `.env` database writes and consolidating multiple `systemctl daemon-reload` calls into a single invocation after all units are copied.
  - Added visual comparison tables for hardware requirements and FreeSWITCH install choices, plus GitHub alert callouts (`[!TIP]`, `[!IMPORTANT]`, `[!CAUTION]`), and an install-time troubleshooting section in `INSTALL.md`.
  - Added CLI commands to `INSTALL.md` for resetting administrator credentials via Artisan (`php artisan admin:password`) and directly via MariaDB for emergency recovery.
  - Documented automatic Let's Encrypt certificate renewal mechanics (`certbot.timer`, 30-day window, twice-daily checks) and explained wildcard certificate use cases (multi-tenant hosting, firewall bypass).
  - Documented web panel UI updating (`/panel/git-update`) as the recommended upgrade procedure, framing terminal commands as manual alternatives.
  - Added post-installation troubleshooting to `docs/operations.md` for 502 web errors, file permission drift, FreeSWITCH startup/registration issues, and Redis restoration.

### Fixed
- **FreeSWITCH Module Configuration and Package Resolution in Installer**:
  - Fixed variable scoping and section marker in `scripts/resources/freeswitch.sh` so `mod_say_es` and `mod_say_fr` are properly enabled under `<!-- Say -->` in `modules.conf.xml` during both package and source installations, and loaded dynamically at runtime.
  - Automatically enabled installed language say grammar modules in `modules.conf.xml` during `configure_freeswitch_sound_defaults()` whenever matching sound folders are present.
  - Pre-created the `freeswitch` system user and group before package installation in `scripts/resources/freeswitch.sh`, preventing Debian package unpack failures where systemd triggers unit actions before the service user exists.
  - Replaced the obsolete `freeswitch-sounds-music` package installation with `freeswitch-music-default` verification, preventing package not found errors and unnecessary package removal churn.
  - Fixed unsandboxed apt download warning by granting `0755` permissions on the temporary working directory during `freeswitch-mod-hiredis` compatibility package build.
  - Added clean newline separation before database password reuse, token reuse, and system upgrade messages in `scripts/install.sh`, preventing output from appending to interactive prompts in log files.
- **Installer Token Validation, Initial Administrator Prompting, and Host Dependencies**:
  - Sanitized SignalWire Personal Access Token resolution by stripping whitespace and quotes, ensuring that empty, whitespace, or empty-quoted tokens in the environment, state file, or apt auth file are treated as unset so operators are properly prompted instead of erroneously claiming to reuse a token.
  - Fixed initial administrator mode selection in `scripts/install.sh` to always prompt interactively until administrator setup is marked completed, rather than bypassing the prompt on re-runs when a mode had been previously written to the installer state file.
  - Added `sudo` to core dependencies and ensured `/etc/sudoers.d` directory exists before installing `/etc/sudoers.d/tallpbx-security` in `scripts/resources/security.sh` on minimal Linux distributions.
  - Ensured FreeSWITCH configuration directory `autoload_configs` exists before writing `xml_curl.conf.xml` in `scripts/resources/freeswitch.sh`.
- **Multi-Tenant Scoping and Access Enforcement in Edit Forms**: Auto-resolved tenant context for non-admin users during mount and save, and added explicit `assertCanAccessTenantRecord()` verification before updating existing records in `QueueEdit`, `ConferencesEdit`, `VoicemailsEdit`, and `RingGroupsEdit`.
- **Multi-Tenant Query Scoping in List Components**: Scoped list queries to the active tenant for tenant users in `QueueList`, `ConferencesList`, and `VoicemailsList` while allowing administrators to view records across all tenants.
- **VoicemailsList Deletion Error Handling**: Re-threw `HttpException` before catching general `RuntimeException` in `VoicemailsList::deleteVoicemail()`, ensuring `TenantMutationGuard` 403 cross-tenant access denial propagates correctly instead of being swallowed.
- **ExtensionsBulkCreate Permission & Multi-Tenant Hardening**: Enforced explicit Livewire action permission gating requiring `extensions.create` for `save` operations on `ExtensionsBulkCreate`, and auto-resolved tenant context for tenant users prior to validation.
- **SipTrunkService Mutation Guard Compliance**: Updated `SipTrunkService` update and delete operations to invoke Eloquent model methods directly rather than query builder operations, ensuring model lifecycle hooks and `TenantMutationGuard` cross-tenant safety rules fire on tenant mutations.
- **Inbound & Outbound Route Index Permission Gating**: Added missing `admin.can:inbound-routes.view` and `admin.can:outbound-routes.view` middleware to index route definitions in `app-modules/inbound-routes/routes/web.php` and `app-modules/outbound-routes/routes/web.php`.
- **Headless Installer Credential Hang**: Non-interactive installer runs in "create administrator during installation" mode without saved credentials now stop with clear guidance instead of waiting forever at the password prompt.

## [1.1.2] - 2026-09-20

### Added
- **Redis Outage & Decoupled Cache Store Architecture Documentation**: Documented the architectural relationship between Redis, application cache drivers (`CACHE_STORE`), and real-time attack protection in `docs/security-architecture.md` and `INSTALL.md`:
  - Clarified that `SecurityIncidentService` connects directly to Redis via `Illuminate\Support\Facades\Redis`, operating completely independent of `.env` cache store configurations (e.g. `CACHE_STORE=file`).
  - Documented graceful degradation and fail-open behavior when Redis is stopped or uninstalled: failure tracking exceptions are caught and logged without disrupting calls or panel sessions, while kernel firewalling (`nftables`), whitelists, blacklists, and manual administrator bans remain 100% active.
- **Automated Kernel Ban Reconciliation & Safe Boot Recovery (Phase 3)**: The Security module now automatically reconciles Linux nftables dynamic ban sets (`@banned_ips`, `@banned_ips6`) with active MariaDB bans and restores missing filtering tables:
  - **Dynamic Ban Set Inspection**: The bounded root helper `/usr/local/sbin/tallpbx-security` provides a read-only `bans` action emitting native `nft -j` JSON output, parsed into typed structures by `SecurityExecutor::bans()`.
  - **Safe Ban-Set Reconciler**: `FirewallBanReconciler` diffs active database bans against live kernel elements. Missing bans are restored with remaining TTL, extraneous or expired kernel elements are pruned, and timeout skews exceeding 5 minutes are corrected. Strict safety rails enforce a 30-second minimum TTL threshold (preventing expiration race conditions) and a 50-action batch limit per run (preventing flap loops).
  - **Automated Boot Recovery**: If a host reboots without loading the `tallpbx_filter` table, the reconciler asserts loopback safety (`127.0.0.1`), validates ruleset syntax, reapplies the saved configuration, and records an enterprise audit event. Static ruleset content drift remains strictly alert-only.
  - **Reconciliation CLI & Scheduler**: Added `php artisan security:reconcile` (supporting `--dry-run`, `--recover-boot`, and `--json`), scheduled every 15 minutes in `routes/console.php`.
- **Cryptographic Firewall Ruleset Verification & Live Drift Detection (Phase 2)**: The Security module now enforces cryptographic synchronization between the declared MariaDB firewall configuration and the live Linux kernel ruleset:
  - **Ruleset Provenance & Canonical Digest**: Generated rulesets embed `# tallpbx-policy:` and `# tallpbx-digest:` SHA-256 headers. The canonical digest decouples dynamic kernel bans (`@banned_ips`, `@banned_ips6`) so ongoing intrusion events never cause false-drift flapping.
  - **Atomic Helper Execution & Post-Apply Verification**: The root-bounded helper `/usr/local/sbin/tallpbx-security` validates live kernel state post-apply and writes an atomic sidecar (`/etc/tallpbx/firewall.nft.applied`, mode `0640 root:www-data`) recording the verified digest, policy, and ISO apply timestamp. If an apply fails verification, any stale sidecar is deleted immediately.
  - **Automated CLI Verification Command & Scheduling**: Added `php artisan security:verify` supporting `--strict` (exit code 2 on unknown) and `--json` formatting, scheduled every 5 minutes in `routes/console.php` for continuous firewall integrity monitoring.
  - **Security Command Center UI Verification Indicators**: The Security Center header displays verified, drift, or unverified badges with relative timestamps and expands the drift alert banner if live ruleset content diverges from the database. Available in English, Spanish, and French.
- **IPv6 Dual-Stack Firewall Support**: The Security Center now manages IPv6 addresses end to end. The whitelist, blacklist, and manual ban forms accept IPv4 and IPv6 entries (including CIDR ranges such as `2001:db8::/64`), the ruleset compiler emits parallel `blacklist_ips6`, `banned_ips6`, and `whitelist_ips6` kernel sets with mirrored drop/accept rules at the same pipeline positions (`::1` is always trusted, mirroring `127.0.0.1`), and the bounded host helper routes IPv6 bans and unbans to the dedicated `banned_ips6` set. Bans created by the intrusion engine, the manual dialog, and the IP lists now work identically for both address families, and the interim "IPv6 not supported" refusal is removed. No database migrations are required; existing IPv6 entries in the database become enforceable as soon as the ruleset is re-applied. This closes the model gap scoped in the IPv6 dual-stack implementation plan (removed after completion; recover from git history if needed).

### Changed
- **Standardized Port Range Hyphen Syntax**: Standardized port range notation across the Security Center UI, port catalog seeds, and database models to use canonical hyphen syntax (`16384-32768`) instead of legacy colon syntax (`16384:32768`). Updated the `RTP Voice/Video Media` standard catalog default, input placeholders, and port range help tooltips across English, Spanish, and French. Added database migration `2026_09_20_000009_standardize_security_port_ranges_to_hyphens` to convert existing colon ranges in `security_services` and `security_rules` to hyphens, while retaining transparent input conversion in `SecurityManager` for administrators who submit colon ranges.
- **Firewall Rules Protocol and Port Columns**: Separated the single "Service / Port" column in the Firewall Rules table into two distinct columns labeled **Protocol** and **Port** across all table sections (Pre-Filters, Standard Services, Custom Rules, and Default Inbound Policy). The Protocol column displays the transport protocol (`TCP`, `UDP`, `TCP/UDP`, `ICMP`, or `ALL`), while the Port column displays the port range, ICMP type annotations, or port coverage (`All` for Pre-Filters). New translation keys `security_protocol`, `security_port`, and `security_all_ports` (`All`) are registered across English, Spanish, and French. No action is required for existing installations.
- **Blacklist & Whitelist Helper Decluttering**: Removed the redundant "Instant Drop" and "Complete Bypass" helper callout cards below the quick-add forms on the Blacklist and Whitelist cards, combining their kernel precedence and unrestricted bypass explanations into the respective card title info tooltips across English, Spanish, and French. No action is required for existing installations.
- **Firewall Rules Action Icons**: Standard Services and Custom Rules edit actions in the Firewall Rules table now both use the consistent pencil-square icon (`x-heroicon-o-pencil-square`) in a compact icon-only format, removing the redundant "Edit" text label. No action is required for existing installations.
- **Firewall Section Title**: The Security Center card previously titled "Firewall Rules & Port Access" is now simply **"Firewall Rules"** — the rules themselves are what control ports and access, so the longer name was redundant. The rename is reflected in English, Spanish, and French. No action is required for existing installations.
- **IP List Card Titles**: The Security Center cards previously titled "Blacklist IPs (Always Dropped)" and "Whitelist IPs (Always Allowed)" are now simply **"Blacklist"** and **"Whitelist"**. The rename is reflected in English, Spanish, and French. No action is required for existing installations.
- **Blacklist Card Cleanup**: Removed the redundant one-line description under the Blacklist card title — the same explanation is already shown in the card's info tooltip.
- **Blocked Attackers Card Cleanup**: Moved the one-line description under the Blocked Attackers title into the card's info tooltip, alongside the existing kernel-drop explanation.
- **Whitelist Card Cleanup**: Removed the redundant one-line description under the Whitelist card title — the same explanation is already shown in the card's info tooltip.
- **Security Center Header Cleanup**: Removed the redundant one-line description under the Security Center page title — the same explanation is already shown in the title's info tooltip.
- **Default Inbound Policy Configuration Form**: The "Firewall Status (Default Policy)" dropdown was removed from the Attack Protection Settings drawer and now has its own dedicated form, opened by the **Configure** button on the **Default Inbound Policy** row of the Firewall Rules table. Saving the form persists the policy and applies the firewall ruleset to the kernel immediately, reporting the outcome — including any zero-lockout safety refusal — in the top-right notification, while the drawer's Save button no longer touches the policy. No action is required for existing installations.
- **Loopback Trust Simplified**: The ruleset compiler no longer injects `127.0.0.1` and `::1` into the kernel whitelist sets. Genuine loopback traffic of both address families is already unconditionally accepted by the `iif "lo"` interface rule evaluated first in the pipeline, so the injected elements were redundant — and invisible: they never appeared in the Security Center lists (which are database-driven), which made `security:status` disagree with the UI. The kernel sets now mirror the database exactly, an empty whitelist emits a declared set without an element clause, and the loopback guarantee lives in the single interface rule that expresses it. The change takes effect the next time the ruleset is applied. No action is required for existing installations.
- **Security Center Title Icon**: The Security Center page title now shows the information-circle "i" icon that marks the page description as hover tooltip text — the security shield remains reserved for the navigation menu entry. The title icon follows the app-wide page-title size and style standard, and the System Services table's tooltip icon is aligned to the in-content size standard. No action is required for existing installations.

### Fixed
- **Unprivileged Test Runner & CI Permission Repair**: `ApplicationFilePermissions::ensureFullRepairCanRun()` was throwing a `RuntimeException` when automated tests exercised full permission repairs against isolated temporary directories under unprivileged test accounts (such as GitHub Actions `runner` and local `www-data`). The root check is now scoped to the live deployed application root (`$rootPath === null`), allowing test suites to verify full permission mechanics on their own disposable test paths without requiring root privileges.
- **GitHub Actions Test Pipeline Upgrades**: Upgraded `.github/workflows/tests.yml` to `actions/checkout@v5` and `actions/cache@v5`, eliminating runner warnings from GitHub's September 2026 deprecation of Node 20. Switched the pipeline trigger to manual on-demand execution (`workflow_dispatch`), preventing redundant automated test runs on git pushes and pull requests.
- **Unprivileged Security Test Helper Execution**: `SecurityExecutor::createProcess()` now skips the `sudo -n` prefix for custom non-system stub helper binaries created by test cases in temporary directories, allowing the test suite to verify bounded helper interactions in unprivileged CI environments.
- **Service / Port Column Squeeze**: When the Pre-Filters section was expanded, the "Service / Port" column of the Firewall Rules table could be squeezed narrow enough that a service-name chip (for example "ICMP Ping Diagnostics") wrapped inside its fixed-height pill and the text spilled over it. The column's chips are now plain text — the service name in regular weight and the rate-limit/dual-stack annotations in muted small text — so content wraps gracefully instead of overflowing a fixed-height pill, and the column's minimum width is reduced to 16rem. No action is required for existing installations.
- **Blocking a Whitelisted IP from the Security UI**: Attempting to ban a whitelisted IP address from the manual ban dialog raised a raw exception error page instead of a proper error alert. The dialog now closes on refusal — exactly like a successful ban — and the error appears as a red alert in the fixed top-right toast layer, dismissible with a close button. The same protection was extended to the dialog's "Permanent" option and the Blacklist add form, which previously allowed silently blacklisting a whitelisted address — since the kernel evaluates the blacklist before the whitelist bypass, that would have defeated the whitelist protection and could lock out the administrator. The new messages are available in English, Spanish, and French. No action is required for existing installations.
- **Web Panel Firewall Saves on Hardened PHP-FPM Hosts**: On hosts where the PHP-FPM service uses systemd's `ProtectSystem=full` hardening (the stock Debian/Ubuntu unit), saving any firewall change from the Security Center failed with `file_put_contents(/etc/tallpbx/firewall.nft.pending): Failed to open stream: Read-only file system`, because that hardening mounts `/etc` read-only inside the PHP-FPM service namespace. The installer now adds a scoped drop-in for the PHP-FPM unit (`ReadWritePaths=/etc/tallpbx`) that re-opens exactly the firewall configuration directory — and nothing else in `/etc` — and restarts PHP-FPM so web workers pick it up. The web-context syntax preflight (`nft -c`) is also now routed through the bounded root helper's new `validate` action, because the nftables utility requires CAP_NET_ADMIN even for check-only runs. Existing installations receive the drop-in by re-running the installer; a PHP-FPM restart applies it immediately.
- **Default Inbound Policy Changes Never Report Unapplied Results**: The dedicated Default Inbound Policy form now rolls the saved value back when the kernel apply is refused (zero-lockout guard, syntax preflight, or helper failure), so the Security Center can no longer display a policy that the live nftables ruleset is not running. The Attack Protection Settings drawer likewise reports apply failures instead of overwriting them with a success message. No action is required for existing installations.
- **Strict Address Validation in the Security Center**: The whitelist, blacklist, and manual-ban forms — and the ban service behind them — now enforce true IPv4 syntax: octets 0-255 and CIDR prefixes up to /32. Entries like `344.34.34.34` can no longer reach the generated firewall ruleset and corrupt it (which previously made every firewall save fail validation). The bounded host helper's input validation was tightened the same way, including IPv6 prefix bounds up to /128. IPv6 addresses are refused for now with a clear message in English, Spanish, and French, because the kernel pipeline currently manages IPv4 sets only; full dual-stack support is scoped in the IPv6 dual-stack implementation plan (removed after completion; recover from git history if needed). Resubmitting a form with new input also replaces the previous field message instead of displaying a stale error. No action is required for existing installations.
- **Firewall Sync Visibility & Honest Apply Reporting**: Every Security Center action — adding or removing IP list entries, banning and unbanning, rule toggles, reordering, and service changes — now reports success only after the kernel actually accepted the applied ruleset; previously a failed apply could still be followed by a green success message that hid the divergence. The page header now also shows a **Firewall Out of Sync** banner whenever the live kernel policy differs from the saved policy (or when the ruleset is not loaded in the kernel at all), with a one-click **Re-apply Ruleset** button; the banner clears automatically once an apply succeeds. The ruleset compiler additionally refuses to generate output when IP list entries or active bans are not valid IPv4 addresses, naming the offending entries in the error instead of failing later with a generic syntax message. The banner text is available in English, Spanish, and French. No action is required for existing installations.
- **IPv6 Ping Policy and Connectivity Invariant**: The unconditional all-ICMPv6 accept rule that followed the ICMP ping rules silently shadowed them — IPv6 echo-requests were always accepted, so the configured rate limit, stealth mode, and source restriction only ever applied to IPv4. The invariant is now narrowed to the ICMPv6 types IPv6 connectivity truly needs — path-MTU discovery (`packet-too-big`), MLD multicast maintenance (listener query/report/done and MLDv2 report), and Neighbor Discovery (router/neighbor solicit, advertise, and redirect) — so the ping policy governs both address families symmetrically. Related fix: configuring an IPv4 source restriction for ICMP Ping Diagnostics previously produced a mixed `ip saddr … ip6 nexthdr …` rule that made the whole ruleset fail nftables syntax validation and blocked every firewall save; source restrictions are now emitted per address family (`ip saddr` / `ip6 saddr`), and malformed source values are refused with a precise error. The change takes effect the next time the ruleset is applied. No action is required for existing installations.

## [1.1.1] - 2026-09-19

### Changed
- **Security Command Center Firewall Table Decluttering**: Removed the Stage 1–5 badges and the Priority column from the Firewall Rules & Port Access table. The table's four sections are now identified by plain names only — **Pre-Filters** (collapsible, with its rule count), **Standard Services**, **Custom Rules**, and **Default Inbound Policy** — individual rows no longer carry per-row stage tags, and the custom-rule reorder arrows moved into the Actions column. The redundant "Standard PBX Ports" quick-add dropdown was removed, since the same ports are already managed in the Standard Services section (with per-service factory-reset buttons). The redundant status badges on the Blacklist IPs, Blocked Attackers, and Whitelist IPs cards were removed (their card titles already state the behavior), keeping only the simplified "Instant Drop" and "Complete Bypass" helper headings, and all remaining "stage" wording was removed across English, Spanish, and French. No action is required for existing installations.
- **Pre-Filter Row Alignment**: The Pre-Filters rows in the Firewall Rules & Port Access table now use fixed-width columns — with the rule-name track sized to fit the longest rule name in every supported language — for the rule name, the kernel-rule badge (for example `iif "lo"`), and the info icon, so all six rows line up in straight vertical columns instead of the badges drifting with each rule label's length. No action is required for existing installations.

### Fixed
- **SQLite-Compatible Security ICMP Migration**: The Security module's ICMP support migration (`2026_09_18_000008`) used a MariaDB-only `ALTER TABLE ... MODIFY COLUMN` statement, which stopped database migrations — and therefore the entire automated test suite — from running on SQLite. The migration now uses Laravel's portable schema builder, producing an identical column definition on MariaDB while allowing SQLite-backed environments and automated tests to migrate cleanly. No action is required for existing MariaDB installations, which have already applied this migration.
- **Standard PBX Service Catalog Test Expectations**: Updated the security catalog test to expect the 8 standard services seeded since 1.1.0; the `ICMP Ping Diagnostics` service added by the ICMP feature had not been reflected in the expected count.
- **Test Suite Could Modify the Live Host Firewall**: Running the Security module's automated tests on a host where the bounded helper is installed (or as the `root` user) could execute real privileged firewall commands — rewriting `/etc/tallpbx` and loading test fixtures into the live kernel ruleset. The tests now use a stubbed executor and an isolated ruleset directory, and `SecurityExecutor` refuses to run privileged operations during automated test runs, so running the test suite can never alter host firewall state again.

### Security
- **Authenticated Real-Time Alert Channel**: Security Command Center alerts (intruder activity, bans, and firewall changes) are now broadcast on a private, permission-checked WebSocket channel. Only signed-in panel users holding the `security.view` permission can subscribe over Laravel Echo/Reverb; unauthenticated clients are rejected by the channel authorization endpoint. No action is required for existing installations.

## [1.1.0] - 2026-09-18

### Changed
- **Collapsible System & Pre-Filters Pipeline Section**:
  - Compacted System Base Invariants, Stage 1 Drops, and Stage 2 Whitelist into a single interactive header row (`SYSTEM + STAGES 1 & 2: BUILT-IN RULES & PRE-FILTERS (6 Rules Active)`), decluttering the **Firewall Rules & Port Access** table and reducing vertical height while preserving full visibility on demand.
  - Replaced technical "Kernel Invariant" jargon with clear, plain-English "Built-in" action labels for non-editable rules (Loopback, Connection Tracking, and Invalid Packet Defense).
  - Streamlined Stage 3 into **Standard Services**, eliminating repetitive and inaccurate "Core PBX" and "System" badges next to individual service names.
  - Clicking the header expands/collapses the full 6 sequential rules in exact evaluation order: unconditional loopback (`iif "lo"`), permanent blacklist IP drops (`@blacklist_ips`), active banned intruder drops (`@banned_ips`), stateful connection tracking (`ct state established,related`), invalid packet defense (`ct state invalid`), and trusted whitelist bypass (`@whitelist_ips`).
- **ICMP Ping Diagnostics Configurable Core Service**:
  - Moved ICMP Ping Diagnostics from static pre-filters into **Stage 3 Core Services** as the first rule, matching its position in sequential rule evaluation.
  - Added full administration controls for ICMP: administrators can toggle echo-request ping diagnostics on/off, restrict echo-requests to specific source IP networks or CIDRs (e.g. monitoring servers), and configure rate-limiting thresholds (custom packet/sec rate limit and burst allowance or completely unlimited).
  - Added 1-click "Restore Factory Defaults" for ICMP diagnostics (defaults: enabled, unrestricted source IP, 5 packets/sec rate limit with burst of 5).
  - Protected critical IPv6 connectivity: echo-request rules control ICMP/ICMPv6 ping, while IPv6 Neighbor Discovery (ND) and Router Advertisements (RA) remain unconditionally accepted at the kernel level so IPv6 routing and layer-2 reachability are never severed.
  - Added database migration `2026_09_18_000008_update_security_services_for_icmp_support.php` extending `protocol` column to `VARCHAR(20)` and adding `rate_limit` and `burst` columns.
- **Kernel Pipeline Invariants & Loopback Step 1 Reordering**:
  - Reordered the Linux `nftables` inbound pipeline in `SecurityConfigGenerator` so unconditional loopback access (`iif "lo" accept`) evaluates at Step 1 before blacklists and dynamic bans, guaranteeing uninterrupted internal localhost IPC between PHP-FPM, MariaDB, Redis, FreeSWITCH ESL, and Laravel Reverb WebSockets.
  - Implemented burstable ICMP rate limiting (`limit rate 5/second burst 5 packets accept` for IPv4 and IPv6) with full support for IPv6 Neighbor Discovery and Router Solicitation, safeguarding against ping floods while preserving essential network diagnostics.
- **Base System Invariants Displayed in Security Command Center**:
  - Displayed living Base System Invariants directly in the **Firewall Rules & Port Access** table (`security-manager.blade.php`), exposing loopback interface access, stateful connection tracking (`ct state established,related`), invalid packet defense (`ct state invalid`), and ICMP diagnostics with rate limiting and dual-stack IPv4/IPv6 indicators.
  - Added full multilingual translations across English, Spanish, and French (`lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php`) for all invariant tooltips, badges, and rule descriptions.
- **Documentation & UI Tour Showcase**:
  - Added the Security Command Center high-resolution screencapture and architecture overview to `docs/security-architecture.md` and `docs/ui-tour.md`.
  - Updated the inbound ingress Mermaid flowcharts and sequential rule documentation to reflect the 9-step kernel filtering pipeline.
- **Blacklist IP Precedence Clarification & Multilingual Translations**:
  - Updated the Blacklist section description and advisory helper across English, Spanish, and French (`lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php`) to explicitly state that Blacklist drop rules take precedence over general traffic and are dropped before the whitelist.
  - Fully synchronized all 50+ new Security Center pipeline, table, and service keys across `es` and `fr` translation catalogs.
- **Security Menu Placement Before PBX**:
  - Positioned the **Security** navigation link directly before the **PBX** section in the main sidebar menu (order 39), grouping core server administration, monitoring, and security together before telephony domains.
- **Security Center Table Typography & Text Size Standardization**:
  - Enlarged table text size across all Security Command Center tables (Blacklist, Blocked Attackers, Whitelist, and Firewall Rules) to match the standard sizing and typography of Users, Administrators, and other core panels.
  - Replaced compact `table-xs` and `table-sm` sizing with standard table sizing (`0.875rem` / 14px body text, 14px monospace ports/protocols/IPs, and `badge-sm` tags), improving legibility and visual consistency across the application.
- **Protection Settings Relocated to Blocked Attackers Header**:
  - Moved the **Attack Protection Settings** button from the top page header directly into the **Blocked Attackers** section header alongside "Block IP Manually", grouping intrusion detection thresholds (`max_retry`, `find_time`, `ban_time`) and attack vector toggles (SIP, Web, SSH) directly with the live threat management workbench.
- **Pipeline-Aligned 3-Deck IP Architecture & Standardized Blacklist/Whitelist Terminology**:
  - Replaced the tab-switched IP management deck with three sequential full-width workbench cards arranged in the exact order traffic is evaluated in the Linux `nftables` packet filtering pipeline:
    1. **Blacklist IPs (Always Dropped)**: Stage 1 permanent kernel drop rules with a Split-Panel layout (Quick-Add Form and Stage 1 advisory helper on left, search filter and scrollable table on right).
    2. **Blocked Attackers**: Stage 1 dynamic fail2ban drop rules with active threat counters, attack vector badges (`SIP`, `Web`, `SSH`), expiry countdowns, and manual ban creation.
    3. **Whitelist IPs (Always Allowed)**: Stage 2 kernel bypass rules with a Split-Panel layout (Quick-Add Form, 1-click admin IP self-protection, and Stage 2 advisory helper on left, search filter and scrollable table on right).
  - Standardized terminology across the UI and translation keys (`lang/en/admin.php`) to strictly use consistent naming everywhere (`Blacklist IPs (Always Dropped)`, `Blocked Attackers`, `Whitelist IPs (Always Allowed)`, `Add to Blacklist`, `Add to Whitelist`, `Blacklist IPs @blacklist_ips`, `Blocked Attackers @banned_ips`, `Whitelist IPs @whitelist_ips`).
  - Added smooth anchor navigation (`↑ View Blacklist`, `↑ View Blocked Attackers`, `↑ View Whitelist`) from the Unified Firewall Rules table directly to the corresponding cards.
  - Eliminated horizontal dead space with a responsive flex layout (`w-full lg:w-80 lg:shrink-0` for form, `w-full lg:flex-1` for table).
  - Removed redundant top **Packet Filtering Pipeline Order** banner since the living firewall table and stacked cards already represent the sequential stages directly.

### Added
- **Unified Firewall Rules Table & Editable Core PBX Services**:
  - Transformed the **Firewall Rules & Port Access** table into a single, unified view representing the complete 5-stage packet filtering pipeline:
    - **Stage 1 & 2 Summary Rows**: Permanent Blacklist (`@blacklist_ips`), Active Intrusion Bans (`@banned_ips`), and Trusted Whitelist (`@whitelist_ips`) with live counts and 1-click management shortcuts (`Manage Blacklist`, `Manage Whitelist`).
    - **Stage 3 Core PBX Services**: Fully integrated all 7 telephony and management services (SIP Signaling, RTP Media, Web Admin, SSH, FreeSWITCH ESL, Reverb WebSockets, and WebRTC) into the table with individual status toggles and customization controls.
    - **Stage 4 Custom Sequential Rules**: Administrator-defined sequential rules with priority Up/Down reordering and quick creation controls.
    - **Stage 5 Default Inbound Fallback Policy**: Clean fallback summary row showing default action (`Drop` or `Accept`) with direct configuration shortcut.
  - **Core PBX Service Customization & Zero-Lockout Safety**:
    - Added customization for core PBX services, allowing administrators to modify port ranges, network protocols (`TCP`, `UDP`, `Both`), and apply source IP / CIDR network restrictions (e.g. restricting SSH or Web Admin access to an administrative VPN).
    - Safety warning modal with plain-English guidance on preventing call disruption.
    - Zero-lockout protection (`LockoutGuardService`) preventing administrators from restricting or disabling Web Admin or SSH access away from their active connection IP.
    - 1-click "Restore Factory Defaults" action to instantly revert core PBX services to factory ports and unrestricted access.
  - Database schema update: added `enabled` and `source_ip` columns to `security_services` via migration `2026_09_18_000007_add_enabled_and_source_ip_to_security_services_table.php`.
- **Security Module**: Integrated native host security and attack protection command center (`app-modules/security`).
  - Single-screen management for host firewall rules, standard PBX port access, trusted and blocked IP lists, and real-time intruder monitoring.
  - Native Linux `nftables` packet filtering with zero-lockout protection ensuring active administrator sessions are never blocked.
  - High-performance in-process authentication failure tracking with automatic IP banning across SIP, Web, and SSH access vectors.
  - Bounded root execution helper (`/usr/local/sbin/tallpbx-security`) for safe, atomic kernel firewall updates.
  - Unified admin panel route (`/panel/security`) and dedicated top-level navigation on the main menu, protected by `security.view` and `security.edit` permissions.
  - Host security database schema and Eloquent models (`SecurityRule`, `SecurityService`, `SecurityIpList`, `SecuritySetting`, `SecurityBan`, `SecurityAuditLog`).
  - Standard PBX Port Catalog seeder (`SecurityServiceSeeder`) pre-populating SIP (5060/5061/5080), RTP (16384-32768), Web Admin (80/443), SSH (22), ESL (8021), Reverb (8080), and WebRTC (7443).
  - In-process web authentication failure listener (`LogFailedLoginListener`) capturing failed logins in real time with Redis sliding-window counters (`SecurityIncidentService`).
  - Real-time FreeSWITCH SIP authentication failure listener (`LogFailedSipAuthListener`) and dedicated `SofiaFailedAuth` event dispatching via ESL for automated SIP attack detection and banning.
  - Host IP ban management service (`SecurityBanService`) and executor (`SecurityExecutor`) coordinating active/historical bans in MariaDB and Linux kernel `nftables` sets.
  - Enterprise security audit logging (`SecurityAuditLog`) automatically recording ban and unban operations with administrator attribution.
  - Bounded root execution helper (`scripts/resources/tallpbx-security`) and sudoers configuration (`scripts/resources/tallpbx-security.sudoers`) enforcing strict parameter regex validation and atomic kernel ruleset compilation.
  - Native Linux `nftables` configuration compiler (`SecurityConfigGenerator`) generating atomic rulesets, kernel interval sets for IPs and CIDRs, and dynamic timeout-backed bans.
  - Zero-lockout protection service (`LockoutGuardService`) evaluating administrator session reachability before applying restrictive firewall policies, complete with 1-click rescue whitelisting.
  - Artisan management CLI commands: `security:apply` (atomic firewall ruleset application with lockout check), `security:status` (active engine and ban overview), and `security:unban <ip>` (instant cross-layer unbanning).
  - Declarative event listener and console command registration support (`listeners()`, `consoleCommands()`) in the base `ModuleServiceProvider`.
  - **Unified Single-Screen Security Command Center (`SecurityManager`)**: Complete interactive administrative interface (`/panel/security`) unifying all 5 security zones on a single responsive screen:
    - Real-time status cards for Kernel Firewall, Attack Protection, Active Banned Attackers, and Administrator Connection with dynamic Lockout Guard.
    - Whitelist and Blacklist IP address management supporting individual IPv4 addresses and CIDR subnets with live search, instant deletion, and validation.
    - Active Threat Monitor displaying banned attacker IPs, attack vectors (SIP, Web, SSH), failure attempt counts, expiration countdowns, 1-click manual unbanning, permanent blocking, and safe-whitelisting.
    - Sequential Firewall Rule builder with live Up/Down priority reordering, enable/disable toggles, PBX Port Catalog quick-add dropdown, custom rule creation modal, and pulsing `[ Save & Apply Changes ]` action.
    - Attack Protection configuration slide-over drawer for tuning failure thresholds (`max_retry`, `find_time`, `ban_time`) and toggling protection per vector (SIP, Web, SSH).
  - **Installer Integration (`scripts/resources/security.sh`)**: Automated installer step installing `nftables`, establishing the `/etc/tallpbx` configuration directory (mode 2775 `root:www-data`), installing `/usr/local/sbin/tallpbx-security` (mode 0750 `root:www-data`), configuring sudoers drop-in `/etc/sudoers.d/tallpbx-security` (mode 0440), registering `SecurityServiceSeeder` in `DatabaseSeeder`, and generating the initial baseline firewall ruleset.
  - **Browser & Smoke Tests**: Dusk browser test coverage and Pest feature test suite (`SecurityManagerLivewireTest.php`) validating Livewire component reactivity, lockout warning display, tab switching, rule reordering, port catalog rule addition, and threat table actions.
- **User & Administrator Security Documentation**: Comprehensive guides and parity analysis added to `README.md`, `INSTALL.md`, and `docs/parity-comparison.md` detailing the native Linux kernel `nftables` firewall, multi-vector intrusion prevention across SIP, Web, and SSH, zero-lockout protection, and the hardened Bounded Sudoers host command execution architecture.
- **Security Architecture & Packet Flow Guide**: Added [docs/security-architecture.md](docs/security-architecture.md) featuring visual Mermaid flowcharts detailing inbound ingress packet traversal, outbound egress routing, real-time WebSocket attack mitigation, sequential top-to-bottom rule ordering, and two-tier reboot persistence.

### Changed
- **Security Command Center Real-Time Responsiveness**: Removed the manual "Refresh Status" button and periodic polling in favor of real-time event-driven push updates (`#[On('echo:security.alerts,...')]` and `#[On('refresh-security')]`), standardizing on the project-wide WebSocket/Echo pattern established by the monitoring dashboard.
- **Instant Auto-Application (Zero-Staging Workflow)**: Eliminated manual change staging and the requirement to click a separate "Save & Apply Changes" button. Firewall rule toggling, reordering, IP whitelisting/blacklisting, service catalog additions, custom rule creations, and security settings updates now atomically and automatically compile and apply directly to the Linux kernel (`nftables`) with automated preflight lockout verification.
- **Access Control Lists (ACL)**: Renamed "Access Controls" to "Access Control Lists" across English, Spanish, and French translations for consistency with FreeSWITCH naming, and added plain-language explanatory tooltips differentiating internal phone system ACLs from the OS-level kernel firewall.
- **Rate Limits (formerly Event Guard)**: Renamed "Event Guard" to "Rate Limits" across the navigation menu, page headers, descriptions, and translations (English, Spanish, French) to align with standard FreeSWITCH telephony terminology and eliminate confusion with legacy FusionPBX naming. Updated tooltips to clearly differentiate telephony event rate limiting from the OS-level packet firewall.

### Fixed
- **Tailwind v4 Modular View Scanning**: Added `@source "../../app-modules/**/*.blade.php"` to `resources/css/app.css` so utility classes across all modular Blade views are scanned and compiled into production CSS bundles.
- **Empty Threat Monitor Table Sizing**: Adjusted the "No active threats" empty state icon in the Security Command Center from an oversized, unconstrained element to a compact `w-6 h-6` (24px) bounded icon with reduced padding (`py-6`), eliminating unintended vertical scrolling.

### Upgrade Notes
- Requires running database migrations: `php artisan migrate`.
- Requires installing Linux package: `apt install nftables`.
- Requires installing bounded security helper and sudoers rule (`scripts/resources/security.sh`).

## [1.0.0] - 2026-09-17

### Added
- Initial production release of TallPBX.
- Unified single-panel architecture for system administrators and tenant users with permission-gated access.
- Core PBX management: Extensions, SIP Trunks, Inbound & Outbound Routes, Ring Groups, IVR Menus, Call Centers, Time Conditions, Dialplans, and Feature Codes.
- Dynamic FreeSWITCH telephony integration powered by `mod_xml_curl` through the application XML Handler API.
- Livewire 4, Alpine 5, and Tailwind CSS v4 / DaisyUI 5 reactive interface.
- Multi-tenant isolation, automated device provisioning, and automated installer with dependency and permission management.
