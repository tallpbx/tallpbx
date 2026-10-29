<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: extensions
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'extension_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'extensions_title' => 'Extensions',
    'extension_header_tooltip' => 'A logical dialable endpoint and identity on the PBX',
    'extension_tooltip' => 'A logical dialable endpoint and identity on the PBX',
    'extensions_tooltip' => 'A logical dialable endpoint and identity on the PBX',
    'create_extension' => 'Create Extension',
    'create_multiple_extensions' => 'Create Multiple Extensions',
    'edit_extension' => 'Edit Extension',
    'extension_number' => 'Extension',
    'extension_password' => 'Password',
    'no_extensions_found' => 'No extensions found.',
    'confirm_delete_extension' => 'Are you sure you want to delete this extension?',
    'extension_number_tooltip' => 'The phone number users dial to reach this extension. Typically 3-5 digits.',
    'extension_description_tooltip' => 'A friendly name shown in the management panel, operator screen, and provisioned desk phone displays (e.g. "John Smith" or "Lobby Phone"). It is not used as internal or outbound Caller ID.',
    'extension_effective_caller_id_name_tooltip' => 'The caller name presented to internal extensions during extension-to-extension calls. Also used for outbound calls unless overridden by Outbound Caller ID Name or an outbound route.',
    'extension_effective_caller_id_number_tooltip' => 'The caller number presented to internal extensions during extension-to-extension calls (typically this extension number). Also used for outbound calls unless overridden by Outbound Caller ID Number or an outbound route.',
    'extension_outbound_caller_id_name_tooltip' => 'The caller name transmitted to external parties when making outbound calls through a trunk or gateway, overriding Effective Caller ID Name (unless overridden by an outbound route).',
    'extension_outbound_caller_id_number_tooltip' => 'The phone number transmitted to external parties when making outbound calls (e.g. your direct DID or company main number), overriding Effective Caller ID Number (unless overridden by an outbound route).',
    'modal_delete_extension_title' => 'Delete Extension?',
    'modal_delete_extension_confirm' => 'Delete Extension',
    'extension_display_name' => 'Display Name',
    'extension_sip_password' => 'SIP Password',
    'extension_effective_caller_id_name' => 'Effective Caller ID Name',
    'extension_effective_caller_id_number' => 'Effective Caller ID Number',
    'extension_outbound_caller_id_name' => 'Outbound Caller ID Name',
    'extension_outbound_caller_id_number' => 'Outbound Caller ID Number',
    'extension_voicemail_enabled' => 'Voicemail Enabled',
];
