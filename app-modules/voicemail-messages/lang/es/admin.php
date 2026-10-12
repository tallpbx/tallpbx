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
    'voicemail_messages_title' => 'Mensajes de Buzón de Voz',
    'voicemail_message' => 'Mensaje de Buzón de Voz',
    'voicemail_message_caller' => 'Llamante',
    'voicemail_message_duration' => 'Duración',
    'voicemail_message_date' => 'Fecha',
    'voicemail_message_listened' => 'Escuchado',
    'voicemail_message_new' => 'Nuevo',
    'no_voicemail_messages_found' => 'No se encontraron mensajes de correo de voz.',
    'confirm_delete_voicemail_message' => '¿Está seguro de que desea eliminar este mensaje de correo de voz?',
    'modal_delete_voicemail_message_title' => '¿Eliminar mensaje de correo de voz?',
    'modal_delete_voicemail_message_confirm' => 'Eliminar mensaje',
    'modal_delete_voicemail_message_message' => 'Eliminar este mensaje de correo de voz. Esta acción no se puede deshacer.',
    'modal_delete_voicemail_message_error_title' => 'El mensaje de correo de voz no se puede eliminar',
];
