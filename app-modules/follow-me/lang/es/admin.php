<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: follow-me
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'follow-me_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'follow_me_title' => 'Sígueme',
    'create_follow_me' => 'Crear Sígueme',
    'edit_follow_me' => 'Editar Sígueme',
    'follow_me_name' => 'Nombre',
    'follow_me_extension' => 'Extensión',
    'follow_me_destination' => 'Destino',
    'follow_me_ring_timeout' => 'Tiempo de Timbrado',
    'no_follow_me_found' => 'No se encontraron registros de sígueme.',
    'confirm_delete_follow_me' => '¿Está seguro de que desea eliminar este registro de sígueme?',
    'follow_me_timeout_tooltip' => 'Cuánto tiempo (en segundos) hacer sonar a cada destino en la lista de sígueme antes de pasar al siguiente.',
    'modal_delete_follow_me_title' => '¿Eliminar regla de sígueme?',
    'modal_delete_follow_me_confirm' => 'Eliminar regla de sígueme',
];
