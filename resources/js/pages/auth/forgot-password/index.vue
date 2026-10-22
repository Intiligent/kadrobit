<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import FormField from '@/components/form/FormField.vue';
import { FORGOT_PASSWORD_URL, LOGIN_URL } from '@/config/constant';

defineOptions({ layout: AuthLayout });

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(FORGOT_PASSWORD_URL);
};
</script>

<template>
    <Head :title="$t('auth.forgot.title')" />

    <h1 class="mb-1 text-2xl font-semibold">{{ $t('auth.forgot.title') }}</h1>
    <p class="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $t('auth.forgot.subtitle') }}</p>

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormField :label="$t('auth.fields.email')" for="email" :error="form.errors.email">
            <el-input
                id="email"
                v-model="form.email"
                type="email"
                size="large"
                name="email"
                autocomplete="username"
                v-autofocus
            />
        </FormField>

        <el-button type="primary" size="large" native-type="submit" :loading="form.processing" class="w-full">
            {{ $t('auth.forgot.submit') }}
        </el-button>
    </form>

    <p class="mt-6 text-center text-sm">
        <Link :href="LOGIN_URL" class="font-medium text-[var(--el-color-primary)] hover:underline">
            {{ $t('auth.forgot.back_to_login') }}
        </Link>
    </p>
</template>
