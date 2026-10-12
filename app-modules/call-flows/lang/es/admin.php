<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-flows
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-flows_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_flows_title' => 'Flujos de Llamada',
    'call_flow' => 'Flujo de Llamada',
    'create_call_flow' => 'Crear Flujo de Llamada',
    'edit_call_flow' => 'Editar Flujo de Llamada',
    'call_flow_name' => 'Nombre',
    'call_flow_extension' => 'Extensión',
    'call_flow_destination_type' => 'Tipo de Destino',
    'call_flow_destination_id' => 'ID de Destino',
    'no_call_flows_found' => 'No se encontraron flujos de llamada.',
    'confirm_delete_call_flow' => '¿Está seguro de que desea eliminar este flujo de llamada?',
    'call_flow_action_tooltip' => 'Qué sucede cuando se activa este flujo de llamadas: transferir a extensión, reproducir anuncio, puentear a número externo, etc.',
    'modal_delete_call_flow_title' => '¿Eliminar flujo de llamada?',
    'modal_delete_call_flow_confirm' => 'Eliminar flujo de llamada',
];
