# TallPBX

TallPBX is a self-hosted business phone system named after its foundation on the **TALL stack** (**T**ailwind CSS, **A**lpine.js, **L**ivewire, and **L**aravel). It gives an administrator a web control panel for setting up and managing phones, extensions, call routing, voicemail, recordings, and other everyday PBX features. It runs on your own Debian server and uses FreeSWITCH® to handle calls.

## What You Can Do With It

- Create extensions and connect desk phones or softphones.
- Route incoming calls to people, ring groups, menus, queues, voicemail, or
  conferences.
- Configure outbound calling through a SIP trunk or gateway.
- Manage voicemail, call recordings, music on hold, announcements, and fax.
- Deliver voicemail notifications, password resets, and system alerts via standard username/password SMTP authentication or OAuth 2.0 (Google, Microsoft 365, or custom providers).
- Operate one or more customer or business tenants from the same system.
- Keep backups and restore approved backup operations.
- Defend the PBX with a native Linux kernel firewall (`nftables`), real-time intrusion prevention across SIP and web vectors, automatic ban management, and zero-lockout protection ([Security Architecture & Flow Guide →](docs/security-architecture.md)).

TallPBX is the phone-system software, not a telephone carrier. You need a SIP
trunk or gateway from a provider if you want to place or receive public phone
network calls.

## Community First

