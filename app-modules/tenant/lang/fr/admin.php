<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: tenant
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'tenant_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'tenants_title' => 'Locataires',
    'create_tenant' => 'Créer un Locataire',
    'edit_tenant' => 'Modifier le Locataire',
    'tenant_name' => 'Nom du Locataire',
    'tenant_email' => 'Email du Locataire',
    'tenant_slug' => 'Slug',
    'tenant_provisioning' => 'URL de Provisionnement',
    'no_tenants_found' => 'Aucun locataire trouvé.',
    'confirm_delete_tenant' => 'Êtes-vous sûr de vouloir supprimer ce locataire ?',
    'tenant_dashboard' => 'Tableau de Bord',
    'tenant_domains_title' => 'Domaines du Locataire',
    'modal_delete_tenant_title' => 'Supprimer le locataire ?',
    'modal_delete_tenant_confirm' => 'Supprimer le locataire',
    'modal_delete_tenant_message' => 'Cela supprime TOUTES les données de « :name ». Saisissez le nom du locataire pour confirmer.',
    'modal_delete_tenant_error_title' => 'Le locataire ne peut pas être supprimé',
    'modal_delete_tenant_domain_title' => 'Supprimer le domaine ?',
    'modal_delete_tenant_domain_confirm' => 'Supprimer le domaine',
    'modal_delete_tenant_limit_title' => 'Supprimer la limite de locataire ?',
    'modal_delete_tenant_limit_confirm' => 'Supprimer la limite de locataire',
    'tenants_count_tooltip' => 'Nombre d\'organisations locataires auxquelles appartient cet utilisateur.',
    'tenant_slug_tooltip' => 'Identifiant unique adapté aux URL pour ce locataire.',
    'tenant_status_tooltip' => 'Indique si ce compte locataire est actif ou désactivé.',
    'tenant_limit' => 'Limite de Locataire',
];
