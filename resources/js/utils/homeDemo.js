/**
 * The animated demos of the home page. Pure functions of one tick counter (useTicker), so every demo
 * stays in step and the final frame is shown as is when the user prefers reduced motion.
 */

/** Photos of the home page, in public/images/home (picsum.photos / Unsplash, to be replaced by authors' work). */
export const homeImage = (id, width, height) => `/images/home/${id}-${width}x${height}.webp`;

/** Element Plus tag type of each demo status (home.demo.status.*). */
export const STATUS_TAG = {
    wait: 'info',
    queued: 'info',
    ok: 'success',
    sent: 'success',
    warning: 'warning',
    outdated: 'warning',
    error: 'danger',
    sending: 'primary',
};

/** Ticks of one loop: 13 s at 100 ms a tick. */
export const DEMO_CYCLE = 130;

/** The frame shown without motion: everything uploaded, checked and sent. */
export const DEMO_FINAL_TICK = 125;

export const DEMO_TITLE = 'Mountain valley under low clouds in early morning light';

export const DEMO_KEYWORDS = ['mountains', 'valley', 'clouds', 'morning', 'landscape', 'nature', 'hiking', 'peaks', 'scenic', 'wilderness'];

/** The stock sites of the hero card and what their checks say: Shutterstock wants a category on its website. */
export const DEMO_STOCKS = [
    { code: 'adobe_stock', name: 'Adobe Stock', initials: 'AS', rule: 'ok' },
    { code: 'shutterstock', name: 'Shutterstock', initials: 'SS', rule: 'warning' },
    { code: 'pond5', name: 'Pond5', initials: 'P5', rule: 'ok' },
];

const clamp = (value, max) => Math.max(0, Math.min(max, value));

/**
 * The hero card: the upload runs first, then the title is typed and keywords appear, then each stock site
 * is checked and sent to, one after another.
 */
export function heroDemo(tick) {
    const chars = tick < 22 ? 0 : clamp(Math.round(((tick - 22) / 18) * DEMO_TITLE.length), DEMO_TITLE.length);

    return {
        uploadPct: clamp(tick * 5, 100),
        title: DEMO_TITLE.slice(0, chars),
        typing: tick >= 22 && chars < DEMO_TITLE.length,
        keywords: tick < 40 ? [] : DEMO_KEYWORDS.slice(0, clamp(Math.floor((tick - 40) / 2) + 1, DEMO_KEYWORDS.length)),
        stocks: DEMO_STOCKS.map((stock, index) => {
            const checkedAt = 64 + index * 4;
            const sentAt = 82 + index * 5;

            if (tick >= sentAt) {
                const pct = clamp((tick - sentAt) * 5, 100);

                return { ...stock, state: pct >= 100 ? 'sent' : 'sending', pct };
            }

            return { ...stock, state: tick >= checkedAt ? stock.rule : 'wait', pct: 0 };
        }),
    };
}

/** "How it works": which of the four frames is lit, and the progress inside it. */
export function filmFrame(tick) {
    const length = DEMO_CYCLE / 4;
    const active = Math.floor(tick / length) % 4;
    const within = tick % length;

    return {
        active,
        uploadPct: active === 0 ? clamp(Math.round((within / (length * 0.8)) * 100), 100) : 100,
        keywords: active === 1 ? clamp(Math.floor(within / 5) + 1, 5) : 5,
    };
}

/** The dot travelling "You → Kadrobit → stock sites", in percent of the route. */
export const routePosition = (tick) => 8 + ((tick % 40) / 40) * 84;

/** The batch counter of the "Batches of hundreds" card. */
export const batchCount = (tick) => 120 + Math.floor(tick / 2);
