<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: emergency
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'emergency_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_emergency_title' => '¿Eliminar registro de emergencia?',
    'modal_delete_emergency_confirm' => 'Eliminar registro de emergencia',
    'modal_delete_emergency_message' => '¿Eliminar el registro de emergencia de “:name”? Esta acción no se puede deshacer.',
    'emergency_title' => 'Configuración de Emergencia',
    'create_emergency' => 'Crear Configuración de Emergencia',
    'emergency_caller' => 'Identificador de Llamadas',
    'emergency_address' => 'Dirección',
    'emergency_latitude' => 'Latitud',
    'emergency_longitude' => 'Longitud',
    'confirm_delete_emergency' => '¿Está seguro de que desea eliminar este registro?',
];
