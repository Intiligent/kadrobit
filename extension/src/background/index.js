import { MESSAGE } from '../core/messages.js';
import * as actions from './actions.js';
import * as auth from './auth.js';
import * as kadrobit from './kadrobit.js';

/**
 * The background worker: the only place that holds the Kadrobit token and talks to Kadrobit. The popup and the
 * content scripts on stock sites ask it through messages (core/messages.js).
 */
const handlers = {
    // The popup: sign-in, the account with its counts, the last action on each marketplace. A token Kadrobit no
    // longer accepts shows the sign-in again, with the reason; Kadrobit out of reach keeps the extension signed in
    // (me is null), so the popup can still sign out.
    async [MESSAGE.STATE]() {
        let me = null;
        let notice = null;

        if (await auth.load()) {
            try {
                me = await kadrobit.me();
            } catch (error) {
                notice = error.message;
            }
        }

        return { signedIn: Boolean(await auth.load()), me, notice, actions: await actions.all() };
    },

    async [MESSAGE.SIGN_IN]() {
        await auth.signIn();
        await actions.clearAll();

        return kadrobit.me();
    },

    // The token stays only if Kadrobit accepts it.
    async [MESSAGE.SIGN_IN_WITH_TOKEN]({ token }) {
        await auth.signInWithToken(token);

        try {
            const me = await kadrobit.me();
            await actions.clearAll();

            return me;
        } catch (error) {
            await auth.clear();
            throw error;
        }
    },

    async [MESSAGE.SIGN_OUT]() {
        // Signed out here even if Kadrobit cannot be reached: the token is forgotten either way.
        await kadrobit.logout().catch(() => null);
        await auth.clear();
        await actions.clearAll();

        return null;
    },

    // A content script: the sent works on its marketplace, and the accounts there with their auto submit.
    async [MESSAGE.LISTINGS]({ marketplace }) {
        const [me, listings] = await Promise.all([kadrobit.me(), kadrobit.listings(marketplace)]);

        return {
            accounts: me.accounts.filter((account) => account.marketplace.code === marketplace),
            listings,
        };
    },

    async [MESSAGE.REPORT]({ reports }) {
        return reports.length ? kadrobit.report(reports) : 0;
    },

    async [MESSAGE.ACTION]({ marketplace, action }) {
        await actions.record(marketplace, action);

        return null;
    },
};

chrome.runtime.onMessage.addListener((message, sender, respond) => {
    const handler = handlers[message?.type];

    if (!handler) {
        return false;
    }

    handler(message, sender)
        .then((data) => respond({ ok: true, data }))
        .catch((error) => respond({ ok: false, error: error.message, signedOut: error instanceof auth.SignedOutError }));

    // The answer comes asynchronously.
    return true;
});

// Refreshing now and then keeps the 30-day refresh token alive while the browser is in use.
const REFRESH_ALARM = 'refresh-token';

// Chrome may drop alarms on a restart or an update, so the worker sets it again whenever it starts.
chrome.alarms.get(REFRESH_ALARM).then((alarm) => {
    if (!alarm) {
        chrome.alarms.create(REFRESH_ALARM, { periodInMinutes: 12 * 60 });
    }
});

chrome.alarms.onAlarm.addListener(async (alarm) => {
    if (alarm.name === REFRESH_ALARM && (await auth.load())?.kind === 'oauth') {
        await auth.refresh().catch(() => null);
    }
});
