<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: recordings
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'recordings_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'recordings_title' => 'Recordings',
    'recording' => 'Recording',
    'create_recording' => 'Create Recording',
    'edit_recording' => 'Edit Recording',
    'recording_name' => 'Name',
    'recording_type' => 'Type',
    'recording_duration' => 'Duration',
    'recording_file_path' => 'File Path',
    'recording_type_moh' => 'Music on Hold',
    'recording_type_greeting' => 'Greeting',
    'recording_type_ivr' => 'IVR',
    'recording_type_announcement' => 'Announcement',
    'no_recordings_found' => 'No recordings found.',
    'confirm_delete_recording' => 'Are you sure you want to delete this recording?',
    'modal_delete_recording_title' => 'Delete Recording?',
    'modal_delete_recording_confirm' => 'Delete Recording',
    'modal_delete_recording_error_title' => 'Recording cannot be deleted',
];
