import { describe, expect, it } from 'vitest';
import * as actions from '../../../extension/src/background/actions.js';
import { fakeChrome } from './helpers.js';

describe('actions', () => {
    it('keeps the last action of each marketplace with every field filled in', async () => {
        fakeChrome();

        await actions.record('shutterstock', { found: 3, submitted: 1, errors: undefined });
        await actions.record('123rf', { error: 'Sign in to 123RF in this browser.' });

        const all = await actions.all();
        expect(all.shutterstock).toMatchObject({ found: 3, submitted: 1, errors: [], error: null });
        expect(all['123rf']).toMatchObject({ found: 0, submitted: 0, errors: [], error: 'Sign in to 123RF in this browser.' });
        expect(all.shutterstock.at).toBeTypeOf('string');
    });
});
