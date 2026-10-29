<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: devices
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'devices_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'devices_title' => 'Dispositivos',
    'device_header_tooltip' => 'Un dispositivo físico o cliente de softphone',
    'device_tooltip' => 'Un dispositivo físico o cliente de softphone',
    'devices_tooltip' => 'Un dispositivo físico o cliente de softphone',
    'create_device' => 'Crear Dispositivo',
    'edit_device' => 'Editar Dispositivo',
    'device_name' => 'Nombre del Dispositivo',
    'device_vendor' => 'Fabricante',
    'device_model' => 'Modelo',
    'device_vendor_placeholder' => 'Polycom, Yealink, Cisco...',
    'device_mac' => 'Dirección MAC',
    'device_template' => 'Plantilla',
    'device_sip_account' => 'Cuenta SIP',
    'no_devices_found' => 'No se encontraron dispositivos.',
    'confirm_delete_device' => '¿Está seguro de que desea eliminar este dispositivo?',
    'modal_delete_device_title' => '¿Eliminar dispositivo?',
    'modal_delete_device_confirm' => 'Eliminar dispositivo',
    'modal_delete_device_message' => '¿Eliminar el dispositivo “:name”? Esta acción no se puede deshacer.',
];
