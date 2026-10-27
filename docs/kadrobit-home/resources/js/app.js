import { createInertiaApp, router } from '@inertiajs/vue3';
import { i18nVue } from 'laravel-vue-i18n';
import { provideGlobalConfig } from 'element-plus';
import elementRu from 'element-plus/es/locale/lang/ru';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import notify from '@/core/notify';
import autofocus from '@/directives/autofocus';
import reveal from '@/directives/reveal';
import { http, setTokenRefresher } from '@/core/api';
import { API_TOKEN_REFRESH_URL } from '@/config/constant';

const appName = import.meta.env.VITE_APP_NAME || 'Kadrobit';

// Texts built into Element Plus (empty lists, pagination, dialog buttons); English is its own default.
const ELEMENT_LOCALES = { ru: elementRu };

// After a deploy, a tab still running the previous build references chunk URLs that now 404.
// Hard-load the page being visited so the user lands on it with fresh assets. Only GET visits are
// repeated this way; a chunk still missing after one reload is left to fail instead of looping.
const RELOAD_KEY = 'kadrobit:chunk-reload';
let pendingVisitUrl = null;

router.on('start', (event) => {
    const { visit } = event.detail;
    pendingVisitUrl = visit.method === 'get' ? visit.url.href : null;
});

router.on('finish', () => {
    pendingVisitUrl = null;
});

router.on('navigate', () => {
    sessionStorageSafe('removeItem', RELOAD_KEY);
});

window.addEventListener('vite:preloadError', (event) => {
    const target = pendingVisitUrl ?? window.location.href;

    if (sessionStorageSafe('getItem', RELOAD_KEY) === target) {
        return;
    }

    event.preventDefault();
    sessionStorageSafe('setItem', RELOAD_KEY, target);
    window.location.href = target;
});

function sessionStorageSafe(method, ...args) {
    try {
        return window.sessionStorage[method](...args);
    } catch {
        return null;
    }
}

// One-time messages from Inertia::flash(). Not kept in browser history, so Back does not repeat them;
// the event also fires on the initial page load.
router.on('flash', (event) => {
    const { success, error } = event.detail.flash;

    if (success) {
        notify.success({ message: success });
    }

    if (error) {
        notify.error({ message: error });
    }
});

// A 401 from /api/* while the web session is alive means the API token expired or was revoked:
// get a new token cookie and let the API client retry. Fails with 401 when the session is gone too.
setTokenRefresher(() => http.post(API_TOKEN_REFRESH_URL, null, { silent: true, untracked: true, authRetried: true })
    .then(() => true, () => false));

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),

    layout: () => DefaultLayout,

    withApp(app, { page }) {
        app.directive('autofocus', autofocus);
        app.directive('reveal', reveal);

        // The language is fixed for the page's lifetime: changing it in Settings → General reloads the page.
        const locale = page.props.locale ?? 'en';

        if (ELEMENT_LOCALES[locale]) {
            provideGlobalConfig({ locale: ELEMENT_LOCALES[locale] }, app, true);
        }

        app.use(i18nVue, {
            lang: locale,
            fallbackLang: 'en',
            // Called with "en" for lang/en.json and "php_en" for the compiled lang/en/*.php.
            resolve: async (lang) => {
                const langs = import.meta.glob('../../lang/*.json');
                return (await langs[`../../lang/${lang}.json`]?.()) ?? { default: {} };
            },
        });
    },

    progress: {
        color: '#f53003',
        delay: 250,
    },
});
