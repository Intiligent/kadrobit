<script setup>
import { computed } from 'vue';
import { getActiveLanguage, trans } from 'laravel-vue-i18n';

const props = defineProps({
    // [{ code, name, protocol }] from HomeController
    marketplaces: { type: Array, required: true },
    // Names of web-only marketplaces
    unsupported: { type: Array, default: () => [] },
});

const unsupportedText = computed(() => (props.unsupported.length
    ? trans('home.stocks.unsupported', {
        names: new Intl.ListFormat(getActiveLanguage(), { type: 'conjunction' }).format(props.unsupported),
    })
    : ''));
</script>

<template>
    <section id="stocks" class="mt-14 scroll-mt-16">
        <div class="stock-marquee">
            <span class="flex-none whitespace-nowrap pl-6 text-[13px] text-[var(--el-text-color-placeholder)]">{{ $t('home.stocks.lead') }}</span>
            <div class="stock-marquee__viewport">
                <ul class="stock-marquee__track">
                    <template v-for="copy in 2" :key="copy">
                        <li v-for="marketplace in marketplaces" :key="`${copy}-${marketplace.code}`" class="stock-marquee__item" :aria-hidden="copy === 2">
                            <span class="font-serif text-[32px] leading-10">{{ marketplace.name }}</span>
                            <span class="text-[11px] font-semibold uppercase tracking-[0.06em] text-[var(--el-text-color-placeholder)]">{{ marketplace.protocol }}</span>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
        <p v-if="unsupportedText" class="mx-auto mt-3 max-w-[1200px] px-6 text-[13px] text-[var(--el-text-color-secondary)]">{{ unsupportedText }}</p>
    </section>
</template>

<style scoped lang="scss">
.stock-marquee {
    display: flex;
    gap: 28px;
    align-items: center;
    padding: 22px 0;
    overflow: hidden;
    color: var(--el-color-white);
    background: var(--el-text-color-primary);
}

.stock-marquee__viewport {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    mask-image: linear-gradient(90deg, transparent, #000 6%, #000 94%, transparent);
}

.stock-marquee__track {
    display: flex;
    gap: 56px;
    width: max-content;
    padding: 0;
    margin: 0;
    list-style: none;
    animation: stock-marquee-left 46s linear infinite;
}

.stock-marquee__item {
    display: flex;
    gap: 10px;
    align-items: baseline;
    white-space: nowrap;
}

@keyframes stock-marquee-left {
    to {
        transform: translateX(calc(-50% - 28px));
    }
}

// Without motion the ribbon wraps into lines instead of scrolling.
@media (prefers-reduced-motion: reduce) {
    .stock-marquee__track {
        flex-wrap: wrap;
        width: auto;
        gap: 8px 40px;
        animation: none;

        li[aria-hidden='true'] {
            display: none;
        }
    }
}
</style>
