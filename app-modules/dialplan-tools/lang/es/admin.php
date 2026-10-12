<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: dialplan-tools
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'dialplan-tools_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'dialplan_tools_title' => 'Herramientas de Plan de Marcado',
    'dialplan_tools_description' => 'Pruebe expresiones regulares del plan de marcado contra números de teléfono.',
    'dialplan_tools_pattern' => 'Patrón Regex',
    'dialplan_tools_number' => 'Número de Prueba',
    'dialplan_tools_test' => 'Probar',
    'dialplan_tools_match' => '¡Coincidencia!',
    'dialplan_tools_no_match' => 'Sin coincidencia',
    'dialplan_tools_captures' => 'Grupos Capturados:',
];
