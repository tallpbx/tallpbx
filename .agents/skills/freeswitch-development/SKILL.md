---
name: freeswitch-development
description: Use when working on FreeSWITCH, FusionPBX-style PBX behavior, SIP, Sofia profiles, gateways, registrations, mod_xml_curl, XML directory/dialplan/configuration rendering, Event Socket Layer (ESL), CDRs, provisioning, voicemail, IVRs, ring groups, call routing, call centers, recordings, or any Laravel module whose data must affect FreeSWITCH runtime behavior.
---

# FreeSWITCH Development

## Core Rules

Use this skill for PBX and telephony behavior in this Laravel/TALL application. Treat FreeSWITCH integration as a runtime contract, not just CRUD data.

- Verify FreeSWITCH behavior against official FreeSWITCH or SignalWire documentation when syntax or semantics matter.
- Do not write static FreeSWITCH XML configuration files from application code.
- Serve app-managed directory, dialplan, configuration, and phrase data through the XML Handler API and `mod_xml_curl`.
- Preserve tenant isolation in all SIP, directory, dialplan, CDR, registration, provisioning, and ESL flows.
- Keep module data, generated XML, ESL commands, and manual call behavior aligned.
- Prefer existing module services, models, Livewire patterns, and `App\Support\ModuleServiceProvider`.
- Run `php artisan optimize:clear` after code changes, per project policy.

## First Inspection

Before changing FreeSWITCH-related behavior, inspect the local implementation points that apply to the task:

- XML handler: `app/Http/Controllers/Api/XmlHandlerController.php`
- FreeSWITCH config: `config/freeswitch.php`
- API route: `routes/api.php`
- ESL service: `app/Services/FreeSwitchService.php`
- ESL listener: `app/Console/Commands/FreeSwitchListenCommand.php`
- Tenant identity: `app/Services/TenantIdentityResolver.php`
- Dialplan context: `app/Services/DialplanContext.php`
- Tenant defaults: `app/Services/TenantDefaults/`
- PBX modules: `app-modules/*/src`
- Existing tests: `tests/Feature/Http/XmlHandlerControllerTest.php`, `tests/Feature/Services/FreeSwitchListenCommandTest.php`, `tests/Unit/Services/FreeSwitchServiceTest.php`, and relevant `tests/Feature/Modules/**`

When the task touches a specific PBX feature, inspect that module's migration, model, service, Livewire components, views, provider, tests, and route behavior before designing changes.

## Runtime Contract Checklist

For each PBX feature, confirm the complete path:

1. Data model and migration represent the FreeSWITCH concept accurately.
2. UI validates the fields needed to generate valid FreeSWITCH behavior.
3. Service layer handles multi-step writes in transactions.
4. Tenant scoping is explicit when bypassing global scopes.
5. XML Handler or ESL service consumes the feature data.
6. Generated XML uses valid FreeSWITCH structure and escaped values.
7. Tests prove both persistence and generated runtime behavior.
8. A manual call scenario can be described for detailed testing.

If a module only stores data and has no generated XML, ESL command, or call scenario, classify it as CRUD-complete but not telephony-complete.

## FusionPBX Parity Guidance

When the user asks for FusionPBX parity, compare against the current FusionPBX master branch unless they provide another revision. Treat every first-party FusionPBX feature as required for TallPBX unless the user explicitly excludes it; implementation may follow Laravel/TALL best practices rather than FusionPBX internals.

- Evaluate dangerous FusionPBX-style system tools individually instead of copying them directly. Prefer constrained, auditable Laravel-native modules for database management, backups/restores, updates, monitoring, notifications, SSL, firewall, and privileged operations.
- Fax, recordings, music on hold, provisioning templates, and all first-party PBX modules should be considered in scope when FusionPBX supports them.
- Device support should track FusionPBX provisioning coverage where TallPBX reuses/refactors first-party templates.
- Multi-tenant behavior should preserve feature parity without requiring each tenant to have a unique domain; tenant context strings are the preferred FreeSWITCH discriminator.
- Git update behavior should support updating the current install from its configured Git remote, tracking the current branch/tag by default, checking for a clean worktree and dependency safety, and rolling back on failure when feasible.
- Keep TallPBX code Apache-2.0. Do not copy FusionPBX MPL code unless there is a deliberate reason and the required notice is preserved.

## XML Handler Guidance

Use `XmlHandlerController` for `mod_xml_curl` responses.

