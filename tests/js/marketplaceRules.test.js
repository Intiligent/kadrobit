import { describe, expect, it } from 'vitest';
import { categoryFields, checkAsset, countIssues } from '../../resources/js/utils/marketplaceRules';

const account = (code, rules = {}, overrides = {}) => ({
    id: code,
    status: 'active',
    marketplace: {
        code,
        name: code,
        supported_types: ['photo', 'video'],
        metadata_delivery: 'csv',
        metadata_rules: rules,
    },
    ...overrides,
});

const asset = (overrides = {}) => ({
    type: 'photo',
    title: 'Glacier lagoon',
    keywords: ['glacier', 'iceland'],
    categories: {},
    ...overrides,
});

const levels = (result) => result.items.map((item) => `${item.level}:${item.key.split('.').at(-1)}`);

describe('checkAsset: file rules', () => {
    const adobe = (overrides = {}) => account('adobe_stock', { keywords_max: 49 }, {
        marketplace: {
            code: 'adobe_stock',
            name: 'Adobe Stock',
            supported_types: ['photo', 'illustration', 'video'],
            metadata_delivery: 'csv',
            metadata_rules: { keywords_max: 49 },
            file_rules: { photo: { min_megapixels: 4, max_megapixels: 100, max_file_mb: 45 } },
        },
        ...overrides,
    });

    it('rejects a photo below the minimum resolution', () => {
        // IMG_8559.jpeg: 1536 × 2048 px, refused by Adobe Stock as "resolution is too small".
        const result = checkAsset(asset({ width: 1536, height: 2048, size_bytes: 2_385_293 }), adobe());

        expect(result.needsFix).toBe(true);
        expect(levels(result)).toEqual(['error:resolution_min', 'ok:keywords_ok']);
        expect(result.items[0].params).toEqual({ width: 1536, height: 2048, mp: 3.1, min: 4 });
    });

    it('passes a photo at the minimum and shows megapixels rounded down', () => {
        expect(levels(checkAsset(asset({ width: 2000, height: 2000 }), adobe()))).toEqual(['ok:keywords_ok']);

        const justUnder = checkAsset(asset({ width: 1999, height: 2000 }), adobe());
        expect(justUnder.items[0]).toMatchObject({ key: 'library.checks.resolution_min', params: { mp: 3.9 } });
    });

    it('rejects too large photos and files', () => {
        expect(levels(checkAsset(asset({ width: 12000, height: 9000 }), adobe()))[0]).toBe('error:resolution_max');

        const big = checkAsset(asset({ width: 6000, height: 4000, size_bytes: 50 * 1024 * 1024 }), adobe());
        expect(big.items[0]).toMatchObject({ key: 'library.checks.file_too_large', params: { size: 50, max: 45 } });
    });

    it('skips file checks without rules for the type or before the size is known', () => {
        expect(levels(checkAsset(asset({ type: 'video', width: 640, height: 480 }), adobe()))).toEqual(['ok:keywords_ok']);
        expect(levels(checkAsset(asset({ width: null, height: null }), adobe()))).toEqual(['ok:keywords_ok']);
        expect(levels(checkAsset(asset({ width: 640, height: 480 }), account('shutterstock')))).toEqual(['ok:keywords_ok']);
    });
});

describe('checkAsset', () => {
    it('passes when keywords fit the limits', () => {
        const result = checkAsset(asset(), account('adobe_stock', { keywords_max: 49 }));

        expect(result.needsFix).toBe(false);
        expect(levels(result)).toEqual(['ok:keywords_ok']);
        expect(result.items[0]).toMatchObject({ params: { count: 2 }, choice: 2 });
    });

    it('asks for more keywords below the minimum', () => {
        const result = checkAsset(asset(), account('shutterstock', { keywords_min: 7, categories_min: 1, categories_max: 2 }));

        expect(result.needsFix).toBe(true);
        expect(levels(result)).toEqual(['warning:keywords_min', 'warning:categories_min']);
    });

    it('flags too many keywords and accepts the category once chosen', () => {
        const rules = { keywords_max: 2, categories_min: 1, categories_max: 2 };
        const result = checkAsset(asset({ keywords: ['a', 'b', 'c'], categories: { shutterstock: ['Nature'] } }), account('shutterstock', rules));

        expect(levels(result)).toEqual(['warning:keywords_max', 'ok:categories_ok']);
    });

    it('requires a title', () => {
        expect(levels(checkAsset(asset({ title: ' ' }), account('dreamstime')))).toContain('warning:title_required');
    });

    it('reports accounts that cannot publish', () => {
        expect(checkAsset(asset(), account('x', {}, { status: 'invalid_credentials' })).items[0].level).toBe('error');
        expect(checkAsset(asset(), account('x', {}, { status: 'disabled' })).off).toBe(true);
        expect(checkAsset(asset({ type: 'vector' }), account('pond5')).off).toBe(true);
    });

    it('skips keyword rules when metadata is entered on the marketplace', () => {
        const manual = account('alamy');
        manual.marketplace.metadata_delivery = 'manual';

        expect(levels(checkAsset(asset({ keywords: [] }), manual))).toEqual(['info:manual_metadata']);
    });
});

describe('countIssues', () => {
    it('counts accounts that need fixes, not turned-off ones', () => {
        const accounts = [
            account('shutterstock', { keywords_min: 7 }),
            account('adobe_stock', { keywords_max: 49 }),
            account('alamy', {}, { status: 'disabled' }),
        ];

        expect(countIssues(asset(), accounts)).toBe(1);
    });
});

describe('categoryFields', () => {
    it('builds a field per connected marketplace with categories for the asset type', () => {
        const categories = {
            shutterstock: [
                { id: 'Nature', name: 'Nature', media_types: ['photo', 'video'] },
                { id: 'Abstract', name: 'Abstract', media_types: ['photo'] },
            ],
        };
        const fields = categoryFields(asset({ type: 'video' }), [account('shutterstock', { categories_min: 1, categories_max: 2 })], categories);

        expect(fields).toHaveLength(1);
        expect(fields[0]).toMatchObject({ code: 'shutterstock', min: 1, max: 2 });
        expect(fields[0].options.map((o) => o.id)).toEqual(['Nature']);
    });

    it('skips disabled accounts, other types of work and repeated marketplaces', () => {
        const categories = { shutterstock: [{ id: 'Nature', name: 'Nature', media_types: ['photo', 'vector'] }] };
        const accounts = [
            account('shutterstock', {}, { id: 1 }),
            account('shutterstock', {}, { id: 2 }),
            account('shutterstock', {}, { id: 3, status: 'disabled' }),
        ];

        expect(categoryFields(asset(), accounts, categories)).toHaveLength(1);
        expect(categoryFields(asset(), [accounts[2]], categories)).toHaveLength(0);
        // The marketplace in the fixture takes photos and videos only.
        expect(categoryFields(asset({ type: 'vector' }), accounts, categories)).toHaveLength(0);
    });
});
