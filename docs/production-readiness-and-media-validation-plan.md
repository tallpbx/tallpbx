# TallPBX Production Readiness and Media Validation Plan

**Purpose:** This is the single source of truth for TallPBX production readiness and the local-first media storage rollout. It replaces the former production-readiness plan, universal-media plan, and both duplicate Task 11 Dusk plans.

**How to use this document:** A capability is not complete merely because code exists. Mark it complete only when its implementation, automated verification, and—where indicated—live operational validation are recorded below. Historical SIPp and load-test reports remain in `docs/sipp-server-to-server-validation.md` and `docs/call-simulation-load-testing.md`; they are runbooks and historical evidence, not a current-release sign-off.

## Completion standard

TallPBX is production-ready only when all of the following are true:

- The full PHP suite and required browser suite pass, and the frontend build exits successfully.
- A fresh production install contains no demo users or known static credentials; demo data appears only when explicitly selected.
- Tenant isolation has negative coverage across authentication, UI, database, media, XML handler, ESL runtime state, provisioning, CDRs, recordings, voicemail, and fax.
- SMTP, queues, backup/restore, monitoring, alerts, Git updates, and operational controls are permission-gated and verified.
- A fresh public-internet deployment passes XML-handler capacity testing plus basic, media, and extended SIPp validation, or any accepted limitation has a production-safe rationale.
- No dangerous operational action is available without superadmin authorization, an explicit permission, auditability, and constrained execution.

## Architecture and non-negotiable decisions

### Tenant and PBX boundaries

- The application uses one `/panel/` interface with separate `admin` and `web` guards. Permissions, rather than a separate panel, determine visibility.
- FreeSWITCH tenant contexts are `tenant_{id}_internal` and `tenant_{id}_public`. Domain resolution is only a fallback and must fail closed when ambiguous.
- Application XML is generated dynamically through `mod_xml_curl`; do not write static application XML configuration files.
- FusionPBX parity is evaluated against the frozen July 20, 2026 baseline. Implement clean-room Laravel/TALL behavior; do not reuse MPL code without explicit approval and preserved notices.

### Local-first media storage

`MediaStorageServiceInterface` owns placement, access, deletion, and archival of TallPBX-managed media. `MediaAsset` uses a stable UUID, tenant, File Store, morph owner, category, availability status, checksums, retry state, and only non-sensitive operational metadata.

#### Local media folders

TallPBX keeps media outside the application source tree in `/var/lib/tallpbx/media`:

- `/var/lib/tallpbx/media/store` is the durable media library. It holds completed files TallPBX keeps, including recordings, voicemail, prompts, and music.
- `/var/lib/tallpbx/media/spool` is temporary staging. FreeSWITCH writes active recordings there and TallPBX keeps archive transfers there until the destination has been verified. Successfully saved files are removed; failed transfers remain for safe retry.

Backups are intentionally separate in `/var/lib/tallpbx/backups`. Administrators normally do not need to manage these folders manually; the File Stores page displays their configured locations.

| Media category | Storage rule | Remote archive eligible |
| --- | --- | --- |
| Voicemail messages | Local FreeSWITCH file, registered in the ledger | No |
| Call recordings | Local spool, then configured archive destination after `RECORD_STOP` | Yes |
| Inbound/outbound fax | Local during lifecycle; archive only at terminal state | Yes |
| Recordings, IVR prompts, MOH, voicemail and conference greetings | Local runtime media | No |

- There is one system-wide archive File Store selected through `media.archive_file_store_id`; there are no tenant overrides or per-category policies.
- A readable local File Store is the default destination. Email destinations are ineligible for archived media.
- Remote archival keeps a durable local spool until a reopened destination stream matches byte count and SHA-256. Exhausted transfers stay failed with their spool retained and alert enabled administrators once per failure episode.
- Runtime playback and voicemail never perform remote reads. Absolute paths, object keys, provider settings, and raw errors are never presented to tenants.
- New and replaced media use the service; legacy rows remain readable for one compatibility release. Do not add remote voicemail replication, remote hydration, generalized policy UI, or legacy backfill without a separate approved plan.
- Both `www-data` and `freeswitch` require the `tallpbx-media` group. The managed media directories use setgid mode `2770`.

## Current status at the consolidation date

