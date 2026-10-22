<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import FormField from '@/components/form/FormField.vue';
import { LOGOUT_URL, VERIFY_EMAIL_URL } from '@/config/constant';

defineOptions({ layout: AuthLayout });

const props = defineProps({
    email: { type: String, required: true },
    cooldown: { type: Number, default: 0 },
    codeLength: { type: Number, default: 6 },
});

const form = useForm({ code: '' });
const resendForm = useForm({});
const emailForm = useForm({ email: props.email });

const editingEmail = ref(false);
const secondsLeft = ref(0);
let timer = null;

function startCountdown(seconds) {
    clearInterval(timer);
    secondsLeft.value = seconds;

    if (seconds > 0) {
        timer = setInterval(() => {
            secondsLeft.value -= 1;

            if (secondsLeft.value <= 0) {
                clearInterval(timer);
            }
        }, 1000);
    }
}

startCountdown(props.cooldown);

// A resend or an address change returns the page with a fresh cooldown.
watch(() => props.cooldown, startCountdown);

onBeforeUnmount(() => clearInterval(timer));

function submit() {
    if (form.code.length !== props.codeLength || form.processing) {
        return;
    }

    form.post(VERIFY_EMAIL_URL, {
        preserveScroll: true,
        onError: () => form.reset('code'),
    });
}

// Digits only, whether typed, pasted or filled in by the OS from the email; sent once complete.
function onCodeInput(value) {
    form.code = String(value).replace(/\D/g, '').slice(0, props.codeLength);
    form.clearErrors('code');

    if (form.code.length === props.codeLength) {
        submit();
    }
}

function resend() {
    resendForm.post(`${VERIFY_EMAIL_URL}/resend`, { preserveScroll: true });
}

function startEditingEmail() {
    emailForm.email = props.email;
    emailForm.clearErrors();
    editingEmail.value = true;
}

function saveEmail() {
    emailForm.put(`${VERIFY_EMAIL_URL}/email`, {
        preserveScroll: true,
        onSuccess: () => {
            editingEmail.value = false;
            form.reset('code');
        },
    });
}
</script>

<template>
    <Head :title="$t('verification.title')" />

    <h1 class="mb-1 text-2xl font-semibold">{{ $t('verification.title') }}</h1>
    <p class="mb-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $t('verification.subtitle') }}</p>

    <p class="mb-5 text-sm">{{ $t('verification.sent_to', { length: codeLength, email }) }}</p>

    <form class="space-y-5" novalidate @submit.prevent="submit">
        <FormField
            :label="$t('verification.code_label')"
            for="code"
            :error="form.errors.code || resendForm.errors.code"
        >
            <el-input
                id="code"
                :model-value="form.code"
                size="large"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                :maxlength="codeLength"
                class="verify-code-input"
                v-autofocus
                @update:model-value="onCodeInput"
            />
        </FormField>

        <el-button
            type="primary"
            size="large"
            native-type="submit"
            :loading="form.processing"
            :disabled="form.code.length !== codeLength"
            class="w-full"
        >
            {{ $t('verification.submit') }}
        </el-button>
    </form>

    <p class="mt-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ $t('verification.spam_hint') }}</p>

    <el-button
        class="mt-4 !px-0"
        text
        type="primary"
        :loading="resendForm.processing"
        :disabled="secondsLeft > 0"
        @click="resend"
    >
        {{ secondsLeft > 0 ? $t('verification.resend_in', { seconds: secondsLeft }) : $t('verification.resend') }}
    </el-button>

    <div class="mt-6 border-t border-[#e3e3e0] pt-6 dark:border-[#3E3E3A]">
        <el-button v-if="!editingEmail" class="!px-0" text @click="startEditingEmail">
            {{ $t('verification.change_email') }}
        </el-button>

        <form v-else class="space-y-3" novalidate @submit.prevent="saveEmail">
            <FormField :label="$t('auth.fields.email')" for="new-email" :error="emailForm.errors.email">
                <el-input id="new-email" v-model="emailForm.email" type="email" size="large" name="email" autocomplete="email" v-autofocus />
            </FormField>

            <div class="flex gap-2">
                <el-button type="primary" native-type="submit" :loading="emailForm.processing">
                    {{ $t('verification.change_email_save') }}
                </el-button>
                <el-button @click="editingEmail = false">{{ $t('verification.change_email_cancel') }}</el-button>
            </div>
        </form>
    </div>

    <p class="mt-6 text-center text-sm">
        <Link :href="LOGOUT_URL" method="post" as="button" class="cursor-pointer text-[#706f6c] hover:underline dark:text-[#A1A09A]">
            {{ $t('auth.logout') }}
        </Link>
    </p>
</template>

<style scoped lang="scss">
// Wide, spaced digits, like the code in the email.
.verify-code-input :deep(.el-input__inner) {
    font-size: 24px;
    letter-spacing: 8px;
    text-align: center;
    font-variant-numeric: tabular-nums;
}
</style>
