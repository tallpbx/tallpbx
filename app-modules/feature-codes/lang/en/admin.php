<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: feature-codes
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'feature-codes_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'feature_codes_title' => 'Feature Codes',
    'create_feature_code' => 'Create Feature Code',
    'edit_feature_code' => 'Edit Feature Code',
    'feature_code_placeholder' => '*97',
    'feature_description' => 'Description',
    'no_feature_codes_found' => 'No feature codes found.',
    'confirm_delete_feature_code' => 'Are you sure you want to delete this feature code?',
    'feature_code_application_tooltip' => 'The FreeSWITCH application this feature code triggers, e.g. transfer, record_session, or voicemail.',
    'feature_code_data_tooltip' => 'Arguments passed to the FreeSWITCH application, e.g. the voicemail mailbox ID or recording file path.',
    'modal_delete_feature_code_title' => 'Delete Feature Code?',
    'modal_delete_feature_code_confirm' => 'Delete Feature Code',
];
