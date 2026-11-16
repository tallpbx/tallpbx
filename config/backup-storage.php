<?php

declare(strict_types=1);

return [
    /*
     * Where backup archives and restore extraction workspaces live.
     */
    'root' => env('TALLPBX_BACKUP_ROOT', '/var/lib/tallpbx/backups'),

    /*
     * Root-only directory holding one authoritative journal entry per restore
     * operation. Fixed for the appliance: the privileged helper installs and
     * writes it, and no unprivileged process reads it directly.
     */
    'journal_root' => env('TALLPBX_RESTORE_JOURNAL', '/var/lib/tallpbx/restore-journal'),

    /*
     * Fixed handoff directory watched by `tallpbx-restore-dispatch.path`.
     * Overrideable in isolated tests only; production systemd units, the
     * dispatcher, the queue sandbox and the installer all name this exact
     * host path, so no `.env` setting advertises an alternate location.
     */
    'request_root' => '/var/lib/tallpbx/restore-requests',

    /*
     * Per-command ceiling for one restore subprocess. A dump or import that
     * streams to a file has no console output, so an idle timer is not a
     * progress signal; this catches a hung client instead.
     */
    'restore_command_timeout' => (float) env('TALLPBX_RESTORE_COMMAND_TIMEOUT', 900),

    /*
     * Whole-operation budget covering machine work only. Time spent waiting for
     * a human to confirm a restore is outside it.
     */
    'restore_operation_deadline' => (int) env('TALLPBX_RESTORE_OPERATION_DEADLINE', 3600),

    /*
     * Decimal product ceiling for a direct restore-archive upload. The PHP and
     * Nginx transport limits carry binary headroom above it.
     */
    'max_upload_bytes' => (int) env('TALLPBX_MAX_UPLOAD_BYTES', 2_000_000_000),
];
