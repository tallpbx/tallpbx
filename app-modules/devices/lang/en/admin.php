<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: devices
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'devices_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'devices_title' => 'Devices',
    'device_header_tooltip' => 'A physical piece of hardware or softphone client',
    'device_tooltip' => 'A physical piece of hardware or softphone client',
    'devices_tooltip' => 'A physical piece of hardware or softphone client',
    'create_device' => 'Create Device',
    'edit_device' => 'Edit Device',
    'device_name' => 'Device Name',
    'device_vendor' => 'Vendor',
    'device_vendor_placeholder' => 'Polycom, Yealink, Cisco...',
    'device_mac' => 'MAC Address',
    'device_template' => 'Template',
    'device_sip_account' => 'SIP Account',
    'no_devices_found' => 'No devices found.',
    'confirm_delete_device' => 'Are you sure you want to delete this device?',
    'modal_delete_device_title' => 'Delete Device?',
    'modal_delete_device_confirm' => 'Delete Device',
    'modal_delete_device_message' => 'Delete device “:name”? This cannot be undone.',
];
