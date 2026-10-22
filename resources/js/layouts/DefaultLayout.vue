<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { LOGIN_URL, LOGOUT_URL, REGISTER_URL } from '@/config/constant';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <div class="flex min-h-screen flex-col items-center p-6 lg:p-8">
        <header class="mb-6 flex w-full max-w-[335px] items-center justify-between text-sm lg:max-w-4xl">
            <Link href="/" class="font-semibold">{{ page.props.appName }}</Link>

            <nav class="flex items-center gap-4">
                <template v-if="user">
                    <span class="text-[#706f6c] dark:text-[#A1A09A]">{{ user.username }}</span>
                    <Link :href="LOGOUT_URL" method="post" as="button" class="cursor-pointer hover:underline">
                        {{ $t('auth.logout') }}
                    </Link>
                </template>
                <template v-else>
                    <Link :href="LOGIN_URL" class="hover:underline">{{ $t('auth.login.title') }}</Link>
                    <Link :href="REGISTER_URL" class="hover:underline">{{ $t('auth.register.title') }}</Link>
                </template>
            </nav>
        </header>

        <main class="flex w-full max-w-[335px] flex-1 flex-col lg:max-w-4xl lg:justify-center">
            <slot />
        </main>
    </div>
</template>
