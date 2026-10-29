<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: voicemails
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'voicemail_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'voicemails_title' => 'Messageries Vocales',
    'create_voicemail' => 'Créer une Messagerie Vocale',
    'edit_voicemail' => 'Modifier la Messagerie Vocale',
    'voicemail_mailbox' => 'Boîte aux Lettres',
    'voicemail_pin' => 'PIN',
    'voicemail_email' => 'Email',
    'voicemail_attach' => 'Joindre l\'audio à l\'email',
    'voicemail_require_pin' => 'Exiger un PIN pour accéder à la messagerie',
    'voicemail_forward_email' => 'Transférer les messages par email',
    'no_voicemails_found' => 'Aucune messagerie vocale trouvée.',
    'confirm_delete_voicemail' => 'Êtes-vous sûr de vouloir supprimer cette messagerie vocale ?',
    'modal_delete_voicemail_mailbox_title' => 'Supprimer la boîte vocale ?',
    'modal_delete_voicemail_mailbox_confirm' => 'Supprimer la boîte vocale',
    'modal_delete_voicemail_mailbox_message' => 'Supprimer la boîte vocale « :name » ? Cette action est irréversible.',
    'modal_delete_voicemail_mailbox_error_title' => 'La boîte vocale ne peut pas être supprimée',
    'voicemail_id' => 'Identifiant de Messagerie Vocale',
    'voicemail_pin_password' => 'PIN / Mot de passe',
    'voicemail_email_notification' => 'Notification par Email',
    'voicemail_greeting_audio' => 'Audio de Salutation',
    'voicemail_options' => 'Options',
    'voicemail_delete_after_email' => 'Supprimer le message du serveur après l\'envoi par email',
];
