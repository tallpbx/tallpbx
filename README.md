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

Built to eliminate the steep learning curve and dated interfaces of legacy PBX platforms without compromising the raw carrier power of FreeSWITCH®, TallPBX delivers feature and function parity with established open-source systems on a modern Laravel 13, Livewire 4, and Tailwind CSS architecture:

| Feature | TallPBX | FusionPBX | FreePBX® |
| :--- | :--- | :--- | :--- |
| **Telephony Engine** | **FreeSWITCH 1.11** (`mod_sofia`, `mod_callcenter`) | **FreeSWITCH 1.11** | Asterisk 20+ (PJSIP, chan_sip) |
| **Web Architecture** | **Laravel 13, Livewire 4, Tailwind v4** | Custom procedural/OOP PHP | BMO (custom procedural PHP) |
| **Database** | **MariaDB / MySQL** (SQLite in testing) | PostgreSQL / SQLite / MariaDB | MariaDB / MySQL |
| **Configuration** | **Dynamic `mod_xml_curl`** (zero static XML on disk) | Dynamic `mod_xml_curl` (PHP scripts) | Static `.conf` files on disk |
| **Multi-Tenancy** | **Native Multi-Tenant** (isolated contexts or domains) | Native Multi-Tenant (domain only) | Single-Tenant Core |
| **User Impersonation** | **1-Click Native Impersonation** (audit trail & banner) | Limited (domain switching only) | None |
| **Multi-Lingual** | **Native English, Spanish, French** (instant switcher) | Partial / community arrays | Partial gettext / PO files |
| **Firewall & Defense** | **In-Kernel `nftables` + Real-Time Threat Defense** | Fail2ban / iptables scripts | Basic iptables / Fail2ban |
| **Host Security** | **Hardened Bounded Sudoers** (zero web shells or raw SQL) | Web shell (`app/exec`) & raw SQL | Sudoers entries for Asterisk/Apache |
| **Automated Tests** | **2,600+ Pest tests incl. Pest Browser tests** | Minimal / community scripts | Minimal unit tests |
| **Licensing** | **Apache 2.0** (100% open source) | MPL 1.1 (Open source) | GPLv3 (Core) + Commercial closed modules |

See the [Feature and Function Parity Guide](docs/parity-comparison.md) for a domain-by-domain breakdown across all 60 PBX modules, and the [Security Architecture & Packet Flow Guide](docs/security-architecture.md) for network defense details.

## User Interface & Visual Tour

TallPBX is built on the **TALL stack** (Tailwind CSS v4, Alpine.js, Livewire 4, Laravel 13) with DaisyUI components, offering a modern responsive control panel with light and dark themes and three switchable navigation layouts:

