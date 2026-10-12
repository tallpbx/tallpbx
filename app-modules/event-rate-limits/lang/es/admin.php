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
    'event_rate_limits_title' => 'Límites de Tasa de Eventos',
    'event_rate_limits_description' => 'Configure los límites de tasa para eventos de FreeSWITCH para evitar sobrecargas del sistema.',
    'event_rate_limits_tooltip' => 'FreeSWITCH genera eventos en tiempo real para cada llamada, registro, tono DTMF y cambio de estado. Los límites de tasa de eventos protegen la PBX contra tormentas de eventos o dispositivos mal configurados limitando los eventos por minuto y ráfagas. Son distintos de los límites de tasa propios de FreeSWITCH (la configuración sessions-per-second y la aplicación de marcado limit, que limitan las tasas de llamadas y sesiones) y del cortafuegos del kernel, que bloquea paquetes de red. Los límites de tasa de eventos regulan el procesamiento interno de eventos de telefonía para evitar bloqueos de la PBX.',
    'event_rate_limits_max_events' => 'Máximo de Eventos por Minuto',
    'event_rate_limits_max_events_tooltip' => 'El número máximo de eventos de FreeSWITCH permitidos desde una única fuente (dirección IP o cuenta SIP) en un minuto antes de activar la limitación de velocidad. El valor predeterminado es 300.',
    'event_rate_limits_burst' => 'Límite de Ráfaga',
    'event_rate_limits_burst_tooltip' => 'El número máximo de eventos que una fuente puede enviar en una sola ráfaga antes de activar el limitador de velocidad, incluso si el promedio por minuto está por debajo del umbral. El valor predeterminado es 50.',
    'event_rate_limits_block_duration' => 'Duración del Bloqueo (segundos)',
    'event_rate_limits_block_duration_tooltip' => 'Tiempo (en segundos) que una fuente bloqueada permanece denegada después de activar el límite de velocidad. Durante este período, se descartan todos los eventos de esa fuente. El valor predeterminado es 60 segundos.',
];
