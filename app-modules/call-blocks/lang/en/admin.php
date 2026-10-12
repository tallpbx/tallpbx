<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-blocks
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-blocks_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'call_blocks_title' => 'Call Blocks',
    'call_block' => 'Call Block',
    'create_call_block' => 'Create Call Block',
    'edit_call_block' => 'Edit Call Block',
    'no_call_blocks_found' => 'No call blocks found.',
    'confirm_delete_call_block' => 'Are you sure you want to delete this call block rule?',
    'call_block_cid_tooltip' => 'The caller ID pattern to block, as a regular expression. E.g. ^15550000\\d{3}$ blocks all numbers starting with 15550000.',
    'modal_delete_call_block_title' => 'Delete Call Block?',
    'modal_delete_call_block_confirm' => 'Delete Call Block',
];
