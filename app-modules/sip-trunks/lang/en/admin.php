<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: SIP Trunks (sip-trunks)
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. 'sip_trunk_*', 'create_sip_trunk', 'no_sip_trunks_found').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'sip_trunks_title' => 'SIP Trunks',
    'sip_trunk' => 'SIP Trunk',
    'create_sip_trunk' => 'Create SIP Trunk',
    'sip_trunk_name' => 'Name',
    'sip_trunk_host' => 'Host',
    'sip_trunk_port' => 'Port',
    'sip_trunk_username' => 'Username',
    'sip_trunk_password' => 'Password',
    'sip_trunk_codecs' => 'Codecs',
    'sip_trunk_enabled' => 'Enabled',
    'no_sip_trunks_found' => 'No trunks found.',
    'confirm_delete_sip_trunk' => 'Are you sure you want to delete this trunk?',
    'modal_delete_sip_trunk_title' => 'Delete SIP Trunk?',
    'modal_delete_sip_trunk_confirm' => 'Delete SIP Trunk',
];
