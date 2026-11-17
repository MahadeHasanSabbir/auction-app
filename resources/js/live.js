/**
 * Live updates over Echo (resources/js/echo.js). Without a connection
 * (BROADCAST_CONNECTION=log, or the websocket server is down) every helper
 * here does nothing, and the auction page falls back to polling.
 *
 * Auction broadcasts (App\Events\Auctions) each carry a whole snapshot of the
 * auction's public state, read when it was sent, with a sequence number
 * (`seq`). A component applies a snapshot only when its seq is not lower than
 * the one it shows, so events that arrive out of order are ignored.
 */

const echo = () => window.Echo ?? null;

const AUCTION_EVENTS = ['bid.placed', 'auction.started', 'auction.closed'];

/** Several components may watch the same auction; the channel is left when the last one stops. */
const watchers = new Map();

export function connected() {
    return echo()?.connector?.pusher?.connection?.state === 'connected';
}

/** Calls handler(eventName, snapshot) for every update of an auction. Returns a function that stops it. */
export function watchAuction(id, handler) {
    const client = echo();

    if (!client) {
        return () => {};
    }

    const name = `auction.${id}`;
    const channel = client.channel(name);
    const callbacks = AUCTION_EVENTS.map((event) => {
        const callback = (payload) => handler(event, payload);
        channel.listen(`.${event}`, callback);

        return [event, callback];
    });

    watchers.set(name, (watchers.get(name) ?? 0) + 1);

    return () => {
        callbacks.forEach(([event, callback]) => channel.stopListening(`.${event}`, callback));

        const left = (watchers.get(name) ?? 1) - 1;

        if (left > 0) {
            watchers.set(name, left);
        } else {
            watchers.delete(name);
            client.leaveChannel(name);
        }
    };
}

/** Runs fn at most once per `wait` ms, at the end of a burst (several auctions closing together). */
function debounce(fn, wait) {
    let timer = null;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}

const text = () => window.liveText ?? {};

function bidCountLabel(count) {
    const labels = text().bids ?? {};
    const label = count === 0 ? labels.zero : count === 1 ? labels.one : labels.other;

    return (label ?? ':count').replace(':count', count);
}

/** The fields of a snapshot a component shows; kept flat so templates read them directly. */
function stateFrom(s) {
    return {
        status: s.status,
        final: s.final,
        seq: s.seq,
        price: s.price,
        bidCount: s.bid_count,
        endsAt: s.ends_at,
        nextMinimum: s.next_minimum,
        nextMinimumInput: s.next_minimum_input,
        reserveMet: s.reserve_met,
        buyNow: s.buy_now,
        extensions: s.extensions,
        leaderRef: s.leader_ref,
    };
}

