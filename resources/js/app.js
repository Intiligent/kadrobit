import { createInertiaApp, router } from '@inertiajs/vue3';
import { i18nVue } from 'laravel-vue-i18n';
import DefaultLayout from '@/layouts/DefaultLayout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Kadrobit';

// After a deploy, a tab still running the previous build references chunk URLs that now 404.
// Hard-load the page being visited so the first click lands on it with fresh assets.
let pendingVisitUrl = null;

router.on('before', (event) => {
    pendingVisitUrl = event.detail.visit.url;
});

window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();
    window.location.href = pendingVisitUrl ? pendingVisitUrl.href : window.location.href;
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),

    layout: () => DefaultLayout,

    withApp(app, { page }) {
        app.use(i18nVue, {
            lang: page.props.locale ?? 'en',
            fallbackLang: 'en',
            resolve: async (lang) => {
                const langs = import.meta.glob('../../lang/*.json');
                return await langs[`../../lang/php_${lang}.json`]();
            },
        });
    },

    progress: {
        color: '#f53003',
        delay: 250,
    },
});