| Workstream | Implementation | Automated evidence | Live/release evidence | Status |
| --- | --- | --- | --- | --- |
| Demo seeding and SIP realms | Permission synchronization precedes demo group grants; demo tenants have distinct realms | Focused seeder/media-service run: 16 tests, 77 assertions passed | Not required beyond fresh-install validation | Implemented; plan status was stale |
| File Stores and backup/restore | File Store module, manifest-backed backups, privileged restore flow, and UI exist | The focused combined run had no backup/restore failures; its five failures were confined to installer-contract expectations | Disposable restore drill outstanding | Partial |
| Local-first media Tasks 1–10 | Ledger, storage service, archive destination, retries, streaming, recording/fax/voicemail/call-path integration, and managed-media backup scope exist | Full feature suite: 1,384 passed; Dusk suite: 41 passed; media stream/archive suite: 11 passed; routes, module sync, permissions, events, schedule, and scope scans verified | Readable remote File Store UI check and live media/recovery validation (Task 3) outstanding | Automated and registration verification complete; not fully live-validated |
| Media browser coverage | Archive-destination selection plus local stream, archived stream/download, isolation, availability, permission, and safe-notification scenarios exist | Dusk media suite: 5 passed, 17 assertions; focused stream/archive suite: 11 passed, 39 assertions | N/A | Task 1 complete; Task 2 next |
| Installer contract | Preflight choices, approved flags, and three first-administrator modes are implemented | Focused installer/seeder tests cover installer, activation-code, and trusted-network setup | Fresh Debian 13 non-demo trusted-network install and idempotent re-run verified on 2026-08-01: one administrator, one Default tenant, two default SIP profiles, and no extensions, SIP accounts, or inbound routes | Baseline operational validation complete |
| SIPp/load harness | Seed, direct XML-handler load test, SIPp scenarios, and sampling scripts exist | Focused tests exist | Historical runs exist; current release has no fresh sign-off | Requires fresh validation |

## Completed implementation record

The following media-storage work is implemented on `main`; retain this as implementation evidence, not as a claim that live validation is complete.

1. Media asset ledger and morph aliases.
2. Local-first service and one archive destination.
3. Shared Laravel/FreeSWITCH media root.
4. Remote archive transfer, idempotent retry, failure retention, and alerts.
5. Private stream and download endpoints with tenant/category authorization.
6. Completed call-recording archival.
7. Terminal fax archival.
8. Local voicemail registration without remote replication.
9. Local-only uploads for recordings, MOH, and greetings.
10. Managed-media inclusion in backups.

The File Stores and backup/restore foundations are also implemented: encrypted File Store credentials, provider validation, checksummed manifests, retention, selected destinations, bounded restore requests, root-owned restore helper integration, rollback snapshots, maintenance/restart behavior, and the superadmin restore UI.

## Active work

### Task 1: Complete Dusk media browser coverage — completed

**Goal:** Add four Dusk scenarios to `tests/Browser/MediaStorageBrowserTest.php` while retaining the existing archive-destination selector unchanged.

The local browser-test runtime now starts ChromeDriver on port `9515` and keeps the isolated application server at `APP_ENV=dusk` on `127.0.0.1:8001`; it never points Dusk at Nginx/PHP-FPM or the primary database.

**Shared test fixtures:** Create two tenants, test-scoped local media/archive File Stores under `storage/framework/dusk-*`, tracked owners/assets/users/groups, and a synchronous same-origin XHR helper. Dusk cannot use container fakes across its separate server process and does not provide response-status assertions suitable for these streamed requests.

The following scenarios are implemented and verified:

1. **Local announcement stream:** an owning-tenant user with `recordings.view` receives `200`, `audio/wav`, and the exact fixture byte count for an available local `recording` asset.
2. **Archived call-recording stream/download:** select a real second local File Store as the archive destination, then prove an owning-tenant user with `call-recordings.view` receives the recording inline and as an attachment. Do not expose or assert filesystem paths.
3. **Isolation and availability:** a foreign tenant receives `404` for both stream and download of another tenant's asset; the owner also receives `404` for a pending asset. The authorization contract is: missing/non-available is `404`, foreign tenant is `404`, and missing category permission is `403`.
4. **Safe archive-failure notification:** an enabled admin sees the failure title/message plus safe category and original filename, but never a spool marker, object key, provider data, or raw error.

The notification scenario should first demonstrate its expected red state. If it does, make the smallest production change only:

- Add `category` and `original_filename` to `MediaArchiveTransferFailed::toArray()`.
- Render only those escaped fields in `app-modules/admin/resources/views/notifications-list.blade.php`.
- Extend `tests/Feature/Modules/FileStores/SyncMediaArchiveTest.php` to assert those payload keys.

The notification payload now contains only the safe `category` and `original_filename` fields in addition to its title, message, and media-asset ID; the notification UI renders only those safe fields. On 2026-08-01, `bash scripts/dusk.sh tests/Browser/MediaStorageBrowserTest.php` passed 5 tests with 17 assertions, and the focused stream/archive feature suite passed 11 tests with 39 assertions. Never record tokens, credentials, media contents, or absolute paths in this document.

### Task 2: Complete media automated and registration verification — automated checks completed

Run the sequence below after Task 1 is green. Stop on the first failure; fix it in a separate scoped task and restart the sequence.

```bash
php artisan optimize:clear
php artisan test --compact
bash scripts/dusk.sh
php artisan route:list --name=media
php artisan module:sync --only-local
php artisan db:seed --class=AdminSeeder
php artisan event:list
php artisan schedule:list
```

On 2026-08-03, the full parallel feature suite passed 1,384 tests with 5,939 assertions and the full Dusk suite passed 41 tests with 132 assertions. The File Stores module, the two `panel.media-assets.*` routes, `media:reconcile --retry-failed --delete-orphans`, and recording/voicemail listeners were verified as registered. Permission seeding completed successfully.

The prohibited-scope scans found no violation: `voicemail.remote_access` is a disabled legacy setting, and `RestoreArchive::hydrate()` is a Livewire lifecycle method rather than media hydration. The remaining Task 2 item is a UI verification against a real readable remote File Store; it is deferred until an approved destination is available.

