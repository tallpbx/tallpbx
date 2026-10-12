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
    'fax_inbox_title' => 'Fax Inbox',
    'fax_send_title' => 'Send Fax',
    'fax_send' => 'Send Fax',
    'fax_caller' => 'Caller',
    'fax_number' => 'Fax Number',
    'fax_pages' => 'Pages',
    'fax_received' => 'Received',
    'fax_document_path' => 'Document Path',
    'confirm_delete_fax' => 'Are you sure you want to delete this fax?',
    'modal_delete_fax_title' => 'Delete Fax?',
    'modal_delete_fax_confirm' => 'Delete Fax',
    'modal_delete_fax_message' => 'Delete fax from “:name”? This cannot be undone.',
    'modal_delete_fax_error_title' => 'Fax Cannot Be Deleted',
];
