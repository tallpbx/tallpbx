# Installation Guide

This guide explains how to set up TallPBX on a new Debian 13 server. Most
installations only need the first five sections; later sections cover optional
certificates, maintenance, and advanced tuning.

TallPBX is a web-managed phone system that runs on your own server. It lets you
manage phones, extensions, call routing, voicemail, recordings, and related
PBX features. It installs FreeSWITCH to handle calls. It does not include a
telephone carrier connection; add a SIP trunk or gateway later for public phone
network calling.

By the end of the basic install, you will have a working TallPBX web panel and
the Default tenant. You can then add your own phones, users, extensions,
numbers, and SIP trunk through the panel. Selecting demo data additionally
creates sample tenants and callable PBX data for evaluation.

## 1. Create the Virtual Machine

- **Platform**: VMware or VirtualBox for a test system; KVM or a physical server for a live system
- **OS Type**: Linux, Debian 13 (64-bit)
- **Hardware Requirements**:
  - **Recommended Minimum (Development)**:
    - CPU: 4 CPUs or vCPUs
    - Storage: 40GB storage
    - RAM: 4GB RAM
    - Swap: 2GB swap
    *(Provides sufficient capacity for Vite/Tailwind asset compilation, running Pest test suites in parallel, and Dusk headless browser testing).*
  - **Minimum (Production)**:
    - CPU: 1 vCPU minimum (2+ vCPUs recommended for active PBX workloads)
    - Storage: 25 GB minimum (40 GB+ recommended for local call recordings and voicemail storage)
    - RAM: 1 GB minimum
    - Swap: 2 GB swap minimum
  - **Network**: Bridged or Host-Only adapter (enp0s3)

## 2. Attach the Installer

Download the Debian 13 amd64 netinst ISO and attach it as the VM's optical drive.

## 3. Install Debian 13

Boot the VM and proceed through the Debian installer UI:

1. Select language, location, and keyboard layout
2. Configure network (DHCP is fine for initial install)
3. Set hostname and domain
4. Set root password
5. Create a standard user account
6. Partition disk (guided - use entire disk is simplest)
7. **Software selection**: check only:
   - SSH server
   - Standard system utilities
8. Install GRUB boot loader to the master boot record

After installation completes, the VM will reboot. Log in as root.

### Configure a Swapfile (When Total Swap Is Below 2 GB)

Check whether the server already has an active swap partition or swapfile:

```bash
swapon --show
```

If the command produces no output or reports less than 2 GB total swap, create a
2 GB swapfile or make it large enough to bring total swap to at least 2 GB.

The command uses `MiB` rather than `MB` because `MiB` is the binary unit used
by Linux memory tools. With `count=2048`, it creates a swapfile a little over
2 GB in decimal terms.

```bash
swapoff /swapfile
rm -f /swapfile
dd if=/dev/zero of=/swapfile count=2048 bs=1MiB
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
```

Make the swapfile persistent across reboots:

```bash
nano /etc/fstab
```

Add the following line to the bottom of `/etc/fstab` if it is not already present:

```text
/swapfile swap swap defaults 0 0
```

Verify that the swapfile is active:

```bash
swapon --show
free -h
```

### IPv4 Preference on Hosts Without an IPv6 Default Route

Some VPS providers assign a global IPv6 address without a default IPv6 route.
PHP then tries IPv6 first when it downloads files from dual-stack hosts such as
`getcomposer.org`, waits for the connection to time out, and the installer
fails at the "Installing Composer" step with:

```text
PHP Warning: copy(https://getcomposer.org/installer): Failed to open stream: Connection timed out
```

The installer now detects this situation automatically and asks the whole
system to prefer IPv4 by activating the IPv4 precedence rule in `/etc/gai.conf`.
Hosts with a working IPv6 default route, or hosts where the rule is already
active, are left untouched.

To confirm the rule after an install:

```bash
grep precedence /etc/gai.conf   # the ::ffff:0:0/96 line should be active
```

To undo the preference manually, put a `#` back in front of the
`precedence ::ffff:0:0/96  100` line (or remove the line).

## 4. Configure a Static IP Address (Optional)

