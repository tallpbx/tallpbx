<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: fax
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'fax_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_fax_title' => '¿Eliminar fax?',
    'modal_delete_fax_confirm' => 'Eliminar fax',
    'modal_delete_fax_message' => '¿Eliminar el fax de “:name”? Esta acción no se puede deshacer.',
    'modal_delete_fax_error_title' => 'El fax no se puede eliminar',
    'fax_inbox_title' => 'Bandeja de Entrada de Fax',
    'fax_send_title' => 'Enviar Fax',
    'fax_send' => 'Enviar Fax',
    'fax_caller' => 'Llamante',
    'fax_number' => 'Número de Fax',
    'fax_pages' => 'Páginas',
    'fax_received' => 'Recibido',
    'fax_document_path' => 'Ruta del Documento',
    'confirm_delete_fax' => '¿Está seguro de que desea eliminar este fax?',
];
