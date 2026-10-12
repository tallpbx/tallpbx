<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: conference-centers
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'conference-centers_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'conference_centers_title' => 'Centros de Conferencias',
    'conference_center' => 'Centro de Conferencia',
    'create_conference_center' => 'Crear Centro de Conferencia',
    'edit_conference_center' => 'Editar Centro de Conferencia',
    'conference_center_name' => 'Nombre',
    'conference_center_extension' => 'Extensión',
    'conference_center_pin' => 'PIN',
    'conference_center_greeting' => 'Saludo',
    'no_conference_centers_found' => 'No se encontraron centros de conferencia.',
    'confirm_delete_conference_center' => '¿Está seguro de que desea eliminar este centro de conferencia?',
    'modal_delete_conference_center_title' => '¿Eliminar centro de conferencias?',
    'modal_delete_conference_center_confirm' => 'Eliminar centro de conferencias',
];
