<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: Pasarelas (gateways)
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. 'gateway_host', 'create_gateway', 'no_gateways_found').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'gateways_title' => 'Pasarelas',
    'create_gateway' => 'Crear Pasarela',
    'edit_gateway' => 'Editar Pasarela',
    'gateway_name' => 'Nombre de la Pasarela',
    'gateway_username' => 'Usuario',
    'gateway_password' => 'Contraseña',
    'gateway_host' => 'Host',
    'gateway_proxy' => 'Proxy',
    'gateway_register' => 'Registrar',
    'gateway_registered' => 'Sí',
    'gateway_not_registered' => 'No',
    'gateway_proxy_placeholder' => 'sip.proveedor.com',
    'no_gateways_found' => 'No se encontraron pasarelas.',
    'confirm_delete_gateway' => '¿Está seguro de que desea eliminar esta pasarela?',
    'gateway_name_tooltip' => 'Un nombre corto y único para esta pasarela. Se utiliza en la configuración de Sofia y en la selección de rutas salientes.',
    'gateway_host_tooltip' => 'El nombre de host o la dirección IP del proveedor de troncales SIP, p. ej. sip.miproveedor.com.',
    'gateway_register_tooltip' => 'Indica si se deben enviar solicitudes SIP REGISTER a esta pasarela. Deshabilítelo para troncales autenticadas por IP.',
    'modal_delete_gateway_title' => '¿Eliminar pasarela?',
    'modal_delete_gateway_confirm' => 'Eliminar pasarela',
];
