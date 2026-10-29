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
    'voicemails_title' => 'Buzones de Voz',
    'create_voicemail' => 'Crear Buzón de Voz',
    'edit_voicemail' => 'Editar Buzón de Voz',
    'voicemail_mailbox' => 'Buzón',
    'voicemail_pin' => 'PIN',
    'voicemail_email' => 'Correo Electrónico',
    'voicemail_attach' => 'Adjuntar audio al correo',
    'voicemail_require_pin' => 'Requerir PIN para acceder al buzón',
    'voicemail_forward_email' => 'Reenviar mensajes al correo',
    'no_voicemails_found' => 'No se encontraron buzones de voz.',
    'confirm_delete_voicemail' => '¿Está seguro de que desea eliminar este buzón de voz?',
    'modal_delete_voicemail_mailbox_title' => '¿Eliminar buzón de correo de voz?',
    'modal_delete_voicemail_mailbox_confirm' => 'Eliminar buzón de correo de voz',
    'modal_delete_voicemail_mailbox_message' => '¿Eliminar el buzón “:name”? Esta acción no se puede deshacer.',
    'modal_delete_voicemail_mailbox_error_title' => 'El buzón de correo de voz no se puede eliminar',
    'voicemail_id' => 'ID de Buzón de Voz',
    'voicemail_pin_password' => 'PIN / Contraseña',
    'voicemail_email_notification' => 'Notificación por Correo Electrónico',
    'voicemail_greeting_audio' => 'Audio de Saludo',
    'voicemail_options' => 'Opciones',
    'voicemail_delete_after_email' => 'Eliminar mensaje del servidor después de enviar por correo',
];
