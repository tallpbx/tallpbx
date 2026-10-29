<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: ring-groups
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'ring-groups_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'ring_groups_title' => 'Groupes de Sonnerie',
    'ring_group' => 'Groupe de Sonnerie',
    'create_ring_group' => 'Créer un Groupe de Sonnerie',
    'edit_ring_group' => 'Modifier le Groupe de Sonnerie',
    'ring_group_name' => 'Nom',
    'ring_group_strategy' => 'Stratégie',
    'ring_group_timeout' => 'Délai d\'Attente',
    'ring_group_extensions' => 'Extensions',
    'no_ring_groups_found' => 'Aucun groupe de sonnerie trouvé.',
    'confirm_delete_ring_group' => 'Êtes-vous sûr de vouloir supprimer ce groupe de sonnerie ?',
    'ring_group_strategy_tooltip' => 'Comment les appels sont distribués : simultané fait sonner tous les membres à la fois, séquence les essaie un par un, entreprise utilise un round-robin pondéré.',
    'ring_group_timeout_tooltip' => 'Combien de temps (en secondes) faire sonner chaque membre ou l\'ensemble du groupe avant de passer à la destination suivante.',
    'modal_delete_ring_group_title' => 'Supprimer le groupe de sonnerie ?',
    'modal_delete_ring_group_confirm' => 'Supprimer le groupe de sonnerie',
    'modal_delete_ring_group_message' => 'Supprimer « :name » et ses membres ? Cette action est irréversible.',
    'ring_group_timeout_seconds' => 'Délai de Sonnerie (secondes)',
];
