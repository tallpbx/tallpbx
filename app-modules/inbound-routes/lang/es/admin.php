<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: inbound-routes
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'inbound_route_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'inbound_routes_title' => 'Rutas Entrantes',
    'create_inbound_route' => 'Crear Ruta Entrante',
    'edit_inbound_route' => 'Editar Ruta Entrante',
    'no_inbound_routes_found' => 'No se encontraron rutas entrantes.',
    'confirm_delete_inbound_route' => '¿Está seguro de que desea eliminar esta ruta entrante?',
    'tenant_inbound_routes' => 'Rutas Entrantes',
    'modal_delete_inbound_route_title' => '¿Eliminar ruta entrante?',
    'modal_delete_inbound_route_confirm' => 'Eliminar ruta entrante',
    'inbound_route_name' => 'Nombre de la Ruta',
    'inbound_route_destination_number' => 'Número de Destino (DID)',
    'inbound_route_action' => 'Acción',
    'inbound_route_select_action' => 'Seleccionar acción',
    'inbound_route_action_data' => 'Datos de Acción',
    'inbound_route_priority' => 'Prioridad',
];
