<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: ivr-menus
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'ivr-menus_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'ivr_menus_title' => 'IVR Menus',
    'ivr_menu' => 'IVR Menu',
    'create_ivr_menu' => 'Create IVR Menu',
    'edit_ivr_menu' => 'Edit IVR Menu',
    'ivr_menu_name' => 'Menu Name',
    'ivr_menu_greeting' => 'Greeting',
    'ivr_menu_greeting_placeholder' => 'Welcome message or audio file path',
    'ivr_menu_timeout' => 'Timeout (seconds)',
    'ivr_menu_options' => 'Options',
    'ivr_menu_digit_length' => 'Digit Length',
    'ivr_menu_digits' => 'digits',
    'ivr_menu_variable' => 'Variable',
    'ivr_menu_loop' => 'Loop',
    'no_ivr_menus_found' => 'No IVR menus configured yet.',
    'confirm_delete_ivr_menu' => 'Are you sure you want to delete this IVR menu?',
    'ivr_menu_greeting_tooltip' => 'The audio prompt played to callers when they enter this IVR. Upload a .wav file on the Recordings page.',
    'ivr_menu_timeout_tooltip' => 'How long (in milliseconds) to wait for the caller to press a digit before timing out.',
    'modal_delete_ivr_menu_title' => 'Delete IVR Menu?',
    'modal_delete_ivr_menu_confirm' => 'Delete IVR Menu',
    'modal_delete_ivr_menu_error_title' => 'IVR Menu Cannot Be Deleted',
    'ivr_menu_max_failures' => 'Max Failures',
    'ivr_menu_digit_length_hint' => 'Digit Length (0 = variable)',
];
