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
    'modal_delete_backup_title' => '¿Eliminar copia de seguridad?',
    'modal_delete_backup_confirm' => 'Eliminar copia de seguridad',
    'modal_delete_backup_message' => '¿Eliminar “:name” y sus archivos de copia almacenados? Esta acción no se puede deshacer.',
    'modal_delete_backup_error_title' => 'La copia de seguridad no se puede eliminar',
    'backup_create' => 'Crear Copia de Seguridad',
    'backup_configuration' => 'Configuración de Copia de Seguridad',
    'backup_no_configs' => 'Aún no hay configuraciones de copia de seguridad. Cree una para comenzar a proteger sus datos.',
    'backup_scopes' => 'Ámbitos',
    'backup_last_run' => 'Última Ejecución',
    'backup_size' => 'Tamaño',
    'backup_retention' => 'Retención',
    'backup_retention_hint' => 'Número de archivos de copia de seguridad que se conservarán antes de eliminar los más antiguos.',
    'backup_compression' => 'Comprimir copia de seguridad con gzip',
    'backup_encryption' => 'Cifrar copia de seguridad con GPG',
    'backup_schedule' => 'Programación (cron)',
    'backup_schedule_hint' => 'Deje vacío para solo manual. Ejemplo: 0 2 * * * se ejecuta diariamente a las 2 AM.',
    'backup_name_placeholder' => 'ej. Copia de Seguridad Diaria de Base de Datos',
    'backup_pending' => 'Pendiente',
    'backup_running' => 'En Ejecución',
    'backup_completed' => 'Completada',
    'backup_failed' => 'Fallida',
    'backup_run_now' => 'Ejecutar Ahora',
    'backup_run_confirm' => '¿Iniciar una copia de seguridad ahora? Esto puede tardar unos minutos.',
    'backup_delete_confirm' => '¿Eliminar esta configuración de copia de seguridad y todos los archivos almacenados?',
    'backups_list_tooltip' => 'Configure copias de seguridad automáticas de su base de datos, archivos de aplicaciones, medios (grabaciones, buzón de voz, fax) y configuración.',
    'backup_config_tooltip' => 'Cree un perfil de copia de seguridad que defina qué respaldar y cómo almacenarlo.',
    'backup_scopes_tooltip' => 'Qué categorías de datos se incluyen en esta copia de seguridad. Base de datos = volcado SQL completo. Archivos de aplicación = almacenamiento de Laravel. Medios = grabaciones/correo de voz/fax. Configuración = .env y conf de FreeSWITCH.',
    'backup_status_tooltip' => 'Resultado de la ejecución de copia de seguridad más reciente. Haga clic en Ejecutar ahora para activar una nueva copia de seguridad.',
    'backup_size_tooltip' => 'Tamaño comprimido del último archivo de copia de seguridad correcto en el disco.',
    'backup_retention_tooltip' => 'Número de archivos de copia de seguridad que se conservarán. Las copias de seguridad más antiguas se eliminan automáticamente cuando se supera este recuento.',
    'backup_compression_tooltip' => 'Comprima el archivo de copia de seguridad con gzip para ahorrar espacio en disco.',
    'backup_encryption_tooltip' => 'Cifre el archivo de copia de seguridad con GPG mediante la clave de cifrado de la aplicación.',
];
