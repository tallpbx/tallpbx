<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Call Broadcast Runtime
    |--------------------------------------------------------------------------
    |
    | media: the announcement played to each recipient after answer. The
    |   default is an install-safe beep; point it at a real audio file or
    |   local_stream URL for production announcements.
    | caller_id_number: the caller ID presented on broadcast calls.
    | pacing_seconds: delay between recipient originates (0 = none).
    */

    'media' => env('FREESWITCH_CALL_BROADCAST_MEDIA', 'tone_stream://%(1000,0,640)'),

    'caller_id_number' => env('FREESWITCH_CALL_BROADCAST_CALLER_ID', ''),

    'pacing_seconds' => (int) env('FREESWITCH_CALL_BROADCAST_PACING_SECONDS', 0),

    'outcome_window_minutes' => (int) env('FREESWITCH_CALL_BROADCAST_OUTCOME_WINDOW_MINUTES', 15),
];
