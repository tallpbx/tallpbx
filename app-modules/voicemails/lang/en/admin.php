<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: voicemails
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'voicemail_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'voicemails_title' => 'Voicemails',
    'create_voicemail' => 'Create Voicemail',
    'edit_voicemail' => 'Edit Voicemail',
    'voicemail_mailbox' => 'Mailbox',
    'voicemail_pin' => 'PIN',
    'voicemail_email' => 'Email',
    'voicemail_attach' => 'Attach audio to email',
    'voicemail_require_pin' => 'Require PIN to access voicemail',
    'voicemail_forward_email' => 'Forward messages to email',
    'no_voicemails_found' => 'No voicemails found.',
    'confirm_delete_voicemail' => 'Are you sure you want to delete this voicemail?',
    'modal_delete_voicemail_mailbox_title' => 'Delete Voicemail Mailbox?',
    'modal_delete_voicemail_mailbox_confirm' => 'Delete Voicemail Mailbox',
    'modal_delete_voicemail_mailbox_message' => 'Delete mailbox “:name”? This cannot be undone.',
    'modal_delete_voicemail_mailbox_error_title' => 'Voicemail Mailbox Cannot Be Deleted',
    'voicemail_id' => 'Voicemail ID',
    'voicemail_pin_password' => 'PIN / Password',
    'voicemail_email_notification' => 'Email Notification',
    'voicemail_greeting_audio' => 'Greeting Audio',
    'voicemail_options' => 'Options',
    'voicemail_delete_after_email' => 'Delete message from server after emailing',
];
