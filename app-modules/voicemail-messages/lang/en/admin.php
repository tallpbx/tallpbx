<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: voicemail-messages
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'voicemail-messages_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'voicemail_messages_title' => 'Voicemail Messages',
    'voicemail_message' => 'Voicemail Message',
    'voicemail_message_caller' => 'Caller',
    'voicemail_message_duration' => 'Duration',
    'voicemail_message_date' => 'Date',
    'voicemail_message_listened' => 'Listened',
    'voicemail_message_new' => 'New',
    'no_voicemail_messages_found' => 'No voicemail messages found.',
    'confirm_delete_voicemail_message' => 'Are you sure you want to delete this voicemail message?',
    'modal_delete_voicemail_message_title' => 'Delete Voicemail Message?',
    'modal_delete_voicemail_message_confirm' => 'Delete Message',
    'modal_delete_voicemail_message_message' => 'Delete this voicemail message. This cannot be undone.',
    'modal_delete_voicemail_message_error_title' => 'Voicemail message cannot be deleted',
];
