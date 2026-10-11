<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: Profils SIP (sip-profiles)
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. 'sip_profile_*', 'profile_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'sip_profiles_title' => 'Profils SIP',
    'sip_profile_header_tooltip' => 'Une interface de signalisation SIP définissant les liaisons IP/port, les codecs et le comportement du protocole pour FreeSWITCH',
    'sip_profile_tooltip' => 'Une interface de signalisation SIP définissant les liaisons IP/port, les codecs et le comportement du protocole pour FreeSWITCH',
    'sip_profiles_tooltip' => 'Une interface de signalisation SIP définissant les liaisons IP/port, les codecs et le comportement du protocole pour FreeSWITCH',
    'create_sip_profile' => 'Créer un Profil SIP',
    'edit_sip_profile' => 'Modifier le Profil SIP',
    'profile_name' => 'Nom du Profil',
    'profile_ip' => 'Adresse IP',
    'profile_port' => 'Port',
    'profile_internal_placeholder' => 'interne',
    'profile_ip_placeholder' => '$${local_ip_v4}',
    'no_sip_profiles_found' => 'Aucun profil SIP trouvé.',
    'confirm_delete_sip_profile' => 'Êtes-vous sûr de vouloir supprimer ce profil SIP ?',
    'sip_profile_settings_tooltip' => 'Paramètres du profil Sofia au format JSON. Paramètres courants : sip-port, rtp-ip, ext-rtp-ip, context, codec-prefs.',
    'modal_delete_sip_profile_title' => 'Supprimer le profil SIP ?',
    'modal_delete_sip_profile_confirm' => 'Supprimer le profil SIP',
];
