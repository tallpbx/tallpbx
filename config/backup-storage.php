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
];