- Directory XML should resolve tenants from SIP domains or auth credentials and return only enabled tenant SIP accounts.
- Dialplan XML should resolve tenant context, load enabled dialplans in order, eager-load details, and render conditions/actions deterministically.
- Configuration XML should return valid FreeSWITCH configuration documents for known files and fail clearly or intentionally return empty config for unsupported files.
- Escape all XML values with `ENT_XML1 | ENT_QUOTES`.
- Avoid interpolating untrusted XML tag names unless constrained to known FreeSWITCH element names.
- Keep response content type as `application/xml; charset=UTF-8`.
- Cover GET and POST request shapes because `mod_xml_curl` may use either.

When adding XML output, add tests that assert:

- Valid section and configuration/context/domain names.
- Correct tenant scoping.
- Disabled records are excluded.
- Unknown tenant/domain does not leak other tenants.
- XML-sensitive characters are escaped.
- No-route or not-found fallbacks are intentional.

## Dialplan Guidance

Do not assume that a CRUD module automatically affects call routing. Explicitly connect feature records to dialplan generation.

Common mappings to verify:

- Extensions and SIP accounts -> directory users and local extension bridges.
- Inbound routes -> public context DID matching.
- Outbound routes and gateways -> external bridge strings and caller ID behavior.
- IVR menus -> menu prompts, digit options, timeout/failure handling.
- Ring groups and follow-me -> ordered bridge destinations and timeout behavior.
- Time conditions -> time/date expressions and alternate destinations.
- Voicemail -> mailbox access, no-answer routes, and message handling.
- Call forwards and call flows -> destination resolution before bridge.
- Emergency routes -> high-priority outbound routing and caller ID/address data.

Keep dialplan ordering deterministic. Include explicit priorities/order fields in queries and tests.

## ESL Guidance

Use `FreeSwitchServiceInterface` for FreeSWITCH Event Socket Layer interactions.

- Check connection state before sending API commands.
- Treat auth failure, socket timeouts, malformed replies, and disconnects as expected operational states.
- Parse ESL responses defensively; FreeSWITCH command output may vary by version and module.
- Do not let dashboard pages hang when FreeSWITCH is offline.
- For long-running listeners, test reconnect behavior and event dispatch mapping.
- Do not log ESL passwords, SIP passwords, auth tokens, or provisioning secrets.

When changing ESL behavior, add unit tests for protocol parsing and feature tests with mocked `FreeSwitchServiceInterface`.

## Provisioning Guidance

Provisioning is public-facing and must be tenant-safe.

- Normalize MAC addresses consistently.
- Resolve only enabled devices/templates.
- Avoid leaking whether unrelated tenants have a device unless the route is intentionally public by MAC.
- Render templates with escaped or format-appropriate values.
- Keep vendor defaults under `app-modules/provision/resources/views/templates/`.
- Add tests for known vendors, unknown MACs, disabled devices, and missing templates.

## Testing Guidance

Use Pest for all tests.

Prefer a layered test set:

- Unit tests for pure parsers, context builders, XML fragments, and ESL response parsing.
- Feature tests for XML Handler GET/POST requests and tenant isolation.
- Livewire tests for PBX screens and validation.
- Mocked ESL tests for dashboard/operational modules.
- Integration tests against a running FreeSWITCH instance when validating beta readiness.

For every FreeSWITCH-facing change, include at least one test proving the runtime artifact or command behavior, not only database persistence.

## SIPp End-to-End Calls-per-Second Load Testing

When validating true calls per second, test the full SIP call path rather than only the Laravel XML handler. A full end-to-end CPS run means SIPp sends authenticated SIP calls into FreeSWITCH, FreeSWITCH asks Laravel for XML directory/auth and dialplan data through `mod_xml_curl`, FreeSWITCH bridges the call to the SIPp UAS, and the call is answered and torn down with BYE.

Keep direct XML-handler capacity tests separate from live SIPp/FreeSWITCH tests:

- `php artisan pbx:load-test:dialplan` or direct HTTP requests to `/api/v1/xml-handler` measure Nginx/PHP-FPM/Laravel XML generation capacity. They do not create FreeSWITCH sessions and usually will not show call activity in `fs_cli`.
- SIPp validation exercises Sofia, registrations, directory XML curl, dialplan XML curl, bridging, and RTP/media behavior. Use this when the user expects to see FreeSWITCH activity.
- Document the two result types separately. Do not present direct XML-handler requests as simultaneous-call capacity.

Use the documented WSL/VirtualBox or WireGuard layout unless the user explicitly asks for a different topology:

