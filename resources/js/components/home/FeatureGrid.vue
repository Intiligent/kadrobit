<script setup>
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { DEMO_KEYWORDS, STATUS_TAG, batchCount, homeImage, routePosition } from '@/utils/homeDemo';

const props = defineProps({
    tick: { type: Number, required: true },
    // Number of stock sites Kadrobit uploads to (the route card)
    stockCount: { type: Number, required: true },
});

const RULES = [
    { code: 'adobe_stock', name: 'Adobe Stock' },
    { code: 'shutterstock', name: 'Shutterstock' },
    { code: 'pond5', name: 'Pond5' },
];

const FAN = [
    { id: 1040, className: 'left-[8%] top-[22px] -rotate-[9deg]' },
    { id: 1047, className: 'left-[24%] top-2 -rotate-2' },
    { id: 1053, className: 'left-[40%] top-5 rotate-[7deg]' },
];

const STATUS_ROWS = [
    { file: 'IMG_0812.jpeg', photo: 1019, states: ['sent', 'sent', 'queued'] },
    { file: 'IMG_0658.jpeg', photo: 1039, states: ['sent', 'outdated', 'sent'] },
    { file: 'DJI_0042.mp4', photo: 1044, states: ['sending', 'sending', 'queued'] },
];

const STATUS_STOCKS = ['AS', 'SS', 'P5'];

const LIBRARY = [1016, 1022, 1024, 1025, 1035, 1080];

const route = computed(() => [
    trans('home.features.route.you'),
    'Kadrobit',
    trans('home.features.route.stocks', { count: props.stockCount }),
]);

const statusLabel = (state) => (state === 'sending' ? trans('home.demo.sending', { pct: 40 }) : trans(`home.demo.status.${state}`));
</script>

<template>
    <section id="features" class="mx-auto max-w-[1200px] scroll-mt-16 px-6 pt-[110px]">
        <h2 v-reveal class="landing-heading max-w-[820px]">
            {{ $t('home.features.title_before') }}<em>{{ $t('home.features.title_accent') }}</em>
        </h2>

        <div class="features">
            <article v-reveal class="feature feature--wide !grid grid-cols-[repeat(auto-fit,minmax(min(100%,260px),1fr))] !p-0">
                <div class="flex flex-col justify-end gap-2.5 p-7">
                    <h3 class="feature__title">{{ $t('home.features.items.ai.title') }}</h3>
                    <p class="feature__text">{{ $t('home.features.items.ai.text') }}</p>
                </div>
                <div class="relative min-h-[260px]">
                    <img :src="homeImage(1015, 640, 480)" alt="" class="absolute inset-0 size-full bg-[var(--el-fill-color)] object-cover">
                    <div class="absolute inset-x-3.5 bottom-3.5 flex flex-wrap gap-1">
                        <span v-for="word in DEMO_KEYWORDS" :key="word" class="feature__chip">{{ word }}</span>
                    </div>
                </div>
            </article>

            <article v-reveal class="feature">
                <div class="flex flex-col gap-1.5">
                    <div v-for="rule in RULES" :key="rule.code" class="flex h-9 items-center gap-2.5 rounded-lg bg-[var(--el-fill-color)] px-3 text-[13px]">
                        <span class="flex-1 font-semibold">{{ rule.name }}</span>
                        <span class="text-[var(--el-text-color-secondary)]">{{ $t(`home.features.rules.${rule.code}`) }}</span>
                    </div>
                </div>
                <div class="mt-auto flex flex-col gap-2">
                    <h3 class="feature__title">{{ $t('home.features.items.rules.title') }}</h3>
                    <p class="feature__text">{{ $t('home.features.items.rules.text') }}</p>
                </div>
            </article>

            <article v-reveal class="feature overflow-hidden">
                <div class="relative h-[170px]" aria-hidden="true">
                    <img v-for="photo in FAN" :key="photo.id" :src="homeImage(photo.id, 320, 240)" alt="" class="feature__print" :class="photo.className">
                    <span class="feature__counter">{{ batchCount(tick) }}</span>
                </div>
                <div class="mt-auto flex flex-col gap-2">
                    <h3 class="feature__title">{{ $t('home.features.items.batches.title') }}</h3>
                    <p class="feature__text">{{ $t('home.features.items.batches.text') }}</p>
                </div>
            </article>

            <article v-reveal class="feature feature--wide !grid grid-cols-[repeat(auto-fit,minmax(min(100%,260px),1fr))] items-end !gap-6">
                <div class="flex flex-col gap-2">
                    <h3 class="feature__title">{{ $t('home.features.items.status.title') }}</h3>
                    <p class="feature__text">{{ $t('home.features.items.status.text') }}</p>
                </div>
                <div class="overflow-hidden rounded-xl border border-[var(--el-border-color-light)] text-[13px] leading-5" aria-hidden="true">
                    <div v-for="row in STATUS_ROWS" :key="row.file" class="flex items-center gap-2.5 border-t border-[var(--el-border-color-lighter)] px-3 py-2.5 first:border-t-0">
                        <img :src="homeImage(row.photo, 96, 96)" alt="" class="size-9 flex-none rounded-md bg-[var(--el-fill-color)] object-cover">
                        <span class="min-w-0 flex-1 truncate font-semibold">{{ row.file }}</span>
                        <div class="flex flex-wrap justify-end gap-1">
                            <el-tag v-for="(state, index) in row.states" :key="index" :type="STATUS_TAG[state]" size="small" round disable-transitions>
                                {{ STATUS_STOCKS[index] }} · {{ statusLabel(state) }}
                            </el-tag>
                        </div>
                    </div>
                </div>
            </article>

            <article v-reveal class="feature">
                <div class="grid grid-cols-3 gap-1 overflow-hidden rounded-[10px]" aria-hidden="true">
                    <img v-for="id in LIBRARY" :key="id" :src="homeImage(id, 200, 200)" alt="" class="block aspect-square w-full bg-[var(--el-fill-color)] object-cover">
                </div>
                <div class="flex flex-col gap-2">
                    <h3 class="feature__title">{{ $t('home.features.items.library.title') }}</h3>
                    <p class="feature__text">{{ $t('home.features.items.library.text') }}</p>
                </div>
            </article>

            <article v-reveal class="feature feature--wide feature--accent justify-between !gap-7">
                <div class="relative flex h-14 items-center justify-between" aria-hidden="true">
                    <span class="feature__route-line" />
                    <span class="feature__route-dot" :style="{ left: `${routePosition(tick)}%` }" />
                    <span v-for="node in route" :key="node" class="feature__route-node">{{ node }}</span>
                </div>
                <div class="flex max-w-[560px] flex-col gap-2">
                    <h3 class="feature__title">{{ $t('home.features.items.server.title') }}</h3>
                    <p class="feature__text !text-[var(--el-color-primary-light-8)]">{{ $t('home.features.items.server.text') }}</p>
                </div>
            </article>
        </div>
    </section>
