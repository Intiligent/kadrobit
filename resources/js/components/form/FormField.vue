<script setup>
defineProps({
    label: { type: String, required: true },
    for: { type: String, required: true },
    error: { type: String, default: null },
    hint: { type: String, default: null },
});
</script>

<template>
    <div>
        <label :for="$props.for" class="mb-1.5 block text-sm font-medium">{{ label }}</label>
        <slot />
        <p v-if="error" class="mt-1.5 text-sm text-[var(--el-color-danger)]">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 text-xs text-[#706f6c] dark:text-[#A1A09A]">{{ hint }}</p>
    </div>
</template>
