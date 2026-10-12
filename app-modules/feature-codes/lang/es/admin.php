<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: feature-codes
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'feature-codes_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'feature_codes_title' => 'Códigos de Función',
    'create_feature_code' => 'Crear Código de Función',
    'edit_feature_code' => 'Editar Código de Función',
    'feature_code_placeholder' => '*97',
    'feature_description' => 'Descripción',
    'no_feature_codes_found' => 'No se encontraron códigos de función.',
    'confirm_delete_feature_code' => '¿Está seguro de que desea eliminar este código de función?',
    'modal_delete_feature_code_title' => '¿Eliminar código de función?',
    'modal_delete_feature_code_confirm' => 'Eliminar código de función',
    'feature_code_application_tooltip' => 'La aplicación FreeSWITCH que activa este código de función, p. ej. transfer, record_session o voicemail.',
    'feature_code_data_tooltip' => 'Argumentos pasados a la aplicación FreeSWITCH, p. ej. el ID del buzón de correo de voz o la ruta del archivo de grabación.',
];