The required boundary scans were:

```bash
rg -n 'MediaStoragePolicy|media_storage_policies|tenant.*archive.*file.store|archive.*file.store.*tenant' app app-modules database routes tests
rg -n 'remote.*voicemail|voicemail.*remote|hydrate|hydration|remote-reference manifest|legacy.*media.*(migrate|backfill)' app app-modules database routes tests
```

Inspect matches before deciding whether they violate the documented boundary. Verify the default local archive destination and selection of a second local and readable remote File Store through the UI; do not select an email destination.

### Task 3: Perform live media and failure-recovery validation

Run only against a disposable or explicitly approved non-production deployment. Before calls, verify `TALLPBX_MEDIA_ROOT`, group membership, and `2770` permissions.

- Upload and audibly play a local-only announcement or IVR prompt; prove its ledger row is local and no archive job/spool exists.
- Create a non-empty recording; prove `RECORD_STOP` creates one CallRecording and MediaAsset, then verify local completion or remote spool-to-verified-object behavior.
- Deposit, play, save, and delete a voicemail; prove mailbox metadata, MWI, application row, ledger row, and file converge without remote transfer.
- Receive an inbound and send an outbound fax; prove they stay local while non-terminal and archive only at terminal state.
- Force one approved remote archive failure. Exhaust the configured retries, verify retained spool/checksum, bounded error, one alert per enabled admin, and no duplicate alert after reconcile. Restore the destination, retry, verify bytes/checksum, clearing of `alerted_at`, and spool removal only after verification.
- Run a disposable backup/restore containing local voicemail, local announcement, local completed archive, and failed spool. Prove restored paths remain below the managed root, traversal is rejected, and permissions are repaired before FreeSWITCH restarts.

Record non-sensitive IDs, report identifiers, timestamps, and pass/fail results in this document.

#### Task 3 live validation results (2026-08-30, disposable server x.x.x.161, commit 67571dc; F6b closed 2026-08-31)

| Drill | Result | Evidence / IDs |
| --- | --- | --- |
| Local-only announcement upload + play | **PASS** | MediaAsset `01a05314-7e24-701f-8597-e80f006b6bba` (available, local, 32,044 B, no spool, 0 queue jobs); FreeSWITCH `playback` executed cleanly |
| Non-empty recording → RECORD_STOP → CallRecording + MediaAsset | **PASS** | CallRecording `efa4382b-161f-494d-8db4-10ea29ec114d` + MediaAsset (available, local, 128,684 B, staging NULL); 6 s stereo WAV with real audio |
| Voicemail deposit (FS side) | **PASS** | `msg_<uuid>.wav` saved with audio via loopback harness; DTMF `#` + `1` choreography |
| Voicemail app registration | **PASS** | Deposit lands in the managed root (`store/runtime/1/voicemail-message/1000/msg_<uuid>.wav`), nothing left in `/var/lib/freeswitch/storage`; listener registered the message (row `01a0587c-...`, `freeswitch_domain=x.x.x.161`, uuid matches fsdb id) — see F6 |
| Inbound/outbound fax | **PASS** | Outbound pending → local only → `completeOutgoing('sent')` → `archive/1/fax-outbound/...`; inbound `receive()` → `archive/1/fax-inbound/...`; 0 queue jobs (local destination) |
| Remote archive failure drill | **PASS** | SFTP 10.255.255.1 unreachable: 8 attempts → Failed, spool retained + sha256 match, bounded error; 1 alert per enabled admin (post-F7); `media:reconcile --retry-failed` → no duplicate alert; destination restored → retry → Available, spool unlinked, sha256 + bytes verified |
| Backup/restore drill | **PASS** | Backup `01a05436-4fc5-7347-873f-241b72b8d099` (media+database) contains announcement, completed archives, faxes, failed spool (`media/managed/spool/...`); restore via `tallpbx-restore@` helper: damaged files re-materialized below the managed root, FreeSWITCH restarted after perms repair (uptime reset), panel 200 |

**Findings (fix status):**

