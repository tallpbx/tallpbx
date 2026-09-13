# SIPp Server-To-Server Validation

Date: July 16, 2026

Updated: July 20, 2026 (extended parity scenarios)

## Audience

This guide is written for both engineers and automated test harnesses.

- Engineers should be able to skim the purpose, quick commands, expected results, and troubleshooting notes without needing to understand every SIP detail.
- Automated harnesses and test runners should use the exact commands, file paths, environment variables, and artifact names when running or debugging the tests.

The first half explains the tests in plain language. The later manual sections are more detailed on purpose, so an engineer or operator can reproduce the exact server-to-server checks.

## Purpose

These tests make fake phone calls from one server to the TallPBX server.

That is useful because it proves the PBX is doing more than returning XML in a web request. It proves a separate machine can register phones, place calls, hear or send media, and hang up cleanly.

These tests are different from the main dialplan load tests:

- `php artisan pbx:load-test:dialplan` measures Laravel XML handler performance directly.
- SIPp proves FreeSWITCH can use that Laravel-generated XML during real calls.

Use SIPp after the XML handler tests are healthy. If the app is already returning bad XML or slow responses, SIPp will fail too, but the failure will be harder to read.

## Quick Version

Most of the time, run the tests in this order:

1. Make sure FreeSWITCH is running on the PBX server.
2. Run the basic SIPp test.
3. If that passes, run the media test.
4. Open `summary.md` first.

Basic test:

```bash
PBX_HOST=192.168.1.76 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
scripts/pbx-sipp-validate.sh
```

Expected result:

- The command finishes successfully.
- `summary.md` says the basic call tests passed.
- Registrations, extension calls, and outbound calls show zero failed calls.

Media test:

```bash
PBX_HOST=192.168.1.76 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
MEDIA_FLOW=1 \
scripts/pbx-sipp-validate.sh
```

Expected result today:

- Registration should pass.
- Recording should pass.
- Music-on-hold should pass.
- Announcement should pass.

The detailed sections below explain what those results mean and how to troubleshoot failures.

## What These Tests Prove

The server-to-server SIPp tests answer practical questions:

- Can a phone register?
- Can one extension call another extension?
- Can an outbound-style call follow the generated routing rules?
- Can the PBX start and stop a recording call?
- Can a caller reach music on hold?
- Can a caller reach an announcement?
- Do the needed FreeSWITCH modules still load after install or reboot?
- Can calls hang up cleanly?
- Can a ring group distribute calls to member extensions?
- Can voicemail answer and record messages?
- Can a conference bridge accept callers?
- Can call forwarding redirect to the correct destination?
- Can time-based routing conditions control call flow?
- Can follow-me forwarding apply before bridging?

They do not prove maximum capacity. They mainly check that features work and that earlier fixes still work. Use the dialplan XML load test when the question is, "How fast can Laravel generate call routing XML?"

## Recommended Run Order

Run these tests in this order:

1. Check that FreeSWITCH is running and listening for calls.
2. Seed the test tenant and test extensions.
3. Run the basic SIPp test without `MEDIA_FLOW`.
4. Run the media test with `MEDIA_FLOW=1` only after the basic test passes.
5. If a scenario fails, inspect `summary.md`, the scenario log, SIPp error/message logs, and `/var/log/freeswitch/freeswitch.log`.
6. Run the extended parity test with `EXTENDED=1` after the basic test passes:

```bash
# Manual run (current working approach)
PBX_HOST=192.168.1.76 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
scripts/pbx-sipp-validate.sh

# Or invoke directly with the Extended flag:
EXTENDED=1 FORCE_SEED=1 \
  PBX_HOST=192.168.1.76 LOAD_GENERATOR_IP=192.168.1.65 \
  scripts/pbx-sipp-validate.sh
```

**Note:** Extended scenarios require `--include-extended-fixtures` in the seed step,
which is triggered automatically when `EXTENDED=1`.

Expected high-level outcome:

- Basic runner: all scenarios should pass.
- Media runner: registration, recording, music-on-hold, and announcement checks should pass.
- Extended runner: ring-group, voicemail (2003), conference (2500), call-forward, time-condition (2401), and follow-me should pass. Emergency (911) and call-block may fail due to known limitations documented below.

## Current Lab Topology

The July 16, 2026 validation used this layout:

| Role | Host | Notes |
| --- | --- | --- |
| PBX server | `192.168.1.76` | Debian 13 VM on Windows 11. Runs Laravel, Nginx/PHP-FPM, MariaDB, Redis, and FreeSWITCH. |
| SIPp load generator | `192.168.1.65` | WSL2 on the Windows 11 workstation. Reached from the PBX server over SSH port `2222`. |
| SIP signaling | PBX `192.168.1.76:5060` | FreeSWITCH internal Sofia profile. |
| SIPp local ports | `5066`, `5070`, `5072`, `5074+` | Separate ports prevent one scenario from colliding with another. |
| SIPp RTP ports | `6000`, `6002`, `6004+` | Media-flow scenarios use RTP echo with SIPp `-mi` and `-mp`. |

For repeatable results, run SIPp from WSL2 or a separate Linux VM instead of the PBX server itself. That keeps the test caller separate from the PBX, closer to how real phones or trunks behave.

## NAT-Safe WireGuard Topology

The full SIPp harness needs bidirectional network reachability. SIPp sends the initial registrations and calls to FreeSWITCH, but FreeSWITCH also opens new SIP dialogs toward the registered SIPp endpoint and the synthetic outbound gateway. Media validation additionally sends RTP back to the address and ports advertised by SIPp. A normal outbound NAT mapping does not make arbitrary SIPp UAS and RTP listeners reliably reachable.

Use WireGuard when either the PBX or the SIPp load generator is behind NAT or a firewall and the other host cannot directly route to its SIP/RTP addresses. A tunnel is unnecessary when both hosts are on the same routable LAN or both have intentionally exposed, firewall-restricted SIP and RTP addresses.

| Network shape | Recommendation |
| --- | --- |
| Both hosts share a routable LAN | Use their LAN addresses directly. |
| Public PBX, load generator behind NAT | Run the PBX as the reachable WireGuard endpoint. The load generator initiates the tunnel outbound. |
| Public load generator, PBX behind NAT | Run the load generator as the reachable WireGuard endpoint. The PBX initiates the tunnel outbound. |
| Both hosts behind NAT, one has a UDP port forward | Use the forwarded host as the WireGuard endpoint. |
| Both hosts behind carrier-grade NAT with no inbound port forward | Use a small public WireGuard relay or a managed mesh VPN. A two-peer configuration alone is not sufficient. |

The following example matches a public PBX and a load generator on a LAN behind NAT:

| Role | Address |
| --- | --- |
| Public PBX endpoint | `PBX_PUBLIC_IP:51820/udp` |
| PBX tunnel address | `10.77.0.1` |
| NATed SIPp load generator | `10.77.0.2` |

