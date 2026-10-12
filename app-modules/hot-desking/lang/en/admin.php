<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: hot-desking
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'hot-desking_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'hot_desking_title' => 'Hot Desking',
    'hot_desking_user_extension' => 'User Extension',
    'hot_desking_user_extension_tooltip' => 'The primary user extension that is visiting and logging in.',
    'hot_desking_desk_extension' => 'Desk Extension',
    'hot_desking_desk_extension_tooltip' => 'The physical desk phone extension being temporarily occupied.',
    'hot_desking_login_at' => 'Logged In',
    'hot_desking_logout' => 'Log Out',
    'hot_desking_end_session' => 'End this active hot desking session',
    'hot_desking_tooltip' => 'Hot Desking allows users to log into any physical desk phone with *11 and adopt their extension, routing, and voicemail. Dial *12 to log out.',
];