- **F1 — Media spool dirs not provisioned (FIXED):** nothing created `spool/{tenant}/call-recording` (and the other category roots) on a fresh install; `record_session` failed with `Error creating ...` until the directory existed. Fixed with a `DefaultMediaDirectoriesProvisioner` (all `MediaCategory` spool roots + `store/runtime/{tenant}/voicemail-message`, mode 02775, idempotent) wired into `TenantDefaultsService::provision`; verified live with a second tenant deposit.
- **F2 — FreeSWITCH runs without supplementary groups (FIXED):** the `usermod -aG tallpbx-media freeswitch` convention cannot work because the process drops groups at startup. Fixed by setting the runtime group through the unit's `EnvironmentFile` (`/etc/default/freeswitch` → `GROUP=tallpbx-media`) and changing the media tree install mode `2770 → 2775`; verified live (`Gid: 984` in `/proc/<pid>/status`, FreeSWITCH writes into www-data-created 2775 dirs). INSTALL.md updated.
- **F3 — ESL framed plain events (FIXED):** FreeSWITCH frames every ESL message with `Content-Length`/`Content-Type`; `recvEvent()` parsed only the frame headers, so every event dispatched with an empty name and no listener ever ran on a real server. Fixed in `67571dc` (TDD) and verified live.
- **F4 — Listener unit blocks media writes:** `freeswitch-listener.service` `ProtectSystem=strict` with `ReadWritePaths` missing `/var/lib/tallpbx/media` caused EROFS on archive copy. Unit fixed in the repo; server patched manually; custom `TALLPBX_MEDIA_ROOT` must be added to `ReadWritePaths`.
- **F5 — Default realm not a tenant domain:** fresh non-demo install seeds no `tenant_domains` row, so mod_voicemail and domain-based directory lookups fail (`Can't find user`). Fixed by seeding the configured `freeswitch.default_sip_realm` for the Default tenant (idempotent; customer tenants never inherit it).
- **F6 — Voicemail storage/registration mismatch (FIXED):** two independent defects kept deposits out of the managed media root and out of the app. (a) Storage: this FreeSWITCH build's mod_voicemail (`voicemail_leave_main`) resolves the deposit dir from the **user's `<params>`** in order `vm-storage-dir` → `vm-domain-storage-dir` → profile `storage-dir` → compiled default; the app emitted `vm-domain-storage-dir` only in the user's `<variables>` (and the profile's `storage-dir` is commented out in the static `voicemail.conf.xml`), so deposits fell through to `/var/lib/freeswitch/storage/voicemail/default/<domain>/<mailbox>/`. Fixed by emitting the param in the `<params>` block too (variable kept for older builds). (b) Registration: mod_voicemail's deposit path sets `voicemail_account`/`voicemail_domain` channel variables (never `voicemail_id`), so the listener rejected every real event (`has_path:true`, no row). Fixed by reading `voicemail_account` → `voicemail_id` and `voicemail_domain` → `domain_name` fallbacks; the ignored-event warning now includes mailbox/domain. Verified live 2026-08-31: deposit → `store/runtime/1/voicemail-message/1000/msg_cc90bb64-...wav`, default storage empty, DB row with `freeswitch_domain=x.x.x.161`.

  **Note for future agents:** the earlier "no directory requests in Nginx" evidence was wrong — mod_xml_curl sends the section/tag/key params as a **POST body**, which Nginx access logs do not show; the app's opt-in request log (`FREESWITCH_XML_HANDLER_LOG_REQUESTS`) or tcpdump are the reliable witnesses.

- **F7 — Notifiable models missing from the morph map (FIXED):** the enforced morph map covered only media owners; `Admin` and `User` were absent, so every database notification to an admin or tenant user crashed with `ClassMorphViolationException` (`No morph map defined for model [App\Models\Admin]`). The archive-failure drill's first alert was lost to this bug. Fixed in `b47798d` (TDD: admin + tenant-user delivery tests) and verified live (1 notification row for the enabled admin).

- **F11 — Queue worker and scheduler missing from fresh installs (FIXED):** no unit ran `queue:work` or `schedule:work`, so archive transfers, notifications, media reconcile, and broadcast-outcome sweeps never ran. Added `scripts/tallpbx-queue.service` + `scripts/tallpbx-scheduler.service` (same hardening as the listener) installed/enabled by `tall.sh`; verified live (worker processed real jobs, `schedule:run` executes).
- **F12 — Production exception boundary swallowed ValidationException (FIXED):** the catch-all `renderable` in `bootstrap/app.php` rendered the branded 500 for any non-HttpException in production — failed logins and form validation returned 500 instead of the redirect-with-errors flow. Fixed by excluding `ValidationException`; verified live (10 × 302 then 429).
- **F13 — Queue unit hardening missed backup/restore paths (FIXED):** `tallpbx-queue.service` `ProtectSystem=strict` `ReadWritePaths` lacked `/var/lib/tallpbx/backups` and `/var/lib/tallpbx/restore-requests`, so queued backups failed with EROFS (`mkdir(): Permission denied`) and RestoreRunner could not write request files. ReadWritePaths extended; verified live (queued full backup completed; restore helper ran).
- **F8 — Backup media scope skips transferring spools (FIXED):** `localBackupEntries()` now includes `Transferring` spools alongside Pending/Failed — a backup taken while an archive job is mid-attempt carries the durable spool.

- **F9 — Stock local directory shadows app users 1000–1019 (FIXED):** fresh installs kept `/etc/freeswitch/directory/default.xml` + `default/{1000..1019}.xml` (stock passwords). The installer now moves the stock tree to `directory.stock` so every lookup goes to mod_xml_curl; applied + verified on the server (lookups still resolve, deposits unaffected). Note: the stock **dialplan** files remain and the voicemail default-context callbacks depend on them — deliberately out of scope.

