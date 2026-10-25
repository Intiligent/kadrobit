import { getActiveLanguage, trans } from 'laravel-vue-i18n';
import { formatNumber } from '@/utils/format';

/**
 * Rows of the "Camera" section of the file info (AssetResource.camera, CameraInfoReader): [key, value] with
 * the value ready to show, rows without data left out. GPS is never there.
 */
export function cameraRows(camera) {
    if (!camera) {
        return [];
    }

    const { make, model } = camera;
    // "Canon" + "Canon EOS R5" reads as "Canon EOS R5"; "Apple" + "iPhone 13 Pro" as "Apple iPhone 13 Pro".
    const name = make && model && !model.toLowerCase().startsWith(make.toLowerCase()) ? `${make} ${model}` : model || make;

    const locale = getActiveLanguage();
    const exposure = [
        camera.exposure_time && trans('library.camera.shutter', { value: shutter(camera.exposure_time, locale) }),
        camera.f_number && `f/${camera.f_number}`,
        camera.iso && `ISO ${camera.iso}`,
    ].filter(Boolean).join(' · ');

    const focal = camera.focal_length
        ? [
            trans('library.camera.focal', { mm: formatNumber(camera.focal_length, locale) }),
            camera.focal_length_35mm && trans('library.camera.equivalent', { mm: camera.focal_length_35mm }),
        ].filter(Boolean).join(' ')
        : null;

    const bias = camera.exposure_bias === undefined ? null : trans('library.camera.bias', { value: `${camera.exposure_bias > 0 ? '+' : ''}${formatNumber(camera.exposure_bias, locale)}` });

    const mode = [
        camera.exposure_program && trans(`library.camera.programs.${camera.exposure_program}`),
        camera.metering && trans(`library.camera.metering.${camera.metering}`),
    ].filter(Boolean).join(' · ');

    const flash = camera.flash === undefined ? null : trans(camera.flash ? 'library.camera.flash_fired' : 'library.camera.flash_off');
    const light = [flash, camera.white_balance && trans(`library.camera.white_balance.${camera.white_balance}`)].filter(Boolean).join(' · ');

    return [
        ['camera', name],
        ['lens', camera.lens],
        ['exposure', exposure],
        ['focal_length', focal],
        ['exposure_bias', bias],
        ['mode', mode],
        ['flash', light],
        ['color_space', camera.color_space && (camera.color_space === 'uncalibrated' ? trans('library.camera.uncalibrated') : camera.color_space)],
        ['software', camera.software],
    ].filter(([, value]) => value);
}

/**
 * A fraction stays as it is ("1/250"); a long exposure is a number formatted for the locale ("2.5" → "2,5").
 */
function shutter(value, locale) {
    return /^\d+(\.\d+)?$/.test(String(value)) ? formatNumber(Number(value), locale) : value;
}
