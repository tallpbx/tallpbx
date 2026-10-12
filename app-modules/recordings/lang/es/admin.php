<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: recordings
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'recordings_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'recordings_title' => 'Grabaciones',
    'recording' => 'Grabación',
    'create_recording' => 'Crear Grabación',
    'edit_recording' => 'Editar Grabación',
    'recording_name' => 'Nombre',
    'recording_type' => 'Tipo',
    'recording_duration' => 'Duración',
    'recording_file_path' => 'Ruta del Archivo',
    'recording_type_moh' => 'Música en Espera',
    'recording_type_greeting' => 'Saludo',
    'recording_type_ivr' => 'IVR',
    'recording_type_announcement' => 'Anuncio',
    'no_recordings_found' => 'No se encontraron grabaciones.',
    'confirm_delete_recording' => '¿Está seguro de que desea eliminar esta grabación?',
    'modal_delete_recording_title' => '¿Eliminar grabación?',
    'modal_delete_recording_confirm' => 'Eliminar grabación',
    'modal_delete_recording_error_title' => 'La grabación no se puede eliminar',
];
