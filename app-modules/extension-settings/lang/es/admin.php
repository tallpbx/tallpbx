<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: extension-settings
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'extension-settings_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'extension_settings_title' => 'Configuraciones de Extensiones',
    'extension_setting_key' => 'Clave',
    'extension_setting_value' => 'Valor',
    'extension_setting_extension' => 'Extensión',
    'extension_setting_select_extension' => 'Seleccionar una extensión...',
];
