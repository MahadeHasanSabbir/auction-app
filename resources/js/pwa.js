/**
 * The installable app: the service worker, the "install" prompt and the
 * web push switch on the notification settings page.
 */
export function registerPwa() {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        });
    }

    // Held back by the browser until we choose to show it.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        window.installPrompt = event;
        window.dispatchEvent(new CustomEvent('install-available'));
    });

    window.addEventListener('appinstalled', () => {
        window.installPrompt = null;
        window.dispatchEvent(new CustomEvent('install-done'));
    });

    document.addEventListener('alpine:init', () => {
        const Alpine = window.Alpine;

        /** A button that appears only when the browser can install the site. */
        Alpine.data('installApp', () => ({
            available: Boolean(window.installPrompt),

            async install() {
                const prompt = window.installPrompt;

                if (!prompt) {
                    return;
                }

                prompt.prompt();
                await prompt.userChoice;
                window.installPrompt = null;
                this.available = false;
            },
        }));

        /** Subscribes this browser to web push, or removes the subscription. */
        Alpine.data('pushToggle', (publicKey, url) => ({
            supported: 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && Boolean(publicKey),
            enabled: false,
            denied: false,
            busy: false,
            failed: false,

            async init() {
                if (!this.supported) {
                    return;
                }

                this.denied = Notification.permission === 'denied';

                const registration = await navigator.serviceWorker.ready;
                this.enabled = Boolean(await registration.pushManager.getSubscription()) && Notification.permission === 'granted';
            },

            async toggle() {
                this.busy = true;
                this.failed = false;

                try {
                    this.enabled ? await this.disable() : await this.enable();
                } catch (e) {
                    this.failed = true;
                } finally {
                    this.busy = false;
                }
            },

            async enable() {
                const permission = await Notification.requestPermission();
                this.denied = permission === 'denied';

                if (permission !== 'granted') {
                    return;
                }

                const registration = await navigator.serviceWorker.ready;
                const subscription =
                    (await registration.pushManager.getSubscription()) ||
                    (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: decodeKey(publicKey) }));

                const json = subscription.toJSON();
                const response = await send('POST', url, {
                    endpoint: json.endpoint,
                    keys: json.keys,
                    contentEncoding: (PushManager.supportedContentEncodings || ['aesgcm'])[0],
                });

                if (!response.ok) {
                    await subscription.unsubscribe();
                    throw new Error('subscription refused');
                }

                this.enabled = true;
            },

            async disable() {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();

                if (subscription) {
                    await send('DELETE', url, { endpoint: subscription.endpoint });
                    await subscription.unsubscribe();
                }

                this.enabled = false;
            },
        }));
    });
}

function send(method, url, body) {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(body),
    });
}

function decodeKey(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}
