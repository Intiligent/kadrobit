import { computed, reactive } from 'vue';
import api from '@/core/api';
import { ASSET_AUTOSAVE_DELAY, ASSETS_API_URL } from '@/config/constant';

/**
 * Debounced autosave of work edits (PATCH /api/assets/{id}).
 *
 * - Changes of one work are merged until the delay passes, so typing a title sends one request,
 *   not one per keystroke. `immediate` sends at once (status buttons).
 * - One request per work at a time: changes made meanwhile wait for it and go in the next one,
 *   so an older response never lands after a newer one.
 * - A 422 keeps the rejected fields out of the following saves until they are edited again;
 *   the other fields of that request are sent again right away.
 * - After a network or server error the changes stay queued and go with the next edit or `flush()`.
 *
 * `state[id]`: 'pending' | 'saving' | 'saved' | 'error'; `errors[id]`: { field: message } from a 422.
 * `onSaved(asset, local)`: the server's version of the work, and the fields whose local value is newer
 * (still queued or rejected) and must not be overwritten. `localFields(id)` gives that set, plus the fields of
 * a request still running, at any time: for other fresh versions of a work (e.g. after a background generation).
 */
export function useAssetAutosave({ onSaved } = {}) {
    const state = reactive({});
    const errors = reactive({});
    // id → { patch, timer, onError: [] }
    const queued = new Map();
    // id → request promise
    const inFlight = new Map();
    // id → patch of the request running now: a fresh version fetched meanwhile must not undo these fields either
    const sending = new Map();
    // id → Set of fields rejected by the last 422
    const rejected = new Map();
    // Works deleted meanwhile: their late responses and edits are ignored.
    const gone = new Set();

    function queue(id, patch, { immediate = false, onError = null } = {}) {
        if (gone.has(id)) {
            return;
        }

        const entry = queued.get(id) ?? { patch: {}, timer: null, onError: [] };

        Object.assign(entry.patch, patch);

        if (onError) {
            entry.onError.push(onError);
        }

        // An edited field is checked again by the server.
        for (const field of Object.keys(patch)) {
            rejected.get(id)?.delete(field);

            if (errors[id]) {
                delete errors[id][field];
            }
        }

        queued.set(id, entry);
        schedule(id, immediate ? 0 : ASSET_AUTOSAVE_DELAY);
        state[id] = 'pending';
    }

    function schedule(id, delay) {
        const entry = queued.get(id);

        clearTimeout(entry.timer);
        entry.timer = setTimeout(() => flush(id), delay);
    }

    async function flush(id) {
        const entry = queued.get(id);

        if (!entry) {
            return inFlight.get(id);
        }

        clearTimeout(entry.timer);

        // The next request starts when the running one is done.
        if (inFlight.has(id)) {
            await inFlight.get(id);

            return flush(id);
        }

        queued.delete(id);
        sending.set(id, entry.patch);
        state[id] = 'saving';

        const request = send(id, entry).finally(() => inFlight.delete(id));
        inFlight.set(id, request);

        return request;
    }

    async function send(id, entry) {
        try {
            const response = await api.patch(`${ASSETS_API_URL}/${id}`, entry.patch, { silent: [422] });
            sending.delete(id);

            if (gone.has(id)) {
                return;
            }

            state[id] = queued.has(id) ? 'pending' : rejected.get(id)?.size ? 'error' : 'saved';
            onSaved?.(response.data, localFields(id));
        } catch (error) {
            sending.delete(id);

            // Deleted while this request ran: nothing to report or to send again.
            if (gone.has(id)) {
                return;
            }

            entry.onError.forEach((callback) => callback(error));

            if (error.status === 422) {
                const fields = Object.fromEntries(
                    Object.entries(error.errors ?? {}).map(([field, messages]) => [field.split('.')[0], messages[0]]),
                );

                errors[id] = { ...errors[id], ...fields };
                rejected.set(id, new Set([...(rejected.get(id) ?? []), ...Object.keys(fields)]));
                state[id] = 'error';

                // The rest of the request was valid: save it without the rejected fields.
                const rest = Object.fromEntries(Object.entries(entry.patch).filter(([field]) => !(field in fields)));

                if (Object.keys(rest).length) {
                    requeue(id, rest);
                    // After this request has settled: flushing from inside it would wait for itself.
                    schedule(id, 0);
                }

                return;
            }

            // Not saved: keep the changes for the next attempt, under newer edits of the same fields.
            requeue(id, entry.patch);
            state[id] = 'error';
        }
    }

    function requeue(id, patch) {
        const entry = queued.get(id);

        if (entry) {
            entry.patch = { ...patch, ...entry.patch };
        } else {
            queued.set(id, { patch: { ...patch }, timer: null, onError: [] });
        }
    }

    function localFields(id) {
        return new Set([
            ...Object.keys(queued.get(id)?.patch ?? {}),
            ...Object.keys(sending.get(id) ?? {}),
            ...(rejected.get(id) ?? []),
        ]);
    }

    /**
     * For a deleted work: drops what was not sent, and ignores a request still running and any later
     * edit, which would only get a 404. Called once the delete has succeeded.
     */
    function forget(id) {
        gone.add(id);
        clearTimeout(queued.get(id)?.timer);
        queued.delete(id);
        rejected.delete(id);
        delete state[id];
        delete errors[id];
    }

    function flushAll() {
        return Promise.all([...new Set([...queued.keys(), ...inFlight.keys()])].map(flush));
    }

    // Edits not yet saved: leaving the page would lose them.
    const unsaved = computed(() => Object.values(state).some((s) => s === 'pending' || s === 'saving'));

    function dispose() {
        queued.forEach((entry) => clearTimeout(entry.timer));
    }

    return { state, errors, unsaved, queue, flush, flushAll, forget, dispose, localFields };
}
