<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ArrowDown } from 'lucide-vue-next';
import LandingLayout from '@/layouts/LandingLayout.vue';
import CheckPrints from '@/components/home/CheckPrints.vue';
import FeatureGrid from '@/components/home/FeatureGrid.vue';
import HeroDemoCard from '@/components/home/HeroDemoCard.vue';
import HowItWorks from '@/components/home/HowItWorks.vue';
import PhotoWall from '@/components/home/PhotoWall.vue';
import StockMarquee from '@/components/home/StockMarquee.vue';
import { useTicker } from '@/composables/useTicker';
import { REGISTER_URL } from '@/config/constant';

defineOptions({ layout: LandingLayout });

defineProps({
    // [{ code, name, protocol }]: marketplaces Kadrobit uploads to (HomeController)
    marketplaces: { type: Array, required: true },
    // Names of marketplaces that take uploads only through their own websites
    unsupported: { type: Array, default: () => [] },
});

// One clock for every demo on the page, so they stay in step (utils/homeDemo.js).
const tick = useTicker();
</script>

<template>
    <Head :title="$t('home.title')">
        <meta name="description" :content="$t('home.description')">
    </Head>

    <section class="mx-auto grid max-w-[1200px] grid-cols-[repeat(auto-fit,minmax(min(100%,480px),1fr))] items-center gap-10 px-6 pt-10">
        <div class="flex flex-col gap-[26px] py-6">
            <h1 class="hero-title">
                {{ $t('home.hero.line1') }}<br>{{ $t('home.hero.line2') }} <em>{{ $t('home.hero.accent') }}</em>
            </h1>
            <p class="max-w-[520px] text-pretty text-lg leading-7">{{ $t('home.hero.text') }}</p>
            <div class="flex flex-wrap items-center gap-2.5">
                <el-button type="primary" size="large" round class="hero-cta" @click="router.visit(REGISTER_URL)">{{ $t('home.signup') }}</el-button>
                <a href="#how" class="flex h-12 items-center gap-2 px-5 font-medium text-[var(--el-text-color-primary)] hover:underline">
                    {{ $t('home.hero.secondary') }}<ArrowDown :size="16" :stroke-width="2" />
                </a>
            </div>
        </div>

        <div class="hero-stage">
            <PhotoWall class="absolute inset-0" />
            <HeroDemoCard :tick="tick" />
        </div>
    </section>

    <StockMarquee :marketplaces="marketplaces" :unsupported="unsupported" />
    <HowItWorks :tick="tick" />
    <FeatureGrid :tick="tick" :stock-count="marketplaces.length" />
    <CheckPrints />
</template>

<style scoped lang="scss">
.hero-title {
    margin: 0;
    font-family: var(--font-serif);
    font-size: clamp(56px, 8vw, 104px);
    font-weight: 400;
    line-height: 0.95;
    letter-spacing: -0.02em;

    em {
        color: var(--el-color-primary);
    }
}

.hero-cta {
    height: 48px;
    padding: 0 24px;
    font-size: 15px;
}

// The portfolio wall fades out at the top and bottom edges.
.hero-stage {
    position: relative;
    height: 640px;
    overflow: hidden;
    border-radius: 20px;
    mask-image: linear-gradient(transparent, #000 12%, #000 88%, transparent);
}
</style>
