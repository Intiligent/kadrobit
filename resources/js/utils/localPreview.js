/**
 * Small previews of files picked for upload, made in the browser before anything is sent: a frame of
 * a video, a downscaled copy of an image. They replace the type icon in the upload list until the
 * server's thumbnail arrives.
 *
 * - Only what the browser can decode: H.264 everywhere, HEVC where the system has a decoder, JPEG,
 *   PNG, SVG. Anything else (TIFF, EPS, ProRes) or a slow decode resolves to null: the icon stays.
 * - A full-size object URL of a 40 MP photo would keep it decoded in memory for a 36px tile, so every
 *   preview is a small JPEG, and the source is let go right after.
 * - Files are decoded a few at a time (`concurrency`), so picking a hundred videos does not open
 *   a hundred decoders.
 */

// Short side in pixels: the 36px tile at up to 3x pixel density.
export const LOCAL_PREVIEW_SIZE = 112;
export const LOCAL_PREVIEW_TIMEOUT = 3000;
export const LOCAL_PREVIEW_CONCURRENCY = 2;

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/svg+xml'];

// The browser leaves the type empty or generic for some files (a .mov is often application/octet-stream).
const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mov', 'webm'];
const IMAGE_EXTENSIONS = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', svg: 'image/svg+xml' };

/**
 * Size of the preview: the short side brought to `size`, never enlarged.
 */
export function fitSize(width, height, size = LOCAL_PREVIEW_SIZE) {
    const scale = Math.min(1, size / Math.min(width, height));

    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

/**
 * Seconds into the video for its frame: a little in, as the very first frames are often black.
 */
export function frameTime(duration) {
    return Number.isFinite(duration) && duration > 0 ? Math.min(1, duration * 0.1) : 0;
}

/**
 * 'video', 'image' (one the browser draws) or null, by the file's type, or by its extension when
 * the browser did not tell the type.
 */
export function previewKind(file) {
    const type = file.type || '';
    const extension = (file.name ?? '').split('.').pop().toLowerCase();

    if (type.startsWith('video/') || ((!type || type === 'application/octet-stream') && VIDEO_EXTENSIONS.includes(extension))) {
        return 'video';
    }

    if (IMAGE_TYPES.includes(type) || ((!type || type === 'application/octet-stream') && IMAGE_EXTENSIONS[extension])) {
        return 'image';
    }

    return null;
}

export const isVideoFile = (file) => previewKind(file) === 'video';

/**
 * Runs async tasks at most `concurrency` at a time, in the order they came.
 */
export function createLimiter(concurrency = LOCAL_PREVIEW_CONCURRENCY) {
    const waiting = [];
    let running = 0;

    const next = () => {
        if (running >= concurrency || !waiting.length) {
            return;
        }

        const { task, resolve, reject } = waiting.shift();
        running += 1;

        Promise.resolve()
            .then(task)
            .then(resolve, reject)
            .finally(() => {
                running -= 1;
                next();
            });
    };

    return (task) => new Promise((resolve, reject) => {
        waiting.push({ task, resolve, reject });
        next();
    });
}

const limit = createLimiter();

/**
 * Object URL of a small JPEG preview of the file, or null. The caller revokes it.
 */
export function createLocalPreview(file) {
    const kind = previewKind(file);

    if (!kind) {
        return Promise.resolve(null);
    }

    return limit(async () => {
        // A decode that runs out of time is stopped, so its source and its element are let go too.
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), LOCAL_PREVIEW_TIMEOUT);

        try {
            return await (kind === 'video' ? videoFrame(file, controller.signal) : imageCopy(file, controller.signal));
        } catch {
            return null;
        } finally {
            clearTimeout(timer);
        }
    });
}

/**
 * The source is read from disk through an object URL, released however the decode ends.
 */
async function withSource(file, decode) {
    const url = URL.createObjectURL(file);

    try {
        return await decode(url);
    } finally {
        URL.revokeObjectURL(url);
    }
}

function imageCopy(file, signal) {
    return withSource(file, async (url) => {
        const image = new Image();
        signal.addEventListener('abort', () => image.removeAttribute('src'));
        image.src = url;
        await image.decode();

        // Browsers apply the EXIF orientation when drawing, so the size here is the upright one.
        return draw(image, image.naturalWidth, image.naturalHeight, signal);
    });
}

function videoFrame(file, signal) {
    return withSource(file, (url) => new Promise((resolve, reject) => {
        const video = document.createElement('video');
        const release = () => {
            video.removeAttribute('src');
            video.load();
        };

        signal.addEventListener('abort', () => {
            release();
            reject(new Error('The video took too long to decode.'));
        });

        video.muted = true;
        video.playsInline = true;
        video.preload = 'metadata';
        video.onerror = () => {
            release();
            reject(new Error('The browser cannot decode this video.'));
        };
        video.onloadedmetadata = () => {
            video.currentTime = frameTime(video.duration);
        };
        video.onseeked = async () => {
            try {
                resolve(await draw(video, video.videoWidth, video.videoHeight, signal));
            } catch (error) {
                reject(error);
            } finally {
                release();
            }
        };
        video.src = url;
    }));
}

async function draw(source, sourceWidth, sourceHeight, signal) {
    if (!sourceWidth || !sourceHeight) {
        return null;
    }

    const { width, height } = fitSize(sourceWidth, sourceHeight);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');
    // JPEG has no transparency: a transparent PNG or SVG goes on white, like the server's previews.
    context.fillStyle = '#fff';
    context.fillRect(0, 0, width, height);
    context.drawImage(source, 0, 0, width, height);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));

    // Too late: nobody would revoke the URL.
    if (!blob || signal.aborted) {
        return null;
    }

    return URL.createObjectURL(blob);
}
