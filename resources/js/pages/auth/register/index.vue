<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import FormField from '@/components/form/FormField.vue';
import { LOGIN_URL, REGISTER_URL } from '@/config/constant';

defineOptions({ layout: AuthLayout });

const form = useForm({
    first_name: '',
    last_name: '',
    username: '',
    email: '',
    password: '',
});

const submit = () => {
    form.post(REGISTER_URL, {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head :title="$t('auth.register.title')" />

    <h1 class="mb-1 text-2xl font-semibold">{{ $t('auth.register.title') }}</h1>
    <p class="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $t('auth.register.subtitle') }}</p>

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <FormField :label="$t('auth.fields.first_name')" for="first_name" :error="form.errors.first_name">
                <el-input id="first_name" v-model="form.first_name" size="large" name="first_name" autocomplete="given-name" autofocus />
            </FormField>

            <FormField :label="$t('auth.fields.last_name')" for="last_name" :error="form.errors.last_name">
                <el-input id="last_name" v-model="form.last_name" size="large" name="last_name" autocomplete="family-name" />
            </FormField>
        </div>

        <!--
            The login is the email; it uses autocomplete="email", as on the login page, so browsers offer
            saved addresses. The public handle avoids "username" in id/name: browsers and password managers
            would take it for the login and fill in the email.
        -->
        <FormField
            :label="$t('auth.fields.username')"
            for="handle"
            :error="form.errors.username"
            :hint="$t('auth.register.username_hint')"
        >
            <el-input id="handle" v-model="form.username" size="large" name="handle" autocomplete="nickname" />
        </FormField>

        <FormField :label="$t('auth.fields.email')" for="email" :error="form.errors.email">
            <el-input id="email" v-model="form.email" type="email" size="large" name="email" autocomplete="email" />
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
            />
        </FormField>

        <el-button type="primary" size="large" native-type="submit" :loading="form.processing" class="w-full">
            {{ $t('auth.register.submit') }}
        </el-button>
    </form>

    <p class="mt-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
        {{ $t('auth.register.has_account') }}
        <Link :href="LOGIN_URL" class="font-medium text-[var(--el-color-primary)] hover:underline">
            {{ $t('auth.register.login_link') }}
        </Link>
    </p>
</template>
