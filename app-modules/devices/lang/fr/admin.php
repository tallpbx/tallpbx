<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: devices
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'devices_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'devices_title' => 'Appareils',
    'device_header_tooltip' => 'Un appareil physique ou un client softphone',
    'device_tooltip' => 'Un appareil physique ou un client softphone',
    'devices_tooltip' => 'Un appareil physique ou un client softphone',
    'create_device' => 'Créer un Appareil',
    'edit_device' => 'Modifier l\'Appareil',
    'device_name' => 'Nom de l\'Appareil',
    'device_vendor' => 'Fabricant',
    'device_model' => 'Modèle',
    'device_vendor_placeholder' => 'Polycom, Yealink, Cisco...',
    'device_mac' => 'Adresse MAC',
    'device_template' => 'Modèle',
    'device_sip_account' => 'Compte SIP',
    'no_devices_found' => 'Aucun appareil trouvé.',
    'confirm_delete_device' => 'Êtes-vous sûr de vouloir supprimer cet appareil ?',
    'modal_delete_device_title' => 'Supprimer le périphérique ?',
    'modal_delete_device_confirm' => 'Supprimer le périphérique',
    'modal_delete_device_message' => 'Supprimer le périphérique « :name » ? Cette action est irréversible.',
];
