<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import FormField from '@/components/form/FormField.vue';
import { LOGIN_URL, REGISTER_URL } from '@/config/constant';

defineOptions({ layout: AuthLayout });

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(LOGIN_URL, {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head :title="$t('auth.login.title')" />

    <h1 class="mb-1 text-2xl font-semibold">{{ $t('auth.login.title') }}</h1>
    <p class="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $t('auth.login.subtitle') }}</p>

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormField :label="$t('auth.fields.email')" for="email" :error="form.errors.email">
            <el-input
                id="email"
                v-model="form.email"
                type="email"
                size="large"
                name="email"
                autocomplete="username"
                autofocus
            />
        </FormField>

        <FormField :label="$t('auth.fields.password')" for="password" :error="form.errors.password">
            <el-input
                id="password"
                v-model="form.password"
                type="password"
                size="large"
                name="password"
                autocomplete="current-password"
                show-password
            />
        </FormField>

        <el-checkbox v-model="form.remember" :label="$t('auth.login.remember')" />

        <el-button type="primary" size="large" native-type="submit" :loading="form.processing" class="w-full">
            {{ $t('auth.login.submit') }}
        </el-button>
    </form>

    <p class="mt-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
        {{ $t('auth.login.no_account') }}
        <Link :href="REGISTER_URL" class="font-medium text-[var(--el-color-primary)] hover:underline">
            {{ $t('auth.login.register_link') }}
        </Link>
    </p>
</template>
