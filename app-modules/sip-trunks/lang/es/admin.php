<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: Troncales SIP (sip-trunks)
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. 'sip_trunk_*', 'create_sip_trunk', 'no_sip_trunks_found').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'sip_trunks_title' => 'Troncales SIP',
    'sip_trunk' => 'Troncal SIP',
    'create_sip_trunk' => 'Crear Troncal SIP',
    'sip_trunk_name' => 'Nombre',
    'sip_trunk_host' => 'Host',
    'sip_trunk_port' => 'Puerto',
    'sip_trunk_username' => 'Usuario',
    'sip_trunk_password' => 'Contraseña',
    'sip_trunk_codecs' => 'Códecs',
    'sip_trunk_enabled' => 'Habilitado',
    'no_sip_trunks_found' => 'No se encontraron troncales.',
    'confirm_delete_sip_trunk' => '¿Está seguro de que desea eliminar este troncal?',
    'modal_delete_sip_trunk_title' => '¿Eliminar troncal SIP?',
    'modal_delete_sip_trunk_confirm' => 'Eliminar troncal SIP',
];
