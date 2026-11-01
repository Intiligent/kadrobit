import { describe, expect, it } from 'vitest';
import { base64UrlToBytes, pushState } from '@/utils/push';

describe('push', () => {
    it('decodes the VAPID public key from base64url', () => {
        expect([...base64UrlToBytes('AQID_-8')]).toEqual([1, 2, 3, 255, 239]);
    });

    it('says a browser without service workers or the Push API cannot have notifications', async () => {
        expect(await pushState()).toBe('unsupported');
    });
});
