import { adapterFor } from '../adapters/index.js';
import { MESSAGE } from '../core/messages.js';
import { marketplaceForUrl } from '../marketplaces.js';
import { dueForAutoRun, run } from './runner.js';

/**
 * The content script on stock sites: runs the marketplace's adapter when the page opens, again every few minutes
 * while the tab is open and visible (marketplaces take minutes to show newly delivered files), and on "Check now"
 * from the popup.
 */
const marketplace = marketplaceForUrl(location.href);
const adapter = marketplace && adapterFor(marketplace.code);

if (adapter) {
    const autoRun = () => {
        if (document.visibilityState === 'visible' && dueForAutoRun(adapter.code)) {
            run(adapter, marketplace);
        }
    };

    autoRun();
    // Checked every minute; dueForAutoRun() keeps the passes AUTO_RUN_INTERVAL_MS apart.
    setInterval(autoRun, 60_000);
    document.addEventListener('visibilitychange', autoRun);

    chrome.runtime.onMessage.addListener((message, sender, respond) => {
        if (message?.type !== MESSAGE.RUN) {
            return false;
        }

        run(adapter, marketplace).then(() => respond({ ok: true, data: null }));

        return true;
    });
}
