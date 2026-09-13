import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const scheme = import.meta.env.VITE_REVERB_SCHEME ?? (typeof window !== 'undefined' && window.location.protocol === 'https:' ? 'https' : 'http');
const isTls = scheme === 'https' || (typeof window !== 'undefined' && window.location.protocol === 'https:');

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || (typeof window !== 'undefined' ? window.location.hostname : '127.0.0.1'),
    wsPort: import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT, 10) : (isTls ? 443 : 80),
    wssPort: import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT, 10) : 443,
    forceTLS: isTls,
    enabledTransports: ['ws', 'wss'],
});

