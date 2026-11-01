import { describe, expect, it } from 'vitest';
import { challenge, randomString } from '../../../extension/src/core/pkce.js';
import { ShapeError, expectShape } from '../../../extension/src/core/shape.js';
import { marketplaceForUrl } from '../../../extension/src/marketplaces.js';

describe('marketplaceForUrl', () => {
    it('finds the marketplace of a supported site', () => {
        expect(marketplaceForUrl('https://submit.shutterstock.com/portfolio').code).toBe('shutterstock');
        expect(marketplaceForUrl('https://www.123rf.com/contributor/').code).toBe('123rf');
        expect(marketplaceForUrl('https://contributor.stock.adobe.com/en/uploads').code).toBe('adobe_stock');
    });

    it('ignores other sites, other schemes and lookalike hosts', () => {
        expect(marketplaceForUrl('https://www.shutterstock.com/')).toBeNull();
        expect(marketplaceForUrl('http://submit.shutterstock.com/')).toBeNull();
        expect(marketplaceForUrl('https://submit.shutterstock.com.evil.test/')).toBeNull();
        expect(marketplaceForUrl('chrome://extensions')).toBeNull();
        expect(marketplaceForUrl(undefined)).toBeNull();
    });
});

describe('pkce', () => {
    it('makes the S256 challenge of RFC 7636', async () => {
        expect(await challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')).toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
    });

    it('makes url-safe random verifiers long enough for PKCE', () => {
        const verifier = randomString(48);

        expect(verifier).toMatch(/^[A-Za-z0-9_-]{43,128}$/);
        expect(randomString(48)).not.toBe(verifier);
    });
});

describe('expectShape', () => {
    const schema = { data: [{ id: 'string', uploadedFilename: 'string', 'categories?': ['string'] }], total: 'number' };

    it('passes an answer of the expected shape through', () => {
        const answer = { data: [{ id: 'U1', uploadedFilename: 'a.jpg', categories: ['9'] }, { id: 'U2', uploadedFilename: 'b.jpg', categories: null }], total: 2, extra: true };

        expect(expectShape(answer, schema, 'media/list')).toBe(answer);
    });

    it('names the source and the place that changed', () => {
        const answer = { data: [{ id: 'U1', uploadedFilename: 'a.jpg' }, { id: 7, uploadedFilename: 'b.jpg' }], total: 2 };

        expect(() => expectShape(answer, schema, 'media/list')).toThrow(ShapeError);
        expect(() => expectShape(answer, schema, 'media/list')).toThrow('The site changed: media/list has no string at data[1].id');
    });

    it('rejects a missing list, object or required field', () => {
        expect(() => expectShape({ total: 1 }, schema, 'x')).toThrow('has no list at data');
        expect(() => expectShape(null, schema, 'x')).toThrow('has no object at the top');
        expect(() => expectShape({ data: [], total: null }, schema, 'x')).toThrow('has no number at total');
    });
});
