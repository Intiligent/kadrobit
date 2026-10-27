import { onBeforeUnmount, onMounted, ref } from 'vue';
import { DEMO_CYCLE, DEMO_FINAL_TICK } from '@/utils/homeDemo';

export const prefersReducedMotion = () => typeof window !== 'undefined'
    && Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);

/**
 * A tick counter for the home page demos: 0…DEMO_CYCLE−1, one step every `interval` ms. Stands on the final
 * frame for users who prefer reduced motion, and pauses while the tab is hidden.
 */
export function useTicker(interval = 100) {
    const tick = ref(0);
    let timer = null;

    const stop = () => {
        clearInterval(timer);
        timer = null;
    };

    const start = () => {
        if (!timer) {
            timer = setInterval(() => {
                tick.value = (tick.value + 1) % DEMO_CYCLE;
            }, interval);
        }
    };

    const onVisibility = () => (document.hidden ? stop() : start());

    onMounted(() => {
        if (prefersReducedMotion()) {
            tick.value = DEMO_FINAL_TICK;

            return;
        }

        start();
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        stop();
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return tick;
}
