<script setup>
import { computed } from 'vue';
import { TAGS_MAX } from '@/config/constant';
import { tagValue, tagsFromValues } from '@/utils/tags';

const props = defineProps({
    // The user's tags to choose from: [{ id, name }]
    tags: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});

// Chosen tags: [{ id, name }], id null for a new one, created when saved (App\Services\Assets\AssetTags).
const model = defineModel({ type: Array, default: () => [] });

const values = computed(() => model.value.map(tagValue));

// New tags typed in so far are known too: picking one again does not make a second.
const update = (next) => {
    model.value = tagsFromValues(next, [...props.tags, ...model.value]);
};
</script>

<template>
    <el-select
        :model-value="values"
        multiple
        filterable
        allow-create
        default-first-option
        :reserve-keyword="false"
        :multiple-limit="TAGS_MAX"
        :disabled="disabled"
        :placeholder="$t('library.tags.placeholder')"
        class="w-full"
        @update:model-value="update"
    >
        <el-option v-for="tag in tags" :key="tag.id" :value="tag.id" :label="tag.name" />

        <template #empty>
            <el-empty :image-size="56" :description="$t('library.tags.empty')" class="!py-4" />
        </template>
    </el-select>
</template>
