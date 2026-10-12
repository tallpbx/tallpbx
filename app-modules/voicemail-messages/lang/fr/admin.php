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
    'voicemail_messages_title' => 'Messages Vocaux',
    'voicemail_message' => 'Message Vocal',
    'voicemail_message_caller' => 'Appelant',
    'voicemail_message_duration' => 'Durée',
    'voicemail_message_date' => 'Date',
    'voicemail_message_listened' => 'Écouté',
    'voicemail_message_new' => 'Nouveau',
    'no_voicemail_messages_found' => 'Aucun message vocal trouvé.',
    'confirm_delete_voicemail_message' => 'Êtes-vous sûr de vouloir supprimer ce message vocal ?',
    'modal_delete_voicemail_message_title' => 'Supprimer le message vocal ?',
    'modal_delete_voicemail_message_confirm' => 'Supprimer le message',
    'modal_delete_voicemail_message_message' => 'Supprimer ce message vocal. Cette action est irréversible.',
    'modal_delete_voicemail_message_error_title' => 'Le message vocal ne peut pas être supprimé',
];
