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
    'music_on_hold_title' => 'Musique d\'Attente',
    'create_music_on_hold' => 'Téléverser de la Musique',
    'edit_music_on_hold' => 'Modifier la Musique',
    'no_music_on_hold_found' => 'Aucun fichier musical trouvé.',
    'confirm_delete_music_on_hold' => 'Êtes-vous sûr de vouloir supprimer ce fichier musical ?',
    'music_on_hold_path_tooltip' => 'Chemin vers le fichier audio ou le répertoire utilisé pour la musique d\'attente. Utilisez local_stream:// pour les fichiers téléversés.',
    'modal_delete_music_on_hold_title' => 'Supprimer la musique d\'attente ?',
    'modal_delete_music_on_hold_confirm' => 'Supprimer la musique d\'attente',
];
