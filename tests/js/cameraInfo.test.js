import { describe, expect, it, vi } from 'vitest';

// Translations as their keys with parameters, so the test checks what is put together.
vi.mock('laravel-vue-i18n', () => ({
    trans: (key, params = {}) => (Object.keys(params).length ? `${key}(${Object.values(params).join(',')})` : key),
    getActiveLanguage: () => 'en',
}));

const { cameraRows } = await import('@/utils/cameraInfo');
const { megapixels } = await import('@/utils/format');

describe('cameraRows', () => {
    it('puts the camera summary into rows to show', () => {
        expect(cameraRows({
            make: 'Apple',
            model: 'iPhone 13 Pro',
            lens: 'iPhone 13 Pro back triple camera 5.7mm f/1.5',
            exposure_time: '1/3704',
            f_number: 1.5,
            iso: 50,
            focal_length: 5.7,
            focal_length_35mm: 26,
            exposure_bias: 0,
            exposure_program: 'auto',
            metering: 'pattern',
            flash: false,
            white_balance: 'auto',
            color_space: 'uncalibrated',
            software: '18.5',
        })).toEqual([
            ['camera', 'Apple iPhone 13 Pro'],
            ['lens', 'iPhone 13 Pro back triple camera 5.7mm f/1.5'],
            ['exposure', 'library.camera.shutter(1/3704) · f/1.5 · ISO 50'],
            ['focal_length', 'library.camera.focal(5.7) library.camera.equivalent(26)'],
            ['exposure_bias', 'library.camera.bias(0)'],
            ['mode', 'library.camera.programs.auto · library.camera.metering.pattern'],
            ['flash', 'library.camera.flash_off · library.camera.white_balance.auto'],
            ['color_space', 'library.camera.uncalibrated'],
            ['software', '18.5'],
        ]);
    });

    it('does not repeat the maker that the model already names', () => {
        expect(cameraRows({ make: 'Canon', model: 'Canon EOS R5' })).toEqual([['camera', 'Canon EOS R5']]);
    });

    it('shows a positive exposure bias with its sign and leaves out what is unknown', () => {
        expect(cameraRows({ exposure_bias: 0.67, iso: 800 })).toEqual([['exposure', 'ISO 800'], ['exposure_bias', 'library.camera.bias(+0.67)']]);
        expect(cameraRows(null)).toEqual([]);
    });
});

describe('megapixels', () => {
    it('counts width × height, rounded down to one decimal', () => {
        expect(megapixels(2048, 1536)).toBe(3.1);
        expect(megapixels(4032, 3024)).toBe(12.1);
        expect(megapixels(null, 100)).toBe(null);
    });
});
