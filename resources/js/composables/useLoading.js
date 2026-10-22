import { computed, reactive } from 'vue';

/**
 * Per-request loading state for the API client.
 *
 * `state` maps request id to { method, url, startedAt } while the request runs.
 *
 * @example
 * const loading = useLoading();
 * const requestId = newRequestId();
 * api.post('/assets', data, { loadingState: loading, requestId });
 * loading.is(requestId); // this request
 * loading.any.value;     // any request using this state
 */
export function useLoading() {
    const state = reactive({});

    return {
        state,
        any: computed(() => Object.keys(state).length > 0),
        ids: computed(() => Object.keys(state)),
        is: (requestId) => Object.hasOwn(state, requestId),
    };
}
