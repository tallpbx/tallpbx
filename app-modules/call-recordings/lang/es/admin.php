<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-recordings
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-recordings_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_recordings_title' => 'Grabaciones de Llamadas',
    'call_recording' => 'Grabación de Llamada',
    'call_recording_caller' => 'Llamante',
    'call_recording_destination' => 'Destino',
    'call_recording_duration' => 'Duración',
    'call_recording_date' => 'Fecha',
    'no_call_recordings_found' => 'No se encontraron grabaciones de llamadas.',
    'confirm_delete_call_recording' => '¿Está seguro de que desea eliminar esta grabación de llamada?',
    'modal_delete_call_recording_message' => 'Eliminar esta grabación de llamada. Esta acción no se puede deshacer.',
];
