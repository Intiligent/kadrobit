import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { useStoredRef } from '../../resources/js/utils/storage';

describe('useStoredRef', () => {
    let store;

    beforeEach(() => {
        store = {};
        vi.stubGlobal('localStorage', {
            getItem: (key) => (key in store ? store[key] : null),
            setItem: (key, value) => { store[key] = String(value); },
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('starts from the default and remembers changes', async () => {
        const size = useStoredRef('library.tile-size', 'large');
        expect(size.value).toBe('large');

        size.value = 'small';
        await nextTick();

        expect(store['library.tile-size']).toBe('"small"');
        expect(useStoredRef('library.tile-size', 'large').value).toBe('small');
    });

    it('ignores stored values that are not allowed or not JSON', () => {
        store['a'] = '"huge"';
        store['b'] = '{broken';

        expect(useStoredRef('a', 'large', { allowed: ['large', 'small'] }).value).toBe('large');
        expect(useStoredRef('b', 'large').value).toBe('large');
    });

    it('falls back to the default when storage is unavailable', async () => {
        vi.stubGlobal('localStorage', {
            getItem: () => { throw new Error('SecurityError'); },
            setItem: () => { throw new Error('SecurityError'); },
        });

        const size = useStoredRef('library.tile-size', 'large');
        size.value = 'small';
        await nextTick();

        expect(size.value).toBe('small');
    });
});