export function registerLive(Alpine) {
    /**
     * An auction card or list row (home, auction list). Price, bid count and
     * end time follow the auction; a start or close re-renders the Livewire
     * component around it, which moves the card where it now belongs.
     */
    Alpine.data('liveAuction', (initial) => ({
        ...stateFrom(initial),
        id: initial.id,
        stop: null,

        init() {
            const refresh = debounce(() => this.$wire?.$refresh(), 400);

            this.stop = watchAuction(this.id, (event, snapshot) => {
                this.apply(snapshot);

                if (event !== 'bid.placed') {
                    refresh();
                }
            });
        },

        destroy() {
            this.stop?.();
        },

        apply(snapshot) {
            if (snapshot.id === this.id && snapshot.seq >= this.seq) {
                Object.assign(this, stateFrom(snapshot));
            }
        },

        get bidCountLabel() {
            return bidCountLabel(this.bidCount);
        },

        get priceLabel() {
            return this.bidCount > 0 ? text().currentBid : text().startingPrice;
        },
    }));

    /**
     * The auction page. Public state comes from the auction channel; the
     * viewer count from a presence channel (signed-in viewers only; guests
     * see the count the server sends). What only the viewer can know (their
     * maximum, what they may bid) is re-rendered by Livewire when it changes.
     */
    Alpine.data('auctionRoom', (config) => ({
        // The state arrives from the server snapshot (the keyed x-init calling apply()).
        ...stateFrom({ seq: -1 }),
        id: config.id,
        feed: [],
        me: config.me,
        signedIn: config.me !== null,
        hasBid: config.hasBid,
        guestViewers: null,
        members: null,
        stops: [],

        init() {
            this.stops.push(watchAuction(this.id, (event, snapshot) => {
                const extended = snapshot.extensions > this.extensions && !snapshot.final;

                this.apply(snapshot);

                if (extended) {
                    this.$dispatch('toast', { message: text().extended });
                }

                // A start or close changes the whole sidebar; a cancelled auction is no longer public.
                if (event !== 'bid.placed' && snapshot.status !== 'cancelled') {
                    this.$wire.$refresh();
                }
            }));

            this.joinViewers();

            const timer = setInterval(() => {
                if (!connected() && !this.final && document.visibilityState === 'visible') {
                    this.$wire.$refresh();
                }
            }, config.pollSeconds * 1000);

            this.stops.push(() => clearInterval(timer));
        },

        destroy() {
            this.stops.forEach((stop) => stop());
        },

        joinViewers() {
            const client = echo();

            if (!client || !this.signedIn) {
                return;
            }

            const name = `auction.${this.id}.viewers`;

            client.join(name)
                .here((members) => (this.members = members.length))
                .joining(() => (this.members = (this.members ?? 0) + 1))
                .leaving(() => (this.members = Math.max(1, (this.members ?? 1) - 1)));

            this.stops.push(() => client.leaveChannel(`presence-${name}`));
        },

        /** A snapshot from a broadcast, or from the server after a Livewire render. */
        apply(snapshot) {
            if (snapshot.id !== this.id || snapshot.seq < this.seq) {
                return;
            }

            Object.assign(this, stateFrom(snapshot));

            if (snapshot.bids) {
                this.feed = snapshot.bids;
            }

            if (snapshot.viewers !== null && snapshot.viewers !== undefined) {
                this.guestViewers = snapshot.viewers;
            }

            if (this.me && this.feed.some((bid) => bid.ref === this.me)) {
                this.hasBid = true;
            }
        },

        get leading() {
            return this.me !== null && this.leaderRef === this.me;
        },

        get outbid() {
            return this.hasBid && !this.leading;
        },

        get viewers() {
            return this.signedIn ? this.members : this.guestViewers;
        },

        get viewersLabel() {
            return (text().viewers ?? ':count').replace(':count', this.viewers);
        },

        get priceLabel() {
            return this.bidCount > 0 ? text().currentBid : text().startingPrice;
        },

        bidder(bid) {
            return bid.ref === this.me ? text().you : bid.bidder;
        },

        time(ms) {
            return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
                timeZone: config.timezone,
                day: 'numeric',
                month: 'short',
                hour: 'numeric',
                minute: '2-digit',
            }).format(new Date(ms));
        },
    }));

    /**
     * The signed-in user's private channel, on every page: toasts for being
     * outbid or winning, the header balance, and a Livewire event that pages
     * showing the viewer's money or bids listen to.
     */
    Alpine.data('liveAccount', (userId) => ({
        walletVersion: 0,

        init() {
            const client = echo();

            if (!client) {
                return;
            }

            client.private(`App.Models.User.${userId}`)
                .listen('.bid.outbid', (event) => this.$dispatch('toast', { message: event.message, type: 'error' }))
                .listen('.auction.won', (event) => this.$dispatch('toast', { message: event.message }))
                .listen('.wallet.updated', (event) => this.wallet(event));
        },

        wallet(event) {
            if (event.version < this.walletVersion) {
                return;
            }

            this.walletVersion = event.version;
            document.querySelectorAll('[data-live="wallet-available"]').forEach((el) => (el.textContent = event.available));
            window.Livewire?.dispatch('wallet-updated');
        },
    }));
}