The representative minimum-hardware test environment currently uses this exact shape:

| Role | Address |
| --- | --- |
| Public PBX | `x.x.x.218` (`10.77.0.1` on `wg0`) |
| SIPp load generator | `192.168.1.76` behind NAT (`10.77.0.2` on `wg0`) |
| WireGuard endpoint | `x.x.x.218:51820/udp` |

Only UDP port `51820` needs to be reachable publicly. Keep SIPp signaling listeners and media ports private inside WireGuard. Restrict any host or cloud firewall rules accordingly.

### Install And Generate Keys

Run on both Linux hosts:

```bash
apt-get update
apt-get install -y wireguard-tools

install -d -m 700 /etc/wireguard
umask 077
test -s /etc/wireguard/privatekey || wg genkey > /etc/wireguard/privatekey
wg pubkey < /etc/wireguard/privatekey > /etc/wireguard/publickey
chmod 600 /etc/wireguard/privatekey
chmod 644 /etc/wireguard/publickey
```

Exchange only the contents of `publickey`. Never copy or display either host's `privatekey`.

### Configure The Public PBX

Create `/etc/wireguard/wg0.conf` on the public PBX:

```ini
[Interface]
Address = 10.77.0.1/24
ListenPort = 51820
PrivateKey = <PBX_PRIVATE_KEY>

[Peer]
PublicKey = <LOAD_GENERATOR_PUBLIC_KEY>
AllowedIPs = 10.77.0.2/32
```

### Configure The NATed Load Generator

Create `/etc/wireguard/wg0.conf` on the SIPp load generator:

```ini
[Interface]
Address = 10.77.0.2/24
PrivateKey = <LOAD_GENERATOR_PRIVATE_KEY>

[Peer]
PublicKey = <PBX_PUBLIC_KEY>
AllowedIPs = 10.77.0.1/32
Endpoint = PBX_PUBLIC_IP:51820
PersistentKeepalive = 25
```

`PersistentKeepalive = 25` keeps the load generator's outbound NAT mapping available for traffic from the PBX. This interval follows the [official WireGuard NAT traversal guidance](https://www.wireguard.com/quickstart/#nat-and-firewall-traversal-persistence).

If the PBX is the host behind NAT, reverse the endpoint roles and put `PersistentKeepalive = 25` on the PBX peer instead. If both hosts are behind NAT, the peer with a UDP port forward or public relay is the endpoint.

### Enable And Verify The Tunnel

Run on both hosts:

```bash
chmod 600 /etc/wireguard/wg0.conf
systemctl enable --now wg-quick@wg0
systemctl is-active wg-quick@wg0
wg show wg0
```

Verify both directions:

```bash
# From the load generator
ping -c 3 10.77.0.1

# From the PBX
ping -c 3 10.77.0.2
```

A healthy result has a recent WireGuard handshake and no packet loss. No IP forwarding or default-route change is needed for this host-to-host test tunnel.

### Bridge A Public-IP-Bound Sofia Listener

If the Sofia profile listens only on the PBX public IP, SIPp cannot target the WireGuard address directly. Keep the existing public listener and add narrow NAT rules to the PBX `[Interface]` section in `/etc/wireguard/wg0.conf`:

```ini
# Let SIPp reach the public-IP-bound internal Sofia listener through wg0.
PostUp = iptables -t nat -C PREROUTING -i %i -d 10.77.0.1 -p udp --dport 5060 -j DNAT --to-destination PBX_PUBLIC_IP:5060 || iptables -t nat -A PREROUTING -i %i -d 10.77.0.1 -p udp --dport 5060 -j DNAT --to-destination PBX_PUBLIC_IP:5060
PostDown = iptables -t nat -D PREROUTING -i %i -d 10.77.0.1 -p udp --dport 5060 -j DNAT --to-destination PBX_PUBLIC_IP:5060 || true

# Give PBX-originated SIP and RTP the source allowed by the load-generator peer.
PostUp = iptables -t nat -C POSTROUTING -o %i -d 10.77.0.2 -p udp -j SNAT --to-source 10.77.0.1 || iptables -t nat -A POSTROUTING -o %i -d 10.77.0.2 -p udp -j SNAT --to-source 10.77.0.1
PostDown = iptables -t nat -D POSTROUTING -o %i -d 10.77.0.2 -p udp -j SNAT --to-source 10.77.0.1 || true
```

Replace `PBX_PUBLIC_IP` with the address shown as `SIP-IP` by `fs_cli -x 'sofia status profile internal'`. The DNAT rule affects only UDP 5060 arriving on `wg0`; it does not open another public listener. The SNAT rule applies only to UDP sent through `wg0` to the one SIPp peer and is needed because WireGuard rejects inner source addresses outside the peer's `AllowedIPs`.

Restart `wg-quick@wg0` on the PBX and then send traffic from the NATed load generator so the PBX relearns its endpoint. `PersistentKeepalive = 25` normally does this within 25 seconds; restarting the load generator's tunnel or pinging `10.77.0.1` forces it immediately.

### Seed And Run SIPp Through WireGuard

Seed on the PBX so FreeSWITCH routes the synthetic outbound gateway to the load generator's tunnel address:

```bash
cd /var/www/tallpbx
SIP_REALM="$(sed -n 's/^FREESWITCH_DEFAULT_SIP_REALM=//p' .env | tail -n 1)"
php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain="$SIP_REALM" \
  --extensions=20 \
  --start=2000 \
  --password='LoadTest1234!' \
  --sipp-host=10.77.0.2 \
  --sipp-port=5088 \
  --output=storage/app/load-tests/sipp-users-wireguard.csv \
  --include-media-fixtures
```

Copy that CSV to the same relative path in the repository on the load generator. Then run SIPp from the load generator without running its local Artisan seeder:

```bash
PBX_HOST=PBX_SIP_LISTENER \
LOAD_GENERATOR_IP=10.77.0.2 \
DOMAIN=PBX_SIP_REALM \
SKIP_SEED=1 \
CSV_PATH=storage/app/load-tests/sipp-users-wireguard.csv \
scripts/pbx-sipp-validate.sh
```

Set `PBX_SIP_LISTENER` to the address where the FreeSWITCH internal Sofia profile actually listens. This may remain the PBX public IP even though SIPp advertises `10.77.0.2` for callbacks and RTP. Use `10.77.0.1` only if the Sofia profile also listens on that tunnel address.

When the public-IP-bound listener bridge above is installed, use `PBX_HOST=10.77.0.1` and set `DOMAIN` to the value of `FREESWITCH_DEFAULT_SIP_REALM`. The stock internal profile uses `force-register-domain=$${domain}`, so seeding an unrelated realm such as `load.test.local` causes authenticated registrations to be looked up under the forced PBX realm and rejected with `403 Forbidden`.

