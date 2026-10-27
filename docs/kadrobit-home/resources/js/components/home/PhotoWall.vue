<script setup>
import { homeImage } from '@/utils/homeDemo';

// Three columns of an author's portfolio drifting past. Each column repeats its photos twice,
// so the loop restarts on the same picture.
const COLUMNS = [
    { ids: [1015, 1040, 1053, 1080, 1036, 1069], duration: 70 },
    { ids: [1043, 1025, 1059, 1016, 1074, 1047], duration: 56, reverse: true },
    { ids: [1039, 1084, 1022, 1050, 1024, 1044], duration: 84 },
];

const RATIOS = [[4, 5], [1, 1], [3, 4], [16, 11]];

const photos = (ids) => ids.map((id, index) => {
    const [w, h] = RATIOS[index % RATIOS.length];

    return { id, src: homeImage(id, 400, Math.round((400 * h) / w)), ratio: `${w} / ${h}` };
});

const columns = COLUMNS.map((column) => ({ ...column, photos: photos(column.ids) }));
</script>

<template>
    <div class="photo-wall" aria-hidden="true">
        <div v-for="column in columns" :key="column.duration" class="photo-wall__column">
            <div
                class="photo-wall__track"
                :class="{ 'is-reverse': column.reverse }"
                :style="{ animationDuration: `${column.duration}s` }"
            >
                <template v-for="copy in 2" :key="copy">
                    <img
                        v-for="photo in column.photos"
                        :key="`${copy}-${photo.id}`"
                        :src="photo.src"
                        alt=""
                        class="photo-wall__photo"
                        :style="{ aspectRatio: photo.ratio }"
                    >
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped lang="scss">
.photo-wall {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    height: 100%;
}

.photo-wall__column {
    overflow: hidden;
}

.photo-wall__track {
    display: flex;
    flex-direction: column;
    gap: 12px;
    animation: photo-wall-up linear infinite;

    &.is-reverse {
        animation-direction: reverse;
    }
}

.photo-wall__photo {
    display: block;
    width: 100%;
    object-fit: cover;
    background: var(--el-fill-color);
    border-radius: 10px;
}

@keyframes photo-wall-up {
    to {
        transform: translateY(calc(-50% - 6px));
    }
}

@media (prefers-reduced-motion: reduce) {
    .photo-wall__track {
        animation: none;
    }
}
</style>
