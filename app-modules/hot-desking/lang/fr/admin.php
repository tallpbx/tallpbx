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
    'hot_desking_user_extension' => 'Extension Utilisateur',
    'hot_desking_user_extension_tooltip' => 'L\'extension utilisateur principale qui est en déplacement et se connecte.',
    'hot_desking_desk_extension' => 'Extension de Bureau',
    'hot_desking_desk_extension_tooltip' => 'L\'extension du téléphone de bureau physique temporairement occupée.',
    'hot_desking_login_at' => 'Connecté Le',
    'hot_desking_logout' => 'Déconnecter',
    'hot_desking_end_session' => 'Mettre fin à cette session active de hot desking',
    'hot_desking_tooltip' => 'Le Hot Desking permet aux utilisateurs de se connecter à n\'importe quel téléphone de bureau avec le *11 pour adopter leur extension, leur routage et leur messagerie vocale. Composez le *12 pour vous déconnecter.',
];
