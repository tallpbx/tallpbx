<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: email-queue
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'email-queue_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'email_queue_title' => 'Email Queue',
    'email_queue_to' => 'To',
    'email_queue_subject' => 'Subject',
    'email_queue_status' => 'Status',
    'email_queue_sent_at' => 'Sent At',
    'email_queue_pending' => 'Pending',
    'email_queue_sent' => 'Sent',
    'email_queue_failed' => 'Failed',
];
