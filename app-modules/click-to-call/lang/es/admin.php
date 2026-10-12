<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: click-to-call
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'click-to-call_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'click_to_call_title' => 'Clic para Llamar',
    'click_to_call_description' => 'Inicie una llamada desde su navegador ingresando un número de teléfono y su extensión.',
    'click_to_call_number' => 'Número de Teléfono',
    'click_to_call_extension' => 'Su Extensión',
    'click_to_call_button' => 'Iniciar Llamada',
    'click_to_call_initiated' => 'Llamada iniciada correctamente.',
];
