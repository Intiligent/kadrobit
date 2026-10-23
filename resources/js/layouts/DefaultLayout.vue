<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/components/ui/AppLogo.vue';
import { LOGIN_URL, LOGOUT_URL, REGISTER_URL, VERIFY_EMAIL_URL } from '@/config/constant';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <div class="flex min-h-screen flex-col items-center p-6 lg:p-8">
        <header class="mb-6 flex w-full max-w-[335px] items-center justify-between text-sm lg:max-w-4xl">
            <Link href="/"><AppLogo class="h-6" /></Link>

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

        <div
            v-if="user && !user.email_verified"
            class="mb-6 flex w-full max-w-[335px] flex-wrap items-center justify-between gap-2 rounded-lg bg-[var(--el-color-warning-light-9)] px-4 py-3 text-sm lg:max-w-4xl"
        >
            <span>{{ $t('verification.banner.text') }}</span>
            <Link :href="VERIFY_EMAIL_URL" class="font-medium text-[var(--el-color-primary)] hover:underline">
                {{ $t('verification.banner.action') }}
            </Link>
        </div>

        <main class="flex w-full max-w-[335px] flex-1 flex-col lg:max-w-4xl lg:justify-center">
            <slot />
        </main>
    </div>
</template>
