<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: event-rate-limits
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. 'event-rate-limits_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    'event_rate_limits_title' => 'Limites de Débit des Événements',
    'event_rate_limits_description' => 'Configurez les limites de débit pour les événements FreeSWITCH afin d\'éviter la surcharge du système.',
    'event_rate_limits_tooltip' => 'FreeSWITCH génère des événements en temps réel pour chaque appel, enregistrement, tonalité DTMF et changement d\'état. Les limites de débit des événements protègent le PBX contre les tempêtes d\'événements ou les appareils mal configurés en plafonnant les événements par minute et les rafales. Elles sont distinctes des limites de débit propres à FreeSWITCH (le réglage sessions-per-second et l\'application de numérotation limit, qui plafonnent les taux d\'appels et de sessions) et du pare-feu du noyau, qui bloque les paquets réseau. Les limites de débit des événements régulent le traitement interne des événements de téléphonie pour éviter les blocages du PBX.',
    'event_rate_limits_max_events' => 'Événements Maximum par Minute',
    'event_rate_limits_max_events_tooltip' => 'Le nombre maximum d\'événements FreeSWITCH autorisés depuis une seule source (adresse IP ou compte SIP) en une minute avant que la limitation ne s\'active. La valeur par défaut est 300.',
    'event_rate_limits_burst' => 'Limite de Rafale',
    'event_rate_limits_burst_tooltip' => 'Le nombre maximum d\'événements qu\'une source peut envoyer en une seule rafale avant d\'engager le limiteur de débit. La valeur par défaut est 50.',
    'event_rate_limits_block_duration' => 'Durée du Blocage (secondes)',
    'event_rate_limits_block_duration_tooltip' => 'Durée (en secondes) pendant laquelle une source bloquée reste refusée après le déclenchement de la limite. Par défaut 60 secondes.',
];
