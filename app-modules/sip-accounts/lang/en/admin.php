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
    'sip_accounts_title' => 'SIP Accounts',
    'create_sip_account' => 'Create SIP Account',
    'edit_sip_account' => 'Edit SIP Account',
    'no_sip_accounts_found' => 'No SIP accounts found.',
    'confirm_delete_sip_account' => 'Are you sure you want to delete this SIP account?',
    'modal_delete_sip_account_title' => 'Delete SIP Account?',
    'modal_delete_sip_account_confirm' => 'Delete SIP Account',
    'sip_account_identity_mode' => 'Identity Mode',
    'sip_account_select_domain' => 'Select domain',
    'sip_account_auth_username' => 'Auth Username',
    'sip_account_auth_password' => 'Auth Password',
];
