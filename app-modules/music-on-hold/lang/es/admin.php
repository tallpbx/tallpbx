<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: music-on-hold
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'music-on-hold_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'music_on_hold_title' => 'Música en Espera',
    'create_music_on_hold' => 'Subir Música',
    'edit_music_on_hold' => 'Editar Música',
    'no_music_on_hold_found' => 'No se encontraron archivos de música.',
    'confirm_delete_music_on_hold' => '¿Está seguro de que desea eliminar este archivo de música?',
    'music_on_hold_path_tooltip' => 'Ruta al archivo de audio o directorio utilizado para música en espera. Utilice local_stream:// para archivos subidos a través de la página Grabaciones.',
    'modal_delete_music_on_hold_title' => '¿Eliminar música en espera?',
    'modal_delete_music_on_hold_confirm' => 'Eliminar música en espera',
];
