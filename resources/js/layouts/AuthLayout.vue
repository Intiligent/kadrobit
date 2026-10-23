<script setup>
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/ui/AppLogo.vue';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center p-6">
        <div class="w-full max-w-[400px]">
            <Link href="/" class="mb-8 inline-block">
                <AppLogo class="h-10" />
            </Link>

            <div
                class="rounded-xl bg-white p-6 shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] lg:p-8 dark:bg-[#161615] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]"
            >
                <slot />
            </div>
        </div>
    </div>
</template>
