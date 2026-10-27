<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLogo from '@/components/ui/AppLogo.vue';
import api from '@/core/api';
import { LOCALE_URL, LOGIN_URL, REGISTER_URL } from '@/config/constant';

const page = usePage();
const locale = computed(() => page.props.locale);

const NAV = [
    { key: 'how', href: '#how' },
    { key: 'features', href: '#features' },
    { key: 'checks', href: '#checks' },
    { key: 'marketplaces', href: '#stocks' },
];

const LANGUAGES = [
    { label: 'RU', value: 'ru' },
    { label: 'EN', value: 'en' },
];

// Translations are loaded once per page, so the new language takes effect with a full reload.
async function setLocale(value) {
    await api.post(LOCALE_URL, { locale: value });
    window.location.reload();
}
</script>

<template>
    <div class="landing min-h-screen overflow-x-clip bg-[var(--el-bg-color-page)] text-[15px] leading-6">
        <header class="landing__header">
            <div class="mx-auto flex h-16 max-w-[1200px] items-center gap-7 px-6">
                <Link href="/" class="flex-none"><AppLogo class="h-7" /></Link>

                <nav class="hidden min-w-0 flex-1 gap-6 whitespace-nowrap text-sm font-medium lg:flex">
                    <a v-for="item in NAV" :key="item.key" :href="item.href" class="landing__nav-link">{{ $t(`home.nav.${item.key}`) }}</a>
                </nav>

                <div class="ml-auto flex flex-none items-center gap-2">
                    <el-segmented
                        :model-value="locale"
                        :options="LANGUAGES"
                        size="small"
                        :aria-label="$t('home.nav.language')"
                        @change="setLocale"
                    />
                    <el-button text @click="router.visit(LOGIN_URL)">{{ $t('home.login') }}</el-button>
                    <el-button round class="landing__signup" @click="router.visit(REGISTER_URL)">{{ $t('home.signup') }}</el-button>
                </div>
            </div>
        </header>

        <slot />

        <footer class="border-t border-[var(--el-border-color-light)]">
            <div class="mx-auto flex max-w-[1200px] flex-wrap items-center gap-x-6 gap-y-3 p-6 text-[13px] text-[var(--el-text-color-secondary)]">
                <span class="min-w-[200px] flex-1">© {{ new Date().getFullYear() }} {{ page.props.appName }}</span>
                <span v-for="key in ['terms', 'privacy', 'support']" :key="key">{{ $t(`home.footer.${key}`) }}</span>
            </div>
        </footer>
    </div>
</template>

<style scoped lang="scss">
.landing__header {
    position: sticky;
    top: 0;
    z-index: 10;
    background: color-mix(in srgb, var(--el-bg-color-page) 88%, transparent);
    border-bottom: 1px solid var(--el-border-color-light);
    backdrop-filter: blur(10px);
}

// The page stays light in a dark colour scheme: keep the logo dark too (AppLogo turns light there).
.landing .landing__header :deep(.app-logo) {
    --app-logo-paper: var(--el-bg-color-page);

    color: var(--el-text-color-primary);
}

.landing__nav-link {
    color: var(--el-text-color-secondary);

    &:hover {
        color: var(--el-text-color-primary);
    }
}

// Dark pill: the red is kept for the main call to action of the hero.
.landing__signup {
    --el-button-text-color: var(--el-color-white);
    --el-button-bg-color: var(--el-text-color-primary);
    --el-button-border-color: var(--el-text-color-primary);
    --el-button-hover-text-color: var(--el-color-white);
    --el-button-hover-bg-color: var(--el-color-primary);
    --el-button-hover-border-color: var(--el-color-primary);
}
</style>

<style lang="scss">
// Shared by the home page sections: smooth anchor scrolling, the serif headings and the v-reveal entrance.
html:has(.landing) {
    scroll-behavior: smooth;
}

.landing .landing-heading {
    margin: 0;
    font-family: var(--font-serif);
    font-size: clamp(40px, 5vw, 64px);
    font-weight: 400;
    line-height: 1;
    letter-spacing: -0.01em;
    text-wrap: balance;

    em {
        color: var(--el-color-primary);
    }
}

.landing .reveal {
    transition:
        opacity 0.7s ease,
        transform 0.7s cubic-bezier(0.2, 0.7, 0.2, 1);

    &.is-hidden {
        opacity: 0;
        transform: translateY(28px);
    }
}

@media (prefers-reduced-motion: reduce) {
    html:has(.landing) {
        scroll-behavior: auto;
    }
}
</style>