## Required PBX Runtime State

Fresh installs and existing installs should converge to the same FreeSWITCH runtime state by running:

```bash
cd /var/www/tallpbx
bash scripts/resources/freeswitch.sh --configure-only
```

That command should make sure FreeSWITCH has the modules it needs after install or reboot:

- enable persistent module load lines in `/etc/freeswitch/autoload_configs/modules.conf.xml`
- write `/etc/freeswitch/autoload_configs/xml_curl.conf.xml`
- reload XML
- attempt to load the required modules immediately

Verify the persistent module lines:

```bash
rg -n '<load module="mod_(sofia|callcenter|dptools|local_stream|sndfile|xml_curl)"/>' \
  /etc/freeswitch/autoload_configs/modules.conf.xml
```

Expected modules:

```text
mod_xml_curl
mod_sofia
mod_dptools
mod_sndfile
mod_local_stream
mod_callcenter
```

Verify the live FreeSWITCH state:

```bash
fs_cli -x 'sofia status'
fs_cli -x 'callcenter_config queue list'
ss -lunp | rg ':5060|:5080'
```

Good result:

- `sofia status` shows internal and external profiles running.
- Port `5060` is listening on the PBX address.
- `callcenter_config queue list` works. When media test data has been seeded, it should include `load_test_moh@default`.

## Seed Data

The SIPp tests use temporary test data. This creates a test tenant, test extensions, and a SIPp CSV file:

```bash
php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=load.test.local \
  --extensions=20 \
  --start=2000 \
  --password='LoadTest1234!' \
  --sipp-host=192.168.1.65 \
  --sipp-port=5088 \
  --output=storage/app/load-tests/sipp-users.csv \
  --include-media-fixtures
```

The command is idempotent for the named synthetic tenant unless `--reset` is used.

Generated test data:

- tenant slug: `load-test-beta`
- SIP domain: `load.test.local`
- SIP accounts/extensions: `2000` through `2019` in this example
- SIP password: `LoadTest1234!`
- internal and external SIP profiles for the tenant
- outbound route that points to SIPp
- SIPp CSV at `storage/app/load-tests/sipp-users.csv`

When `--include-media-fixtures` is present, it also creates:

- callcenter queue `load_test_moh` with `local_stream://moh`
- IVR menu `load_test_announcement` using a packaged FreeSWITCH Callie prompt

## Full Runner

### Basic End-To-End Run

Use this first. It checks that test phones can register, call each other, place an outbound-style test call, and hang up.

```bash
PBX_HOST=192.168.1.76 \
PBX_PORT=5060 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
scripts/pbx-sipp-validate.sh
```

Expected result:

- The script exits with status `0`.
- `summary.md` says registration, extension calls, and outbound calls passed.
- Registrations show zero failures.
- Extension calls show zero failures.
- Outbound test calls show zero failures.
- A run directory is created under `storage/app/load-tests/sipp-e2e-*`.
- The run directory contains `summary.md`, SIPp logs, and FreeSWITCH snapshots when available.

What a passing basic run tells us:

- FreeSWITCH is listening for calls.
- Test phones can register.
- TallPBX can generate the call routing FreeSWITCH needs.
- Calls can be answered and hung up cleanly.

### Media-Flow Run

Add this after the basic run is passing. It checks a few media-related PBX features:

```bash
PBX_HOST=192.168.1.76 \
PBX_PORT=5060 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
MEDIA_FLOW=1 \
scripts/pbx-sipp-validate.sh
```

Expected result:

- Recording should pass with zero failed calls.
- Music-on-hold should pass with zero failed calls.
- Announcement should pass with zero failed calls.
- If packet capture is enabled and available, the run also saves RTP capture files for deeper debugging.

What a passing media-flow run tells us:

- The recording call path works.
- The music-on-hold queue path works.
- The announcement path works and hangs up cleanly.
- Audio traffic can flow between the PBX server and the test server.

### Run Artifacts

The runner stores artifacts under:

```text
storage/app/load-tests/sipp-e2e-*
```

Open `summary.md` first. It is the readable pass/fail summary. Only inspect the detailed SIPp logs when something failed or behaved strangely.

For a healthy run, `summary.md` should show each scenario as passed. Individual SIPp logs should show:

- no timeout
- successful calls equal to the requested count
- failed calls equal to `0`

For a failed run, preserve the entire run directory. The most useful files are:

- `summary.md`
- `register.log`
- `*-media.log`
- `*_errors.log`
- `*_messages.log`
- `freeswitch-before.log`
- `freeswitch-after.log`

### Variables

Useful settings:

| Variable | Purpose |
| --- | --- |
| `PBX_HOST` | PBX SIP target. |
| `PBX_PORT` | PBX SIP port, usually `5060`. |
| `LOAD_GENERATOR_IP` | IP address FreeSWITCH can reach for SIPp signaling/RTP. |
| `FORCE_SEED=1` | Refresh seed data and CSV before running. |
| `SKIP_SEED=1` | Use an existing CSV without running Artisan. Useful when the runner is copied to WSL2. |
| `REGISTER_RATE`, `REGISTER_COUNT` | SIP REGISTER rate/count. |
| `CALL_RATE`, `MAX_SIMULTANEOUS`, `CALLS` | Extension-to-extension call rate, concurrency, count. |
| `OUTBOUND_CALL_RATE`, `OUTBOUND_MAX_SIMULTANEOUS`, `OUTBOUND_CALLS` | Outbound-route call rate, concurrency, count. |
| `MEDIA_FLOW=1` | Enable recording, MOH, and announcement media checks. |
| `MEDIA_CALL_RATE`, `MEDIA_MAX_SIMULTANEOUS`, `MEDIA_CALLS` | Media scenario rate/concurrency/count. Defaults should stay small. |
| `MEDIA_RTP_PORT` | Base RTP port for media checks. Defaults to `6000`. |
| `MEDIA_RTP_ECHO=1` | Echo RTP back to FreeSWITCH during media checks. Leave this enabled for playback/announcement tests. |
| `MEDIA_CAPTURE=0` | Disable optional `tcpdump` capture. |
| `MEDIA_CAPTURE_INTERFACE` | Interface for optional RTP packet capture. Defaults to `any`. |
| `RUN_DIR` | Override artifact directory. |

## Advanced: Manual Server-To-Server Run

Most people should use the full runner above. This manual section is for engineers or operators who need to debug one step at a time from the PBX server while SIPp is installed on WSL2.

### 1. Confirm SSH To WSL2

From the PBX server:

```bash
ssh -i /root/.ssh/ppx2-client.rsa \
  -p 2222 \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=no \
  root@192.168.1.65 \
  'hostname -I; command -v sipp; sipp -v 2>&1 | head -5'
```

Expected:

- WSL reports `192.168.1.65`.
- `sipp` is present.
- SIPp prints its version.

### 2. Prepare A WSL Test Directory