If you need a static IP address, edit the network interfaces file:

```bash
nano /etc/network/interfaces
```

Replace the DHCP line for your interface with:

```
iface enp0s3 inet static
address 192.168.1.76
netmask 255.255.255.0
gateway 192.168.1.254
dns-nameservers 192.168.1.254 8.8.8.8 8.8.4.4
```

Restart networking to apply:

```bash
systemctl restart networking
```

Verify the configuration:

```bash
ip addr show enp0s3
```

## 5. Run the Install Script

Install TallPBX with a single command:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash
```

The command downloads a small bootstrap script and runs it. The bootstrap
prepares the TallPBX source code in `/var/www/tallpbx`, then starts the main
installer, which asks the setup questions below and installs everything.

Options placed after `-s --` are passed to the bootstrap and the installer:

```bash
# Pin the stable 1.1 release branch instead of the default main:
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --ref 1.1

# Skip the demo-data question (recommended for production):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --no-demo

# Install without demo data or development tooling (recommended for production):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --no-demo --no-development
```

Re-running the command safely updates TallPBX and keeps all data. Use the
same `--ref` value every time: without it, the re-run switches the working
copy to `main`.

A headless run cannot answer the installer's questions, so it must supply two
environment values. `FSPBX_FREESWITCH_INSTALL_METHOD` chooses how FreeSWITCH
is installed (`packages` or `source`). A second value,
`FSPBX_INITIAL_ADMIN_MODE`, chooses how the first administrator is created:
`activation-code` prints a one-time code for the `/panel/setup` page,
`trusted-network` lets the first visitor create the account, and `installer`
creates it during installation from pre-seeded credentials (see "First
Administrator Setup" below). The example uses the browser activation code:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | FSPBX_FREESWITCH_INSTALL_METHOD=packages FSPBX_INITIAL_ADMIN_MODE=activation-code bash
```

To create the administrator during installation instead, pre-seed
`/etc/pbx/installer.env` with `FSPBX_ADMIN_USERNAME` and `FSPBX_ADMIN_PASSWORD`
before running the same command.

### Verified Installation (Optional)

For production servers, install a release and verify the bootstrap before
running it. The GitHub release notes for every release publish the SHA-256
checksum of `scripts/bootstrap.sh` at that release tag:

```bash
release_tag="v1.1.2"   # the release you are installing
wget -O /tmp/tallpbx-bootstrap.sh "https://raw.githubusercontent.com/tallpbx/tallpbx/${release_tag}/scripts/bootstrap.sh"
echo "<checksum-from-the-release-notes>  /tmp/tallpbx-bootstrap.sh" | sha256sum --check && bash /tmp/tallpbx-bootstrap.sh --ref "${release_tag}"
```

The checksum must come from the same release tag used in the download URL and
in `--ref`. When the checksum does not match, the check fails visibly and the
install does not start.

### Manual Installation

To prepare the source code yourself (for example from a mirror):

```bash
apt-get install -y git && mkdir -p /var/www && cd /var/www && git clone https://github.com/tallpbx/tallpbx.git
cd /var/www/tallpbx && bash ./scripts/install.sh
```

### Installer Questionnaire

Before it installs or changes packages, services, databases, or application
files, the main installer collects every choice needed for that run: demo data,
development tooling, database password, FreeSWITCH method, any required
SignalWire token, an existing source-build decision, and first-install
administrator setup mode. The choices and any installer-mode credentials are
validated and saved in the root-only `/etc/pbx/installer.env` state file. The
installation phase then does not stop for more questions. If a run fails,
re-running it reuses those saved choices by default.

Individual resource scripts can still be run directly for maintenance. In that
case they may ask only for the value needed by that standalone operation.

### Demo Data Modes

- **No flags**: The installer asks both questions on every interactive run.
  The previous choices are the defaults, so pressing Enter preserves them.
- **`--no-demo`**: Does not ask to add demo data, skips demo-data
  reconciliation, and never deletes previously seeded demo records.

### Development Tooling