| Public Landing (Light Theme) | Public Landing (Dark Theme) |
| :---: | :---: |
| [![TallPBX Landing Page (Light)](docs/images/landing-light.png)](docs/ui-tour.md#1-public-guest-landing-page) | [![TallPBX Landing Page (Dark)](docs/images/landing-dark.png)](docs/ui-tour.md#1-public-guest-landing-page) |

### Unified Panel & Flexible Navigation Layouts

The single unified panel (`/panel/`) adapts to administrator preference with instant theme and layout toggles:

| Full Sidebar (`w-64`) | Mini Icon Rail (`w-16`) | Horizontal Topbar |
| :---: | :---: | :---: |
| [![Full Sidebar](docs/images/dashboard-full-sidebar.png)](docs/ui-tour.md#mode-a-full-sidebar-navigation-w-64) | [![Mini Rail](docs/images/dashboard-compressed-sidebar.png)](docs/ui-tour.md#mode-b-mini-icon-rail-sidebar-w-16) | [![Horizontal Menu](docs/images/dashboard-horizontal-menu.png)](docs/ui-tour.md#mode-c-horizontal-topbar-navigation) |

👉 **[Explore the Complete Visual Tour (Tenants, Extensions, Devices, Impersonation, Multi-Language & Layouts) →](docs/ui-tour.md)**

## Quick Start

1. Prepare a Debian 13 server with at least 1 GB RAM, 2 GiB swap, 1 CPU core, and 25 GB disk space.
2. Follow the [installation guide](INSTALL.md). It walks through server setup, the installer, and the first login.
3. Open the server's IP address in a browser and sign in with the administrator email and password chosen during installation.
4. Follow the [PBX "Hello World" Guide](docs/pbx-hello-world.md) to create your first extension, register a softphone (MicroSIP, Linphone, or desk phone), and place an audio loopback echo test call (`*9196`).
5. Consult the [Operations Guide](docs/operations.md) for production configuration, including sound prompt languages, email notifications, capacity tuning, and backups.

The installer configures TallPBX, FreeSWITCH, Nginx, MariaDB, and Redis. It is safe to re-run at any time without touching existing data. [Full installation guide →](INSTALL.md)

## Prerequisites & Hardware Requirements

- **Operating System**: Debian 13 server (64-bit)
- **Access**: Root / sudo access
- **Network**: Internet connectivity with static IP or bridged network adapter
- **Minimum Hardware (Production)**:
  - **CPU**: 1 vCPU (2+ vCPUs recommended for active PBX workloads)
  - **Storage**: 25 GB disk space (40 GB+ recommended for call recordings and voicemail)
  - **RAM**: 1 GB RAM
  - **Swap**: 2 GB swap
- **Recommended Hardware (Development / High-Volume)**:
  - **CPU**: 4 vCPUs
  - **Storage**: 40 GB storage
  - **RAM**: 4 GB RAM
  - **Swap**: 2 GB swap

## Modular Architecture

TallPBX uses a modular architecture where features reside in `app-modules/`:

- Core PHP code, migrations, views, routes, and translations are encapsulated within each module directory (`app-modules/<ModuleName>/`).
- Modules register themselves via `App\Support\ModuleServiceProvider`.
- Modules are auto-discovered through Composer path repositories and Laravel package discovery.

Scaffold a new module with Artisan:

```bash
php artisan make:module call-forwarding \
    --display-name="Call Forwarding" \
    --description="Forward calls to external numbers" \
    --category="PBX Features"
```

Manage modules from the command line:

```bash
php artisan module:list            # List installed modules and their status
php artisan module:sync            # Sync module manifests into the database registry
php artisan module:cache           # Build the module manifest cache for fast loading
```

## Telephony Integration (mod_xml_curl)

Instead of generating static XML files on disk, TallPBX serves dynamic configurations to FreeSWITCH on demand over HTTP using `mod_xml_curl`:

- **Dynamic Configuration Delivery**: Extension directories, dialplans, and configurations are generated via the XML Handler API (`/api/v1/xml-handler`).
- **Real-Time Telephony Events**: Call states, connections, and security alerts stream in real time via FreeSWITCH's Event Socket Layer (`php artisan freeswitch:listen`).
- **Redis XML Caching**: Routing lookups are cached in Redis (`FS_XML_HANDLER_CACHE_TTL=5`), protecting the database and ensuring sub-millisecond call setup under heavy load. Web panel changes invalidate active caches immediately.
- **Audio & Media Storage**: Call recordings, voicemails, and greetings are stored on disk under `/var/lib/tallpbx/media/` while metadata remains in the database.

### Tenant Isolation & Phone Provisioning

Configuring a desk phone or softphone adapts to your deployment model:

| Deployment Mode | Server Address on Phone | Extension on Phone | Login (Auth Username) | How Separation Works |
| :--- | :--- | :--- | :--- | :--- |
| **1. Single Company (Default Tenant)** | `x.x.x.x` (or domain) | `101` | `101` | **No separation needed.** All phones register in the Default tenant with clean internal extensions. |
| **2. Multi-Tenant by Domain** | `acme.yourpbx.com` | `101` | `101` | **Separated by Domain.** Each tenant has its own subdomain, allowing duplicate extension numbers across companies. |
| **3. Multi-Tenant on Shared IP** | `x.x.x.x` (shared IP) | `101` | `acme_101` | **Separated by Username.** A tenant prefix on the login ID ensures unique authentication while the phone displays `101`. |

Once registered, FreeSWITCH isolates all call flows, transfers, and voicemails within each tenant's private dialplan context (`tenant_{id}_internal`).

## Integrated Security & Threat Defense

TallPBX includes a first-party Security module (`app-modules/security`) providing defense across telephony, web, and network layers:

- **Linux Kernel Firewall (`nftables`)**: High-performance in-kernel sets (`@whitelist_ips`, `@blacklist_ips`, `@banned_ips`) with preflight validation (`nft -c -f`) ensuring broken rules are never applied.
- **Real-Time Intrusion Defense**: Scans FreeSWITCH SIP authentication failures in real time via the Event Socket Layer (ESL), intercepts web login brute-force attempts, and enforces temporary rate-based bans in Redis.
- **Zero-Lockout Safety Guard**: Prevents administrators from locking themselves out by verifying remote IP, active sessions, and local loopback services (database and Redis connections) before restrictive policies apply.
- **Hardened System Architecture**: Web processes run under the restricted web user (`www-data`). Privileged firewall operations run through a dedicated root helper (`/usr/local/sbin/tallpbx-security`) with strict input validation.

Manage security from the web panel or directly from the terminal:

```bash
php artisan security:status              # Check firewall status, active sets, and banned IPs
php artisan security:apply               # Atomically recompile and apply pending firewall rules
php artisan security:unban <IP_ADDRESS>  # Immediately unban an IP address
```

For packet flow diagrams and architecture details, see [docs/security-architecture.md](docs/security-architecture.md).

## Running the Application

```bash
# Serve the Laravel app (development)
php artisan serve

# Compile frontend assets (in a new terminal)
npm run dev
```

In production, Nginx serves the application automatically. After making code changes, clear application caches:

```bash
php artisan optimize:clear
```

### Panel Navigation & Switchable Themes

The unified control panel provides flexible ergonomics designed for wide PBX data tables (CDRs, routing rules, extensions):

- **Switchable Themes (Light, Dark, System)**: Instant toggle between Light, Dark, or System preference, saved per-user.
- **Collapsible Sidebar (Mini Rail)**: Collapse the vertical navigation down to a compact 64px icon rail to maximize table viewing space.
- **Horizontal Header Navigation**: Optional topbar dropdown layout for administrators accustomed to traditional PBX interfaces.
- **Mobile Responsive Drawer**: Viewports below 1024px automatically switch to an accessible slide-over mobile drawer.
- **Scroll Preservation**: Navigation preserves exact scroll positions across page transitions.

## Running Tests

Tests use Pest with a tiered runner for speed:

```bash
# Smoke — critical-path verification (~20s)
php artisan app:test --smoke

# Default — all feature tests, parallel by default (~65s)
php artisan app:test

# Full — features + Pest 4 browser tests via Playwright
php artisan app:test --full

# Filter a specific test file or class
php artisan test --filter=AdminAuthTest
```

All automated tests execute inside an isolated in-memory SQLite database and temporary cache, **never touching your live MariaDB database** or active calls.

## Performance & Load Testing

TallPBX is capable of handling high-concurrency telephony workloads. When calls or registrations occur, FreeSWITCH requests user extension accounts (directory) and call routing rules (dialplans) dynamically over HTTP via `mod_xml_curl`. After the initial database query, Redis caches the result in memory. Because simultaneous calls and in-call transfers frequently re-query the same extension and routing data, serving repeated lookups directly from memory avoids querying MariaDB again, delivering sub-millisecond response times.

- **Dialplan Benchmarks**: Built-in test tooling (`php artisan pbx:load-test:dialplan`) measures XML handler throughput, concurrency, and latency across synthetic workloads.
- **End-to-End SIP Validation**: The test harness (`scripts/pbx-sipp-validate.sh`) verifies SIP registrations, outbound routing, RTP media echo, call forwarding, ring groups, and voicemail.
- **Hardware Sizing**: Tested to sustain 25+ calls/sec and 250+ concurrent channels on standard 4 GB instances.

For complete benchmarking instructions, PHP-FPM pool sizing, and empirical performance metrics across hardware tiers:
- 👉 **[Load Testing Guide (docs/load-testing-guide.md)](docs/load-testing-guide.md)** — Operational runbook for running dialplan and SIPp tests.
- 👉 **[Benchmark Results & Hardware Sizing (docs/load-testing-results.md)](docs/load-testing-results.md)** — Empirical measurements, latency percentiles, and production hardware recommendations.

## Versioning & Release Strategy

TallPBX follows the **[Laravel framework versioning model](https://laravel.com/docs/releases#versioning-scheme)** and adheres to **[Semantic Versioning](https://semver.org/spec/v2.0.0.html)** (`MAJOR.MINOR.PATCH`):

- **Versioned Series Branches**: Following the pattern used by Laravel, the repository does not maintain a perpetual `main` or `master` branch. Instead, active development takes place directly on versioned series branches representing release lines (e.g. `1.1`, `2.0`, `3.x`).
- **Release Strategy**:
  - **Major Releases (`MAJOR.0.0`)**: Represent significant architectural milestones or breaking changes. For example, the `3.x` and `2.0` series introduce major modernizations and require a fresh install rather than an in-place upgrade from earlier major lines.
  - **Minor Releases (`MAJOR.MINOR.0`)**: Introduce new features, modules, and backwards-compatible enhancements within a release series.
  - **Patch Releases (`MAJOR.MINOR.PATCH`)**: Deliver targeted bug fixes, security patches, and performance improvements.
- **Active Release Branches**:
  - **`3.x`**: Current primary development branch for 3.x features and releases (latest stable: `v3.0.2`).
  - **`2.0`**: Maintenance release series for 2.x deployments (latest stable: `v2.1.1`).
  - **`1.1`**: Maintenance release series for 1.1.x deployments.
  - **`1.0`**: Frozen maintenance branch for critical security fixes only.
- **In-Place Updates**: In-place updates via the web updater panel (**System Settings → Updates**) and CLI (`scripts/update.sh`) are supported within the same release series branch. For detailed deployment notes, see [INSTALL.md](INSTALL.md), and for a complete historical record of releases, see [CHANGELOG.md](CHANGELOG.md).

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
