<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: Gateways (gateways)
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. 'gateway_host', 'create_gateway', 'no_gateways_found').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'gateways_title' => 'Gateways',
    'create_gateway' => 'Create Gateway',
    'edit_gateway' => 'Edit Gateway',
    'gateway_name' => 'Gateway Name',
    'gateway_username' => 'Username',
    'gateway_password' => 'Password',
    'gateway_host' => 'Host',
    'gateway_proxy' => 'Proxy',
    'gateway_register' => 'Register',
    'gateway_registered' => 'Yes',
    'gateway_not_registered' => 'No',
    'gateway_proxy_placeholder' => 'sip.provider.com',
    'no_gateways_found' => 'No gateways found.',
    'confirm_delete_gateway' => 'Are you sure you want to delete this gateway?',
    'gateway_name_tooltip' => 'A short, unique name for this gateway. Used in Sofia configuration and outbound route selection.',
    'gateway_host_tooltip' => 'The hostname or IP address of the SIP trunk provider, e.g. sip.myprovider.com.',
    'gateway_register_tooltip' => 'Whether to send SIP REGISTER requests to this gateway. Disable for IP-authenticated trunks.',
    'modal_delete_gateway_title' => 'Delete Gateway?',
    'modal_delete_gateway_confirm' => 'Delete Gateway',
];