- Load generator: WSL host `192.168.1.65`, SSH port `2222`, running SIPp locally from `/root/pbx-sipp-validation`.
- PBX under test: VirtualBox or server host such as `192.168.1.76`, running FreeSWITCH, Nginx, PHP-FPM, Laravel, MariaDB, and Redis.
- Run SIPp from WSL, not from the PBX server, so client-side SIPp load does not consume PBX CPU.
- Use `scripts/pbx-sipp-validate.sh` or the scenarios in `tools/sipp/` as the source of truth before inventing new commands.
- Use `docs/sipp-server-to-server-validation.md` as the recovery/runbook reference when a reboot or restart breaks the test setup.

For remote datacenter tests where the load generator is behind NAT, prefer WireGuard rather than public inbound exposure to the load generator:

- Put the public VPS/PBX at `10.77.0.1/24` and the NATed load generator at `10.77.0.2/24`, or follow the current documented addressing if it has changed.
- Configure `PersistentKeepalive = 25` on the NATed peer so the public peer can keep reaching it after the load generator opens the NAT mapping.
- If Sofia is bound only to the PBX public IP, target `PBX_HOST=10.77.0.1` and use narrow WireGuard-only DNAT/SNAT rules on the PBX so UDP 5060 arriving on `wg0` reaches the public-IP-bound Sofia listener and PBX-originated SIP/RTP uses a source address allowed by the load-generator peer.
- After restarting `wg-quick@wg0` on the PBX, send traffic from the NATed load generator, restart the load generator tunnel, or ping `10.77.0.1` so the PBX relearns the peer endpoint.
- Verify with `wg show`, `ping`, `fs_cli -x "show calls count"`, `fs_cli -x "show registrations count"`, and Nginx access-log entries with user agent `freeswitch-xml/1.0`.

Before every SIPp CPS run, perform the repeatable preflight gate instead of troubleshooting from scratch. Do not start SIPp until it passes:

1. PBX SSH is reachable and FreeSWITCH is running.
2. WSL SSH is reachable on `192.168.1.65:2222`.
3. SIPp is installed on WSL at `/usr/local/bin/sipp`.
4. Synthetic PBX data still contains enabled SIP account `2000`.
5. SIP account `2000` still belongs to domain/realm `192.168.1.76` and its password still decrypts to the expected test password.
6. The SIP realm/domain in the CSV matches the PBX IP or test realm FreeSWITCH is actually using. On the stock internal profile, `force-register-domain=$${domain}` means the seed domain should normally match `FREESWITCH_DEFAULT_SIP_REALM`; seeding `load.test.local` against a public-IP realm can produce valid-looking accounts that fail REGISTER with `403 Forbidden`.
7. The WSL auth CSV preserves the first `SEQUENTIAL` header row exactly, then appends the SIPp authentication field only to real users. If the header has authentication text, regenerate the CSV.
8. FreeSWITCH `sessions-per-second` is high enough for the test target; the project default is 60 so tests are not accidentally capped by the old 30 SPS setting.
9. PHP-FPM and Laravel config caches have been refreshed after `.env` or code changes.

If the PBX data or CSV preflight fails, run `php artisan pbx:load-test:seed --tenant=load-test-virtualbox --domain=192.168.1.76 --extensions=20 --start=2000 --password='LoadTest1234!' --sipp-host=192.168.1.65 --sipp-port=5066 --output=storage/app/load-tests/sipp-users-virtualbox-sps60.csv --reset --no-interaction`, copy the CSV to WSL, regenerate the auth CSV, and clear Laravel's application cache before retrying. A missing SIP account or malformed auth CSV produces `403 Forbidden` setup failures and must not be counted as a CPS result.

When clearing stale SIPp processes from WSL through SSH, prefer `pgrep -x sipp` plus `kill`. Avoid putting `pkill -f /usr/local/bin/sipp` directly in the SSH command string because the pattern can match and terminate the SSH shell running the cleanup command.

Known SIPp trap: FreeSWITCH may send voicemail/message-summary `NOTIFY` packets to newly registered users. If the UAS starts too soon, SIPp can receive a `NOTIFY` while it expects an `INVITE`, abort the UAS call, and create a false failure. After registration, wait long enough for NOTIFY traffic to drain before starting the UAS. If failures mention unexpected `NOTIFY`, classify that run as a test-harness artifact, not a valid PBX CPS failure.

When interpreting CPS results:

- SIPp `UDP errors (send/recv/cong)` indicate load-generator/network/socket trouble. Zero UDP errors makes a bandwidth/socket bottleneck less likely.
- SIP `403 Forbidden` during REGISTER means the seeded SIP account/domain/password path is wrong; fix seeding/CSV/domain before drawing performance conclusions.
- SIP `408 Request Timeout`, missing `180/200`, or FreeSWITCH `NORMAL_TEMPORARY_FAILURE` can indicate PBX call-chain timing, XML-curl latency, bridge setup issues, or a UAS artifact. Inspect both SIPp logs and FreeSWITCH/Laravel timing logs.
- Runs capped by FreeSWITCH `sessions-per-second` are invalid for max-CPS conclusions and should not be documented as throughput limits.
- SIPp media-flow runs intentionally hold calls longer than simple XML capacity tests. Keep counts small when the goal is functional validation, and explain the expected duration before starting a media-flow or repeated live-call run.

For XML-curl bottleneck work, use the XML handler timing log deliberately:

- Enable `FREESWITCH_XML_HANDLER_LOG_TIMING=true` only for diagnostic runs, then disable it afterward.
- Compare `elapsed_ms`, `db_query_count`, `db_time_ms`, and `non_db_time_ms` by XML section.
- Dialplan XML may be cached and cheap while directory/auth XML is still expensive; do not assume dialplan timing represents the whole call setup path.
- In recent VirtualBox testing, directory/auth lookups were the larger Laravel-side cost than cached dialplan lookups, so optimize tenant identity and directory XML caching before broad MariaDB/PHP tuning.
- Keep `FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_TTL` short enough that new/changed SIP accounts become usable quickly, but long enough to absorb call bursts.
- Prefer Redis for XML handler caches during load testing: `FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_STORE=redis` and `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis`.
- Cache scalar payloads or XML strings, not Eloquent models, especially on Laravel 13 where cache unserialization is stricter.

After diagnostic load testing, restore noisy/runtime flags such as XML timing logs to their normal disabled state and record whether a failed run was a valid PBX result or a harness/setup artifact.

## Representative Hardware Capacity Testing

Use representative hardware for capacity claims, and preserve the distinction between regression hardware and production-like hardware:

- Treat local VirtualBox/LAN results as regression baselines, not production capacity references.
- For remote/shared VPS tests, record CPU count, memory, swap, provider CPU class/model, region, public IP, WireGuard path, PHP-FPM settings, Redis/cache settings, seed size, app revision, and whether the instance was resized or replaced.
- Shared CPU VPS results are provider-state-sensitive. A resize can move the VM to a different host or noisy-neighbor profile, so do not assume a slower result means the added RAM caused the slowdown.
- Use at least three measured repetitions for comparison tiers such as `100 x 5` and `500 x 25`, then report a median. One `1000 x 25` validation run is useful after the smaller tiers pass.
- Keep optional hardware sizes explicitly marked as skipped when the user decides not to run them; do not leave them as pending after the benchmark series is closed.
- Preserve artifact directories under `storage/app/load-tests/`, but do not commit generated load-test artifacts unless the user explicitly asks.

Project observations from July 2026 representative VPS testing:

- On 1 vCPU/1 GB, Debian's default PHP-FPM dynamic pool was acceptable for light or moderate use. Static 6 was a reasonable optional high-load profile with about 440 MiB available after testing and no swap growth.
- On 1 vCPU/2 GB, added memory improved headroom but not throughput; the single shared CPU remained the limiter and provider scheduling noise was plausible.
- On 2 vCPU/2 GB, static 6 produced the strongest remote XML-handler results and substantial memory headroom.
- A 2 vCPU/2 GB sweep of static 8, 10, and 12 workers did not improve throughput meaningfully and introduced worse p99/max tail spikes. Keep `pm.max_children = 6` as the recommended 2 GB profile unless future measurements prove otherwise.
- Keep `pm.max_requests = 0` unless sustained monitoring demonstrates worker memory growth. Nonzero recycling should be a leak-mitigation setting, not a default capacity-tuning variable.
- For the standard 4 GB combined PBX/application server, use the project install guidance before changing the current recommendation.

## External References

Use official sources first:

- FreeSWITCH Users Manual: `https://developer.signalwire.com/freeswitch/`
- FreeSWITCH source repository: `https://github.com/signalwire/freeswitch`
- FreeSWITCH package installation guidance: `https://developer.signalwire.com/freeswitch/`
- FusionPBX repository for behavioral comparison: `https://github.com/fusionpbx/fusionpbx`

When using external references, cite the exact docs or source files in the final response if they influenced the change.
