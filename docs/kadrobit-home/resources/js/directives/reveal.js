/**
 * v-reveal: the element rises into place the first time it scrolls into view. Elements already on screen
 * when the page opens, and every element for users who prefer reduced motion, are shown as they are.
 * v-reveal="callback" calls the callback instead of animating (the stamps of the checks section).
 */
const observed = new WeakMap();

function show(el) {
    const callback = observed.get(el);

    if (typeof callback === 'function') {
        callback();
    } else {
        el.classList.remove('is-hidden');
    }
}

let observer = null;

function getObserver() {
    observer ??= new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                show(entry.target);
                observer.unobserve(entry.target);
            }
        }
    }, { threshold: 0.15 });

    return observer;
}

export default {
    mounted(el, binding) {
        observed.set(el, binding.value);

        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

        if (reduced || !('IntersectionObserver' in window) || el.getBoundingClientRect().top < window.innerHeight) {
            show(el);

            return;
        }

        if (typeof binding.value !== 'function') {
            el.classList.add('reveal', 'is-hidden');
        }

        getObserver().observe(el);
    },

    unmounted(el) {
        observer?.unobserve(el);
        observed.delete(el);
    },
};
