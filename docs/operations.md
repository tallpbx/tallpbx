# Operations & Maintenance

Day-to-day commands for running a TallPBX server. Installation is covered in
[INSTALL.md](../INSTALL.md).

## Service Management

TallPBX runs as systemd services. Check or restart any of them with
`systemctl status <name>` / `systemctl restart <name>`:

| Service | Purpose |
| --- | --- |
| `freeswitch` | Phone system engine |
| `freeswitch-listener` | FreeSWITCH event listener |
| `tallpbx-queue` | Background jobs (recordings, media, mail) |
| `tallpbx-scheduler` | Scheduled tasks and sweeps |
| `tallpbx-reverb` | Real-time WebSocket server |
| `redis-server` | In-memory storage |
| `nftables` | Kernel firewall |

Handy checks: `redis-cli ping` (expects `PONG`) and `php artisan security:status`
(firewall state and active bans).

## Health Checks and Testing

After installation — and at any time — run the smoke check to confirm the
application is healthy:

```bash
cd /var/www/tallpbx
php artisan app:test --smoke
```

Developers can run the broader suites:

```bash
php artisan app:test              # all application tests
php artisan app:test --full       # also browser tests (Chromium required)
php artisan app:test --sequential # one process at a time when investigating failures
```

Tests run in a temporary in-memory database, never the live TallPBX database,
and they stop if that safety rule is not in effect. TallPBX also blocks Laravel
commands that would erase or rebuild the primary database; these safeguards do
not prevent a person with MariaDB access from running destructive SQL manually.
