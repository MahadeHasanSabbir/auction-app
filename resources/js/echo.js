import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * The broadcaster is chosen by BROADCAST_CONNECTION in .env:
 *   reverb - self-hosted Laravel Reverb (local development, VPS)
 *   pusher - hosted Pusher Channels (hosting without long-running processes)
 * Any other value (log, null) leaves real-time features switched off.
 */
const connection = import.meta.env.VITE_BROADCAST_CONNECTION;

if (connection === 'reverb' && import.meta.env.VITE_REVERB_APP_KEY) {
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else if (connection === 'pusher' && import.meta.env.VITE_PUSHER_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        forceTLS: true,
    });
}
