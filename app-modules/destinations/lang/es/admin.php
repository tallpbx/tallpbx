<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: destinations
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'destinations_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'destinations_title' => 'Destinos',
    'destination' => 'Destino',
    'create_destination' => 'Crear Destino',
    'edit_destination' => 'Editar Destino',
    'destination_type' => 'Tipo',
    'destination_dial_string' => 'Cadena de Marcado',
    'destination_type_conference' => 'Conferencia',
    'destination_type_ivr' => 'IVR',
    'destination_type_ring_group' => 'Grupo de Timbre',
    'destination_type_call_flow' => 'Flujo de Llamada',
    'destination_number' => 'Número',
    'no_destinations_found' => 'No se encontraron destinos.',
    'confirm_delete_destination' => '¿Está seguro de que desea eliminar este destino?',
    'destination_number_tooltip' => 'El número que los llamantes marcan para llegar a este destino. Se utiliza en expresiones de plan de marcado para el enrutamiento.',
    'modal_delete_destination_title' => '¿Eliminar destino?',
    'modal_delete_destination_confirm' => 'Eliminar destino',
];