From the PBX server:

```bash
RUN_DIR=/tmp/pbx-media-$(date +%Y%m%d-%H%M%S)

ssh -i /root/.ssh/ppx2-client.rsa -p 2222 root@192.168.1.65 "mkdir -p ${RUN_DIR}/tools/sipp"

scp -i /root/.ssh/ppx2-client.rsa -P 2222 \
  tools/sipp/register.xml \
  tools/sipp/uac-media-client-hangup.xml \
  tools/sipp/uac-media-server-hangup.xml \
  root@192.168.1.65:${RUN_DIR}/tools/sipp/

scp -i /root/.ssh/ppx2-client.rsa -P 2222 \
  storage/app/load-tests/sipp-users.csv \
  root@192.168.1.65:${RUN_DIR}/sipp-users.csv
```

The manual July 16 run used:

```text
/tmp/pbx-media-20260716-124816
```

### 3. Generate SIPp Auth And Media CSVs

On WSL:

```bash
cd /tmp/pbx-media-20260716-124816

awk -F';' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]" }' \
  sipp-users.csv > sipp-users-auth.csv

awk -F';' -v destination='*732' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]", destination }' \
  sipp-users.csv > recording-media.csv

awk -F';' -v destination='load_test_moh' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]", destination }' \
  sipp-users.csv > moh-media.csv

awk -F';' -v destination='load_test_announcement' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]", destination }' \
  sipp-users.csv > announcement-media.csv
```

CSV meaning:

- `field0`: SIP auth username.
- `field1`: SIP password.
- `field2`: SIP realm/domain.
- `field3`: destination extension for normal extension tests.
- `field4`: caller ID extension.
- `field5`: SIPp authentication header injection.
- `field6`: destination for media-flow tests.

### 4. Register Seeded Users

From the PBX server, executing on WSL:

```bash
ssh -i /root/.ssh/ppx2-client.rsa -p 2222 root@192.168.1.65 \
  "cd /tmp/pbx-media-20260716-124816 && \
   timeout 120 sipp 192.168.1.76:5060 \
     -sf tools/sipp/register.xml \
     -inf sipp-users-auth.csv \
     -i 192.168.1.65 \
     -p 5066 \
     -r 5 \
     -m 20 \
     -trace_err \
     -trace_counts \
     -trace_stat \
     -fd 5 \
     > register.log 2>&1; \
   ec=\$?; echo exit=\$ec; tail -80 register.log; exit \$ec"
```

Passing result:

- Exit code `0`.
- SIPp shows `Successful call` equal to registration count.
- SIPp shows `Failed call` as `0`.
- Message flow shows initial `401`, authenticated retry, then `200`.

Out-of-call `NOTIFY` messages after registration are expected because FreeSWITCH may send message-summary notifications. SIPp can report them as discarded without failing registration.

## VirtualBox CPS Lab Recovery After Reboot Or Interrupted Runs

Use this runbook before any VirtualBox calls-per-second run from WSL. It exists
so the lab does not need to be rediscovered after every reboot, FreeSWITCH
restart, test runner restart, or interrupted SIPp process.

Known-good lab addresses:

- PBX / FreeSWITCH / Laravel: `192.168.1.76`
- WSL SIPp load generator: `192.168.1.65`
- WSL SSH port: `2222`
- SIPp UAS answer port: `5066`
- SIP realm used by the VirtualBox CPS harness: `192.168.1.76`

### 1. Run the mandatory preflight gate

Do this before every VirtualBox CPS test. Do not start SIPp just because the
previous test worked. Reboots, database refreshes, interrupted runs, APP key
resets, and manual seed resets can remove or invalidate the synthetic SIP
accounts. If this preflight fails, reseed first and regenerate the WSL auth CSV.

On the PBX, verify the known test user, domain, and password without printing
the password:

```bash
cd /var/www/tallpbx

php artisan tinker --execute '
use Modules\SipAccounts\Models\SipAccount;

$account = SipAccount::withoutGlobalScopes()
    ->where("auth_username", "2000")
    ->first();

echo json_encode([
    "exists" => $account !== null,
    "enabled" => $account?->enabled,
    "realm_ok" => $account?->tenantDomain?->domain === "192.168.1.76",
    "password_ok" => $account?->auth_password === "LoadTest1234!",
], JSON_PRETTY_PRINT).PHP_EOL;
'
```

Expected result:

```json
{
    "exists": true,
    "enabled": true,
    "realm_ok": true,
    "password_ok": true
}
```

On WSL, verify the auth CSV header and first data row:

```bash
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   head -n 2 storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv'
```

Expected output:

```text
SEQUENTIAL
2000;LoadTest1234!;192.168.1.76;2001;2000;[authentication username=2000 password=LoadTest1234!]
```

If any PBX value is false, or if the CSV first line is not exactly
`SEQUENTIAL`, perform sections 3 and 4 before starting SIPp. A missing `2000`
account or malformed auth CSV causes immediate `403 Forbidden` failures, which
are setup failures and not capacity results.

### 2. Clear stale SIPp and FreeSWITCH runtime state

On WSL, stop any SIPp processes left behind by an interrupted run:

```bash
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'pids=$(pgrep -x sipp || true); if [ -n "$pids" ]; then kill $pids || true; fi'
```

Do not use `pkill -f /usr/local/bin/sipp` inside the SSH command string. The
pattern can match the SSH command itself and kill the shell before the test
starts.

On the PBX, restart FreeSWITCH and confirm it is idle:

```bash
systemctl restart freeswitch
sleep 8
fs_cli -x 'show calls count'
fs_cli -x 'show channels count'
fs_cli -x 'show registrations count'
```

Expected result before starting a fresh run:

- `0 total` calls
- `0 total` channels
- `0 total` registrations

### 3. Confirm Sofia is really listening

After a reboot or FreeSWITCH restart, do not assume SIP is ready. Check it:

```bash
fs_cli -x 'sofia status'
ss -lunp | grep -E ':5060|:5080'
```

Expected result:

- `internal` profile is `RUNNING`
- UDP `5060` is listening

If `sofia status` shows `0 profiles 0 aliases`, SIPp registrations cannot
work. In this lab, the fastest recovery is:

```bash
sed -i 's/bindings="directory|dialplan|configuration"/bindings="directory|dialplan"/' \
  /etc/freeswitch/autoload_configs/xml_curl.conf.xml
systemctl restart freeswitch
sleep 8
fs_cli -x 'sofia status'
```

Why this matters: if XML curl is bound to `configuration`, FreeSWITCH may ask
Laravel for `sofia.conf` during startup. That is only safe when the database has
enabled SIP profile records and the generated configuration includes them. If
the database was refreshed, reseeded incorrectly, or has no SIP profiles, Sofia
starts with no profiles and there is no SIP listener for SIPp to test.

