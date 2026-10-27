<script setup>
import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';
import { STATUS_TAG, heroDemo, homeImage } from '@/utils/homeDemo';

const props = defineProps({
    // useTicker() of the page
    tick: { type: Number, required: true },
});

const demo = computed(() => heroDemo(props.tick));

const statusLabel = (stock) => (stock.state === 'sending'
    ? trans('home.demo.sending', { pct: stock.pct })
    : trans(`home.demo.status.${stock.state}`));
</script>

<template>
    <div class="hero-card" aria-hidden="true">
        <div class="flex items-center gap-3">
            <img :src="homeImage(1018, 160, 120)" alt="" class="h-12 w-16 flex-none rounded-[7px] bg-[var(--el-fill-color)] object-cover">
            <div class="flex min-w-0 flex-1 flex-col gap-[5px]">
                <div class="flex justify-between gap-2">
                    <span class="font-semibold">IMG_1018.jpeg</span>
                    <span class="whitespace-nowrap text-xs" :class="demo.uploadPct < 100 ? 'text-[var(--el-text-color-secondary)]' : 'text-[var(--el-color-success-dark-2)]'">
                        {{ demo.uploadPct < 100 ? $t('home.demo.uploading', { pct: demo.uploadPct }) : $t('home.demo.uploaded') }}
                    </span>
                </div>
                <el-progress :percentage="demo.uploadPct" :show-text="false" :stroke-width="4" />
            </div>
        </div>

        <div class="flex min-h-[118px] flex-col gap-1.5">
            <p class="min-h-10 font-semibold">
                {{ demo.title }}<span class="hero-card__caret" :class="{ 'is-on': demo.typing && tick % 6 < 3 }" />
            </p>
            <div class="flex flex-wrap gap-1">
                <el-tag v-for="word in demo.keywords" :key="word" type="primary" size="small" round disable-transitions>{{ word }}</el-tag>
            </div>
        </div>

        <div class="flex flex-col gap-1.5 border-t border-[var(--el-border-color-lighter)] pt-2">
            <div v-for="stock in demo.stocks" :key="stock.code" class="flex items-center gap-2">
                <span class="hero-card__logo">{{ stock.initials }}</span>
                <span class="min-w-0 flex-1 font-semibold">{{ stock.name }}</span>
                <el-tag :type="STATUS_TAG[stock.state]" size="small" round disable-transitions>{{ statusLabel(stock) }}</el-tag>
            </div>
        </div>
    </div>
</template>

<style scoped lang="scss">
.hero-card {
    position: absolute;
    top: 50%;
    left: 50%;
    display: flex;
    flex-direction: column;
    gap: 12px;
    width: min(360px, calc(100% - 32px));
    padding: 14px;
    font-size: 13px;
    line-height: 20px;
    background: var(--el-bg-color);
    border-radius: 14px;
    box-shadow:
        0 30px 80px rgb(27 27 24 / 28%),
        0 2px 8px rgb(27 27 24 / 8%);
    transform: translate(-50%, -50%) rotate(-1.5deg);
}

.hero-card__caret {
    display: inline-block;
    width: 1px;
    height: 15px;
    margin-left: 1px;
    vertical-align: -2px;
    background: var(--el-text-color-primary);
    opacity: 0;

    &.is-on {
        opacity: 1;
    }
}

.hero-card__logo {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    font-size: 8px;
    font-weight: 600;
    background: var(--el-fill-color);
    border-radius: 5px;
}
</style>