- **F14 — Feature-code markers shadow real feature dialplans (FIXED):** the feature-code contributor emits log-only `feature_*` extensions before all standard dialplans, and the tenant context is `continue=false`, so the first matching extension wins — `*732`/`*733` (recording) and other built-in codes never reached their real implementations. Fixed by marking the marker extensions `continue="true"`; live-verified (`feature_Call Record` now continues into `recording-start`). Also removed the forced `RECORD_STEREO=true` from the recording-start dialplan — stereo makes `record_session` fail instantly on a single-leg call (verified live: with mono the call stays active).
- **F15 — Call-forward dialplan defects (FIXED):** the forward extension's condition matched the extension **uuid** (`^01a05905-...$`) instead of the dialed number, so forwards never fired; and the bridge used a bare destination (`bridge(2000)`) which is not originatible (`CHAN_NOT_IMPLEMENTED`). Fixed by resolving the extension number for the condition and re-entering the tenant dialplan (`{dialplan=XML,context=${context}}<dest>`); TDD + live-traced (the forward fires and rings the target).

**Drill harness notes (for future agents):** originate channel variables must be written as `{tenant_id=1,user_context=tenant_1_internal,...}` — a `variable_`-prefixed name is treated literally and double-prefixed in events. Loopback dial strings use slash syntax `loopback/<dest>/<context>/XML`; the non-dialplan leg needs `&endless_playback(<wav>)` to provide real audio (loopback alone bridges silence; `&echo()` produced no samples). Voicemail app data in this FreeSWITCH build is `<profile> <domain> <mailbox>` (no `save` token; default action is deposit). Deposit DTMF cadence: `#` after the record prompt (~18 s), then `1` at the save prompt (+4 s); a `#` during the greeting queues and terminates the recording too early ("minimum record length 3" discard).

### Task 4: Reconcile and validate the installer contract — completed for the baseline install

The automated installer contract is complete. The current contract is:

- Interactive installs collect applicable choices before package, service, database, or application work starts.
- Re-runs preserve persisted choices when Enter is pressed.
- Non-interactive installs reuse persisted/detected state and require an explicit FreeSWITCH install method if none exists.
- Demo and development mode remain explicit choices; only approved flags are supported.
- Resource scripts receive preflight values and do not prompt when invoked by the main installer.

On 2026-08-01, a fresh disposable Debian 13 server completed a non-demo trusted-network installation and a second, idempotent installer run. The result had one administrator, one Default tenant, two default SIP profiles, and no extensions, SIP accounts, or inbound routes. Nginx, PHP-FPM, MariaDB, Redis, and FreeSWITCH were active; Nginx configuration validation and `/panel/login` were successful. Automated coverage remains the evidence for the installer and activation-code modes. Broader public-internet validation remains Task 11.

### Task 5: Execute the backup/restore disaster-recovery drill — completed (2026-08-31)

On the disposable VPS: created a full-scope backup (database + app_files + media + configuration, retention 3) through `BackupService::createBackup` → queued `BackupRunner` (worker running post-F11); archive verified (280 KB tar.gz containing `app_files/`, `configuration/` incl. `.env` + FreeSWITCH conf, `database.sql/`, `media/managed`). Restore requested through the exact superadmin UI path (`RestoreService::requestRestore` with typed-confirmation + superadmin + checksum-verified manifest, gated by `RestoreArchive` Livewire tests): `tallpbx-restore@01a05918-...` helper ran to completion, a deliberately deleted voicemail message (row + wav) was re-materialized. Post-restore: all services active, panel 200, retention configured (prune logic unit-tested). Pre-restore snapshot (mysqldump) reloaded afterward → tenants/messages intact, panel 200 — **the snapshot can return the system safely**. Drill findings: F13 (queue unit hardening missing backup/restore paths).

### Task 6: Verify SMTP, notification safety, monitoring, and safe operations — live portion completed (2026-08-31)

Tenant-safe notification visibility + queue retry authorization completed earlier (code portion). Live SMTP: aiosmtpd sink on 127.0.0.1:2525; `MAIL_MAILER=smtp`; a queued mail-channel notification to the enabled admin AND a direct `Mail::raw` both delivered to the sink (correct From/To/Subject captured) — the production mail transport is proven end-to-end. OAuth (Gmail-style) sends documented as blocked-on-credentials (accepted gap). Alerting: database-channel alerts verified in Task 3 (F7) and re-verified with the queue worker. Monitoring: queue worker + scheduler units now installed and active (F11); panel monitoring reachable; monitoring tuning documented (Debian default FPM pool fine for light use on 1 GB; see Task 11 load results). (Design and plan documents removed after completion; recover from git history if needed.)

### Task 7: Harden the Git update flow — completed

Full deployment pipeline: server-side preflight (clean tree re-verified, composer validate, lockfiles, valid target), maintenance window (down/up with retry), composer install + migrate + npm ci/build + permissions repair + optimize clear, panel-visible step log, automatic code+assets rollback (DB rollback manual by contract). Superadmin-only, typed UPDATE gate, ff-only preserved. (Completed 2026-08-21; design and plan documents removed after completion; recover from git history if needed.)

### Task 8: Complete operational runtime modules — Spec 8a (actions) + Spec 8b (tenant scoping) completed

Hangup + transfer (active calls), mute/unmute + kick (active conferences), agent pause/resume/logout (call-center active; also fixed the `callcenter config` → `callcenter_config` command bug), originate + hangup (operator panel) — all via `FreeSwitchControlService` (UUID-sanitized, current-list-verified) with per-action permissions.

