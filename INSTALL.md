# Installation Guide

This guide explains how to set up TallPBX on a new Debian 13 server. The main
install is one command; the sections after it cover optional configuration,
maintenance, and tuning.

TallPBX is a web-managed phone system that runs on your own server: phones,
extensions, call routing, voicemail, recordings, and related PBX features,
powered by FreeSWITCH. It does not include a telephone carrier connection; add
a SIP trunk or gateway later for public phone network calling.

After the basic install you have a working web panel and the Default tenant,
ready for your own phones, users, extensions, and trunks. You can also add
demo data, which creates sample tenants and callable extensions for evaluation.

## 1. Create the Virtual Machine

- **OS Type**: Linux, Debian 13 (64-bit)
- **Hardware Requirements**:
  - **Minimum (Production)**:
    - CPU: 1 vCPU minimum (2+ vCPUs recommended for active PBX workloads)
    - Storage: 25 GB minimum (40 GB+ recommended for local call recordings and voicemail storage)
    - RAM: 1 GB minimum
    - Swap: 2 GB swap minimum
  - **Recommended (Development)**: 4 vCPUs, 40 GB storage, 4 GB RAM, 2 GB swap
    *(needed to compile frontend assets and run the test suites).*

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

Check the current swap:

```bash
swapon --show
```

If less than 2 GB is active, create a 2 GB swapfile (`MiB` is the binary unit
Linux memory tools use, so `count=2048` gives a little over 2 GB):

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

Restart networking and verify:

```bash
systemctl restart networking
ip addr show enp0s3
```

## 5. Run the Install Script

Install TallPBX with a single command:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash
```

The command downloads a small bootstrap script and runs it. The bootstrap
prepares the TallPBX source code in `/var/www/tallpbx`, then starts the main
installer, which asks a few setup questions and installs everything.

Re-running the command is always safe: the installer is idempotent, meaning it
can be run repeatedly without affecting existing data, and it updates TallPBX
in place. Use the same `--ref` value every time: without it, the re-run
switches the working copy to `main`.

Options placed after `-s --` are passed to the bootstrap and the installer:

```bash
# Pin the stable 1.1 release branch instead of the default main:
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --ref 1.1

# Skip the demo-data question (recommended for production):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --no-demo

# Install without demo data or development tooling (recommended for production):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/main/scripts/bootstrap.sh | bash -s -- --no-demo --no-development
```

### First Administrator Setup

The installer asks how to create the first administrator:

1. **Create during installation (default)** — enter the administrator email and
   password at the prompt; TallPBX creates the account before finishing.
2. **Browser setup with a one-time activation code** — the installer prints a
   code (also saved to `/var/log/pbx-install.log`). Open
   `http://SERVER-IP/panel/setup`, enter the code, and choose the email and
   password there.
3. **Browser setup without a code — trusted networks only** — the first person
   to open `http://SERVER-IP/panel/setup` becomes the administrator. Do not use
   this on an internet-reachable server.

Re-runs never replace an existing administrator. There is no default
administrator password.

### Demo Data and Development Tooling

The installer asks about two optional extras. Demo data adds sample tenants,
users, and callable extensions for evaluation. Development tooling adds test
and coding utilities and is only for servers where TallPBX itself will be
developed — choose **No** for a normal PBX server.

When it finishes, the installer prints the panel address. Open it in a browser
and sign in with your administrator account. Development tooling also switches
TallPBX to Laravel's local development mode; without it, production mode is
used automatically.

### Outgoing Mail (Email Connector)

To enable password resets, voicemail notifications, backup reports, and system
alerts, sign in to the panel and open **Email Connector**. Standard
username/password SMTP (including Gmail App Passwords) and OAuth 2.0 (Google,
Microsoft 365, or a custom provider) are supported.

See `docs/operations.md` for service management (restarting FreeSWITCH, the
queue worker, Redis, and the other background services), health checks, and
test commands.

### Running the Installer Again