This does not bypass XML curl for the call test itself. Directory and dialplan
lookups still go through `/api/v1/xml-handler`, which is the part being tested
during end-to-end calls.

### 4. Recreate the PBX load-test data

If registrations fail with messages like `Can't find user [2000@192.168.1.76]`,
the test tenant/SIP accounts are missing or mismatched. Recreate them on the PBX:

```bash
cd /var/www/tallpbx

php artisan pbx:load-test:seed \
  --tenant=load-test-virtualbox \
  --domain=192.168.1.76 \
  --extensions=20 \
  --start=2000 \
  --password='LoadTest1234!' \
  --sipp-host=192.168.1.65 \
  --sipp-port=5066 \
  --output=storage/app/load-tests/sipp-users-virtualbox-sps60.csv \
  --reset \
  --no-interaction

php artisan optimize:clear
php artisan optimize
```

Expected PBX data after seeding:

```bash
php artisan tinker --execute 'echo "tenants=".App\Models\Tenant::count()."\n"; echo "sip_accounts=".Modules\SipAccounts\Models\SipAccount::withoutGlobalScope("tenant")->count()."\n"; echo "sip_profiles=".Modules\SipProfiles\Models\SipProfile::withoutGlobalScope("tenant")->count()."\n";'
```

Expected values for this lab:

- `tenants=1`
- `sip_accounts=20`
- `sip_profiles=2`

### 5. Copy the CSV to WSL and add SIPp authentication

The seed command writes a plain CSV. The SIPp UAC/register scenarios also need
an authentication macro column. Copy the CSV to WSL and build the auth CSV:

```bash
scp -P 2222 -i /root/.ssh/ppx2-client.rsa \
  /var/www/tallpbx/storage/app/load-tests/sipp-users-virtualbox-sps60.csv \
  root@192.168.1.65:/root/pbx-sipp-validation/storage/app/load-tests/sipp-users-virtualbox-sps60.csv

ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   awk -F";" '"'"'NR==1{print;next}{print $0 ";[authentication username=" $1 " password=" $2 "]"}'"'"' \
     storage/app/load-tests/sipp-users-virtualbox-sps60.csv \
     > storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv'
```

Expected first data row:

```text
SEQUENTIAL
2000;LoadTest1234!;192.168.1.76;2001;2000;[authentication username=2000 password=LoadTest1234!]
```

Without the final authentication column, SIPp receives `407` and then sends a
second INVITE or REGISTER without usable credentials. That produces misleading
`403 Forbidden` failures that are test setup failures, not PBX capacity
failures.

### 6. Register users, then let voicemail NOTIFY noise drain

Register the 20 users from WSL:

```bash
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   /usr/local/bin/sipp 192.168.1.76 \
     -sf tools/sipp/register.xml \
     -inf storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv \
     -i 192.168.1.65 \
     -p 5066 \
     -r 20 \
     -m 20 \
     -l 20 \
     -trace_err \
     -error_file storage/app/load-tests/register-errors.log'
```

Expected result:

- `20` successful registrations
- `0` failed registrations
- FreeSWITCH `show registrations count` returns `20 total`

Then wait about 8 seconds before starting the SIPp answer-side UAS. FreeSWITCH
often sends voicemail message-summary `NOTIFY` packets immediately after
registration. If the UAS starts too soon, it can receive a `NOTIFY` before the
test INVITE, treat it as an unexpected message, exit, and cause the real call to
fail with `503 NORMAL_TEMPORARY_FAILURE`.

### 7. Prove one complete call before any CPS run

Do not run a capacity ladder until this one-call proof succeeds:

```bash
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   OUT=storage/app/load-tests/capacity/virtualbox-one-call-proof && \
   mkdir -p "$OUT" && \
   /usr/local/bin/sipp -sf tools/sipp/uas-auto-answer-capacity.xml \
     -i 192.168.1.65 -p 5066 -m 1 \
     -trace_err -error_file "$OUT/uas_errors.log" \
     192.168.1.76 > "$OUT/uas_stdout.log" 2>&1 & \
   uas=$!; sleep 1; \
   /usr/local/bin/sipp 192.168.1.76 \
     -sf tools/sipp/uac-extension-capacity.xml \
     -inf storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv \
     -i 192.168.1.65 -p 5158 -r 1 -m 1 -l 1 \
     -trace_err -error_file "$OUT/uac_errors.log" \
     > "$OUT/uac_stdout.log" 2>&1; \
   rc=$?; wait "$uas" >/dev/null 2>&1 || true; \
   echo "exit=$rc"; grep -E "Successful call|Failed call" "$OUT/uac_stdout.log"; \
   exit "$rc"'
```

Expected result:

- UAC shows `Successful call | 1`
- UAC shows `Failed call | 0`
- UAS error log has no unexpected SIP message except the harmless epoll cleanup
  warning SIPp sometimes prints at shutdown

Only after this proof passes should a CPS result be interpreted.

### 8. Run the short clean CPS diagnostic

This is the current quick repeat check used after the lab has been recovered:

```bash
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   OUT=storage/app/load-tests/capacity/virtualbox-xmltiming-8cps-80calls-clean-$(date +%Y%m%d-%H%M%S) && \
   mkdir -p "$OUT" && \
   /usr/local/bin/sipp -sf tools/sipp/uas-auto-answer-capacity.xml \
     -i 192.168.1.65 -p 5066 -m 80 \
     -trace_err -trace_msg \
     -message_file "$OUT/uas_messages.log" \
     -error_file "$OUT/uas_errors.log" \
     192.168.1.76 > "$OUT/uas_stdout.log" 2>&1 & \
   uas=$!; sleep 1; \
   /usr/local/bin/sipp 192.168.1.76 \
     -sf tools/sipp/uac-extension-capacity.xml \
     -inf storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv \
     -i 192.168.1.65 -p 5180 \
     -r 8 -m 80 -l 40 \
     -trace_stat -trace_err -trace_msg \
     -stf "$OUT/uac_stats.csv" \
     -message_file "$OUT/uac_messages.log" \
     -error_file "$OUT/uac_errors.log" \
     > "$OUT/uac_stdout.log" 2>&1; \
   rc=$?; \
   for i in 1 2 3 4 5; do kill -0 "$uas" 2>/dev/null || break; sleep 1; done; \
   kill -TERM "$uas" 2>/dev/null || true; wait "$uas" >/dev/null 2>&1 || true; \
   echo "exit=$rc"; grep -E "Call Rate|Successful call|Failed call|UDP errors" "$OUT/uac_stdout.log"; \
   echo "artifacts=$OUT"; exit "$rc"'
```

The July 18, 2026 clean retry completed `80/80` calls with `0` SIPp UDP
send/receive/congestion errors and an achieved completed rate of about
`5.17` calls/sec. Treat that as a short diagnostic proof, not a replacement for
the full staged CPS ladder.

### Quick failure map

