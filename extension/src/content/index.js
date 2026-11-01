import { adapterFor } from '../adapters/index.js';
import { MESSAGE } from '../core/messages.js';
import { marketplaceForUrl } from '../marketplaces.js';
import { dueForAutoRun, run } from './runner.js';

/**
 * The content script on stock sites: runs the marketplace's adapter when the page opens, and again on
 * "Check now" from the popup.
 */
const marketplace = marketplaceForUrl(location.href);
const adapter = marketplace && adapterFor(marketplace.code);

if (adapter) {
    if (dueForAutoRun(adapter.code)) {
        run(adapter, marketplace);
    }

    chrome.runtime.onMessage.addListener((message, sender, respond) => {
        if (message?.type !== MESSAGE.RUN) {
            return false;
        }

        run(adapter, marketplace).then(() => respond({ ok: true, data: null }));

        return true;
    });
}