It is safe to run the installer again at any time. It keeps existing call data,
users, settings, and recordings, reuses saved passwords and choices, keeps the
application key, applies only missing database updates, and renews the
TallPBX-to-FreeSWITCH connection and media permissions.

Demo data is added only when selected and never removed by later runs. If you
switch FreeSWITCH between packages and a source build, the installer removes
the old program first; that does not touch your database or recordings.

### Redis Cache And Session Storage

Redis is TallPBX's in-memory storage for sessions, cache, and dynamic
intrusion bans, and new installs configure it automatically. Keep
`redis-server` running in production: if Redis is temporarily down the PBX
keeps working, but dynamic login and ban counters pause until it returns — the
kernel firewall and manual bans remain active. After changing cache or session
values in `.env`, run `php artisan optimize:clear && php artisan optimize`.

### FreeSWITCH Session Rate

Fresh installs cap new calls at FreeSWITCH's default of `60` sessions per
second. This is a safety limit suitable for most servers; change it only after
load testing shows that this specific limit is holding back a server with
enough CPU, memory, and network capacity.

### PHP-FPM Worker Sizing

PHP-FPM serves the web panel. Debian's defaults suit small systems; adjust only
when monitoring shows worker exhaustion or slow bursts:

| Server size | Suggested `pm.max_children` | Notes |
| --- | --- | --- |
| Minimum, 1 GB RAM | Keep Debian defaults | Change only after monitoring. |
| Small, 2 GB RAM | `6` | A reasonable starting point. |
| Standard, 4 GB RAM | `12` | Recommended starting point. |
| Larger, 8 GB RAM | `24` | Confirm memory is still available. |
| High-volume | Measure first | Tune from real traffic data. |

Set `pm = static` alongside the value and leave `pm.max_requests` at `0`.
Always leave memory for FreeSWITCH, MariaDB, Redis, Nginx, recordings, and the
operating system, and check `/var/log/php8.5-fpm.log` for worker-limit warnings.

### File Permissions

The installer applies correct ownership and permissions automatically. After a
manual deployment, Composer update, or permission error, restore them with:

```bash
cd /var/www/tallpbx
sudo php artisan permissions:repair --scope=full
```

Do not run recursive `chown` or `chmod` commands over the application
directory; they can make the source writable by the web server or break helper
scripts. If Laravel itself will not boot, use the emergency fallback
`sudo bash scripts/repair-application-permissions.sh`.

Recordings, faxes, and voicemail under `/var/lib/tallpbx/media` are set up
automatically too. If you use a custom `TALLPBX_MEDIA_ROOT`, add that path to
the `ReadWritePaths` line of the `freeswitch-listener` and `tallpbx-queue`
systemd units, then run
`systemctl daemon-reload && systemctl restart freeswitch-listener tallpbx-queue`.

### Firewall (nftables)

The installer configures the kernel firewall for these services:

| Service | Port / Protocol |
| --- | --- |
| SSH | `22/tcp` |
| HTTP / HTTPS | `80/tcp`, `443/tcp` |
| SIP internal | `5060/udp,tcp` |
| SIP over TLS | `5061/tcp` |
| SIP external (carriers) | `5080/udp,tcp` |
| RTP media | `16384-32768/udp` |

Intrusion defense runs automatically: SIP authentication scanning, web login
rate limits, and dynamic kernel bans. Manage it from the panel's Security
pages, or from the terminal:

```bash
php artisan security:status              # firewall state, sets, and bans
php artisan security:unban <IP_ADDRESS>  # lift a ban immediately
sudo nft flush ruleset                   # emergency: clear all rules if locked out
```

Privileged firewall changes run through the bounded helper
`/usr/local/sbin/tallpbx-security` (mode `0750 root:www-data`); the web user
never receives general sudo access.

### Browser Testing (Dusk)

