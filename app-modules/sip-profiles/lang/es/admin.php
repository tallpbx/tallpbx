<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: Perfiles SIP (sip-profiles)
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
    'sip_profiles_title' => 'Perfiles SIP',
    'sip_profile_header_tooltip' => 'Una interfaz de señalización SIP que define enlaces IP/puerto, códecs y comportamiento de protocolo para FreeSWITCH',
    'sip_profile_tooltip' => 'Una interfaz de señalización SIP que define enlaces IP/puerto, códecs y comportamiento de protocolo para FreeSWITCH',
    'sip_profiles_tooltip' => 'Una interfaz de señalización SIP que define enlaces IP/puerto, códecs y comportamiento de protocolo para FreeSWITCH',
    'create_sip_profile' => 'Crear Perfil SIP',
    'edit_sip_profile' => 'Editar Perfil SIP',
    'profile_name' => 'Nombre del Perfil',
    'profile_ip' => 'Dirección IP',
    'profile_port' => 'Puerto',
    'profile_internal_placeholder' => 'interno',
    'profile_ip_placeholder' => '$${local_ip_v4}',
    'no_sip_profiles_found' => 'No se encontraron perfiles SIP.',
    'confirm_delete_sip_profile' => '¿Está seguro de que desea eliminar este perfil SIP?',
    'sip_profile_settings_tooltip' => 'Parámetros del perfil Sofia en formato JSON. Ajustes comunes: sip-port, rtp-ip, ext-rtp-ip, context, codec-prefs.',
    'modal_delete_sip_profile_title' => '¿Eliminar perfil SIP?',
    'modal_delete_sip_profile_confirm' => 'Eliminar perfil SIP',
];
