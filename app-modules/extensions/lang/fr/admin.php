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
    'extension_header_tooltip' => 'Un point de terminaison appelable logique et une identité sur le PBX',
    'extension_tooltip' => 'Un point de terminaison appelable logique et une identité sur le PBX',
    'extensions_tooltip' => 'Un point de terminaison appelable logique et une identité sur le PBX',
    'create_extension' => 'Créer une Extension',
    'create_multiple_extensions' => 'Créer Plusieurs Extensions',
    'edit_extension' => 'Modifier l\'Extension',
    'extension_number' => 'Extension',
    'extension_password' => 'Mot de Passe',
    'no_extensions_found' => 'Aucune extension trouvée.',
    'confirm_delete_extension' => 'Êtes-vous sûr de vouloir supprimer cette extension ?',
    'extension_number_tooltip' => 'Le numéro de téléphone que les utilisateurs composent pour joindre cette extension. Généralement 3 à 5 chiffres.',
    'extension_description_tooltip' => 'Un nom convivial affiché dans le panneau d\'administration, l\'écran opérateur et les écrans des téléphones provisionnés (par ex. "Jean Dupont" ou "Téléphone du hall"). Il n\'est pas utilisé comme identifiant d\'appelant (Caller ID) interne ou sortant.',
    'extension_effective_caller_id_name_tooltip' => 'Le nom présenté aux postes internes lors des appels entre postes. Également utilisé pour les appels sortants, sauf s\'il est remplacé par le Nom de l\'appelant sortant ou une route sortante.',
    'extension_effective_caller_id_number_tooltip' => 'Le numéro présenté aux postes internes lors des appels entre postes (généralement ce numéro). Également utilisé pour les appels sortants, sauf s\'il est remplacé par le Numéro de l\'appelant sortant ou une route sortante.',
    'extension_outbound_caller_id_name_tooltip' => 'Le nom d\'appelant transmis aux correspondants externes lors d\'appels sortants via un tronc ou une passerelle, remplaçant le Nom effectif (sauf s\'il est remplacé par une route sortante).',
    'extension_outbound_caller_id_number_tooltip' => 'Le numéro de téléphone transmis aux correspondants externes lors d\'appels sortants (ex. votre DID direct ou numéro principal), remplaçant le Numéro effectif (sauf s\'il est remplacé par une route sortante).',
    'modal_delete_extension_title' => 'Supprimer la poste ?',
    'modal_delete_extension_confirm' => 'Supprimer la poste',
    'extension_display_name' => 'Nom Affiché',
    'extension_sip_password' => 'Mot de Passe SIP',
    'extension_effective_caller_id_name' => 'Nom de l\'Identifiant Effectif',
    'extension_effective_caller_id_number' => 'Numéro de l\'Identifiant Effectif',
    'extension_outbound_caller_id_name' => 'Nom de l\'Identifiant Sortant',
    'extension_outbound_caller_id_number' => 'Numéro de l\'Identifiant Sortant',
    'extension_voicemail_enabled' => 'Messagerie Vocale Activée',
];
