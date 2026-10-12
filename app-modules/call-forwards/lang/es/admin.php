<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-forwards
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-forwards_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_forwards_title' => 'Desvíos de Llamadas',
    'call_forward' => 'Desvío de Llamada',
    'create_call_forward' => 'Crear Desvío de Llamada',
    'edit_call_forward' => 'Editar Desvío de Llamada',
    'no_call_forwards_found' => 'No se encontraron desvíos de llamada.',
    'confirm_delete_call_forward' => '¿Está seguro de que desea eliminar esta regla de desvío de llamada?',
    'call_forward_type_tooltip' => 'Incondicional: desviar siempre. Ocupado: desviar solo cuando la extensión está en otra llamada. Sin respuesta: desviar después de timbrar sin contestar.',
    'modal_delete_call_forward_title' => '¿Eliminar desvío de llamada?',
    'modal_delete_call_forward_confirm' => 'Eliminar desvío de llamada',
    'modal_delete_call_forward_message' => '¿Eliminar el desvío de llamada hacia “:name”? Esta acción no se puede deshacer.',
];
