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
    'modal_delete_file_store_title' => 'Supprimer le stockage de fichiers ?',
    'modal_delete_file_store_confirm' => 'Supprimer le stockage de fichiers',
    'modal_delete_file_store_message' => 'Supprimer « :name » de TallPBX. Cela ne supprime que le profil de connexion enregistré ; aucun fichier n\'est supprimé sur le serveur distant.',
    'modal_delete_file_store_error_title' => 'Le stockage de fichiers ne peut pas être supprimé',
];
