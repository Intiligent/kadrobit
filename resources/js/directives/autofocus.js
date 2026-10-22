/**
 * v-autofocus: focuses the field when the component mounts. The native `autofocus` attribute
 * works only on a full page load, not after an Inertia visit swaps the page.
 * On a wrapper component (el-input) it focuses the first input inside.
 */
export default {
    mounted(el) {
        const target = el.matches('input, textarea, select') ? el : el.querySelector('input, textarea, select');
        target?.focus();
    },
};
