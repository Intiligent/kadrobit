<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import FormField from '@/components/form/FormField.vue';
import { FORGOT_PASSWORD_URL, RESET_PASSWORD_URL } from '@/config/constant';

defineOptions({ layout: AuthLayout });

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(RESET_PASSWORD_URL, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head :title="$t('auth.reset.title')" />

    <h1 class="mb-1 text-2xl font-semibold">{{ $t('auth.reset.title') }}</h1>
    <p class="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $t('auth.reset.subtitle') }}</p>

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormField :label="$t('auth.fields.email')" for="email" :error="form.errors.email">
            <el-input id="email" v-model="form.email" type="email" size="large" name="email" autocomplete="username" />
        </FormField>

        <FormField
            :label="$t('auth.fields.password')"
            for="password"
            :error="form.errors.password"
            :hint="$t('auth.register.password_hint')"
        >
            <el-input
                id="password"
                v-model="form.password"
                type="password"
                size="large"
                name="password"
                autocomplete="new-password"
                show-password
                v-autofocus
            />
        </FormField>

        <FormField
            :label="$t('auth.reset.password_confirmation')"
            for="password_confirmation"
            :error="form.errors.password_confirmation"
        >
            <el-input
                id="password_confirmation"
                v-model="form.password_confirmation"
                type="password"
                size="large"
                name="password_confirmation"
                autocomplete="new-password"
                show-password
            />
        </FormField>

        <el-button type="primary" size="large" native-type="submit" :loading="form.processing" class="w-full">
            {{ $t('auth.reset.submit') }}
        </el-button>
    </form>

    <p class="mt-6 text-center text-sm">
        <Link :href="FORGOT_PASSWORD_URL" class="font-medium text-[var(--el-color-primary)] hover:underline">
            {{ $t('auth.reset.request_new') }}
        </Link>
    </p>
</template>