Development tooling is only for people who will write or test TallPBX code on
this server. It adds developer utilities such as automated tests and Laravel
Boost. It is not needed to make calls, manage users, or run the PBX.

For development servers, the recommended minimum hardware requirements are
**4 CPUs or vCPUs, 40GB storage, 4GB RAM, and 2GB swap** to accommodate running
automated test suites (over 2,290 unit/feature tests and Dusk browser tests)
and compiling frontend assets with Vite.

For a normal PBX server, choose **No** when asked about development tooling, or
run:

```bash
bash ./scripts/install.sh --no-development
```

This keeps only the software needed to run TallPBX and removes previously
installed developer packages. Choose **Yes** only when this server will also be
used for TallPBX development.

The installer remembers the last choice. With no flags, it asks again and
pressing Enter keeps the current choice. When either installer flag is used,
the installer skips both the demo-data and development-tooling questions; the
choice not named by the flag stays as it was.

### First Administrator Setup

The installer asks this question before it changes packages, services, or the
database. The default is option 1.

1. **Create administrator during installation (default)** — enter the
   administrator email and password during installation. TallPBX creates the
   account before the installer finishes. The password is never displayed or
   written to the installer log.
2. **Create administrator in the web browser with a one-time activation code**
   — the installer creates no administrator. Instead, it shows a one-time
   activation code in your terminal and `/var/log/pbx-install.log`. Open
   `http://SERVER-IP/panel/setup`, enter the code, then choose the
   administrator email and password. After success the code stops working.
3. **Create administrator in the web browser without an activation code —
   trusted network only** — the installer creates no administrator. The first
   person to open `http://SERVER-IP/panel/setup` can create it. Use this only
   on a private, trusted network; do not use it on an internet-reachable
   server.

All three choices use the same TallPBX administrator-creation code. It creates
the account, grants Super Administrator access, and closes first-time setup as
one database operation. Re-runs preserve the selected mode and never replace
an existing administrator or activation code.

The script installs the following automatically:

- **PHP 8.5** and the extensions TallPBX needs
- **Nginx 1.26** (web server)
- **MariaDB 11.8** (database server)
- **Redis** (fast temporary storage)
- **Node.js** and **Composer** (used while installing TallPBX)
- **FreeSWITCH** (the phone system)
- **TallPBX and its web application dependencies**
- **Developer tools** only when you choose development tooling

TallPBX is installed in `/var/www/tallpbx` as a normal Git working copy. The
installer also creates server-only files there, such as passwords, installed
packages, and generated web assets. Do not add those files to Git.

After installation, open the server's IP address in a browser to reach TallPBX.
Sign in with the administrator email and password you chose in option 1, or
complete the browser setup page when you selected option 2 or 3. There is no
default TallPBX administrator password.

### Outgoing Mail Configuration (Email Connector)

To enable email delivery for password resets, voicemail notifications, backup reports, and system alerts, sign in to the web panel and open **Email Connector** from the navigation menu (sidebar or top header). TallPBX supports standard username/password SMTP authentication (including Gmail App Passwords) as well as modern token-based OAuth 2.0 authentication for Google (Gmail), Microsoft 365, or custom OAuth 2.0 providers.

### Check That TallPBX Is Working

For a quick health check after installation, run:

```bash
cd /var/www/tallpbx
php artisan app:test --smoke
```

This checks important application functions without using the real TallPBX
database. Developers who need broader checks can use:

```bash
# Run all application tests.
php artisan app:test

# Also run browser tests. Chromium is required.
php artisan app:test --full

# Run one test process at a time when investigating a failure.
php artisan app:test --sequential
```

Tests run in a temporary in-memory database, not in the real TallPBX database.
They stop if that safety rule is not in effect. TallPBX also blocks Laravel
commands that would erase or rebuild the primary database. These safeguards do
not prevent a person with MariaDB access from running destructive SQL manually.

### Browser Testing (Dusk)

Browser tests open TallPBX in Chrome or Chromium and check that the panel works
as a user would see it. They are optional and mainly useful for development.

```bash
# Install Chromium (Debian 13)
apt-get install -y chromium

# Install matching ChromeDriver
php artisan dusk:chrome-driver

# Run browser tests against the isolated tallpbx_dusk database
bash scripts/dusk.sh
```

