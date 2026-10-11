<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: dialplans
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'dialplan_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'dialplans_title' => 'Plans de Numérotation',
    'dialplan' => 'Plan de Numérotation',
    'create_dialplan' => 'Créer un Plan de Numérotation',
    'edit_dialplan' => 'Modifier le Plan de Numérotation',
    'dialplan_name' => 'Nom',
    'dialplan_context' => 'Contexte',
    'dialplan_details' => 'Détails',
    'dialplan_rules' => 'règles',
    'dialplan_context_placeholder' => 'par défaut',
    'dialplan_order' => 'Ordre',
    'no_dialplans_found' => 'Aucun plan de numérotation trouvé.',
    'confirm_delete_dialplan' => 'Êtes-vous sûr de vouloir supprimer ce plan de numérotation ?',
    'modal_delete_dialplan_title' => 'Supprimer le plan de numérotation ?',
    'modal_delete_dialplan_confirm' => 'Supprimer le plan de numérotation',
    'modal_delete_dialplan_message' => 'Supprimer « :name » et ses règles ? Cette action est irréversible.',
];