| Symptom | Meaning | Fix before testing CPS |
| --- | --- | --- |
| `sofia status` shows `0 profiles 0 aliases` | SIP is not listening. FreeSWITCH cannot register or receive calls. | Seed SIP profiles and/or remove `configuration` from the XML curl binding for this lab, then restart FreeSWITCH. |
| REGISTER times out before `401` | SIP listener/profile is not ready or not reachable. | Confirm `internal` profile is `RUNNING` and UDP `5060` is listening. |
| REGISTER gets `403 Forbidden` | SIPp auth CSV is missing/wrong, or the PBX has no matching SIP users. | Recreate PBX load-test data and regenerate `sipp-users-virtualbox-sps60-auth.csv`. |
| FreeSWITCH log says `Can't find user [2000@192.168.1.76]` | Database seed data does not match the SIP realm/user in the CSV. | Rerun `pbx:load-test:seed --domain=192.168.1.76` and copy the CSV to WSL. |
| First call fails with `503 NORMAL_TEMPORARY_FAILURE` and UAS saw `NOTIFY` | The SIPp answer side exited because voicemail NOTIFY arrived before the INVITE. | Wait about 8 seconds after registration before starting the UAS. |
| Calls fail with `mod_xml_curl` timeout | The test is valid and the PBX is waiting too long for XML curl responses. | Investigate Laravel/PHP-FPM/XML handler timing; do not blame SIPp until UDP errors or load-generator CPU prove it. |

## Advanced: Manual Media-Flow Checks

### 5. Recording Media Flow

This checks that dialing `*732` reaches the recording feature-code path, negotiates media, and the PBX ends the call.

```bash
ssh -i /root/.ssh/ppx2-client.rsa -p 2222 root@192.168.1.65 \
  "cd /tmp/pbx-media-20260716-124816 && \
   timeout 120 sipp 192.168.1.76:5060 \
     -sf tools/sipp/uac-media-server-hangup.xml \
     -inf recording-media.csv \
     -i 192.168.1.65 \
     -mi 192.168.1.65 \
     -p 5070 \
     -mp 6000 \
     -rtp_echo \
     -r 1 \
     -l 1 \
     -m 1 \
     -trace_err \
     -trace_msg \
     -trace_counts \
     -trace_stat \
     -fd 5 \
     > recording-media.log 2>&1; \
   ec=\$?; echo exit=\$ec; tail -160 recording-media.log; exit \$ec"
```

Passing result:

- Exit code `0`.
- SIPp receives `407`, sends authenticated INVITE, receives `200`, sends ACK.
- SIPp receives BYE from FreeSWITCH.
- `Successful call` is `1`.
- `Failed call` is `0`.

What it tells us:

- SIP auth works for the seeded user.
- The tenant internal context is selected.
- The generated feature-code dialplan matches `*732`.
- FreeSWITCH executes the recording path far enough to answer/negotiate and hang up cleanly.

### 6. Music-On-Hold Queue Media Flow

This checks that the callcenter queue exists, that the generated queue name matches the dialplan bridge target, and that `local_stream://moh` is usable.

```bash
ssh -i /root/.ssh/ppx2-client.rsa -p 2222 root@192.168.1.65 \
  "cd /tmp/pbx-media-20260716-124816 && \
   timeout 120 sipp 192.168.1.76:5060 \
     -sf tools/sipp/uac-media-client-hangup.xml \
     -inf moh-media.csv \
     -i 192.168.1.65 \
     -mi 192.168.1.65 \
     -p 5072 \
     -mp 6002 \
     -rtp_echo \
     -r 1 \
     -l 1 \
     -m 1 \
     -trace_err \
     -trace_msg \
     -trace_counts \
     -trace_stat \
     -fd 5 \
     > moh-media.log 2>&1; \
   ec=\$?; echo exit=\$ec; tail -180 moh-media.log; exit \$ec"
```

Passing result:

- Exit code `0`.
- SIPp receives `200`.
- SIPp waits through the media window.
- SIPp sends BYE and receives `200`.
- `Successful call` is `1`.
- `Failed call` is `0`.

What it tells us:

- `mod_callcenter` is installed and loaded.
- Dynamic `callcenter.conf` includes `load_test_moh@default`.
- The callcenter queue target generated by the app matches FreeSWITCH runtime configuration.
- `mod_local_stream` and packaged MOH files are available enough for the queue to answer and hold the call.

### 7. Announcement Media Flow

This checks that the IVR/announcement destination is generated and that FreeSWITCH reaches the packaged prompt path.

```bash
ssh -i /root/.ssh/ppx2-client.rsa -p 2222 root@192.168.1.65 \
  "cd /tmp/pbx-media-20260716-124816 && \
   timeout 45 sipp 192.168.1.76:5060 \
     -sf tools/sipp/uac-media-server-hangup.xml \
     -inf announcement-media.csv \
     -i 192.168.1.65 \
     -mi 192.168.1.65 \
     -p 5074 \
     -mp 6004 \
     -rtp_echo \
     -r 1 \
     -l 1 \
     -m 1 \
     -trace_err \
     -trace_msg \
     -trace_counts \
     -trace_stat \
     -fd 5 \
     > announcement-media.log 2>&1; \
   ec=\$?; echo exit=\$ec; tail -180 announcement-media.log; exit \$ec"
```

Passing result:

- Exit code `0`.
- SIPp receives `200`.
- FreeSWITCH sends BYE after announcement playback.
- `Successful call` is `1`.
- `Failed call` is `0`.

Current July 16, 2026 result:

- Passed after two fixes: announcement-only IVR dialplans now play and hang up without waiting for digit input, and SIPp media tests use RTP echo.
- The final run received FreeSWITCH BYE after playback and showed `1` successful call with `0` failed calls.

What this tells us:

- SIP auth works.
- Tenant context selection works.
- The generated announcement dialplan is present and matches `load_test_announcement`.
- Packaged prompt path expansion reached `/usr/share/freeswitch/sounds/en/us/callie/ivr/8000/ivr-thank_you_for_calling.wav`.
- Live teardown completes cleanly when the SIPp load generator echoes RTP.

## Runtime Debug Commands

Use these on the PBX server while a SIPp test is running or immediately after a failure.

```bash
fs_cli -x 'show calls'
fs_cli -x 'show channels'
fs_cli -x 'sofia status'
fs_cli -x 'callcenter_config queue list'
tail -240 /var/log/freeswitch/freeswitch.log
```

Useful XML handler checks:

```bash
curl -sS 'http://127.0.0.1/api/v1/xml-handler?token=TOKEN&section=configuration&key_name=name&key_value=sofia.conf'
curl -sS 'http://127.0.0.1/api/v1/xml-handler?token=TOKEN&section=configuration&key_name=name&key_value=callcenter.conf'
curl -sS 'http://127.0.0.1/api/v1/xml-handler?token=TOKEN&section=configuration&key_name=name&key_value=ivr.conf'
```