The installer gives browser tests their own `tallpbx_dusk` database and login.
That login cannot access the real `tallpbx` database, so browser testing cannot
reset normal PBX data.

> **Do not use the web panel while browser tests are running.** When
> `php artisan dusk` starts, it temporarily swaps the application's `.env` file
> with `.env.dusk` and restores it when the run finishes (even after a failure).
> If you sign in — or any browser request reaches the panel — during that
> window, the request runs with the Dusk environment instead of your normal one:
>
> - Your login session and any changes belong to the disposable `tallpbx_dusk`
>   database, not your live data; the session can appear to "not stick" and
>   signing in may look broken.
> - Your activity shares sessions and Redis counters with the running tests,
>   which makes Dusk tests fail or behave unpredictably.
> - Failed login attempts are still counted by the intrusion-detection system.
>
> Wait for the run to complete before signing in. The firewall side is
> safeguarded as well: privileged kernel firewall actions are disabled
> automatically while the Dusk environment is active, so a concurrent login can
> never change your live firewall rules.

Documentation screenshots in `docs/images/` are not touched by normal runs. To
recapture them after changing the interface, run the suite with the capture
flag and commit only the images that actually changed:

```bash
DUSK_CAPTURE_DOCS=1 bash scripts/dusk.sh
```

### Service Management

TallPBX relies on background systemd services for call events, queue workers, scheduled tasks, real-time WebSockets, and Redis. Use these commands to check or restart them:

*   **FreeSWITCH Telephony Engine**:
    ```bash
    systemctl status freeswitch          # Check FreeSWITCH status
    systemctl restart freeswitch         # Restart FreeSWITCH
    ```
*   **ESL Call Event Listener**:
    ```bash
    systemctl status freeswitch-listener # Check ESL event listener status
    systemctl restart freeswitch-listener # Restart the event listener
    ```
*   **Background Queue Worker**:
    ```bash
    systemctl status tallpbx-queue       # Check async queue worker (recordings, media archival, mail)
    systemctl restart tallpbx-queue      # Restart queue worker
    ```
*   **Background Scheduler**:
    ```bash
    systemctl status tallpbx-scheduler   # Check cron scheduler (reconcile, backup retention, broadcast sweeps)
    systemctl restart tallpbx-scheduler  # Restart scheduler
    ```
*   **Reverb WebSocket Server**:
    ```bash
    systemctl status tallpbx-reverb      # Check real-time Livewire broadcasting daemon (port 8080)
    systemctl restart tallpbx-reverb     # Restart WebSocket server
    ```
*   **Redis Temporary Storage**:
    ```bash
    systemctl status redis-server        # Check Redis status
    redis-cli ping                       # Expect: PONG
    ```
*   **Kernel Firewall (`nftables`)**:
    ```bash
    systemctl status nftables            # Check kernel firewall service status
    nft list ruleset                     # Display active kernel firewall rules and sets
    php artisan security:status          # Check TallPBX security module status and active bans
    ```

### Running the Installer Again

It is safe to run the installer again after an interrupted install or an
ordinary software update. It keeps existing call data, users, settings, and
database records. It reuses saved passwords and choices, keeps the existing
application key, and applies only missing database updates.

You can also re-run the one-line command from Section 5: it safely updates the
working copy in `/var/www/tallpbx` and then runs this installer again. Use the
same `--ref` value every time.

On a re-run, the installer:

- Ensures PHP, MariaDB, Nginx, Redis, and FreeSWITCH are installed and running.
- Preserves the TallPBX application, its settings file, the administrator
  account, and existing database data.
- Restores the expected cache, session, media, and file-permission settings.

The installer also renews the TallPBX-to-FreeSWITCH connection, keeps required
phone-system modules enabled, and installs default prompts and music. It keeps
recordings, voicemail, faxes, and other media as files; it does not store them
inside the database.

Demo data is added only when selected. The installer never removes previously
created demo data. A new demo install includes two sample extensions, `1000`
and `1001`, so you can make a basic internal test call.

