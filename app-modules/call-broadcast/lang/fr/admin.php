<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: call-broadcast
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'call-broadcast_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_call_broadcast_title' => 'Supprimer la diffusion d\'appel ?',
    'modal_delete_call_broadcast_confirm' => 'Supprimer la diffusion d\'appel',
    'call_broadcast_answered' => 'répondus',
    'call_broadcast_failed' => 'Échec',
    'create_call_broadcast' => 'Créer une Diffusion',
    'call_broadcast_name' => 'Nom',
    'call_broadcast_recipients' => 'Destinataires',
    'call_broadcast_phone_numbers' => 'Numéros de Téléphone',
    'call_broadcast_date' => 'Date',
    'call_broadcast_draft' => 'Brouillon',
    'call_broadcast_sending' => 'Envoi en cours',
    'call_broadcast_completed' => 'Terminé',
    'no_call_broadcasts_found' => 'Aucune diffusion d\'appel trouvée.',
    'confirm_delete_call_broadcast' => 'Êtes-vous sûr de vouloir supprimer cette diffusion ?',
];
