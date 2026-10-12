<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: backups
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'backups_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'modal_delete_backup_title' => 'Supprimer la sauvegarde ?',
    'modal_delete_backup_confirm' => 'Supprimer la sauvegarde',
    'modal_delete_backup_message' => 'Supprimer « :name » et ses archives de sauvegarde stockées ? Cette action est irréversible.',
    'modal_delete_backup_error_title' => 'La sauvegarde ne peut pas être supprimée',
    'backup_create' => 'Créer une Sauvegarde',
    'backup_configuration' => 'Configuration de Sauvegarde',
    'backup_no_configs' => 'Aucune configuration de sauvegarde pour le moment. Créez-en une pour protéger vos données.',
    'backup_scopes' => 'Portées',
    'backup_last_run' => 'Dernière Exécution',
    'backup_size' => 'Taille',
    'backup_retention' => 'Rétention',
    'backup_retention_hint' => 'Nombre de fichiers de sauvegarde à conserver avant de purger les plus anciens.',
    'backup_compression' => 'Compresser la sauvegarde avec gzip',
    'backup_encryption' => 'Chiffrer la sauvegarde avec GPG',
    'backup_schedule' => 'Planification (cron)',
    'backup_schedule_hint' => 'Laisser vide pour manuel uniquement. Exemple : 0 2 * * * s\'exécute quotidiennement à 2h.',
    'backup_name_placeholder' => 'ex. Sauvegarde Quotidienne de la Base de Données',
    'backup_pending' => 'En Attente',
    'backup_running' => 'En Cours',
    'backup_completed' => 'Terminée',
    'backup_failed' => 'Échouée',
    'backup_run_now' => 'Exécuter Maintenant',
    'backup_run_confirm' => 'Démarrer une sauvegarde maintenant ? Cela peut prendre quelques minutes.',
    'backup_delete_confirm' => 'Supprimer cette configuration de sauvegarde et tous les fichiers stockés ?',
    'backups_list_tooltip' => 'Configurez des sauvegardes automatiques de votre base de données, fichiers d\'application, médias (enregistrements, messagerie vocale, fax) et configuration.',
    'backup_config_tooltip' => 'Créez un profil de sauvegarde définissant quoi sauvegarder et comment le stocker.',
    'backup_scopes_tooltip' => 'Catégories de données incluses dans cette sauvegarde : Base de données = dump SQL complet. Fichiers d\'application = stockage Laravel. Médias = enregistrements/messages vocaux/fax. Configuration = .env et conf FreeSWITCH.',
    'backup_status_tooltip' => 'Résultat de la dernière exécution de sauvegarde. Cliquez sur Exécuter maintenant pour déclencher une nouvelle sauvegarde.',
    'backup_size_tooltip' => 'Taille compressée du dernier fichier de sauvegarde réussi sur le disque.',
    'backup_retention_tooltip' => 'Nombre de fichiers de sauvegarde à conserver. Les plus anciennes sont automatiquement supprimées lorsque ce nombre est dépassé.',
    'backup_compression_tooltip' => 'Compressez l\'archive de sauvegarde avec gzip pour économiser de l\'espace disque.',
    'backup_encryption_tooltip' => 'Chiffrez l\'archive de sauvegarde avec GPG à l\'aide de la clé de chiffrement de l\'application.',
];
