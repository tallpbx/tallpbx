<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: music-on-hold
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'music-on-hold_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'music_on_hold_title' => 'Music on Hold',
    'create_music_on_hold' => 'Upload Music',
    'edit_music_on_hold' => 'Edit Music',
    'no_music_on_hold_found' => 'No music files found.',
    'confirm_delete_music_on_hold' => 'Are you sure you want to delete this music file?',
    'music_on_hold_path_tooltip' => 'Path to the audio file or directory used for music-on-hold. Use local_stream:// for files uploaded through the Recordings page.',
    'modal_delete_music_on_hold_title' => 'Delete Music on Hold?',
    'modal_delete_music_on_hold_confirm' => 'Delete Music on Hold',
];
