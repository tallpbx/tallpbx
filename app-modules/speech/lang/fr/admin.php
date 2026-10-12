<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: speech
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'speech_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'speech_title' => 'Configuration Vocale',
    'speech_config' => 'Configuration Vocale',
    'speech_engine' => 'Moteur',
    'speech_voice' => 'Voix',
    'speech_language' => 'Langue',
    'speech_rate' => 'Vitesse',
    'speech_enabled' => 'Activé',
];