If you intentionally change between the package and source versions of
FreeSWITCH, the installer removes the old FreeSWITCH program before installing
the selected alternative. That switch affects the phone-system software, not
your TallPBX database or stored media.

### Application Mode

When development tooling is enabled, TallPBX uses Laravel's local development
mode. When it is disabled, TallPBX uses production mode with developer details
hidden from normal users. The installer sets this automatically; most
administrators do not need to change `APP_ENV` themselves.

If an install stops because of a network problem or missing dependency, run the
installer again after fixing the problem. It continues safely from the saved
state.

### Redis Cache And Session Storage

Redis is TallPBX's fast temporary storage. It keeps logins, cached information,
and phone-system lookups responsive. A new install configures it automatically.

For an older server that does not yet use Redis, run:

```bash
apt-get install -y redis-server redis-tools php8.5-redis
systemctl enable --now redis-server
redis-cli ping

cd /var/www/tallpbx
sed -i 's/^CACHE_STORE=.*/CACHE_STORE=redis/' .env
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=redis/' .env
grep -q '^SESSION_CONNECTION=' .env \
  && sed -i 's/^SESSION_CONNECTION=.*/SESSION_CONNECTION=cache/' .env \
  || echo 'SESSION_CONNECTION=cache' >> .env
grep -q '^FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=' .env \
  && sed -i 's/^FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=.*/FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis/' .env \
  || echo 'FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis' >> .env

php artisan optimize:clear
php artisan optimize
```

For a short-lived local development system without Redis, use file-based cache
and sessions instead, then rebuild Laravel's configuration.

> [!NOTE]
> **Intrusion Detection & Redis Decoupling:**
> TallPBX's in-process intrusion detection engine (`SecurityIncidentService`) maintains per-IP sliding-window failure counters directly in Redis memory, completely independent of the application's configured `CACHE_STORE`. Setting `CACHE_STORE=file` for general caching will **not** disable security incident tracking.
> 
> If Redis is not installed or the `redis-server` service is temporarily stopped:
> - The PBX and web interface fail open gracefully without throwing 500 errors (incident tracking errors are caught and logged to `storage/logs/laravel.log`).
> - Dynamic sliding-window bans pause since attempt counters cannot be stored in memory.
> - The Linux kernel `nftables` firewall, permanent blacklists, whitelists, and manual UI bans remain 100% operational.
> 
> For production telephony servers, running `redis-server` is strongly recommended so dynamic attack mitigation is active.

### FreeSWITCH Session Rate

Fresh installs set FreeSWITCH's core `sessions-per-second` default to `60`:

```xml
<param name="sessions-per-second" value="60"/>
```

This is a safety limit for new calls. Most servers should leave it at `60`.
Change it only after measured load testing shows that this specific limit is
holding back a server with enough CPU, memory, and network capacity.

### PHP-FPM Worker Sizing

PHP-FPM runs the Laravel web application. The Debian default is suitable for a
small or lightly used system. Change these settings only when monitoring shows
that the server is running out of PHP-FPM workers or needs faster response to
large bursts of calls.

For a busier 1 GB server, this optional setting can help:

```ini
pm = static
pm.max_children = 6
```

For a typical 4 GB server running TallPBX, MariaDB, Redis, and FreeSWITCH:

```ini
pm = static
pm.max_children = 12
```

Leave the other static-worker settings unchanged. Leave `pm.max_requests` at
its default of `0` unless monitoring shows that PHP workers steadily grow in
memory use.

General sizing guidance:

| Server size | Suggested setting | Notes |
| --- | --- | --- |
| Minimum, 1 GB RAM | Keep Debian defaults | Change only after monitoring. |
| Small, 2 GB RAM | `pm.max_children = 6` | A reasonable starting point. |
| Standard, 4 GB RAM | `pm.max_children = 12` | Recommended starting point. |
| Larger, 8 GB RAM | `pm.max_children = 24` | Confirm memory is still available. |
| High-volume | Measure first | Tune from real traffic data. |

For high-volume systems, measure worker memory under normal call traffic before
choosing a larger value:

