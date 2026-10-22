<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head :title="$t('home.title')" />

    <div
        class="rounded-lg bg-white p-6 text-[13px] leading-[20px] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] lg:p-20 lg:pb-10 dark:bg-[#161615] dark:text-[#EDEDEC] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]"
    >
        <h1 class="mb-1 font-medium">{{ page.props.appName }}</h1>
        <p class="text-[#706f6c] dark:text-[#A1A09A]">
            <template v-if="user">{{ $t('home.greeting', { name: user.first_name }) }}</template>
            <template v-else>{{ $t('home.guest') }}</template>
        </p>
    </div>
</template>
