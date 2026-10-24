<script setup>
import { ref } from 'vue';
import { Lock } from 'lucide-vue-next';

const open = ref(false);

const POINTS = ['key', 'write_only', 'uploads_only', 'removal'];
</script>

<template>
    <div class="flex flex-col gap-2.5 rounded-lg bg-[var(--el-fill-color-light)] px-3.5 py-3">
        <div class="flex items-start gap-2.5">
            <Lock :size="16" :stroke-width="1.75" class="mt-0.5 flex-none" />
            <p class="flex-1 text-pretty">{{ $t('settings.marketplaces.security.summary') }}</p>
            <el-link type="primary" underline="never" class="flex-none !text-xs" @click="open = !open">
                {{ $t(open ? 'settings.marketplaces.security.less' : 'settings.marketplaces.security.more') }}
            </el-link>
        </div>

        <div v-if="open" class="grid grid-cols-2 gap-x-6 gap-y-2.5 pb-0.5 pl-[26px] text-xs leading-[18px] text-[var(--el-text-color-secondary)]">
            <div v-for="point in POINTS" :key="point">
                <p class="font-semibold text-[var(--el-text-color-primary)]">{{ $t(`settings.marketplaces.security.points.${point}.title`) }}</p>
                <p class="text-pretty">{{ $t(`settings.marketplaces.security.points.${point}.text`) }}</p>
            </div>
        </div>
    </div>
</template>