```text
available RAM reserved for PHP-FPM / average warm PHP-FPM worker RSS
```

Always leave memory for FreeSWITCH, MariaDB, Redis, Nginx, recordings, and the
operating system. More workers are not always faster. Check
`/var/log/php8.5-fpm.log` for worker-limit warnings and test changes under real
call traffic.

### Application File Permissions

TallPBX keeps application code protected from the web server while allowing
Laravel to write logs, cache files, and media. The installer applies the right
ownership and permissions automatically.

After a manual deployment, Composer update, or permission error, run:

```bash
cd /var/www/tallpbx
sudo php artisan permissions:repair --scope=full
```

Do not run broad recursive `chown` or `chmod` commands over the application
directory. They can make the source writable by the web server or break helper
scripts. If a normal repair cannot start because Laravel itself will not boot,
use the emergency fallback:

```bash
cd /var/www/tallpbx
sudo bash scripts/repair-application-permissions.sh
```

Restarting FreeSWITCH does not change TallPBX file permissions. Run a repair
only after an application-file deployment, a root-owned maintenance operation,
or an actual permission error.

### Managed Media Permissions

Call recordings, faxes, and voicemail are written by FreeSWITCH into
`/var/lib/tallpbx/media` and read back by PHP-FPM (`www-data`) for archiving.
The service user `freeswitch` runs without supplementary groups (FreeSWITCH
drops them at startup even when the installer adds it to `tallpbx-media`), so
the whole media tree must be traversable by "other" and the FreeSWITCH-owned
leaf directories must carry the setgid bit:

```bash
# Traversal for the group-less freeswitch process
chmod 755 /var/lib/tallpbx /var/lib/tallpbx/media /var/lib/tallpbx/media/store /var/lib/tallpbx/media/spool
chmod 755 /var/lib/tallpbx/media/store/runtime /var/lib/tallpbx/media/store/runtime/*

# FreeSWITCH-owned leaf directories (files inherit tallpbx-media via setgid)
chown freeswitch:tallpbx-media /var/lib/tallpbx/media/spool/*/call-recording \
    /var/lib/tallpbx/media/spool/*/fax-inbound \
    /var/lib/tallpbx/media/store/runtime/*/voicemail-message
chmod 2775 /var/lib/tallpbx/media/spool/*/call-recording \
    /var/lib/tallpbx/media/spool/*/fax-inbound \
    /var/lib/tallpbx/media/store/runtime/*/voicemail-message
```

Directories that only the application writes (`store`, `spool`, `runtime`, `archive`,
and per-tenant subdirectories) stay owned by `www-data:tallpbx-media` with mode
`2775` so group-write survives and the setgid bit keeps new subdirectories in
the `tallpbx-media` group.

The `freeswitch-listener` and `tallpbx-queue` systemd units run under `ProtectSystem=strict`
and explicitly allow write access to `/var/www/tallpbx/storage`, `/var/lib/tallpbx/media`,
`/var/lib/tallpbx/backups`, and `/var/lib/tallpbx/restore-requests` via `ReadWritePaths`.
If you configure a custom `TALLPBX_MEDIA_ROOT`, add that path to the unit's `ReadWritePaths` line in
`/etc/systemd/system/freeswitch-listener.service` and `/etc/systemd/system/tallpbx-queue.service`,
then run `systemctl daemon-reload && systemctl restart freeswitch-listener tallpbx-queue`.

### Firewall & Network Security (nftables)

TallPBX protects the operating system and telephony engine using native Linux kernel packet filtering (`nftables`) paired with real-time multi-vector intrusion defense in `app-modules/security`.

#### 1. Standard Network Ports
The installer configures baseline firewall rules to permit necessary PBX services while dropping malicious scanning and unwanted traffic:

