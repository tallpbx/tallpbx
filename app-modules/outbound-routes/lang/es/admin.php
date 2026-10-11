<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: outbound-routes
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'outbound_route_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'outbound_routes_title' => 'Rutas Salientes',
    'create_outbound_route' => 'Crear Ruta Saliente',
    'edit_outbound_route' => 'Editar Ruta Saliente',
    'no_outbound_routes_found' => 'No se encontraron rutas salientes.',
    'confirm_delete_outbound_route' => '¿Está seguro de que desea eliminar esta ruta saliente?',
    'tenant_outbound_routes' => 'Rutas Salientes',
    'modal_delete_outbound_route_title' => '¿Eliminar ruta saliente?',
    'modal_delete_outbound_route_confirm' => 'Eliminar ruta saliente',
];
