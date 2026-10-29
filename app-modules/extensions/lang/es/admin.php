<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: extensions
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'extension_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'extensions_title' => 'Extensiones',
    'extension_header_tooltip' => 'Un punto final marcable lógico e identidad en la PBX',
    'extension_tooltip' => 'Un punto final marcable lógico e identidad en la PBX',
    'extensions_tooltip' => 'Un punto final marcable lógico e identidad en la PBX',
    'create_extension' => 'Crear Extensión',
    'create_multiple_extensions' => 'Crear Múltiples Extensiones',
    'edit_extension' => 'Editar Extensión',
    'extension_number' => 'Extensión',
    'extension_password' => 'Contraseña',
    'no_extensions_found' => 'No se encontraron extensiones.',
    'confirm_delete_extension' => '¿Está seguro de que desea eliminar esta extensión?',
    'extension_number_tooltip' => 'El número de teléfono que los usuarios marcan para comunicarse con esta extensión. Normalmente de 3 a 5 dígitos.',
    'extension_description_tooltip' => 'Un nombre descriptivo mostrado en el panel de administración, pantalla de operador y pantallas de teléfonos aprovisionados (p. ej. "Juan Pérez" o "Teléfono del vestíbulo"). No se utiliza como identificador de llamadas (Caller ID) interno ni saliente.',
    'extension_effective_caller_id_name_tooltip' => 'El nombre mostrado a las extensiones internas en llamadas internas. También se usa en llamadas salientes a menos que sea anulado por el Identificador de Llamadas Salientes o una ruta de salida.',
    'extension_effective_caller_id_number_tooltip' => 'El número mostrado a las extensiones internas en llamadas internas (normalmente este número de extensión). También se usa en llamadas salientes a menos que sea anulado por el Identificador de Llamadas Salientes o una ruta de salida.',
    'extension_outbound_caller_id_name_tooltip' => 'El nombre transmitido a partes externas al realizar llamadas salientes por una troncal o pasarela, anulando el Nombre de Identificador Efectivo (a menos que una ruta de salida lo anule).',
    'extension_outbound_caller_id_number_tooltip' => 'El número transmitido a partes externas al realizar llamadas salientes (p. ej., su DID directo o número principal), anulando el Número de Identificador Efectivo (a menos que una ruta de salida lo anule).',
    'modal_delete_extension_title' => '¿Eliminar extensión?',
    'modal_delete_extension_confirm' => 'Eliminar extensión',
    'extension_display_name' => 'Nombre para Mostrar',
    'extension_sip_password' => 'Contraseña SIP',
    'extension_effective_caller_id_name' => 'Nombre de Identificador Efectivo',
    'extension_effective_caller_id_number' => 'Número de Identificador Efectivo',
    'extension_outbound_caller_id_name' => 'Nombre de Identificador Saliente',
    'extension_outbound_caller_id_number' => 'Número de Identificador Saliente',
    'extension_voicemail_enabled' => 'Buzón de Voz Habilitado',
];