</template>

<style scoped lang="scss">
.features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
    grid-auto-flow: dense;
    gap: 14px;
    margin-top: 44px;
}

.feature {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 28px;
    overflow: hidden;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-light);
    border-radius: 18px;
}

.feature--wide {
    @media (width >= 900px) {
        grid-column: span 2;
    }
}

.feature--accent {
    color: var(--el-color-white);
    background: var(--el-color-primary);
    border-color: var(--el-color-primary);
}

.feature__title {
    margin: 0;
    font-size: 22px;
    font-weight: 600;
    line-height: 28px;
}

.feature__text {
    margin: 0;
    color: var(--el-text-color-secondary);
    text-wrap: pretty;
}

.feature__chip {
    display: flex;
    align-items: center;
    height: 24px;
    padding: 0 9px;
    font-size: 12px;
    background: color-mix(in srgb, var(--el-bg-color) 94%, transparent);
    border-radius: 12px;
}

.feature__print {
    position: absolute;
    width: 48%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    background: var(--el-fill-color);
    border: 4px solid var(--el-bg-color);
    border-radius: 8px;
    box-shadow: 0 8px 20px rgb(27 27 24 / 18%);
}

.feature__counter {
    position: absolute;
    top: 0;
    right: 4%;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    font-size: 14px;
    font-weight: 600;
    color: var(--el-color-white);
    background: var(--el-color-primary);
    border-radius: 17px;
    box-shadow: 0 6px 16px color-mix(in srgb, var(--el-color-primary) 35%, transparent);
}

.feature__route-line {
    position: absolute;
    top: 50%;
    right: 28px;
    left: 28px;
    height: 2px;
    background: repeating-linear-gradient(90deg, rgb(255 255 255 / 55%) 0 6px, transparent 6px 12px);
}

.feature__route-dot {
    position: absolute;
    top: 50%;
    width: 10px;
    height: 10px;
    margin: -5px 0 0 -5px;
    background: var(--el-color-white);
    border-radius: 50%;
    box-shadow: 0 0 0 5px rgb(255 255 255 / 25%);
}

.feature__route-node {
    position: relative;
    display: flex;
    align-items: center;
    height: 40px;
    padding: 0 16px;
    font-weight: 600;
    color: var(--el-text-color-primary);
    white-space: nowrap;
    background: var(--el-bg-color);
    border-radius: 20px;
}
</style>
