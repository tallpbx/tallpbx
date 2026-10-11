<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: SIP Profiles (sip-profiles)
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. 'sip_profile_*', 'profile_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'sip_profiles_title' => 'SIP Profiles',
    'sip_profile_header_tooltip' => 'A SIP signaling interface defining IP/port bindings, codecs, and protocol behavior for FreeSWITCH',
    'sip_profile_tooltip' => 'A SIP signaling interface defining IP/port bindings, codecs, and protocol behavior for FreeSWITCH',
    'sip_profiles_tooltip' => 'A SIP signaling interface defining IP/port bindings, codecs, and protocol behavior for FreeSWITCH',
    'create_sip_profile' => 'Create SIP Profile',
    'edit_sip_profile' => 'Edit SIP Profile',
    'profile_name' => 'Profile',
    'profile_ip' => 'IP Address',
    'profile_port' => 'Port',
    'profile_internal_placeholder' => 'internal',
    'profile_ip_placeholder' => '$${local_ip_v4}',
    'no_sip_profiles_found' => 'No SIP profiles found.',
    'confirm_delete_sip_profile' => 'Are you sure you want to delete this SIP profile?',
    'sip_profile_settings_tooltip' => 'Sofia profile parameters in JSON format. Common settings: sip-port, rtp-ip, ext-rtp-ip, context, codec-prefs.',
    'modal_delete_sip_profile_title' => 'Delete SIP Profile?',
    'modal_delete_sip_profile_confirm' => 'Delete SIP Profile',
];
