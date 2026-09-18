import { reactive, ref } from 'vue';
import { ApiError, api } from '../api/client.js';

/**
 * Drives a paginated, filterable, sortable list endpoint.
 *
 * Every list endpoint in this API shares the same contract — `search`, typed
 * filters, `sort` with a `-` prefix for descending, `page`, `per_page`, and a
 * `meta` block — so one composable serves all of them.
 */
export function useResourceList(path, { defaultSort = '-created_at', defaultFilters = {} } = {}) {
    const rows = ref([]);
    const meta = reactive({ current_page: 1, per_page: 15, total: 0, last_page: 1, from: null, to: null });
    const filters = reactive({ ...defaultFilters, search: '', sort: defaultSort, page: 1, per_page: 15 });
    const loading = ref(false);
    const error = ref('');

    async function load(page = filters.page) {
        filters.page = page;
        loading.value = true;
        error.value = '';

        try {
            const response = await api.get(path, { ...filters });
            rows.value = response.data ?? [];
            Object.assign(meta, response.meta ?? {});
        } catch (exception) {
            rows.value = [];
            error.value = exception instanceof ApiError ? exception.message : 'Unable to load records.';
        } finally {
            loading.value = false;
        }
    }

    /** Toggle the sort direction for a column, or switch to it ascending. */
    function sortBy(column) {
        filters.sort = filters.sort === column ? `-${column}` : column;
        load(1);
    }

    /** Return the arrow shown next to a sortable column header. */
    function sortMark(column) {
        if (filters.sort === column) {
            return '▲';
        }

        return filters.sort === `-${column}` ? '▼' : '';
    }

    function reset() {
        Object.keys(filters).forEach((key) => {
            if (!['sort', 'page', 'per_page'].includes(key)) {
                filters[key] = defaultFilters[key] ?? '';
            }
        });
        filters.sort = defaultSort;
        load(1);
    }

    return { rows, meta, filters, loading, error, load, sortBy, sortMark, reset };
}
