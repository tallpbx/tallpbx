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
    'click_to_call_title' => 'Cliquer pour Appeler',
    'click_to_call_description' => 'Initiez un appel depuis votre navigateur en saisissant un numéro de téléphone et votre extension.',
    'click_to_call_number' => 'Numéro de Téléphone',
    'click_to_call_extension' => 'Votre Extension',
    'click_to_call_button' => 'Initier l\'Appel',
    'click_to_call_initiated' => 'Appel initié avec succès.',
];
