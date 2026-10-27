import { describe, expect, it } from 'vitest';
import { DEMO_FINAL_TICK, DEMO_KEYWORDS, DEMO_TITLE, batchCount, filmFrame, heroDemo, routePosition } from '../../resources/js/utils/homeDemo';

describe('hero demo', () => {
    it('starts with an empty upload and nothing checked', () => {
        const demo = heroDemo(0);

        expect(demo.uploadPct).toBe(0);
        expect(demo.title).toBe('');
        expect(demo.keywords).toEqual([]);
        expect(demo.stocks.map((s) => s.state)).toEqual(['wait', 'wait', 'wait']);
    });

    it('types the title after the upload, then adds keywords', () => {
        expect(heroDemo(21).uploadPct).toBe(100);
        expect(heroDemo(31).title.length).toBeGreaterThan(0);
        expect(heroDemo(31).typing).toBe(true);
        expect(heroDemo(40).title).toBe(DEMO_TITLE);
        expect(heroDemo(40).keywords).toEqual(DEMO_KEYWORDS.slice(0, 1));
    });

    it('checks the stock sites one by one, with their own outcome', () => {
        expect(heroDemo(68).stocks.map((s) => s.state)).toEqual(['ok', 'warning', 'wait']);
    });

    it('ends with every stock site sent to', () => {
        const demo = heroDemo(DEMO_FINAL_TICK);

        expect(demo.keywords).toEqual(DEMO_KEYWORDS);
        expect(demo.stocks.every((s) => s.state === 'sent' && s.pct === 100)).toBe(true);
        expect(heroDemo(85).stocks[0]).toMatchObject({ state: 'sending', pct: 15 });
    });
});

describe('film strip and counters', () => {
    it('lights the four frames in turn', () => {
        expect([0, 33, 66, 99].map((t) => filmFrame(t).active)).toEqual([0, 1, 2, 3]);
        expect(filmFrame(0).uploadPct).toBe(0);
        expect(filmFrame(40).uploadPct).toBe(100);
        expect(filmFrame(33).keywords).toBe(1);
    });

    it('moves the route dot within the card and counts the batch up', () => {
        expect(routePosition(0)).toBe(8);
        expect(routePosition(39)).toBeLessThan(92);
        expect(batchCount(10)).toBe(125);
    });
});
