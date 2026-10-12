<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: bridges
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'bridges_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'bridges_title' => 'Puentes',
    'bridge' => 'Puente',
    'create_bridge' => 'Crear Puente',
    'edit_bridge' => 'Editar Puente',
    'bridge_name' => 'Nombre del Puente',
    'no_bridges_found' => 'No se encontraron puentes.',
    'confirm_delete_bridge' => '¿Está seguro de que desea eliminar este puente?',
    'bridge_destination_tooltip' => 'El número de teléfono o URI SIP al que se conecta este extremo del puente cuando se marca.',
    'bridge_pin_tooltip' => 'PIN opcional que los llamantes deben ingresar antes de que se conecte el puente. Se utiliza para restringir las llamadas salientes a través del puente.',
    'modal_delete_bridge_title' => '¿Eliminar puente?',
    'modal_delete_bridge_confirm' => 'Eliminar puente',
];
