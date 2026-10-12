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
    'ivr_menus_title' => 'Menus IVR',
    'ivr_menu' => 'Menu IVR',
    'create_ivr_menu' => 'Créer un Menu IVR',
    'edit_ivr_menu' => 'Modifier le Menu IVR',
    'ivr_menu_name' => 'Nom du Menu',
    'ivr_menu_greeting' => 'Message d\'accueil',
    'ivr_menu_greeting_placeholder' => 'Message de bienvenue ou chemin du fichier audio',
    'ivr_menu_timeout' => 'Délai d\'attente (secondes)',
    'ivr_menu_options' => 'Options',
    'ivr_menu_digit_length' => 'Longueur des Chiffres',
    'ivr_menu_digits' => 'chiffres',
    'ivr_menu_variable' => 'Variable',
    'ivr_menu_loop' => 'Boucle',
    'no_ivr_menus_found' => 'Aucun menu IVR configuré pour le moment.',
    'confirm_delete_ivr_menu' => 'Êtes-vous sûr de vouloir supprimer ce menu IVR ?',
    'ivr_menu_greeting_tooltip' => 'Le message audio diffusé aux appelants lorsqu\'ils entrent dans cet IVR. Téléversez un fichier .wav sur la page Enregistrements.',
    'ivr_menu_timeout_tooltip' => 'Combien de temps (en millisecondes) attendre que l\'appelant appuie sur une touche avant l\'expiration du délai.',
    'modal_delete_ivr_menu_title' => 'Supprimer le menu IVR ?',
    'modal_delete_ivr_menu_confirm' => 'Supprimer le menu IVR',
    'modal_delete_ivr_menu_error_title' => 'Le menu IVR ne peut pas être supprimé',
];
