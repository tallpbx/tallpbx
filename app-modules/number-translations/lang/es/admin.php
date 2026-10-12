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
    'number_translations_title' => 'Traducciones de Números',
    'number_translation' => 'Traducción de Número',
    'create_number_translation' => 'Crear Traducción de Número',
    'number_translation_name' => 'Nombre',
    'number_translation_match' => 'Patrón de Coincidencia',
    'number_translation_replace' => 'Patrón de Reemplazo',
    'number_translation_direction' => 'Dirección',
    'number_translation_enabled' => 'Habilitado',
    'no_number_translations_found' => 'No se encontraron traducciones.',
    'confirm_delete_number_translation' => '¿Está seguro de que desea eliminar esta traducción?',
    'modal_delete_number_translation_title' => '¿Eliminar traducción de número?',
    'modal_delete_number_translation_confirm' => 'Eliminar traducción de número',
];
