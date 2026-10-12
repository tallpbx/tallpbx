<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: file-stores
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'file-stores_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_file_store_title' => '¿Eliminar almacén de archivos?',
    'modal_delete_file_store_confirm' => 'Eliminar almacén de archivos',
    'modal_delete_file_store_message' => 'Eliminar “:name” de TallPBX. Esto solo elimina el perfil de conexión guardado; no elimina archivos del servidor remoto.',
    'modal_delete_file_store_error_title' => 'El almacén de archivos no se puede eliminar',
];
