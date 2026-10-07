# Installation Guide
 
A guided setup experience for administrators, pre-configured with reasonable defaults. This guide explains how to set up TallPBX on a new Debian 13 server. The main install is one command; the sections after it cover optional configuration, maintenance, and tuning.

TallPBX is a web-managed phone system that runs on your own server. After
installing, you'll have a working web panel ready for phones, extensions, call
routing, voicemail, and recordings — all manageable from your browser.

## 1. Create the Server

- **OS Type**: Linux, Debian 13 (64-bit)
- **Hardware Requirements**:

| | Minimum (Production) | Recommended (Development) |
|---|---|---|
| CPU | 1 CPU core (2+ recommended) | 4 CPU cores |
| Storage | 25 GB (40 GB+ for recordings) | 40 GB |
| RAM | 1 GB | 4 GB |
| Swap | 2 GB | 2 GB |

Development specs are higher because building frontend assets and running
tests need more CPU and memory.

## 2. Attach the Installer

Use a Debian 13 amd64 image or netinst ISO.

## 3. Install Debian 13

If installing from ISO, proceed through the Debian installer UI:

1. **Partition disk**: Guided - use entire disk is simplest. Creating a swap partition is optional if you prefer that instead of a swapfile.
2. **Software selection**: Check only:
   - SSH server
   - Standard system utilities
3. **Install GRUB boot loader** to the master boot record.

After installation completes, reboot and log in as root.

### Configure a Swapfile (When Total Swap Is Below 2 GB)

Check the current swap:

```bash
swapon --show
```

If less than 2 GB is active, create a 2 GB swapfile:

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

## 4. Configure a Static IP Address

If you need a static IP address, edit the network interfaces file:

```bash
nano /etc/network/interfaces
```

Replace the DHCP line for your interface with your static configuration
(the interface name, IP address, and gateway below are examples — use the
values for your particular network):

```
iface <interface> inet static
address 192.168.1.76
netmask 255.255.255.0
gateway 192.168.1.254
dns-nameservers 192.168.1.254 8.8.8.8 8.8.4.4
```

Restart networking and verify:

```bash
systemctl restart networking
ip addr show <interface>
```

## 5. Run the Install Script

Install TallPBX with a single command:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/3.x/scripts/bootstrap.sh | bash
```

The command downloads a small bootstrap script and runs it. The bootstrap
prepares the TallPBX source code in `/var/www/tallpbx`, then starts the main
installer, which asks a few setup questions and installs everything.

Re-running the command is safe within the same release series — the installer can
run repeatedly without affecting existing data, and it updates TallPBX in place. See
[Upgrading TallPBX](#upgrading-tallpbx) for details.
Use the same `--ref` value every time: without it, the re-run switches the
working copy to `3.x`.

> [!WARNING]
> **Major Version Notice (3.x vs Earlier Versions)**:
> TallPBX 3.x is not backwards-compatible with earlier release branches (`2.0`, `1.1`). Updating an existing 1.x or 2.x system to 3.x is not supported via in-place updates and requires a clean re-install. If operating an existing 2.x deployment, remain on your release series branch (e.g., `--ref 2.0`). Routine in-place updates are supported within the same release series.

To customize the installation, add options after `-s --`:

```bash
# Pin the stable 2.0 release branch instead of the default 3.x:
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/3.x/scripts/bootstrap.sh | bash -s -- --ref 2.0

# Skip the demo-data question and install clean data (recommended for production):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/3.x/scripts/bootstrap.sh | bash -s -- --no-demo

# Standard production install without demo data or development packages:
# (Development mode configures APP_ENV=local and installs test/debug utilities)
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/3.x/scripts/bootstrap.sh | bash -s -- --no-demo --no-development
```

### First Administrator Setup

The installer asks how to create the first administrator:

> [!TIP]
> **Option 1 is recommended for most users.** It's the simplest path — you'll
> have a working admin account as soon as the installer finishes.

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

#### Changing or Resetting the Administrator Password

If you forgot your password or need to change it from the Linux command line:

**Using Artisan (recommended):**

TallPBX uses Laravel's built-in command-line tool, **Artisan** (`php artisan`), for system management. It provides both standard Laravel utilities and custom commands designed specifically for TallPBX.

To change a password interactively (it will prompt for the email and new password):

```bash
cd /var/www/tallpbx
php artisan admin:password
```

Or provide the email and new password directly:

```bash
cd /var/www/tallpbx
php artisan admin:password admin@example.com --password="YourNewPassword"
```

**Directly via MariaDB (if Laravel cannot boot):**

If Laravel's bootstrap cannot run (for example, due to a broken cache or syntax error), update the password directly in MariaDB using PHP's built-in password hashing:

```bash
mariadb tallpbx -e "UPDATE admins SET password = '$(php -r 'echo password_hash("YourNewPassword", PASSWORD_BCRYPT);')' WHERE email = 'admin@example.com';"
```

> [!TIP]
> If you do not remember the administrator's email address, run:
> ```bash
> mariadb tallpbx -e "SELECT id, name, email FROM admins;"
> ```

### FreeSWITCH Sound Prompt Languages

The installer offers to install additional sound prompt languages. English
(Callie) is installed by default; you can also choose to install Spanish (Mario)
and French (June).

The installer will also prompt for which language FreeSWITCH should use as its
system-wide default sound prompt language. You can change this at any time
after installation with `php artisan pbx:sounds:default <language>` or through
`vars.xml`; see [docs/operations.md](docs/operations.md#freeswitch-sound-prompt-languages) for details.

### Post-Installation Next Steps

Once the installation finishes, complete these initial setup steps:

1. **Access the Web Panel**: Open the panel address printed by the installer in
   your browser (e.g., `http://<your-server-ip>/panel`) and log in with your
   administrator account.
