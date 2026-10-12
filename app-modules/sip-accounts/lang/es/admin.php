<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: sip-accounts
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'sip-accounts_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'sip_accounts_title' => 'Cuentas SIP',
    'create_sip_account' => 'Crear Cuenta SIP',
    'edit_sip_account' => 'Editar Cuenta SIP',
    'no_sip_accounts_found' => 'No se encontraron cuentas SIP.',
    'confirm_delete_sip_account' => '¿Está seguro de que desea eliminar esta cuenta SIP?',
    'modal_delete_sip_account_title' => '¿Eliminar cuenta SIP?',
    'modal_delete_sip_account_confirm' => 'Eliminar cuenta SIP',
];
