<script setup>
import { computed } from 'vue';
import { ArrowRight } from 'lucide-vue-next';
import { STATUS_TAG, filmFrame, homeImage } from '@/utils/homeDemo';

const props = defineProps({
    tick: { type: Number, required: true },
});

const FRAMES = [
    { key: 'upload', photo: 1043 },
    { key: 'review', photo: 1036 },
    { key: 'send', photo: 1050 },
    { key: 'submit', photo: 1059 },
];

const KEYWORDS = ['winter', 'snow', 'forest', 'trees', 'cold'];

const STOCKS = [
    { name: 'Adobe Stock', state: 'ok' },
    { name: 'Shutterstock', state: 'warning' },
    { name: 'Pond5', state: 'ok' },
];

const frame = computed(() => filmFrame(props.tick));
</script>

<template>
    <section id="how" class="mx-auto max-w-[1200px] scroll-mt-16 px-6 pt-[110px]">
        <h2 v-reveal class="landing-heading max-w-[820px]">
            {{ $t('home.how.title_before') }}<em>{{ $t('home.how.title_accent') }}</em>{{ $t('home.how.title_after') }}
        </h2>

        <div v-reveal class="film">
            <ol class="film__frames">
                <li v-for="(item, index) in FRAMES" :key="item.key" class="film__frame" :class="{ 'is-active': frame.active === index }">
                    <div class="film__picture">
                        <img :src="homeImage(item.photo, 480, 360)" alt="" class="size-full object-cover">

                        <div v-if="item.key === 'upload'" class="film__overlay flex flex-col gap-[5px] px-[9px] py-[7px]">
                            <div class="flex justify-between text-xs font-semibold"><span>IMG_1043.jpeg</span><span class="font-normal">{{ frame.uploadPct }}%</span></div>
                            <el-progress :percentage="frame.uploadPct" :show-text="false" :stroke-width="3" />
                        </div>

                        <div v-else-if="item.key === 'review'" class="absolute inset-x-2.5 bottom-2.5 flex flex-wrap gap-1">
                            <span v-for="word in KEYWORDS.slice(0, frame.keywords)" :key="word" class="film__chip">{{ word }}</span>
                        </div>

                        <div v-else-if="item.key === 'send'" class="film__overlay flex flex-col gap-[3px] px-[9px] py-1.5 text-xs leading-[18px]">
                            <div v-for="stock in STOCKS" :key="stock.name" class="flex items-center gap-[7px]">
                                <span class="film__dot" :class="`is-${STATUS_TAG[stock.state]}`" />
                                <span class="flex-1 font-medium">{{ stock.name }}</span>
                                <span class="text-[var(--el-text-color-secondary)]">{{ $t(`home.demo.status.${stock.state}`) }}</span>
                            </div>
                        </div>

                        <span v-else class="film__submit">{{ $t('home.how.submit_button') }}<ArrowRight :size="14" :stroke-width="2.25" /></span>
                    </div>

                    <div class="flex flex-col gap-1 px-0.5">
                        <div class="flex items-baseline gap-2.5">
                            <span class="text-xs font-semibold text-[var(--el-color-primary)] tabular-nums">0{{ index + 1 }}</span>
                            <span class="text-[17px] font-semibold">{{ $t(`home.how.steps.${item.key}.title`) }}</span>
                        </div>
                        <p class="text-pretty text-sm leading-[21px] text-[var(--el-border-color-darker)]">{{ $t(`home.how.steps.${item.key}.text`) }}</p>
                    </div>
                </li>
            </ol>
        </div>
    </section>
</template>

<style scoped lang="scss">
// A strip of film: dark, with perforation along both edges.
.film {
    position: relative;
    padding: 30px 22px;
    margin-top: 44px;
    color: var(--el-color-white);
    background: var(--el-text-color-primary);
    border-radius: 18px;

    &::before,
    &::after {
        position: absolute;
        right: 22px;
        left: 22px;
        height: 8px;
        content: '';
        background-image: repeating-linear-gradient(90deg, rgb(253 253 252 / 18%) 0 12px, transparent 12px 28px);
    }

    &::before {
        top: 10px;
    }

    &::after {
        bottom: 10px;
    }
}

.film__frames {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    padding: 0;
    margin: 0;
    list-style: none;

    @media (width <= 860px) {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    @media (width <= 520px) {
        grid-template-columns: minmax(0, 1fr);
    }
}

.film__frame {
    display: flex;
    flex-direction: column;
    gap: 12px;
    opacity: 0.5;
    transition: opacity 0.5s;

    &.is-active {
        opacity: 1;
    }
}

.film__picture {
    position: relative;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    background: color-mix(in srgb, var(--el-color-white) 14%, transparent);
    border-radius: 8px;
    outline: 2px solid transparent;
    outline-offset: 2px;
    transition: outline-color 0.5s;

    .is-active & {
        outline-color: var(--el-color-primary);
    }
}

.film__overlay {
    position: absolute;
    right: 10px;
    bottom: 10px;
    left: 10px;
    color: var(--el-text-color-primary);
    background: color-mix(in srgb, var(--el-bg-color) 94%, transparent);
    border-radius: 8px;
}

.film__chip {
    display: flex;
    align-items: center;
    height: 22px;
    padding: 0 8px;
    font-size: 12px;
    color: var(--el-text-color-primary);
    background: color-mix(in srgb, var(--el-bg-color) 94%, transparent);
    border-radius: 11px;
}

.film__dot {
    flex: none;
    width: 6px;
    height: 6px;
    border-radius: 50%;

    &.is-success {
        background: var(--el-color-success);
    }

    &.is-warning {
        background: var(--el-color-warning);
    }
}

.film__submit {
    position: absolute;
    right: 10px;
    bottom: 10px;
    display: flex;
    gap: 6px;
    align-items: center;
    height: 32px;
    padding: 0 14px;
    font-size: 13px;
    font-weight: 600;
    color: var(--el-color-white);
    background: var(--el-text-color-primary);
    border-radius: 999px;
    box-shadow: 0 6px 18px rgb(0 0 0 / 30%);
}
</style>
