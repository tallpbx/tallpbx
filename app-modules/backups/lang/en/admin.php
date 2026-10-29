<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: backups
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'backups_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'backup_create' => 'Create Backup',
    'backup_configuration' => 'Backup Configuration',
    'backup_no_configs' => 'No backup configurations yet. Create one to start protecting your data.',
    'backup_scopes' => 'Scopes',
    'backup_last_run' => 'Last Run',
    'backup_size' => 'Size',
    'backup_retention' => 'Retention',
    'backup_retention_hint' => 'Number of backup files to keep before pruning the oldest.',
    'backup_compression' => 'Compress backup with gzip',
    'backup_encryption' => 'Encrypt backup with GPG',
    'backup_schedule' => 'Schedule (cron)',
    'backup_schedule_hint' => 'Leave empty for manual-only. Example: 0 2 * * * runs daily at 2 AM.',
    'backup_name_placeholder' => 'e.g. Daily Database Backup',
    'backup_pending' => 'Pending',
    'backup_running' => 'Running',
    'backup_completed' => 'Completed',
    'backup_failed' => 'Failed',
    'backup_run_now' => 'Run Now',
    'backup_run_confirm' => 'Start a backup now? This may take a few minutes.',
    'backup_delete_confirm' => 'Delete this backup configuration and all stored backup files?',
    'backups_list_tooltip' => 'Configure automated backups of your database, application files, media (recordings, voicemail, fax), and configuration. Backups run as queued jobs and old ones are automatically pruned.',
    'backup_config_tooltip' => 'Create a backup profile that defines what to back up and how to store it. The first backup runs immediately when you save.',
    'backup_scopes_tooltip' => 'Which data categories are included in this backup. Database = full SQL dump. App files = Laravel storage. Media = recordings/voicemail/fax. Configuration = .env and FreeSWITCH conf.',
    'backup_status_tooltip' => 'Result of the most recent backup run. Click Run Now to trigger a new backup.',
    'backup_size_tooltip' => 'Compressed size of the last successful backup file on disk.',
    'backup_retention_tooltip' => 'Number of backup files to keep. Oldest backups are automatically deleted when this count is exceeded.',
    'backup_compression_tooltip' => 'Gzip-compress the backup archive to save disk space. Adds minimal CPU overhead — recommended for all backups.',
    'backup_encryption_tooltip' => 'GPG-encrypt the backup archive using the application encryption key. Required when storing backups off-server or in shared storage.',
    'modal_delete_backup_title' => 'Delete Backup?',
    'modal_delete_backup_confirm' => 'Delete Backup',
    'modal_delete_backup_message' => 'Delete “:name” and its stored backup archives. This cannot be undone.',
    'modal_delete_backup_error_title' => 'Backup cannot be deleted',
    'backups_file_store' => 'File Store',
    'backups_select_destination' => 'Select a destination',
];