TallPBX is a community-first project, released under the [Apache 2.0 license](https://opensource.org/licenses/Apache-2.0). Encouraging third-party development is the primary goal — integrations, modules, and contributions from the community are always welcome. Commercial use is also welcome.

## Parity with FusionPBX & FreePBX®

Built to eliminate the steep learning curve and dated interfaces of legacy PBX platforms without compromising the raw carrier power of FreeSWITCH®, TallPBX delivers feature and function parity with established open-source systems on a modern Laravel 13, Livewire 4, and Tailwind CSS architecture with comprehensive automated test coverage:

| Metric / Dimension | TallPBX | FusionPBX | FreePBX® |
| :--- | :--- | :--- | :--- |
| **Telephony Engine** | **FreeSWITCH 1.11** (`mod_sofia`, `mod_callcenter`) | **FreeSWITCH 1.11** | **Asterisk 20+** (PJSIP, chan_sip) |
| **Core Web Stack** | **Laravel 13, Livewire 4, Tailwind v4** | Custom procedural/OOP PHP (legacy) | BMO (custom procedural PHP framework) |
| **Database Support** | **MariaDB / MySQL** (SQLite in testing) | PostgreSQL / SQLite / MariaDB | MariaDB / MySQL |
| **Configuration Model** | **Dynamic `mod_xml_curl`** (No static XML on disk) | Dynamic `mod_xml_curl` (PHP scripts) | Static `.conf` files written to disk (`#include`) |
| **Multi-Tenancy** | **Native Multi-Tenant** (Isolated contexts, domains, data) | **Native Multi-Tenant** (Domain-based) | **Single-Tenant Core** (Multi-tenant requires commercial PBXact) |
| **User Impersonation** | **1-Click Native Impersonation** (Instant tenant user perspective, persistent recovery banner & audit trail) | Limited (Domain switching only, no direct user session impersonation) | None (Separate UCP logins, no multi-tenant user impersonation) |
| **Multi-Language Support** | **Native Multi-Lingual** (English, Spanish, French with instant topbar switcher, locale routing & user preference) | Partial / Community arrays (`app_languages.php`) | Partial gettext / PO files (often incomplete, English-centric) |
| **User Interface & Themes** | **Dual Layouts & Switchable Themes**: Collapsible mini-rail sidebar (`w-16` / `w-64`), horizontal topbar dropdowns, and instant switchable Light/Dark/System themes with per-user database persistence & zero-flicker client caching | Fixed top navbar (legacy procedural HTML, static light theme, no dynamic dark mode) | Fixed top navbar (classic FreePBX theme, static light theme, no dark mode) |
| **Firewall & Intrusion Defense** | **Native `nftables` Kernel Engine + Real-Time Multi-Vector Defense** (Kernel sets, ESL SIP auth hook, zero-lockout protection) | Fail2ban / `iptables` scripts (Legacy log scraping, prone to desync) | Basic `iptables` / Fail2ban (Requires commercial System Admin for advanced features) |
| **Host Command & CLI Security** | **Strict Bounded Sudoers Architecture** (Discrete argument arrays, non-interactive root helpers, zero web shells or raw SQL runners) | Vulnerable (`app/exec` web shell, `app/database` raw SQL runner, unescaped shell strings) | Complex sudoers entries for Asterisk/Apache, historical CWE-78 vulnerabilities |
| **Automated Testing** | **2,337 Pest tests + 44 Dusk browser tests** | Minimal / community scripts | Minimal unit tests |
| **Licensing** | **Apache 2.0** (100% open source) | MPL 1.1 (Open source) | GPLv3 (Core) + Commercial closed modules |

See the [Feature and Function Parity Guide](docs/parity-comparison.md) for the complete domain-by-domain breakdown across all 59 PBX modules (Extensions, Routing, PBX Features, Media, Operations, Security, and Administration).

👉 **[Explore the Security Architecture & Packet Flow Guide (Firewall, Intrusion Defense & Flow Diagrams) →](docs/security-architecture.md)**

## User Interface & Visual Tour

TallPBX is built on the **TALL stack** (Tailwind CSS v4, Alpine.js, Livewire 4, Laravel 13) with DaisyUI components, offering a modern responsive control panel with light and dark themes and three switchable navigation layouts:

| Public Landing (Light Theme) | Public Landing (Dark Theme) |
| :---: | :---: |
| [![TallPBX Landing Page (Light)](docs/images/landing-light.png)](docs/ui-tour.md#1-public-guest-landing-page) | [![TallPBX Landing Page (Dark)](docs/images/landing-dark.png)](docs/ui-tour.md#1-public-guest-landing-page) |

### Unified Panel & Flexible Navigation Layouts

The single unified panel (`/panel/`) adapts to administrator preference with instant theme and layout toggles persisted to the database:

| Full Sidebar (`w-64`) | Mini Icon Rail (`w-16`) | Horizontal Topbar |
| :---: | :---: | :---: |
| [![Full Sidebar](docs/images/dashboard-full-sidebar.png)](docs/ui-tour.md#mode-a-full-sidebar-navigation-w-64) | [![Mini Rail](docs/images/dashboard-compressed-sidebar.png)](docs/ui-tour.md#mode-b-mini-icon-rail-sidebar-w-16) | [![Horizontal Menu](docs/images/dashboard-horizontal-menu.png)](docs/ui-tour.md#mode-c-horizontal-topbar-navigation) |

👉 **[Explore the Complete Visual Tour (Tenants, Extensions, Devices, Impersonation, Multi-Language & Layouts) →](docs/ui-tour.md)**

## Quick Start

1. Prepare a Debian 13 server with at least 1 GB RAM, 2 GiB swap, 1 CPU core,
   and 25 GB disk space.
2. Follow the [installation guide](INSTALL.md). It walks through server setup,
   the installer, and the first login.
3. Open the server's IP address in a browser and sign in with the administrator
   email and password chosen during installation. If you selected a browser
   setup option, open `/panel/setup` first instead.
4. Follow the [PBX "Hello World" Guide](docs/pbx-hello-world.md) to create your
   first extension, register a softphone (MicroSIP, Linphone, or desk phone),
   and place an audio loopback echo test call (`*9196`).

The installer installs TallPBX, FreeSWITCH, the web server, database, and other
required services. It is safe to run again after an interrupted installation;
it preserves existing application data and saved installation choices.

For a normal PBX server, choose **No** when the installer asks about demo data
and development tooling. The `--no-demo` and `--no-development` flags skip
those individual choices for an unattended install. [Full installation guide →](INSTALL.md)

## Prerequisites & Hardware Requirements

- **Operating System**: Debian 13 server (64-bit)
- **Access**: Root / sudo access
- **Network**: Internet connectivity with static IP or bridged network adapter
- **Recommended Minimum Hardware (Development)**:
  - **CPU**: 4 CPUs or vCPUs
  - **Storage**: 40GB storage
  - **RAM**: 4GB RAM
  - **Swap**: 2GB swap
  *(Recommended for running Vite frontend compilation, Pest test suites in parallel, and Dusk headless browser testing).*
- **Minimum Hardware (Production)**:
  - **CPU**: 1 vCPU (2+ vCPUs recommended for active PBX workloads)
  - **Storage**: 25 GB disk space (40 GB+ recommended for local call recordings and voicemail storage)
  - **RAM**: 1 GB RAM
  - **Swap**: 2 GB swap

## Modular Architecture

This project is built using a modular architecture with modules in the `app-modules/` directory:

- Modules reside under `app-modules/ModuleName/`
- PSR-4 namespaces map to the module's `src/` directory: `Modules\ModuleName\` → `app-modules/ModuleName/src/`
- Each module registers its own routes, views, migrations, menus, and permissions via its `ModuleServiceProvider`
- Modules are auto-discovered through Composer path repositories and Laravel package discovery

### Creating a New Module

Scaffold a new module with the `make:module` command:

```bash
php artisan make:module call-forwarding \
    --display-name="Call Forwarding" \
    --description="Forward calls to external numbers" \
    --category="PBX Features"
```

This creates the full directory structure:

```
app-modules/call-forwarding/
├── composer.json          # PSR-4 autoloading + Composer discovery
├── module.json            # Module manifest (version, namespace, requirements)
├── config/                # Module-specific config files
├── database/migrations/   # Database migrations
├── lang/en/               # English translations
├── resources/views/       # Blade views (namespace: call-forwarding::)
└── src/
    ├── Livewire/          # Livewire components
    └── Providers/         # ModuleServiceProvider
```

The generated provider extends `App\Support\ModuleServiceProvider`, which auto-registers standard views, migrations, routes, Livewire components, menu items, and permissions. Create a `routes/web.php` file only when the module needs custom routes beyond the standard panel list/create/edit conventions.

After scaffolding, add a Composer path repository in root `composer.json` and run `composer update` to register the module. First-party modules use `app-modules/*` path repositories; third-party modules may be installed from GitHub or private Composer repositories.

### Module Commands

TallPBX provides first-party Artisan commands for module discovery and registry maintenance:

```bash
# Sync module.json manifests into the database module registry
php artisan module:sync

# Sync only first-party modules under app-modules/
php artisan module:sync --only-local

# Build the TallPBX module manifest cache
php artisan module:cache

# Clear generated module manifest caches
php artisan module:clear

# List modules known to the registry
php artisan module:list
```

Composer is responsible for PHP autoloading and Laravel package discovery. The module cache stores TallPBX module metadata, not a separate PHP autoloader.

## Telephony Integration (mod_xml_curl)

Instead of generating static XML configurations on disk, this system serves dynamic configs to FreeSWITCH on demand over HTTP using `mod_xml_curl`:

- Dynamic directories, dialplans, and configurations are handled via the XML Handler API (`/api/v1/xml-handler`).
- Events are captured using a persistent ESL connection running under `php artisan freeswitch:listen`.
- Fresh installs install and enable the default PBX runtime modules: `mod_sofia` for SIP profiles, `mod_callcenter` for queues, `mod_dptools` for core dialplan applications, `mod_local_stream` and `mod_sndfile` for packaged media/MOH, and `mod_xml_curl` for app-served XML. The installer also writes `/etc/freeswitch/autoload_configs/xml_curl.conf.xml` with the generated XML handler token automatically and adds a systemd drop-in so FreeSWITCH starts after Nginx, PHP-FPM, MariaDB, and Redis.
- Non-demo fresh installs seed the Default tenant only. The installer separately
  creates the first administrator during installation or through `/panel/setup`;
  demo mode is the sole creator of sample tenants, extensions, SIP accounts,
  trunks, routes, voicemail boxes, and other callable PBX data.
- Fresh installs use FreeSWITCH's packaged US English Callie prompts for digits, voicemail, IVR, conference, and miscellaneous PBX audio, plus the packaged music-on-hold files when available. The XML handler serves `local_stream.conf` so queue MOH can use `local_stream://moh`.
- Media payloads such as call recordings, uploaded recordings, voicemails, and fax files are stored on disk. The database stores paths and metadata only.

For tenant routing, TallPBX prefers tenant-specific call contexts over domain names. A domain name can still be used as a fallback, but domains are not treated as the only way to identify a tenant. This allows multiple tenants to share the same SIP domain when the deployment needs that.

### XML Handler Performance Knobs

The XML handler is a hot path: FreeSWITCH can call it during directory lookup, dialplan routing, and configuration requests. The app keeps successful per-request XML handler logging off by default, uses short-lived generated dialplan XML caching to avoid rebuilding identical tenant/context/destination responses during call bursts, and caches context-wide standard dialplan fragments so mixed-destination bursts do not repeatedly query the same base dialplans.

Relevant `.env` settings:

```bash
# Keep auth enabled outside tightly controlled local testing.
FREESWITCH_XML_HANDLER_AUTH=true
FREESWITCH_XML_HANDLER_TOKEN=generated-by-installer

# Successful request debug logging is intentionally opt-in.
FREESWITCH_XML_HANDLER_LOG_REQUESTS=false

# Repeated generated dialplan XML cache. Set 0 to disable.
FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5

# Repeated context-wide fragment cache for standard dialplans and contributors.
FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=5

# Production default. Use file only for simple local installs without Redis.
CACHE_STORE=redis

# Production default. Use file only for simple local installs without Redis.
SESSION_DRIVER=redis
SESSION_CONNECTION=cache

# Optional. Defaults to CACHE_STORE.
FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis
```

Redis is the recommended cache and session backend for this project because the XML handler is a hot path, control-panel sessions should not depend on local disk, and file/database stores add avoidable I/O under concurrent calls. Simple local development can temporarily use `CACHE_STORE=file` and `SESSION_DRIVER=file` if Redis is not installed.

Fresh installs enable Redis automatically. For an existing server, install and enable Redis before switching these `.env` values:

```bash
apt-get install -y redis-server redis-tools php8.5-redis
systemctl enable --now redis-server
redis-cli ping
```

After changing these settings, clear and rebuild Laravel caches:

```bash
php artisan optimize:clear
php artisan optimize
```

## Integrated Security & Threat Defense

TallPBX includes a first-party Security module (`app-modules/security`) that replaces legacy log scrapers (like Fail2ban) with native Linux kernel packet filtering and real-time application intrusion defense:

### 1. Linux Kernel Firewall (nftables)
- **High-Performance Kernel Sets**: Fast in-kernel lookups with `@whitelist_ips`, `@blacklist_ips`, and `@banned_ips` (supporting dynamic kernel timeouts).
- **All-or-Nothing (Atomic) Preflight Verification**: Proposed firewall rules are compiled to `/etc/tallpbx/firewall.nft.pending` and verified using `nft -c -f` before replacing the active ruleset, preventing syntax errors or broken rules from taking down host networking.
- **Critical Protocol Safeguards**: IPv6 Neighbor Discovery (`ip6 nexthdr icmpv6 accept`) and standard ICMP echo requests are explicitly allowed so DNS resolution and network diagnostics never stall.

### 2. Multi-Vector Real-Time Intrusion Prevention
- **FreeSWITCH SIP Auth Scanning**: FreeSWITCH Event Socket Layer (ESL) captures `sofia::failed_auth` events as they happen, blocking SIP registration brute-force attackers in sub-seconds.
- **Web Control Panel Brute-Force**: In-process interception of Laravel authentication failures (`Illuminate\Auth\Events\Failed`), blocking web brute-force attacks before they exhaust server resources.
- **Sliding-Window Rate Limiting**: Redis-backed incident tracking evaluates configurable retry limits (`max_retry`), time windows (`find_time`), and ban durations (`ban_time`).

### 3. Zero-Lockout Safety Guard
- **Pre-Flight Protection**: The system inspects the administrator's current remote IP, session context, and local network subnets before applying restrictive default `DROP` firewall policies.
- **Bypass Protection**: Banning services refuse to block whitelisted IPs, subnets, or loopback addresses, preventing accidental administrative lockouts.

### 4. Hardened Linux CLI Execution & Jailbreak Defense
TallPBX enforces a strict two-tier execution policy to prevent command injection (CWE-78) and root privilege escalation:
- **Unprivileged Execution**: The web application and PHP-FPM run under the unprivileged `www-data` user with zero direct access to root shells or general system utilities.
- **Bounded Sudoers Architecture**: Privileged operations (firewall compilation, kernel set ban/unban) are encapsulated in a dedicated root-owned script (`/usr/local/sbin/tallpbx-security`, mode `0750 root:www-data`).
- **Strict Parameter Whitelisting**: The sudoers drop-in (`/etc/sudoers.d/tallpbx-security`) permits execution exclusively for that single binary. The script strictly regex-validates all parameters (IPs, CIDRs, actions) and runs non-interactively (`set -euo pipefail`) without subshell escape vectors.
- **Zero Dangerous Web Tools**: Dangerous legacy utilities like web shells (`app/exec`) and raw SQL runners (`app/database`) present in older PBX platforms are intentionally excluded from TallPBX.

### 5. CLI Management Commands
Administrators can inspect and manage security directly from the terminal:

```bash
# Check firewall status, active kernel sets, and banned attackers
php artisan security:status

# Safely recompile and apply pending firewall rules (all-or-nothing)
php artisan security:apply

# Unban an IP address and remove it from the kernel
php artisan security:unban 198.51.100.25
```

## Running the Application

```bash
# Serve the Laravel app (development)
php artisan serve

# Compile frontend assets (in a new terminal)
npm run dev
```

In production, Nginx serves the application — the installer configures this automatically. After making code changes, clear caches:

```bash
php artisan optimize:clear
```

Generated files in `bootstrap/cache` and `public/build` must be readable by PHP-FPM/Nginx (`www-data`). Artisan commands repair generated permissions automatically, and `npm run build` runs a post-build repair script. If assets or cache files are generated manually as `root`, run:

```bash
bash scripts/fix-generated-permissions.sh
```

### Panel Navigation, Layout Modes & Switchable Themes

The unified control panel provides flexible navigation layouts and color themes designed to maximize screen real estate for wide data tables (CDRs, routing rules, active calls, extensions) and optimize operator ergonomics:

- **Switchable Color Themes (Light, Dark, System):** Instant runtime switching between Light mode, Dark mode, and automatic System preference detection (`prefers-color-scheme`). Preferences are cached synchronously in browser `localStorage` and executed by an inline `<head>` script prior to HTML render, guaranteeing zero flash-of-unstyled-theme (FOUC), while being persisted asynchronously to the user's profile in the database.
- **Ergonomics & Parity Comparison:** FusionPBX and FreePBX lock operators into legacy, fixed light-mode interfaces that lack runtime theme toggling and dark mode support, causing high glare during overnight operations. TallPBX provides native dark theme support tailored for 24/7 Network Operations Centers (NOCs), telecom control rooms, and dispatch environments to reduce eye strain, alongside a high-contrast light theme for daytime office environments.
- **Collapsible Sidebar (Mini "Icon Rail"):** On desktop, users can collapse the vertical sidebar from its standard expanded width (`256px`) down to a compact `64px` icon rail with centered icons, hover tooltips, and flyout popover submenus for grouped PBX categories. A toggle button is pinned at the bottom of the sidebar (`[«]` / `[»]`) and accessible via the desktop header toggle.
- **Horizontal Header Navigation:** Users can switch to a full-width top-bar layout with standard dropdown menus (`menu menu-horizontal`), familiar to operators migrating from FusionPBX or classic PBX systems.
- **Display Preferences:** A unified **Display Settings** dropdown in the header allows users to switch between Sidebar and Top Navigation, adjust sidebar size, and toggle themes (Light, Dark, System). Preferences are saved immediately to `localStorage` for zero-flicker rendering and synced to the user profile in the database.
- **Mobile Responsive Fallback:** Regardless of the chosen layout mode, viewports below `1024px` automatically fall back to an accessible slide-over mobile drawer.
- **Scroll Preservation:** Panel navigation uses Livewire `wire:navigate.preserve-scroll` with persisted scroll containers in `resources/views/layouts/app.blade.php`. The sidebar drawer and nav keep `data-panel-sidebar-scroll`, and nested menu sections, including PBX → Advanced, preserve the recursive `persistedNavigation` flag in `resources/views/components/sidebar-menu-item.blade.php` so navigating between pages never resets scroll position.

**Tooltips:** Hover over items in the control panel or the ⓘ icon to see more information.

## Running Tests

Tests use Pest with a tiered runner for speed:

```bash
# Smoke — critical-path verification (~20s)
php artisan app:test --smoke

# Default — all feature tests, parallel by default (~65s)
php artisan app:test

# Full — features + Dusk browser tests (~150s, requires Chromium; do not use the
# web panel in another tab while they run — see INSTALL.md, "Browser Testing (Dusk)")
php artisan app:test --full

# Filter a specific test file
php artisan test --filter=AdminAuthTest

# Sequential debugging (no --parallel)
php artisan app:test --sequential

# Optional: clear Laravel caches before testing
php artisan app:test --smoke --clear-cache
```

The `app:test` command applies `--parallel` and `--compact` automatically. Both `php artisan app:test` and `php artisan test` use an in-memory SQLite database and temporary array-backed cache and session stores, so test data cannot use the server's MariaDB database. The recommended `app:test` command also removes inherited `.env` values before it starts Pest. A second check stops a test process if it is configured with anything other than in-memory SQLite. Run smoke after small changes, default before committing, and full before pushing.

The smoke tier includes a tiny seeded PBX XML-handler repeat check that makes sure internal, inbound, outbound, and generated-cache dialplan responses continue to work without running external SIPp traffic or high-volume load.

## PBX Dialplan Load Testing

The primary PBX performance test is the Laravel-generated dialplan XML path, not direct SIP traffic to FreeSWITCH. Direct SIP tests mostly measure FreeSWITCH; `pbx:load-test:dialplan` measures the app path that FreeSWITCH reaches through `mod_xml_curl`.

Seed repeatable synthetic PBX data:

```bash
php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=load.test.local \
  --extensions=100 \
  --start=2000 \
  --password='LoadTest1234' \
  --reset
```

Run against the real Nginx/PHP-FPM endpoint, not `php artisan serve`:

```bash
php artisan pbx:load-test:dialplan \
  --tenant=load-test-beta \
  --url=http://PBX_HOST/api/v1/xml-handler \
  --scenario=mixed \
  --requests=100 \
  --concurrency=5 \
  --token="$FREESWITCH_XML_HANDLER_TOKEN" \
  --label="small-office-smoke" \
  --max-failure-rate=0 \
  --max-average-ms=1000 \
  --report=storage/app/load-tests/dialplan-smoke.json
```

Use `--scenario=cache-hit` to repeat one exact dialplan lookup and isolate XML cache-hit behavior. Use `--scenario=mixed` for a more realistic blend of internal, inbound, and outbound requests.

For concurrency testing, prefer Redis or another memory-backed store for `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE`. After changing cache-related env values, run `php artisan optimize:clear` followed by `php artisan optimize` before load testing the real PHP-FPM endpoint. Avoid running `optimize:clear` while traffic is active; PHP-FPM workers can briefly fail if they request bootstrap cache files while those files are being rebuilt.

Useful tiers:

| Tier | Requests | Concurrency | Purpose |
|---|---:|---:|---|
| Baseline | 25 | 1 | Confirm endpoint health and cold/warm latency. |
| Smoke | 100 | 5 | Catch auth, XML, routing, and moderate queueing issues. |
| Practical repeat check | 500 | 10-25 | Expose PHP-FPM, DB, Redis, and contributor problems that may return on small/medium workloads. |
| Optional stability | 1,000 | 25 | Use after meaningful code/config changes to confirm a longer burst stays stable. |

The command writes JSON reports under `storage/app/load-tests/`. Reports include run labels, Git commit state, load-generator environment details, scenario counts, success/failure rates, latency sample counts, threshold settings, and pass/fail reasons. The current `192.168.1.76` beta host is a Windows-hosted VM, so use these runs for relative comparison checks rather than capacity claims. Run heavier capacity tiers only on representative VPS/datacenter hardware. The detailed operational guide is in `docs/load-testing-guide.md`, and authoritative benchmark measurements and hardware sizing tables are in `docs/load-testing-results.md`.

Report terms: `req/sec` is completed XML handler responses per second. `average` is the mean response time across all requests. `min` is the fastest response, and `max` is the slowest single response in the run.

For SIPp end-to-end validation, run the load generator from WSL2 or a separate Linux VM when possible:

```bash
PBX_HOST=PBX_HOST \
LOAD_GENERATOR_IP=LOAD_GENERATOR_IP \
FORCE_SEED=1 \
scripts/pbx-sipp-validate.sh
```

The SIPp runner registers seeded users, starts an auto-answer registered endpoint, starts an outbound-route UAS, places extension-to-extension and outbound-route calls, and writes artifacts under `storage/app/load-tests/sipp-e2e-*`.

- Add `MEDIA_FLOW=1` for live media RTP echo validation of recording (`*732`), music-on-hold (`load_test_moh`), and IVR announcements (`load_test_announcement`).
- Add `EXTENDED=1` to run all 8 extended telephony parity scenarios: ring groups (`2400`), voicemail (`2003`), conferences (`2500`), loopback call forwarding (`2001` -> `2000`), time conditions (`2401`), follow-me (`2002`), emergency (`911`), and call blocking.

Expected basic SIPp result: the script exits `0`, `summary.md` shows all scenarios passed, and each SIPp log shows successful calls equal to the requested count with zero failed calls. The unified load testing guide is in `docs/load-testing-guide.md`, and empirical benchmark measurements are in `docs/load-testing-results.md`. It explains the topology, manual commands, artifacts, expected results, and how to interpret failures.

### PHP-FPM Load-Test Tuning

Inspect active PHP-FPM worker limits:

```bash
php-fpm8.5 -tt 2>&1 | grep -E 'pm\.max_children|pm\.start_servers|pm\.min_spare_servers|pm\.max_spare_servers|pm\.max_requests'
tail -n 50 /var/log/php8.5-fpm.log
```

If the log repeatedly shows `server reached pm.max_children`, requests are queueing behind PHP-FPM. The standard install recommendation for a 4 GB combined PBX/application server is:

```ini
pm = static
pm.max_children = 12
pm.max_requests = 500
```

In `static` mode, all workers are ready for FreeSWITCH XML handler bursts; `pm.start_servers`, `pm.min_spare_servers`, and `pm.max_spare_servers` are ignored. Use `INSTALL.md` for small/standard/larger server sizing guidance. More workers are not automatically faster: tune PHP-FPM while watching CPU load, memory, MariaDB, and XML handler latency.

### Telephony XML Cache Tuning & Hit Rate Sweep

TallPBX uses a tiered in-memory caching architecture backed by Redis to keep dialplan lookups fast and protect MariaDB during high-frequency call bursts:

1. **Full Dialplan XML Cache (`FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5`)**: Stores the complete compiled XML response per tenant, context, and destination. Eliminates dialplan rebuilding for repeat calls within the TTL window.
2. **Contributor Cache (`FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=5`)**: Caches individual dialplan contributor query fragments (extensions, ring groups, call forwards, IVRs) across calls to different destinations.
3. **Directory Cache (`FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_TTL=5`)**: Caches SIP authentication and registration lookups.

To benchmark all 5 standard cache configurations and calculate Redis hit rates on your hardware:

```bash
bash scripts/run-cache-sweep.sh
```

The script runs a standardized 5-tier sweep (Cold baseline, Contributor-only, Default 5s burst, Call-center 30s profile, and Memory hit ceiling), reporting requests/sec, average latency, and Redis keyspace hit rates, and automatically restores production defaults upon completion.

Check active Redis cache hit statistics at any time:

```bash
redis-cli info stats | grep -E 'keyspace_hits|keyspace_misses'
```

Recommended settings in `.env`:
- **Standard Office**: `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5` (default: optimal balance between high burst throughput and quick 5-second propagation of panel changes).
- **High-Density Call Center**: `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=30` (achieves ~99% cache hit rate and maximum request concurrency).
- **Development**: `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=0` (disables XML response caching for instant inspection of dialplan changes).

## Tech Stack

| Component | Version |
|---|---|
| Laravel | 13 |
| Livewire | 4 |
| Tailwind CSS | 4 |
| DaisyUI | latest |
| Alpine.js | 3 (via Livewire) |
| Laravel Boost | 2 (dev) |
| Pest | 4 |

## License

Open-sourced under the [Apache 2.0 license](https://opensource.org/licenses/Apache-2.0).
