<script setup>
/**
 * A sortable table driven by a column definition list.
 *
 * Columns may declare `sortKey` to become clickable headers, and the default
 * cell rendering can be replaced per column with a named slot.
 */
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
    emptyMessage: { type: String, default: 'No records found.' },
    sortMark: { type: Function, default: () => '' },
    rowKey: { type: String, default: 'id' },
    clickable: { type: Boolean, default: true },
});

const emit = defineEmits(['sort', 'select']);
</script>

<template>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        :class="{ sortable: column.sortKey }"
                        @click="column.sortKey ? emit('sort', column.sortKey) : null"
                    >
                        {{ column.label }}
                        <span v-if="column.sortKey" class="sort-mark">{{ sortMark(column.sortKey) }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="loading">
                    <td :colspan="columns.length">Loading…</td>
                </tr>
                <tr v-else-if="!rows.length">
                    <td :colspan="columns.length">{{ emptyMessage }}</td>
                </tr>
                <tr
                    v-else
                    v-for="row in rows"
                    :key="row[rowKey]"
                    :class="{ 'clickable-row': clickable }"
                    @click="clickable ? emit('select', row) : null"
                >
                    <td v-for="column in columns" :key="column.key">
                        <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">
                            {{ row[column.key] ?? '—' }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