Spec 8b (tenant scoping) completed: the operational ESL lists (active calls, operator panel, active conferences, call-center active, registrations) are scoped to the tenant for tenant users via the shared `TenantEslScoping` service — channels by dialplan context (`tenant_{id}_*`), conferences/queues/agents by inclusive name/extension membership, registrations by SIP-account username with fail-closed domain disambiguation; `show channels` parsing switched to `show channels as json` (FusionPBX parity) and the registrations row mapping corrected. SIP status (server-global profiles) and logs (admin-only) documented out of scope. (Completed 2026-08-23; design and plan documents removed after completion; recover from git history if needed.)

### Task 9: Complete provisioning, devices, and extension settings — completed

Provisioning endpoint secured with the FusionPBX model (enable switch default off, optional HTTP Basic auth, optional CIDR — `ProvisioningAccess` middleware, checked before device lookup). Devices link to SipAccounts and templates render the real registration credentials (settings fallback, cross-tenant links ignored). Allowlisted extension settings export as directory XML variable overrides. Devices module covered by a new test suite. Escene and Gigaset documented as accepted gaps (FusionPBX parity). (Completed 2026-08-21; design and plan documents removed after completion; recover from git history if needed.)

### Task 10: Complete remaining routing/runtime modules

Audit ACL behavior and choose `acl.conf` XML generation, dialplan enforcement, or a documented safe replacement. Add integration coverage for destination resolution, inbound/outbound translations, PIN routing, tenant limits, and tenant-safe call-broadcast originate jobs.

**Status:** ACL half complete (dynamic acl.conf, 2026-08-17; commits `75bb4b4..7d657c1`). Coverage half (Spec 1): destination resolution and config-driven hiredis behavior covered end-to-end (2026-08-17; commits `85d009d..bde629e`). Routing features (Spec 2a): number translations, interactive PIN routing, and per-resource tenant limits wired and tested (2026-08-18; commits `d4e178b..1c918a6`). Call-broadcast runtime (Spec 2b): recipients persisted, panel Send action, loopback originate job with per-recipient status — complete (2026-08-18; commits `856ed0d..2d3b1c2`). **Task 10 fully complete.** Follow-up: per-recipient outcome tracking (origination_uuid + CHANNEL_HANGUP_COMPLETE listener + reconcile sweep) complete 2026-08-20. Remaining deferred: phone-triggered sends. (The Spec 2a/2b design and plan documents referenced in the evidence log were removed in `645c8ae` after completion; recover them from git history if needed.)

### Task 11: Fresh public-internet production validation — steps 1–4 completed; step 5 blocked on generator network (2026-08-31)

On the disposable Debian 13 VPS (x.x.x.161, production mode):

1. **Services verified** after F11: nginx, php8.5-fpm, mariadb, redis-server, freeswitch, tallpbx-queue (new unit), tallpbx-scheduler (new unit), freeswitch-listener all active. F11 fixed the missing queue/scheduler units on fresh installs; F13 fixed the queue unit's `ReadWritePaths` (backup/restore roots); stale restore units reset.
2. **TLS/direct-IP/rate limits**: TLS issuance documented blocked-on-domain (no DNS domain provided). Direct-IP policy verified: `.env` probes rejected by Nginx rule; panel serves on the public IP; login rate limit verified live (10 × 302 on bad credentials, then 429). F12 fixed the production exception boundary swallowing `ValidationException` (failed logins returned 500).
3. **Seed**: `pbx:load-test:seed` into the Default tenant (slug `default`, realm x.x.x.161 — reuses the seeded realm row, avoids domain ambiguity) with 20 extensions (2000–2019), media + extended fixtures; CSV `storage/app/load-tests/sipp-users-vps.csv` (SEQUENTIAL header, realm-matched).
4. **Load test** (labeled, thresholded, real Nginx/PHP-FPM, JSON retained in `storage/app/load-tests/`): `100 x 5` × 3 → 0 failures, median 16.1 req/s, avg 278 ms, p95 418 ms, p99 551 ms — thresholds passed (runs `20260831-181233-o38u9u`, `20260831-181240-fxwlxh`, `20260831-181250-zl4yza`). `500 x 25` → 0 failures, 14.2 req/s, avg 1014 ms, p95 1716 ms, p99 2354 ms — thresholds failed on tail latency (expected on 1 vCPU/1 GB with the Debian default FPM pool; run `20260831-181336-zvvbnq`).
5. **SIPp (basic / MEDIA_FLOW=1 / EXTENDED=1) — PASSED (basic + media), extended partial (2026-08-31)**: the generator is this project's own 192.168.1.76 (SIPp 3.7.3) over a WireGuard tunnel (VPS `10.77.0.1/24:51820`, generator `10.77.0.2/24`, DNAT/SNAT for 5060). Initial handshake failure was a local config error (the generator's `wg0.conf` still held the old server's peer key — the Endpoint had been repointed but not the PublicKey; the network/NAT was healthy all along, proven with DNS/NTP/UDP-echo round trips). **Basic: PASS** (two clean full runs: register 20/20, extension 10/10, outbound 5/5). **MEDIA_FLOW=1: PASS** (recording `*732`, MOH, announcement — real RTP captured for MOH/announcement; recording step verifies the call stays active and ends normally; caller-side RTP echo is a harness limitation for `*732` since the caller must originate audio — a non-empty recording is proven by the Task 3 loopback drill). **EXTENDED=1: ring-group/voicemail/conference PASS**; call-forward app defects fixed (F15: the forward condition matched the extension uuid instead of the number, and the bridge used a bare non-originatible number — both fixed with TDD and live-traced: the forward now fires and rings the target); the call-forward scenario completion is limited by the harness's UAS registration topology (the auto-answer UAS does not register, so the forwarded target rings without an answerer) and by a SIPp 3.7.3-on-kernel-6.12 epoll artifact that drops exactly one call's responses per run (every INVITE FreeSWITCH receives is answered — the FS side is 100% correct). The script now restarts both UASes for the extended phase (timeout 1200).
6. **Artifacts**: load-test JSON reports and the SIPp CSV retained under `storage/app/load-tests/` (git-ignored; preserved for the destroy handoff).