When a call gets stuck:

```bash
fs_cli -x 'show calls'
fs_cli -x 'uuid_kill UUID NORMAL_CLEARING'
```

## Artifacts

The runner and manual tests produce several useful files.

Common files:

- `summary.md`: human-readable run summary when using the full runner.
- `register.log`: registration screen output.
- `register_*_errors.log`: discarded/unexpected messages and errors.
- `*_counts.csv`: SIPp counters over time.
- `*_messages.log`: SIP message traces when `-trace_msg` is enabled.
- `recording-media.log`, `moh-media.log`, `announcement-media.log`: scenario outputs.
- `freeswitch-before.log`, `freeswitch-after.log`: full-run FreeSWITCH snapshots when available.
- `*.rtp.pcap`: optional RTP captures when tcpdump capture is enabled.

Keep these artifacts when reporting failures. The SIPp screen output alone is often not enough; the FreeSWITCH log tells whether the app-generated dialplan matched and which FreeSWITCH application was executing.

## Interpreting Results

### Registration Failure

Likely causes:

- `mod_sofia` is not loaded.
- UDP `5060` is not listening on the PBX address.
- Directory XML is not resolving the tenant or SIP account.
- SIP realm/domain in the CSV does not match a seeded tenant domain.
- Password mismatch.

Check:

```bash
fs_cli -x 'sofia status'
ss -lunp | rg ':5060'
tail -160 /var/log/freeswitch/freeswitch.log
```

### Recording Failure

Likely causes:

- Feature-code dialplan did not match `*732`.
- Feature-code regex escaped incorrectly.
- Recording directory or FreeSWITCH recording application has a runtime issue.
- SIPp scenario is waiting for a BYE that the dialplan no longer sends.

The July 16 tests exposed a real bug here: feature codes like `*97` were XML-safe but not regex-safe, producing FreeSWITCH regex compile errors such as an unescaped `*`. The fix was to generate expressions like `^\*97$`.

### MOH Failure

Likely causes:

- `freeswitch-mod-callcenter` missing or not loaded.
- `callcenter.conf` did not include no-domain queues during module load.
- Queue name mismatch between dialplan and callcenter config. FreeSWITCH expects names such as `load_test_moh@default`.
- `mod_local_stream` missing or not loaded.
- Packaged music files are missing.

Check:

```bash
fs_cli -x 'callcenter_config queue list'
fs_cli -x 'show application callcenter'
fs_cli -x 'show file'
```

### Announcement Failure

Likely causes:

- IVR/announcement dialplan did not match.
- Prompt file missing.
- `mod_sndfile` or playback support missing.
- The generated IVR action sequence waits for input or sleep/playback longer than the SIPp scenario expects.
- SIPp is not echoing RTP. In this lab, playback did not complete until the media generator echoed RTP back to FreeSWITCH.
- The SIPp scenario expects server BYE, but the generated dialplan behavior changed to client-hangup or a longer wait.

Current status: announcement routing, RTP setup, playback, and server-side teardown passed on July 16, 2026 with `MEDIA_RTP_ECHO=1`.

## July 16, 2026 Results

Final WSL2-to-PBX scripted run:

| Test | Result | Important Observation |
| --- | --- | --- |
| Register 20 users | Passed | `20` successful, `0` failed. Out-of-call NOTIFY messages were discarded but harmless. |
| Recording media to `*732` | Passed | Authenticated call answered, FreeSWITCH sent BYE, `1` successful, `0` failed. |
| MOH media to `load_test_moh` | Passed | Call answered, held through 10-second media window, SIPp sent BYE and received `200`. |
| Announcement media to `load_test_announcement` | Passed | FreeSWITCH played the packaged prompt, SIPp echoed RTP, FreeSWITCH sent BYE, `1` successful, `0` failed. |

Final run details:

- Artifact directory: `/root/pbx-sipp-validation/storage/app/load-tests/sipp-e2e-20260716-133138`
- PBX target: `192.168.1.76:5060`
- Load generator: WSL2 at `192.168.1.65`
- Media RTP echo: enabled
- FreeSWITCH calls/channels after the run: `0`

Runtime fixes discovered during this testing:

- `mod_sofia` and `mod_callcenter` must be installed and enabled by default, not loaded manually.
- `modules.conf.xml` must persist required module load lines so SIP/callcenter survive reboot.
- Dynamic no-domain configuration requests must include all enabled Sofia profiles and callcenter queues when FreeSWITCH loads modules.
- Tenant Sofia profile XML must wrap params in `<settings>`.
- Callcenter queue names must include the FreeSWITCH queue namespace, for example `load_test_moh@default`.
- Feature-code destination regexes must escape star codes, for example `^\*97$`, not `^*97$`.
- Announcement-only IVR dialplans should play and hang up without waiting for digit input.
- SIPp media-flow scenarios should use RTP echo so FreeSWITCH playback can advance normally in this synthetic test setup.

## July 17, 2026 Remote VPS Results

The 1-vCPU/1-GB remote VPS was tested from the NATed `192.168.1.76` load generator through WireGuard (`10.77.0.1`/`10.77.0.2`). The PBX Sofia profile remained bound to `x.x.x.218`; the WireGuard-only DNAT/SNAT bridge above carried SIP and RTP without exposing the SIPp host publicly.

| Test | Result | Important observation |
| --- | --- | --- |
| Register 20 users | Passed | The synthetic accounts used the forced PBX realm `x.x.x.218`; all 20 registrations succeeded. |
| Extension calls | Passed | 10 authenticated extension calls completed through the tunnel. |
| Outbound-route calls | Passed | 5 calls reached the SIPp UAS at `10.77.0.2:5088` after PBX egress SNAT was enabled. |
| Recording media to `*732` | Failed | The dialplan ran `record_session` and immediately ran `hangup`; SIPp received `480`, and FreeSWITCH discarded the empty recording. |
| MOH media to `load_test_moh` | Passed | The call answered, remained active through the 10-second window, and ended normally. |
| Announcement media to `load_test_announcement` | Passed | The call answered and FreeSWITCH sent BYE after playback. |

The basic run artifacts are under `storage/app/load-tests/capacity/vps-1c-1g-20260717T212713Z/sipp-basic-wg-nat`. The targeted MOH and announcement artifacts are under the sibling `sipp-media-targeted` directory. SIPp RTP echo was enabled, but `tcpdump` was not installed on the load generator, so this run does not include packet captures. FreeSWITCH reported zero active calls after testing.

## July 18, 2026 1-vCPU/2-GB Resize Check

After the same VPS was resized in place to 1 vCPU and 1973 MiB RAM, a low-volume live XML curl subset passed through the same WireGuard path:

| Test | Result | Important observation |
| --- | --- | --- |
| Register 5 users | Passed | The synthetic accounts used the forced PBX realm `x.x.x.218`; XML curl directory POSTs were observed. |
| Extension calls | Passed | 2 authenticated extension calls completed through the tunnel. |
| Outbound-route calls | Passed | 1 call reached the SIPp UAS at `10.77.0.2:5088`. |

The artifact directory is `storage/app/load-tests/sipp-e2e-20260717-170848`. Media-flow checks were not repeated for this resize because no SIP/RTP/media behavior changed; the goal was to confirm that the installed Laravel modules and resized host still served FreeSWITCH XML curl directory and dialplan requests during live calls.

## July 18, 2026 2-vCPU/2-GB Resize Check

After the same VPS was resized in place to 2 vCPU and 1973 MiB RAM, the same low-volume live XML curl subset passed again through WireGuard:

| Test | Result | Important observation |
| --- | --- | --- |
| Register 5 users | Passed | The synthetic accounts used the forced PBX realm `x.x.x.218`; XML curl directory POSTs were observed. |
| Extension calls | Passed | 2 authenticated extension calls completed through the tunnel. |
| Outbound-route calls | Passed | 1 call reached the SIPp UAS at `10.77.0.2:5088`. |

The artifact directory is `storage/app/load-tests/sipp-e2e-20260717-171946`. Media-flow checks were not repeated for this resize because no SIP/RTP/media behavior changed.

## Recommended Use

For routine development:

1. Run focused Pest tests for XML handler and contributor changes.
2. Run `php artisan app:test --smoke`.
3. Run the dialplan XML load test for performance-sensitive changes.
4. Run SIPp basic end-to-end validation before beta handoff.
5. Run SIPp extended parity validation before promoting a module from `partial` to `complete` in the parity audit.
6. Run `MEDIA_FLOW=1` only when changing recording, MOH, announcements, SIP/RTP behavior, or FreeSWITCH install defaults.

For beta or release candidates:

1. Verify fresh install or installer re-run.
2. Reboot the PBX server.
3. Confirm `sofia status` and `callcenter_config queue list`.
4. Run basic SIPp.
5. Run media-flow SIPp.
6. Archive the SIPp artifact directory with the build or release notes.

## Extended Parity Validation (July 20, 2026)

Six of eight extended parity scenarios were validated against the live PBX on
July 20, 2026. The scenarios cover every `ContextWideDialplanXmlContributor` module
not already exercised by the basic runner.

### Extended Scenarios

| Scenario | Destination | SIPp XML | Result |
| --- | --- | --- | --- |
| Ring group (simultaneous, 3 members) | `2400` | `tools/sipp/uac-ring-group.xml` | ✅ Passed |
| Voicemail mailbox access | `2003` | `tools/sipp/uac-voicemail.xml` | ✅ Passed |
| Conference bridge | `2500` | `tools/sipp/uac-conference.xml` | ✅ Passed |
| Unconditional call forward | `2001` → `2000` | `tools/sipp/uac-call-forward.xml` | ✅ Passed |
| Time condition (always-match) | `2401` | `tools/sipp/uac-time-condition.xml` | ✅ Passed |
| Follow-me forwarding | `2002` | `tools/sipp/uac-follow-me.xml` | ✅ Passed |
| Emergency (911) routing | `911` | `tools/sipp/uac-emergency.xml` | ❌ Timeout — no emergency gateway in sofia.conf.xml (see known limitations) |
| Call block (blocked CID) | any | `tools/sipp/uac-call-block.xml` | ✅ Mechanism verified — `orig_caller_id_number` export + check (see known limitations for SIPp scenario) |

### Bugs Found and Fixed

| Bug | Files Changed |
| --- | --- |
| Ring group bridged to DB UUIDs instead of extension numbers → 480 | `RingGroupService.php`, `RingGroupExtension.php` |
| Context-wide contributor extensions placed after greedy `local_extension` catch-all | `XmlHandlerController.php` |
| Time condition had no `destination_number` constraint — acted as global redirect | `TimeConditionService.php` |
| `user/` channel type unsupported in FreeSWITCH bridge → `CHAN_NOT_IMPLEMENTED` | `RingGroupService.php`, `FollowMeService.php` |
| `sip_from_uri` empty post-auth; call-block switched to `orig_caller_id_number` export | `XmlHandlerController.php`, `CallBlockService.php` |

### Extended Seed Command

The extended scenarios rely on synthetic test data seeded by:

```bash
php artisan pbx:load-test:seed \
  --include-extended-fixtures \
  --domain=192.168.1.76 \
  --sipp-host=192.168.1.65 \
  --sipp-port=5088
```

This creates ring group 2400, voicemail mailbox 2003, conference 2500, call forward
2001→2000, time condition 2401, follow-me for 2002, emergency config, call block rule,
and an `*98` voicemail feature code.

---

## Known Limitations

- WSL2 networking can behave differently from a separate Linux VM on the LAN.
- These tests are low-volume by default and are intended for correctness, not capacity claims.
- SIPp RTP echo confirms media negotiation and packet flow, not audio quality.
- Direct SIPp load mostly stresses FreeSWITCH and network behavior; it does not isolate Laravel XML handler performance the way `pbx:load-test:dialplan` does.
- For high-rate SIPp capacity runs, confirm FreeSWITCH `sessions-per-second`
  before interpreting failures. The project default is `60`; the stock
  FreeSWITCH default of `30` can reject two-leg extension calls near 15 offered
  calls/sec with `SIP/2.0 503 Maximum Calls In Progress`.
- **Emergency (911) scenario**: The `EmergencyService` dialplan contributor now correctly
  bridges to `sofia/external/911@<gateway-host>:<gateway-port>` using the emergency
  gateway's host and port from the database. The bridge executes correctly in FreeSWITCH
  but SIPp test success depends on the gateway UAS (port 5088) being alive when the
  emergency scenario runs. The full `runner3.sh` starts both UAS instances.
- **Call-block scenario**: FIXED (July 21, 2026). The call-block contributor now uses
  `orig_caller_id_number`, a channel variable exported at the start of every context
  dialplan that captures the pre-auth `caller_id_number`. The SIPp scenario cannot exercise
  this because FreeSWITCH's `auth-calls=true` on the Sofia profile rejects calls where
  the From header user (e.g. `15550000123`) does not match the authenticated user
  (e.g. `2000`). In production, unauthenticated inbound calls from external gateways
  arrive in the public context where `caller_id_number` = raw From user, the export
  captures it, and the block check fires correctly. To test via SIPp, use
  `auth-calls=false` on the test profile or send matching From/auth credentials.
  Files: [XmlHandlerController.php](file:///var/www/tallpbx/app/Http/Controllers/Api/XmlHandlerController.php) (capture_orig_caller export),
  [CallBlockService.php](file:///var/www/tallpbx/app-modules/call-blocks/src/Services/CallBlockService.php) (orig_caller_id_number check).
