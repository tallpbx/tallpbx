# Backup & Restore Operator Journey

What a TallPBX backup contains, what a restore does to the appliance, how long
it may take, how to watch it, and what your recovery options are at each stage.
The technical internals are in [Architecture](architecture.md) and the day-to-day
commands in [Operations](operations.md).

> **Restores are destructive.** A restore replaces the database and the
> selected file trees. Read §5 before you confirm one.

## 1. What each scope contains

| Scope | Contains | Deliberately excludes |
| :--- | :--- | :--- |
| **Database** | A full SQL dump of every TallPBX table (tenants, extensions, SIP accounts, dialplans, CDRs, settings) | Nothing — it is a complete dump |
| **Application files** | `storage/app` (excluding media) and every third-party module under `custom-modules/` | `recordings`, `voicemail`, `fax`, `backups` (they have their own scope) |
| **Media** | `recordings`, `voicemail`, `fax`, and the managed media store and spool | Database rows that describe the media |
| **Configuration** | `.env`, `config/`, and `/etc/freeswitch` | Certificates, `/etc/tallpbx/` firewall state, installed packages |

Choose scopes when you create a backup profile. A database-only restore is the
common case after a bad migration; a full restore is the disaster-recovery case.

## 2. Where backups live

Backups are written to the configured **File Store** — the local appliance disk
by default, or a remote store you select. A completed archive is accompanied by
`backup-manifest.json`, which records the format version, the selected scopes,
the SHA-256 checksum, and the archive size.

- **Location on the appliance**: `<backup-storage.root>/` (default
  `/var/lib/tallpbx/backups`).
- **Retention**: each profile keeps `retention_count` completed runs; older runs
  are pruned automatically.
- **Encryption**: archives are **not encrypted by TallPBX**. If they leave the
  appliance, encrypt them at rest yourself (for example with an encrypted disk or
  bucket). The manifest checksum proves integrity, not confidentiality.
- **Credentials**: a restore never uses remote-provider credentials from the
  archive. A remote archive is first staged to a private local directory, and
  only that local file is handed to the privileged helper.

## 3. Verifying a backup before you need it

1. Open the completed run and confirm `backup-manifest.json` exists and its
   `archive.sha256` matches the archive.
2. Restore it on a **disposable host**, never production.
3. Confirm the restored panel loads, tenants and extensions exist, and a test
   call completes.

A backup you have never restored is a hypothesis, not a backup.

## 4. What a restore does, in order

1. **Preflight** — the archive's member list is checked for absolute or
   traversal paths, then disk space is measured from the archive's uncompressed
   member sizes. Required space is `working set + max(2 GB, 10 % of the working
   set)` on **every** filesystem the restore writes to. A short volume refuses
   the restore before anything is touched.
2. **Extract** into `<backup-storage.root>/restore-extract/<operation>`.
3. **Snapshot** the current state (the rollback material): selected file trees
   and, for a database scope, a fresh dump.
4. **Maintenance mode on** (`artisan down`). The panel stops answering from here.
5. **Import** the database dump through the configured MySQL client, then
   restore the selected file trees.
6. **Migrate** (`migrate --force`) so an older archive matches the installed code.
7. **Reconcile** the restore journal back into the new database — this recreates
   the operation row, its progress, and who requested it.
8. **Health check** — required services are restarted and verified running, then
   the database, Redis, and FreeSWITCH respond; the app leaves maintenance mode
   and `GET /up` must return 200.

Progress is written to the **journal** at every phase, so a restore that dies
mid-import still reports where it stopped.

## 5. Time bounds and downtime

| Bound | Value | Reset? |
| :--- | :--- | :--- |
| One restore command | 15 minutes (`TALLPBX_RESTORE_COMMAND_TIMEOUT`, default 900 s) | Per command |
| The whole operation | 1 hour (`TALLPBX_RESTORE_OPERATION_DEADLINE`, default 3600 s) | Never — checked before phases and inside subprocesses; systemd stops blocked file work |

The per-command ceiling catches one hung client; the operation budget stops a
sequence of individually healthy commands from running for hours. The systemd
service also enforces a one-hour hard limit on in-process file work. A hard
stop may leave the journal at its last nonterminal phase; treat that as an
interrupted restore requiring manual triage. Time spent waiting for a human to
confirm is outside both.

**Expected downtime:** the panel is unavailable from step 4 to step 8. For a
small appliance with a database-only archive this is typically a couple of
minutes; a full archive with media is bounded by the operation deadline.

## 6. Watching a restore

- **Panel**: the restore operation shows its current phase and, on failure, the
  reason and the rollback snapshot path.
- **Journal (database-free)**: if the panel is down, run

  ```bash
  sudo tallpbx-restore-status
  ```

  It reads `/var/lib/tallpbx/restore-journal/` directly, prints one row per
  operation, and exits non-zero while any operation is unfinished. It does not
  boot Laravel, so it works while the database is being replaced.
- **Helper output**: `journalctl -u 'tallpbx-restore@*' -n 100` shows the unit
  that executed the operation.

The journal directory is `0700 root:root` and only the privileged helper writes
it. The panel renders the projected `restore_operations` columns, including the
requester's name and email captured at request time.

## 7. Failure recovery

| Failure point | What happened | Rollback |
| :--- | :--- | :--- |
| Preflight | Nothing was touched | Not needed |
| Extract or snapshot | Nothing destructive ran; maintenance mode was never entered | Not needed |
| Import | Database and/or files replaced; maintenance mode is **on** and stays on | **Manual** — restore the snapshot from `pre_restore_snapshot_path`, then `tpbx up` |
| After import (migrate, reconcile, health check) | Database replaced, health check failed | **Manual** — the row and journal both say `failed`; inspect the reason, correct the service or schema, re-run the health check by repeating the restore |
| Web tier did not answer `/up` | The app was put back into maintenance mode deliberately | **Manual** — fix the web tier, then `php artisan up` |

A restore that fails **never** leaves the appliance taking live traffic: the
catch path records the failure in the journal first, then reconciles the row,
and leaves maintenance mode active (`maintenance_locked: true`).

There is **no automatic rollback** for a restore: the snapshot is written for a
human to use, because re-importing it automatically would replace the database a
second time while the first failure is still unexplained.

## 8. Restoring onto a replacement host

1. Install TallPBX on the new host, then run `tpbx appliance:sync-host` so host
   settings (IP addresses, PHP-FPM pool shape) match the hardware.
2. Create the root-only journal directory:
   `sudo install -d -m 0700 -o root -g root /var/lib/tallpbx/restore-journal`.
3. Copy the archive and its manifest to the new host and upload it through
   **Backups → Restore → Upload archive**, confirming the archive name exactly.
4. After the restore completes, re-run `tpbx appliance:sync-host` — a
   configuration-scope restore brings the old `.env` back, including the previous
   host's address and pool settings.

## 9. Upload ceiling

A direct archive upload is capped at **2 GB decimal** (2,000,000,000 bytes).
PHP and Nginx carry binary headroom above it, so the application rule is what
the operator sees: an oversized archive is rejected with a validation message,
not a transport error. Larger backups should be restored from a File Store
instead of uploaded through the browser.

## Related Documentation

- [Operations & Maintenance](operations.md) — service management, the journal bootstrap, and the web-updater prerequisite.
- [Environment Reference](env-reference.md) — `TALLPBX_RESTORE_*`, `TALLPBX_BACKUP_ROOT`, and the upload ceiling.
- [Architecture](architecture.md) — how the privileged helper, journal, and projection fit together.
