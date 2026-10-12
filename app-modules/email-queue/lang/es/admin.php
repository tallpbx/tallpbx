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
    'email_queue_title' => 'Cola de Correo',
    'email_queue_to' => 'Para',
    'email_queue_subject' => 'Asunto',
    'email_queue_status' => 'Estado',
    'email_queue_sent_at' => 'Enviado El',
    'email_queue_pending' => 'Pendiente',
    'email_queue_sent' => 'Enviado',
    'email_queue_failed' => 'Fallido',
];
