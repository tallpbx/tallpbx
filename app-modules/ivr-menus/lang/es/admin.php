<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: ivr-menus
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'ivr-menus_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'ivr_menus_title' => 'Menús IVR',
    'ivr_menu' => 'Menú IVR',
    'create_ivr_menu' => 'Crear Menú IVR',
    'edit_ivr_menu' => 'Editar Menú IVR',
    'ivr_menu_name' => 'Nombre del Menú',
    'ivr_menu_greeting' => 'Saludo',
    'ivr_menu_greeting_placeholder' => 'Mensaje de bienvenida o ruta de archivo de audio',
    'ivr_menu_timeout' => 'Tiempo de espera (segundos)',
    'ivr_menu_options' => 'Opciones',
    'ivr_menu_digit_length' => 'Longitud de Dígitos',
    'ivr_menu_digits' => 'dígitos',
    'ivr_menu_variable' => 'Variable',
    'ivr_menu_loop' => 'Bucle',
    'no_ivr_menus_found' => 'No hay menús IVR configurados aún.',
    'confirm_delete_ivr_menu' => '¿Está seguro de que desea eliminar este menú IVR?',
    'ivr_menu_greeting_tooltip' => 'El mensaje de audio que se reproduce para los llamantes cuando ingresan a este IVR. Suba un archivo .wav en la página Grabaciones.',
    'ivr_menu_timeout_tooltip' => 'Cuánto tiempo (en milisegundos) esperar a que el llamante presione un dígito antes de que se agote el tiempo de espera.',
    'modal_delete_ivr_menu_title' => '¿Eliminar menú IVR?',
    'modal_delete_ivr_menu_confirm' => 'Eliminar menú IVR',
    'modal_delete_ivr_menu_error_title' => 'El menú IVR no se puede eliminar',
    'ivr_menu_max_failures' => 'Máximo de Fallos',
    'ivr_menu_digit_length_hint' => 'Longitud de Dígitos (0 = variable)',
];
