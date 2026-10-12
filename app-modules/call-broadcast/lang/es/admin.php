<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-broadcast
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-broadcast_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_call_broadcast_title' => '¿Eliminar difusión de llamadas?',
    'modal_delete_call_broadcast_confirm' => 'Eliminar difusión de llamadas',
    'call_broadcast_answered' => 'contestadas',
    'call_broadcast_failed' => 'Fallida',
    'create_call_broadcast' => 'Crear Difusión',
    'call_broadcast_name' => 'Nombre',
    'call_broadcast_recipients' => 'Destinatarios',
    'call_broadcast_phone_numbers' => 'Números de Teléfono',
    'call_broadcast_date' => 'Fecha',
    'call_broadcast_draft' => 'Borrador',
    'call_broadcast_sending' => 'Enviando',
    'call_broadcast_completed' => 'Completado',
    'no_call_broadcasts_found' => 'No se encontraron difusiones de llamada.',
    'confirm_delete_call_broadcast' => '¿Está seguro de que desea eliminar esta difusión?',
];
