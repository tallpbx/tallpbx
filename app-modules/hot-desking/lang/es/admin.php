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
    'hot_desking_user_extension' => 'Extensión del Usuario',
    'hot_desking_user_extension_tooltip' => 'La extensión del usuario principal que está visitando e iniciando sesión.',
    'hot_desking_desk_extension' => 'Extensión de Escritorio',
    'hot_desking_desk_extension_tooltip' => 'La extensión del teléfono de escritorio físico que se ocupa temporalmente.',
    'hot_desking_login_at' => 'Conectado',
    'hot_desking_logout' => 'Cerrar Sesión',
    'hot_desking_end_session' => 'Finalizar esta sesión activa de hot desking',
    'hot_desking_tooltip' => 'Hot Desking permite a los usuarios iniciar sesión en cualquier teléfono de escritorio con *11 y adoptar su extensión, enrutamiento y buzón de voz. Marque *12 para cerrar sesión.',
];
