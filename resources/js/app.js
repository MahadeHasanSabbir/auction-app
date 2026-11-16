// Alpine is bundled with Livewire and started by it; do not import or start it here.
// Components are registered on Alpine's own init event instead.
import './bootstrap';

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Counts down to a moment. Both timestamps are epoch milliseconds from
     * the server, so a wrong clock on the visitor's device does not shift the
     * countdown: only the time elapsed since page load is measured locally.
     */
    Alpine.data('countdown', (target, serverNow) => ({
        remaining: Math.max(0, target - serverNow),
        startedAt: performance.now(),
        timer: null,

        init() {
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        tick() {
            this.remaining = Math.max(0, target - serverNow - (performance.now() - this.startedAt));

            if (this.remaining === 0) {
                clearInterval(this.timer);
            }
        },

        get done() {
            return this.remaining === 0;
        },

        // Less than five minutes left.
        get urgent() {
            return this.remaining > 0 && this.remaining < 5 * 60 * 1000;
        },

        get label() {
            const total = Math.floor(this.remaining / 1000);
            const days = Math.floor(total / 86400);
            const hours = Math.floor((total % 86400) / 3600);
            const minutes = Math.floor((total % 3600) / 60);
            const seconds = total % 60;
            const pad = (n) => String(n).padStart(2, '0');

            if (days > 0) return `${days}d ${hours}h`;
            if (hours > 0) return `${hours}h ${pad(minutes)}m`;

            return `${minutes}m ${pad(seconds)}s`;
        },
    }));

    /** Seconds until a button (such as "send a new code") can be used again. */
    Alpine.data('cooldown', (seconds) => ({
        left: seconds,

        init() {
            if (this.left <= 0) return;

            const timer = setInterval(() => {
                this.left -= 1;
                if (this.left <= 0) clearInterval(timer);
            }, 1000);
        },
    }));

    /** Light and dark theme, remembered on this device. */
    Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),

        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        },
    });
});