| Service | Port / Protocol | Direction | Description |
| :--- | :--- | :--- | :--- |
| **SSH** | `22/tcp` | Inbound | Remote server management (can be restricted to trusted subnets) |
| **HTTP** | `80/tcp` | Inbound | Web panel redirect & Let's Encrypt ACME verification |
| **HTTPS** | `443/tcp` | Inbound | Secure web panel & WebSocket traffic |
| **SIP Internal** | `5060/udp,tcp` | Inbound | Softphones, desk phones, and internal registrations |
| **SIP TLS** | `5061/tcp` | Inbound | Encrypted SIP signaling |
| **SIP External** | `5080/udp,tcp` | Inbound | Upstream SIP carriers, gateways, and PSTN trunks |
| **RTP Media** | `16384-32768/udp` | Inbound | Voice & video RTP audio streams |
| **Reverb WebSockets**| `8080/tcp` | Loopback | Internal real-time event broadcasting daemon |

#### 2. Privilege Separation & Bounded Sudoers Helper Pattern
TallPBX enforces strict least-privilege boundaries to eliminate command injection (CWE-78) and root privilege escalation:
- **Unprivileged Web User**: All PHP-FPM and web requests execute strictly under `www-data`. Direct execution of system binaries like `/usr/sbin/nft` under `sudo` is forbidden.
- **Dedicated Root Helper**: Privileged firewall modifications are isolated within `/usr/local/sbin/tallpbx-security` (`mode 0750 root:www-data`).
- **Bounded Sudoers Drop-In**: `/etc/sudoers.d/tallpbx-security` grants `www-data` permission to invoke *only* `/usr/local/sbin/tallpbx-security`. Wildcard sudo access (`ALL=(ALL) NOPASSWD: ALL`) is never used.
- **Strict Parameter Whitelisting**: The helper script validates every input parameter against strict regular expressions (`^[0-9a-fA-F:.]+$`) before executing any action, preventing command chaining or jailbreaking.

#### 3. Real-Time Multi-Vector Threat Defense
- **SIP Auth Scanning**: Real-time FreeSWITCH ESL events (`sofia::failed_auth`) detect credential brute-forcing instantly and ban malicious IPs in sub-seconds directly in the kernel table `@banned_ips`.
- **Web Login Protection**: Failed web login attempts (`Illuminate\Auth\Events\Failed`) are rate-limited in Redis and trigger automatic temporary bans.
- **Dynamic Kernel Sets**: Banned IPs are stored directly in nftables kernel timeout sets, dropping attacking packets before they consume web server or PBX CPU cycles.

#### 4. Lockout Prevention & Emergency Administration
- **Zero-Lockout Protection**: TallPBX checks the administrator's remote IP address and session before applying restrictive default `DROP` policies. Whitelisted IPs and administrative subnets can never be banned.
- **Terminal Management & Unbanning**: If an administrator or trusted device is blocked or needs emergency access, manage the firewall directly from the host CLI:
  ```bash
  # Check active firewall status, kernel sets, and banned attackers
  php artisan security:status

  # Immediately unban an IP address
  php artisan security:unban <IP_ADDRESS>

  # Or invoke the bounded helper directly
  sudo /usr/local/sbin/tallpbx-security unban <IP_ADDRESS>

  # Emergency fallback: temporarily flush all rules if locked out
  sudo nft flush ruleset
  ```

## 6. FreeSWITCH Installation Choice

The installer asks whether to install FreeSWITCH from packages or from source
code. It remembers the choice for later runs. You can switch later, but the
installer must remove the old FreeSWITCH program before installing the other
type. This does not remove your TallPBX database or stored recordings.

### Package Install

This is the recommended choice for most servers. It is faster to install and
update, but requires a SignalWire Personal Access Token. The installer asks
for the token before it begins installation and saves it securely for future
runs. It installs the modules TallPBX needs for SIP phones, voicemail,
recordings, call queues, music, and dynamic configuration.

TallPBX automatically configures FreeSWITCH to request its phone directory and
call-routing instructions from the application. It writes a small connection
file similar to this:

```xml
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="tallpbx">
      <param name="gateway-url" value="http://SERVER/api/v1/xml-handler?token=TOKEN" bindings="directory|dialplan|configuration"/>
      <param name="timeout" value="5"/>
    </binding>
  </bindings>
</configuration>
```

If you manually change `FREESWITCH_XML_HANDLER_TOKEN`, run the following and
then restart FreeSWITCH:

