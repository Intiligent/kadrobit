<script setup>
import SettingsNav from '@/components/settings/SettingsNav.vue';
</script>

<template>
    <SettingsNav />

    <el-scrollbar class="min-w-0 flex-1">
        <main class="mx-auto max-w-[880px] px-10 pb-16 pt-8">
            <slot />
        </main>
    </el-scrollbar>
</template>
