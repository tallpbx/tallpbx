<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: active-calls
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'active-calls_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'active_calls_hangup' => 'Raccrocher',
    'active_calls_transfer' => 'Transférer',
    'active_calls_transfer_placeholder' => 'Ext ou numéro',
    'active_calls_tooltip' => 'Nombre d\'appels actifs en cours.',
    'active_calls_title' => 'Appels Actifs',
    'no_active_calls' => 'Aucun appel actif.',
];
