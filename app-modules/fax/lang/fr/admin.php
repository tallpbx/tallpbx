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
    'modal_delete_fax_title' => 'Supprimer le fax ?',
    'modal_delete_fax_confirm' => 'Supprimer le fax',
    'modal_delete_fax_message' => 'Supprimer le fax de « :name » ? Cette action est irréversible.',
    'modal_delete_fax_error_title' => 'Le fax ne peut pas être supprimé',
    'fax_inbox_title' => 'Boîte de Réception Fax',
    'fax_send_title' => 'Envoyer un Fax',
    'fax_send' => 'Envoyer un Fax',
    'fax_caller' => 'Appelant',
    'fax_number' => 'Numéro de Fax',
    'fax_pages' => 'Pages',
    'fax_received' => 'Reçu',
    'fax_document_path' => 'Chemin du Document',
    'confirm_delete_fax' => 'Êtes-vous sûr de vouloir supprimer ce fax ?',
];
