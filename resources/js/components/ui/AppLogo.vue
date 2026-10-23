<script setup>
import { usePage } from '@inertiajs/vue3';
import source from '../../../../public/images/logo.svg?raw';

const page = usePage();

// Inlined rather than an <img>, so the dark parts follow the colour scheme: the ink becomes
// currentColor and the film frame windows take the page background.
const svg = source
    .replace(/<\?xml[^>]*>\s*/, '')
    .replace(/\s(width|height)="[^"]*"/g, '')
    .replace(/fill="#131b26"/gi, 'fill="currentColor"')
    .replace(/fill="#ffffff"/gi, 'fill="var(--app-logo-paper)"');
</script>

<template>
    <span class="app-logo" role="img" :aria-label="page.props.appName" v-html="svg" />
</template>

<style scoped lang="scss">
.app-logo {
    --app-logo-paper: #fdfdfc;

    display: inline-block;
    color: #131b26;
    line-height: 0;

    :deep(svg) {
        width: auto;
        height: 100%;
    }

    @media (prefers-color-scheme: dark) {
        --app-logo-paper: #0a0a0a;

        color: #ededec;
    }
}
</style>