```bash
cd /var/www/tallpbx
bash scripts/resources/freeswitch.sh --configure-only
systemctl restart freeswitch
```

To get a SignalWire token:

1. Go to https://signalwire.com
2. Sign up or log in
3. Navigate to Personal Access Tokens
4. Create a new token with repo access

### Source Build

Use this only when you need to change FreeSWITCH itself or cannot use the
SignalWire package repository. It takes much longer because the server compiles
FreeSWITCH from source code. No SignalWire token is needed. On later runs, the
installer asks whether to rebuild the existing source installation.

## 7. HTTPS Certificates (Optional)

TallPBX includes helper scripts for free Let's Encrypt certificates and
Cloudflare DNS. Run them after the main install when you are ready to use a
public domain name.

### Let's Encrypt (Single Domain)

Use this for one domain name. Nginx must be running, and the internet must be
able to reach port 80 on this server.

```bash
cd /var/www/tallpbx/scripts/resources

# Interactive (prompts for domain and email):
bash letsencrypt.sh

# Non-interactive:
bash letsencrypt.sh --domain pbx.example.com --email admin@example.com

# Test with staging environment first (no rate limits):
bash letsencrypt.sh --domain pbx.example.com --staging
```

After success, the script updates Nginx to use HTTPS. Certificates renew
automatically.

### Wildcard Certificate (Cloudflare DNS)

Use this only when you need a wildcard certificate such as `*.example.com`.
It requires Cloudflare DNS access.

```bash
cd /var/www/tallpbx/scripts/resources

# Save Cloudflare API token:
bash cloudflare-dns.sh

# Issue wildcard certificate:
bash letsencrypt.sh --wildcard --domain pbx.example.com
```

The Cloudflare token needs `Zone:Zone:Read` and `Zone:DNS:Edit` permissions.
Create one at https://dash.cloudflare.com/profile/api-tokens.

### Manual Certificate

If you have a certificate from another provider, place the files at the
standard paths and reload Nginx:

```bash
# Place your certificate files:
#   Full chain: /etc/letsencrypt/live/<domain>/fullchain.pem
#   Private key: /etc/letsencrypt/live/<domain>/privkey.pem

# Then update Nginx and reload:
nginx -t && systemctl reload nginx
```

### Certificates for Tenant Domains

Add the domain under **Admin → Domains**, then issue a certificate with the
same `letsencrypt.sh` command. Nginx can serve more than one domain.

## Upgrading TallPBX

Before upgrading, take a backup. Then run these commands from
`/var/www/tallpbx`:

```bash
# 1. Pull the latest code. This only accepts a straightforward update.
# For stable production releases, track origin/1.0:
git pull --ff-only origin 1.0

# Or to track latest development:
# git pull --ff-only origin main

# 2. Install the required PHP packages
composer install --no-dev --optimize-autoloader

# 3. Rebuild the browser files
npm ci
npm run build

# 4. Run database migrations
php artisan migrate --force

# 5. Refresh TallPBX module information
php artisan module:sync --only-local

# 6. Clear all caches
php artisan optimize:clear

# 7. Restore normal application permissions
sudo php artisan permissions:repair --scope=full

# 8. Tell FreeSWITCH to load any new call-routing settings
fs_cli -x "reloadxml"
```

### If Git Will Not Pull the Update

Git may refuse the update when this server has local changes that conflict with
the new code. Do not delete those changes. First save them temporarily, update
TallPBX, then restore them:

```bash
# Save local changes, including new files, outside the working copy for now.
git stash push --include-untracked -m "before TallPBX upgrade"

# Download the straightforward update from the release branch (1.0 or main).
git pull --ff-only origin 1.0

# Put the saved local changes back after the update.
git stash pop
```

If the final command reports a conflict, do not continue with the upgrade until
the conflicting local change has been reviewed and resolved. The saved change
remains available as a Git stash until it is applied successfully.

After upgrading, confirm that the panel opens, FreeSWITCH is running, and a
test call works. If an upgrade fails, restore the backup. `php artisan migrate
--force` applies only database updates that have not already run; it does not
erase the database. Database updates move forward and should not be rolled back
casually.