Browser tests are optional developer tooling. **Do not use the web panel while
they run**: `bash scripts/dusk.sh` temporarily swaps in the test environment,
and concurrent panel use interferes with the run. Documentation screenshots
are refreshed on demand only (`DUSK_CAPTURE_DOCS=1 bash scripts/dusk.sh`); see
`AGENTS.md` for the full workflow.

## 6. FreeSWITCH Installation Choice

The installer asks whether to install FreeSWITCH from packages or from source
code, and remembers the choice. Switching later removes the old FreeSWITCH
program first; your database and recordings are untouched.

**Package install (recommended for most servers).** Faster to install and
update, and installs the modules TallPBX needs for phones, voicemail,
recordings, queues, music, and dynamic configuration. It requires a free
SignalWire Personal Access Token — create one at https://signalwire.com under
**Personal Access Tokens** (repo access). The installer configures FreeSWITCH
to fetch its phone directory and call routing from the application. If you
change `FREESWITCH_XML_HANDLER_TOKEN` later, re-apply the configuration:

```bash
cd /var/www/tallpbx
bash scripts/resources/freeswitch.sh --configure-only
systemctl restart freeswitch
```

**Source build.** Only when you need to change FreeSWITCH itself or cannot use
the SignalWire packages. No token is required, but compilation takes much
longer and updates require rebuilding. On later runs, the installer asks
whether to rebuild the source installation.

## 7. HTTPS Certificates (Optional)

Helper scripts cover free Let's Encrypt certificates and Cloudflare DNS. Run
them after the main install, once a public domain name points at the server.

### Let's Encrypt (Single Domain)

```bash
cd /var/www/tallpbx/scripts/resources

bash letsencrypt.sh                                        # interactive
bash letsencrypt.sh --domain pbx.example.com --email admin@example.com
bash letsencrypt.sh --domain pbx.example.com --staging     # test, no rate limits
```

Nginx must be running, and the internet must be able to reach port 80. The
script switches Nginx to HTTPS; certificates renew automatically.

### Wildcard Certificate (Cloudflare DNS)

```bash
bash cloudflare-dns.sh                                # save the API token first
bash letsencrypt.sh --wildcard --domain pbx.example.com
```

The Cloudflare token needs `Zone:Zone:Read` and `Zone:DNS:Edit` permissions
(create one at https://dash.cloudflare.com/profile/api-tokens).

### Other Certificates

For a certificate from another provider, place `fullchain.pem` and
`privkey.pem` under `/etc/letsencrypt/live/<domain>/` and run
`nginx -t && systemctl reload nginx`. Tenant domains are added under
**Admin → Domains** and issued with the same `letsencrypt.sh` command.

## Upgrading TallPBX

The simplest update is to re-run the one-line command from Section 5: it
updates the working copy and re-runs the idempotent installer, keeping your
data. To update manually, take a backup first, then from `/var/www/tallpbx`:

```bash
# 1. Pull the latest code from the branch you installed
#    (main, or a release branch such as 1.1).
git pull --ff-only origin main

# 2. Install PHP dependencies and rebuild browser files.
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# 3. Apply database and module updates.
php artisan migrate --force
php artisan module:sync --only-local

# 4. Refresh caches, permissions, and FreeSWITCH configuration.
php artisan optimize:clear
sudo php artisan permissions:repair --scope=full
fs_cli -x "reloadxml"
```

After upgrading, confirm that the panel opens, FreeSWITCH is running, and a
test call works. If an upgrade fails, restore the backup. Database updates move
forward and should not be rolled back casually.

### If Git Will Not Pull the Update

Git may refuse the update when the server has local changes that conflict with
the new code. Do not delete those changes — save them, update, then restore:

```bash
# Save local changes, including new files.
git stash push --include-untracked -m "before TallPBX upgrade"

# Download the straightforward update.
git pull --ff-only origin main

# Put the saved local changes back after the update.
git stash pop
```

If the final command reports a conflict, resolve it before continuing. The
saved change remains available as a Git stash until it is applied successfully.
