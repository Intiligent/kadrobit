<script setup>
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import notify from '@/core/notify';

const page = usePage();

// Session flash messages set by the backend, e.g. after a redirect.
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) {
            notify.success({ message: flash.success });
        }

        if (flash?.error) {
            notify.error({ message: flash.error });
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="flex min-h-screen flex-col items-center p-6 lg:justify-center lg:p-8">
        <main class="w-full max-w-[335px] lg:max-w-4xl">
            <slot />
        </main>
    </div>
</template>