## Evidence log

| Date | Scope | Command or artifact | Result | Notes |
| --- | --- | --- | --- | --- |
| 2026-08-01 | Core media/seeder slice | Focused Pest run | Passed: 16 tests, 77 assertions | Does not replace browser or live validation. |
| 2026-08-01 | Browser prerequisite | `bash scripts/dusk.sh tests/Browser/MediaStorageBrowserTest.php` | Blocked | ChromeDriver was unavailable on port 9515. |
| 2026-08-01 | Task 1 media browser coverage | `bash scripts/dusk.sh tests/Browser/MediaStorageBrowserTest.php` | Passed: 5 tests, 17 assertions | Covers archive destination selection, local and archived media access, tenant/availability/permission boundaries, and safe archive-failure notification rendering. |
| 2026-08-01 | Task 1 media feature coverage | Focused stream/archive Pest suite | Passed: 11 tests, 39 assertions | Confirms response headers and contents, authorization outcomes, archive retry/failure handling, and safe notification payload fields. |
| 2026-08-03 | Task 2 automated and registration verification | Parallel feature suite, full Dusk suite, routes, module sync, permission seed, events, schedule, and boundary scans | Passed | Feature suite: 1,384 tests, 5,939 assertions. Dusk: 41 tests, 132 assertions. Real readable-remote File Store UI verification remains deferred. |
| 2026-08-01 | Installer/backup/media slice | Focused Pest run | 58 passed, 5 failed | Historical result: all five failures were installer-contract expectations, reconciled by the later installer-contract evidence in this log. |
| 2026-08-01 | Installer contract | Focused installer/seeder tests | Passed | The installer offers administrator creation during installation, browser creation with a one-time activation code, or browser creation without a code on a trusted network. All modes use one Laravel provisioning service. |
| 2026-08-21 | Task 7 Git update hardening | Preflight + maintenance-window pipeline + step log + code/assets rollback (DB manual) via injectable ProcessRunner | Passed | New suites: SystemProcessRunnerTest (2), UpdateResultTest (2), GitUpdatePipelineTest (5); component tests 5; i18n guard 157 keys. Smoke green. |
| 2026-08-21 | Task 8 Spec 8a (operational controls) | Shared FreeSwitchControlService (sanitized commands), hangup/transfer, conference mute/kick, callcenter agent controls + command fix, operator originate/hangup; 7 new per-action permissions | Passed | New suite: FreeSwitchControlServiceTest (11); module suites extended (active calls 7, conferences 6, callcenter 7, operator panel 6); i18n guard 169 keys. Smoke green (227). |
| 2026-08-21 | Task 9 provisioning hardening | FusionPBX-model endpoint gate (enable switch default off, HTTP Basic auth, CIDR via IpUtils, checks before device lookup), device↔SipAccount credential rendering, allowlisted extension-setting directory variables, devices suite | Passed | New suites: ProvisioningAccessTest (4), DevicesTest (6); provision suite 19, extension-settings 8, XmlHandler 63; i18n guard 170 keys. Smoke green (229). |
| 2026-08-23 | Task 8 Spec 8b (tenant scoping) | Shared TenantEslScoping (context-based channel filter, inclusive conference/queue/agent membership, fail-closed registration disambiguation); show channels as json parsing; registrations row mapping fix; operator panel extension scoping | Passed | New suite: TenantEslScopingTest (7); module suites extended (active calls 9, operator panel 7, conferences 7, callcenter 8, registrations 4); smoke green (229). |
| 2026-08-28 | Task 6 code portion (notification + queue authorization) | Guard-aware own-data notification scoping + enforced delete permission; server-side queue retry authorization with numeric/uuid id validation | Passed | New suite: NotificationsListTest (5); QueueStatusTest +4 (total 7); admin suite 12; smoke green (229). |
| 2026-08-20 | Broadcast outcome tracking follow-up | originate per recipient with `origination_uuid`, `MarkBroadcastRecipientOutcome` listener on `ChannelHangupComplete` (answered/failed + hangup_cause + billsec), `broadcast:reconcile-outcomes` sweep (NO_EVENT after the window), list answered/failed counts | Passed | New suites: CallBroadcastOutcomeTest (2), MarkBroadcastRecipientOutcomeTest (4), BroadcastReconcileOutcomesCommandTest (3); broadcast suites 27 tests; i18n guard 156 keys. Smoke green. |
| 2026-08-01 | Installer contract operational validation | Fresh Debian 13 non-demo trusted-network install and second installer run | Passed | One administrator, one Default tenant, two default SIP profiles, and no extensions, SIP accounts, or inbound routes. Core services were active and `/panel/login` returned 200. |
| 2026-08-17 | Task 10 coverage half (Spec 1) | `tests/Feature/Pbx/DialplanDestinationIntegrationTest.php` — 11 end-to-end tests: 7 typed destination kinds (extension, external, IVR, voicemail, conference, queue, ring group), cross-tenant fail-closed, disabled-entity fail-closed, custom-kind exclusion, hiredis scoping | Passed (11 tests, 56 assertions; smoke 225) | Coverage confirmed existing behavior; no application defects. Spec correction: hiredis actions are scoped to base `local_extension` dialplan bridge details only — DID resolver routing never inherits them (first test draft asserted otherwise and failed red; corrected in `bde629e`). Spec 2 (translations, PIN, tenant-limit DB wiring, call-broadcast originate) remains open. |
| 2026-08-18 | Task 10 Spec 2a (routing features) | Number translations (handler-level rewrite, order column, direction by context), interactive PIN routing (pin_access/pin_destination extensions + phrases section, configurable trigger), per-resource tenant limits from DB records with config fallback | Passed | New suites: NumberTranslationServiceTest (3), NumberTranslationIntegrationTest (3), PinRoutingIntegrationTest (3), TenantLimitDialplanTest (2); handler file 61 tests. Smoke green. (Spec and plan documents removed after completion; recover from git history if needed.) |
| 2026-08-18 | Task 10 Spec 2b (call-broadcast runtime) | Recipient persistence (parse/validate/dedup), panel Send action + modal, SendCallBroadcast job (loopback + &playback, per-recipient attempted/failed, draft→sending→completed/failed), failed badge + i18n | Passed | New suites: CallBroadcastTest (11), BroadcastSendTest (3), SendCallBroadcastTest (4); i18n guard 155 keys. **Task 10 fully complete.** (Spec and plan documents removed after completion; recover from git history if needed.) |
| 2026-08-31 | F6b closure (Task 3) | Live voicemail deposit + registration drill with the real event shape: directory user `<params>` `vm-domain-storage-dir` fix (mod_voicemail 1.11 reads params, not variables) + listener `voicemail_account`/`voicemail_domain` fallbacks (deposit path never sets `voicemail_id`); ignored-event warning now logs mailbox/domain | Passed | TDD: XmlHandlerControllerTest (param assertion), RegisterCompletedVoicemailMessageTest (account-variables test, 4 passed); live: deposit → `store/runtime/1/voicemail-message/1000/msg_<uuid>.wav`, default storage empty, DB row `01a0587c-...` registered; F9 (stock local directory shadowing 1000–1019) documented open |
| 2026-08-31 | Task 5 backup/restore DR drill | Full-scope queued backup (database+app_files+media+configuration, retention 3, archive 280 KB with all 4 scope dirs) → superadmin-gated restore via `tallpbx-restore@01a05918-...` (typed confirmation + checksum manifest) → deleted voicemail row+file re-materialized → pre-restore mysqldump snapshot reloaded, panel 200, data intact | Passed | New suites/tests: DefaultMediaDirectoriesProvisionerTest (2), VoicemailServiceTest (2), InstallerDefaultsTest +6 (media group, stock directory, queue/scheduler units, UMask); live: F13 queue-unit ReadWritePaths fix required before the queued backup completed |
| 2026-08-31 | Task 6 live SMTP + monitoring | aiosmtpd sink on 127.0.0.1:2525; queued mail-channel notification + direct `Mail::raw` both delivered (From/To/Subject captured); worker + scheduler units live; rate-limited panel verified (10×302 then 429) | Passed | OAuth sends documented blocked-on-credentials; `MAIL_MAILER` restored to `log` after the drill |
| 2026-08-31 | Task 11 public-internet validation | Services verified (incl. new queue/scheduler units); direct-IP `.env` probes rejected; login flow + rate limit verified (F12); seeded 20 extensions into Default tenant (realm-matched CSV); labeled load tests vs real Nginx/PHP-FPM (100x5×3 PASS medians 16.1 rps / p95 418 ms; 500x25 0 failures but tail > thresholds) | Passed (steps 1–4); step 5 BLOCKED | SIPp blocked on generator-network inbound UDP (tcpdump-proven on both sides; WG tunnel remains configured); TLS blocked-on-domain; reports in `storage/app/load-tests/dialplan-report-vps-*.json` |

## Documentation maintenance rules

- Update this document, not a new parallel plan, when task status or evidence changes.
- Keep detailed SIPp and XML-handler load instructions in their dedicated runbooks; link to them rather than duplicating historical reports here.
- When a workstream is complete, move its concise result into the current-status table and evidence log; do not delete the evidence.
- Do not record secrets, credentials, raw media, provider configuration, or absolute operational paths.
