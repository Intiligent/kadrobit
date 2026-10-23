import { ref, watch } from 'vue';

/**
 * A ref kept in localStorage: a per-browser convenience such as a remembered view option.
 * Storage may be unavailable (private mode, blocked site data); the ref then just starts from
 * the default. Only values in `allowed` are taken from storage, when given.
 */
export function useStoredRef(key, defaultValue, { allowed = null } = {}) {
    const value = ref(read(key, defaultValue, allowed));

    watch(value, (next) => {
        try {
            localStorage.setItem(key, JSON.stringify(next));
        } catch {
            // Not saved this time; the choice still holds for this page.
        }
    });

    return value;
}

function read(key, defaultValue, allowed) {
    try {
        const raw = localStorage.getItem(key);

        if (raw === null) {
            return defaultValue;
        }

        const parsed = JSON.parse(raw);

        return !allowed || allowed.includes(parsed) ? parsed : defaultValue;
    } catch {
        return defaultValue;
    }
}
