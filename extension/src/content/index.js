import { adapterFor } from '../adapters/index.js';
import { MESSAGE } from '../core/messages.js';
import { marketplaceForUrl } from '../marketplaces.js';
import { dueForAutoRun, rememberAwaiting, run } from './runner.js';

/**
 * The content script on stock sites: runs the marketplace's adapter when the page opens, again every few minutes
 * while the tab is open and visible (marketplaces take minutes to show newly delivered files), every minute even in
 * a background tab while works wait to go to review, and on "Check now" from the popup.
 */
const marketplace = marketplaceForUrl(location.href);
const adapter = marketplace && adapterFor(marketplace.code);

if (adapter) {
    const pass = () => run(adapter, marketplace).then((result) => {
        if (result) {
            rememberAwaiting(adapter.code, result.awaiting);
        }
    });

    const autoRun = () => {
        if (dueForAutoRun(adapter.code, { visible: document.visibilityState === 'visible' })) {
            pass();
        }
    };

    autoRun();
    // Checked often; dueForAutoRun() keeps the passes apart.
    setInterval(autoRun, 15_000);
    document.addEventListener('visibilitychange', autoRun);

    chrome.runtime.onMessage.addListener((message, sender, respond) => {
        if (message?.type !== MESSAGE.RUN) {
            return false;
        }

        pass().then(() => respond({ ok: true, data: null }));

        return true;
    });
}
