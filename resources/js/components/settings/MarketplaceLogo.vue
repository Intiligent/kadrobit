<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    logoUrl: { type: String, default: null },
    size: { type: Number, default: 36 },
});

// "Adobe Stock" → AS, "Pond5" → P5, "123RF" → 12
const initials = computed(() => {
    const words = props.name.replace(/\(.*?\)/g, '').trim().split(/\s+/);

    const [first] = words;

    return (words.length > 1 ? words.map((w) => w[0]).join('') : `${first[0]}${first.slice(1).match(/\d/)?.[0] ?? first[1] ?? ''}`)
        .slice(0, 2)
        .toUpperCase();
});
</script>

<template>
    <!-- contain: some logos are not square (Magnific), cover would crop them. -->
    <el-avatar shape="square" :size="size" :src="logoUrl || undefined" fit="contain" class="marketplace-logo">{{ initials }}</el-avatar>
</template>

<style scoped lang="scss">
.marketplace-logo {
    flex: none;
    font-size: 11px;
    font-weight: 600;
    color: var(--el-text-color-primary);
    background: var(--el-fill-color);
    border-radius: 8px;
}
</style>