2. **Configure HTTPS & TLS**: Secure web access and SIP/WebRTC encryption using Let's Encrypt or your own SSL certificates (see [docs/operations.md](docs/operations.md#tls-certificate-management--httpswss-lifecycle)).
3. **Configure Outgoing Mail (Email Connector)**: In the web panel, navigate to **Email Connector** to set up SMTP or OAuth 2.0 (Google Workspace or Microsoft 365) so password resets, voicemail notifications, and system alerts are delivered (see [docs/operations.md](docs/operations.md#outgoing-mail--notifications-email-connector)).
4. **Connect Carriers & Trunks**: Add SIP gateways under **PBX > Gateways** for outbound calling and configure inbound DIDs under **PBX > Inbound Routes**.

### Re-Running the Installer

It is safe to re-run the installer at any time. It preserves all existing accounts, settings, and recordings while safely applying any missing database updates and repairing service permissions.

Demo data is added only when selected and never removed by later runs. If you
switch FreeSWITCH between packages and a source build, the installer removes
the old program first; that does not touch your database or recordings.

### Redis Cache & Session Storage

Redis provides in-memory storage for sessions, cache, dynamic intrusion bans, and FreeSWITCH XML handler caching. Fresh installs configure it automatically (`FS_XML_HANDLER_CACHE_TTL=5`), protecting MariaDB during call bursts. Keep `redis-server` running in production; if Redis is down, the PBX continues basic routing while dynamic login and ban tracking pauses. After changing cache or session settings in `.env`, run `php artisan optimize:clear && php artisan optimize`.

### FreeSWITCH Session Rate

Fresh installs cap new calls at FreeSWITCH's default of `60` sessions per second (roughly 30 two-leg calls/sec). This is a safe baseline for most servers; adjust it only after load testing proves the server has excess capacity. See [docs/operations.md](docs/operations.md#freeswitch-session-rate-capacity-tuning).

### PHP-FPM Worker Sizing

PHP-FPM serves the web panel and FreeSWITCH HTTP XML handlers. The installer detects host RAM and automatically tunes the PHP-FPM worker pool in `/etc/php/8.5/fpm/pool.d/www.conf` for optimal performance:

| Server Size | Process Mode (`pm`) | Workers (`pm.max_children`) | Notes |
| :--- | :--- | :---: | :--- |
| **1 GB RAM** | `dynamic` | `5` | Conserves memory on minimal servers. |
| **2 GB RAM** | `static` | `6` | Balanced for small office bursts. |
| **4 GB RAM** | `static` | `12` | Recommended baseline (validated for 25+ calls/sec). |
| **8 GB+ RAM** | `static` | `24` | For higher-concurrency call centers. |

In `dynamic` mode, workers are created on demand and shut down when idle to preserve memory on smaller servers. The trade-off is that constantly spawning and terminating workers adds CPU overhead and minor latency during traffic spikes.

In `static` mode, all workers remain initialized and ready for call bursts. Check `/var/log/php8.5-fpm.log` for worker-limit warnings if call volume increases.

### File Permissions

The installer sets file ownership and permissions automatically. After a manual deployment, Composer update, or permission issue, restore them with:

```bash
cd /var/www/tallpbx
sudo php artisan permissions:repair --scope=full
```

Never run broad `chown` or `chmod` commands across the repository. If Laravel cannot boot, use the emergency fallback script: `sudo bash scripts/repair-application-permissions.sh`.

### Firewall (nftables)

The installer configures the Linux kernel firewall for these services by default:

| Service | Port / Protocol |
| :--- | :--- |
| SSH | `22/tcp` |
| HTTP / HTTPS | `80/tcp`, `443/tcp` |
| SIP internal | `5060/udp,tcp` |
| SIP over TLS | `5061/tcp` |
| SIP external (carriers) | `5080/udp,tcp` |
| RTP media | `16384-32768/udp` |

Automated intrusion detection protects against failed authentication attempts across SIP, Web, and SSH by applying dynamic kernel bans to offending IP addresses. You can monitor status and manage active bans from the web panel's **Security Center** or the terminal:

```bash
php artisan security:status              # Check firewall state, sets, and bans
php artisan security:unban <IP_ADDRESS>  # Lift a ban immediately
sudo nft flush ruleset                   # Emergency: clear all rules if locked out
```

All firewall changes pass through a secure helper script (`/usr/local/sbin/tallpbx-security`), so the web panel never needs direct root access.

## 6. FreeSWITCH Installation Choice

The installer asks whether to install FreeSWITCH from packages or from source
code, and remembers the choice. Switching later by re-running the installer
removes the old FreeSWITCH installation first; your database and recordings are
untouched.

| | Packages (recommended) | Source build |
|---|---|---|
| Speed | Fast (pre-built binaries) | Slow (compiles on your server) |
| Updates | `apt upgrade` | Rebuild from source |
| Token required | Yes (free SignalWire PAT) | No |
| Best for | Most servers | Custom FreeSWITCH development |

**Package install (recommended for most servers).** Faster to install and
update. It requires a free SignalWire Personal Access Token (PAT) for APT
repository access — create one at https://signalwire.com under **Personal
Access Tokens** (repo access).

**Source build.** Only when you need to change FreeSWITCH itself or cannot use
the SignalWire packages. No token is required, but compilation takes much
longer and updates require rebuilding. On later runs, the installer asks
whether to rebuild the source installation.

## Upgrading TallPBX

> [!CAUTION]
> Always take a full backup before upgrading. In-place updates are supported strictly within the same release series (e.g., updating within `3.x` or within `2.0`). Upgrading across major version boundaries (such as 2.x to 3.x) requires a clean re-install.

### Updating from the Web Panel (Recommended)

The recommended way to update TallPBX is directly from the web interface:

1. Sign in to the web panel as an administrator.
2. In the sidebar, navigate to **Git Update** (`/panel/git-update`).
3. The page displays your current branch, version, and any incoming commits
   available from the repository.
4. Click **Update Now**, type `UPDATE` in the safety confirmation dialog, and
   proceed.

TallPBX executes the update pipeline automatically: pulling the latest code,
installing dependencies, applying database migrations, syncing modules,
rebuilding frontend assets, clearing stale caches, and repairing file permissions.

---

### Manual Updating from the Linux CLI

If you prefer to update from the terminal, need to automate updates via scripts,
or cannot access the web panel, use one of the manual CLI methods below.

#### Method 1: Using the Bootstrap Script (Terminal One-Liner)

Re-run the one-line installer command — it pulls the latest code, applies
database migrations, and preserves all your existing configuration, accounts,
and recordings:

```bash
# Update on the default 3.x branch:
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/3.x/scripts/bootstrap.sh | bash

# Or update on a specific release branch (such as 2.0 or 1.1):
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/3.x/scripts/bootstrap.sh | bash -s -- --ref 2.0
```

Use the same `--ref` value you used during initial installation so the working
copy stays on your chosen release branch.

#### Method 2: Step-by-Step Manual Update

To update manually step by step, take a backup first, then run from `/var/www/tallpbx`:

```bash
# 1. Pull the latest code from the branch you installed
#    (such as 3.x, or a maintenance release branch such as 2.0 or 1.1).
git pull --ff-only origin 3.x

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
git pull --ff-only origin 3.x

# Put the saved local changes back after the update.
git stash pop
```

If the final command reports a conflict, resolve it before continuing. The
saved change remains available as a Git stash until it is applied successfully.

## Troubleshooting

### Installer Fails at "Installing Composer"

Some hosting providers assign a global IPv6 address without a default IPv6 route.
PHP tries IPv6 first when downloading files, the connection times out, and
the installer fails with:

```text
PHP Warning: copy(https://getcomposer.org/installer): Failed to open stream: Connection timed out
```

The installer detects this automatically and configures the system to prefer
IPv4 by activating the IPv4 precedence rule in `/etc/gai.conf`. If the
detection did not run (for example, on a manual Composer install), activate
the rule yourself:

```bash
# Uncomment or add this line in /etc/gai.conf:
precedence ::ffff:0:0/96  100
```

To undo the preference later, comment out or remove that line.

### SignalWire Token Rejected

If the FreeSWITCH package step fails with an authentication error, the saved
token may have expired or been revoked. Re-run the installer — it will ask for
a new token. You can also update the token directly:

```bash
nano /etc/pbx/installer.env   # update the SWITCH_TOKEN line
```

### Git Conflicts During Upgrade

See [If Git Will Not Pull the Update](#if-git-will-not-pull-the-update) above.

### Server Unreachable After a Firewall Change

If the web panel and `php artisan` commands hang after changing firewall or pre-filter
settings, the server's own connections to its database and cache are being dropped. Recover
from an SSH session — these plain Linux commands need no PHP and no database (run them as
root — prefix with `sudo` if needed):

```bash
# Restore the server's own connections (usually enough on its own)
nft insert rule inet tallpbx_filter input iif "lo" accept

# Last resort: remove every firewall rule (the server is open until you re-apply)
nft flush ruleset
```

Then open the Security Center and press **Apply** to restore the saved configuration.
The full runbook is in [docs/operations.md](docs/operations.md#recovering-from-a-firewall-lockout).
