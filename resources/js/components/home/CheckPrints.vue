<script setup>
import { ref } from 'vue';
import { homeImage } from '@/utils/homeDemo';

const PRINTS = [
    { code: 'pond5', initials: 'P5', name: 'Pond5', protocol: 'FTP', state: 'ok', photo: 1080, rotate: -2 },
    { code: 'shutterstock', initials: 'SS', name: 'Shutterstock', protocol: 'FTPS', state: 'warning', photo: 1084, rotate: 1.5 },
    { code: 'adobe_stock', initials: 'AS', name: 'Adobe Stock', protocol: 'SFTP', state: 'error', photo: 1074, rotate: -1 },
];

// The stamps land on the prints once the section scrolls into view (v-reveal with a callback).
const stamped = ref(false);
</script>

<template>
    <section id="checks" class="mx-auto max-w-[1200px] scroll-mt-16 px-6 pb-[120px] pt-[110px]">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,420px),1fr))] items-end gap-x-14 gap-y-6">
            <h2 v-reveal class="landing-heading">
                {{ $t('home.checks.title_before') }}<em>{{ $t('home.checks.title_accent') }}</em>{{ $t('home.checks.title_after') }}
            </h2>
            <p v-reveal class="text-pretty text-[17px] leading-[27px]">{{ $t('home.checks.text') }}</p>
        </div>

        <div v-reveal="() => (stamped = true)" class="mt-14 grid grid-cols-[repeat(auto-fit,minmax(min(100%,240px),1fr))] gap-7">
            <figure
                v-for="(print, index) in PRINTS"
                :key="print.code"
                class="print"
                :class="`is-${print.state}`"
                :style="{ '--print-rotate': `${print.rotate}deg`, '--stamp-delay': `${300 + index * 220}ms` }"
            >
                <div class="relative aspect-[4/3] overflow-hidden rounded-sm">
                    <img :src="homeImage(print.photo, 640, 480)" alt="" class="block size-full bg-[var(--el-fill-color)] object-cover">
                    <span class="print__stamp" :class="{ 'is-stamped': stamped }">{{ $t(`home.demo.status.${print.state}`) }}</span>
                </div>
                <figcaption class="flex flex-col gap-1 px-1">
                    <span class="flex items-center gap-2">
                        <span class="print__logo">{{ print.initials }}</span>
                        <span class="font-semibold">{{ print.name }}</span>
                        <span class="text-xs text-[var(--el-text-color-placeholder)]">{{ print.protocol }}</span>
                    </span>
                    <span class="print__reason">{{ $t(`home.checks.reasons.${print.state}`) }}</span>
                </figcaption>
            </figure>
        </div>
    </section>
</template>

<style scoped lang="scss">
// A photo print with a rubber stamp: the outcome of the checks for one stock site.
.print {
    --print-color: var(--el-color-success-dark-2);

    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 12px 12px 18px;
    margin: 0;
    background: var(--el-bg-color);
    border-radius: 4px;
    box-shadow:
        0 1px 2px rgb(27 27 24 / 8%),
        0 16px 40px rgb(27 27 24 / 8%);
    transform: rotate(var(--print-rotate));
    transition: transform 0.3s;

    &:hover {
        transform: translateY(-4px);
    }

    &.is-warning {
        --print-color: var(--el-color-warning-dark-2);
    }

    &.is-error {
        --print-color: var(--el-color-danger);
    }
}

.print__stamp {
    position: absolute;
    top: 14px;
    right: 14px;
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 600;
    color: var(--print-color);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    white-space: nowrap;
    background: color-mix(in srgb, var(--el-bg-color) 90%, transparent);
    border: 2.5px solid var(--print-color);
    border-radius: 6px;
    opacity: 0;
    transform: rotate(-8deg) scale(1.6);
    transition:
        transform 0.45s cubic-bezier(0.2, 1.5, 0.4, 1) var(--stamp-delay),
        opacity 0.25s var(--stamp-delay);

    &.is-stamped {
        opacity: 1;
        transform: rotate(-8deg) scale(1);
    }
}

.print__logo {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    font-size: 8px;
    font-weight: 600;
    background: var(--el-fill-color);
    border-radius: 5px;
}

.print__reason {
    font-size: 14px;
    line-height: 21px;
    color: var(--print-color);
    text-wrap: pretty;

    .is-ok & {
        color: var(--el-text-color-secondary);
    }
}

@media (prefers-reduced-motion: reduce) {
    .print__stamp {
        transition: none;
    }
}
</style>
