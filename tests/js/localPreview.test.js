import { describe, expect, it } from 'vitest';
import { createLimiter, fitSize, frameTime, LOCAL_PREVIEW_SIZE, previewKind } from '../../resources/js/utils/localPreview';

describe('fitSize', () => {
    it('brings the short side to the preview size', () => {
        expect(fitSize(6000, 4000)).toEqual({ width: 168, height: LOCAL_PREVIEW_SIZE });
        expect(fitSize(1080, 1920)).toEqual({ width: LOCAL_PREVIEW_SIZE, height: 199 });
    });

    it('never enlarges a small source', () => {
        expect(fitSize(80, 40)).toEqual({ width: 80, height: 40 });
    });
});

describe('frameTime', () => {
    it('takes a frame a little into the clip, at most a second', () => {
        expect(frameTime(4)).toBeCloseTo(0.4);
        expect(frameTime(120)).toBe(1);
    });

    it('falls back to the start when the length is unknown', () => {
        expect(frameTime(NaN)).toBe(0);
        expect(frameTime(Infinity)).toBe(0);
        expect(frameTime(0)).toBe(0);
    });
});

describe('previewKind', () => {
    it('takes what browsers decode and leaves the rest to the server', () => {
        expect(previewKind({ type: 'image/jpeg', name: 'a.jpg' })).toBe('image');
        expect(previewKind({ type: 'image/svg+xml', name: 'a.svg' })).toBe('image');
        expect(previewKind({ type: 'video/quicktime', name: 'a.mov' })).toBe('video');
        expect(previewKind({ type: 'image/tiff', name: 'a.tif' })).toBeNull();
        expect(previewKind({ type: 'application/postscript', name: 'a.eps' })).toBeNull();
    });

    it('goes by the extension when the browser does not tell the type', () => {
        expect(previewKind({ type: 'application/octet-stream', name: 'IMG_3178.MOV' })).toBe('video');
        expect(previewKind({ type: '', name: 'clip.mp4' })).toBe('video');
        expect(previewKind({ type: '', name: 'photo.JPG' })).toBe('image');
        expect(previewKind({ type: 'application/octet-stream', name: 'logo.eps' })).toBeNull();
    });
});

describe('createLimiter', () => {
    it('runs at most the given number of tasks at once, in order', async () => {
        const limit = createLimiter(2);
        const started = [];
        const finish = {};
        const task = (name) => () => new Promise((resolve) => {
            started.push(name);
            finish[name] = () => resolve(name);
        });

        const results = ['a', 'b', 'c'].map((name) => limit(task(name)));
        await Promise.resolve();
        await Promise.resolve();

        expect(started).toEqual(['a', 'b']);

        finish.a();
        await results[0];
        await new Promise((resolve) => setImmediate(resolve));

        expect(started).toEqual(['a', 'b', 'c']);

        finish.b();
        finish.c();
        expect(await Promise.all(results)).toEqual(['a', 'b', 'c']);
    });

    it('keeps going after a task fails', async () => {
        const limit = createLimiter(1);

        await expect(limit(() => Promise.reject(new Error('broken')))).rejects.toThrow('broken');
        expect(await limit(() => 'next')).toBe('next');
    });
});
