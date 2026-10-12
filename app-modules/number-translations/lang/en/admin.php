<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: number-translations
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'number-translations_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'number_translations_title' => 'Number Translations',
    'number_translation' => 'Number Translation',
    'create_number_translation' => 'Create Number Translation',
    'number_translation_name' => 'Name',
    'number_translation_match' => 'Match Pattern',
    'number_translation_replace' => 'Replace Pattern',
    'number_translation_direction' => 'Direction',
    'number_translation_enabled' => 'Enabled',
    'no_number_translations_found' => 'No translations found.',
    'confirm_delete_number_translation' => 'Are you sure you want to delete this translation?',
    'modal_delete_number_translation_title' => 'Delete Number Translation?',
    'modal_delete_number_translation_confirm' => 'Delete Number Translation',
];
