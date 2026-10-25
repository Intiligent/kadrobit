/**
 * Selecting works in the library grid: one work opens the info panel, several open the selection panel.
 * A click picks one work; Cmd/Ctrl-click adds or removes one; Shift-click adds the range from the anchor
 * (the last work clicked) in the order the grid shows.
 *
 * @param {string[]} current Ids picked now
 * @param {string[]} order Ids in the grid's order
 * @param {string} id The work clicked
 * @param {{ toggle?: boolean, range?: boolean, anchor?: string|null }} options
 * @returns {{ ids: string[], anchor: string|null }} Ids in the grid's order, and the anchor for the next Shift-click
 */
export function selectWorks(current, order, id, { toggle = false, range = false, anchor = null } = {}) {
    const from = anchor && order.includes(anchor) ? anchor : null;

    if (range && from) {
        const [start, end] = [order.indexOf(from), order.indexOf(id)].sort((a, b) => a - b);
        const picked = new Set([...current, ...order.slice(start, end + 1)]);

        // The anchor stays: the next Shift-click spans from it again, as in Finder.
        return { ids: order.filter((other) => picked.has(other)), anchor: from };
    }

    if (toggle) {
        const picked = new Set(current);

        if (picked.has(id)) {
            picked.delete(id);
        } else {
            picked.add(id);
        }

        return { ids: order.filter((other) => picked.has(other)), anchor: id };
    }

    return { ids: [id], anchor: id };
}
