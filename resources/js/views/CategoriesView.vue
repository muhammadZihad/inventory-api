<script setup>
import { onMounted, reactive } from 'vue';
import { ApiError, api } from '../api/client.js';
import { useResourceList } from '../composables/useResourceList.js';
import { useToasts } from '../composables/useToasts.js';
import DataTable from '../components/DataTable.vue';
import DrawerPanel from '../components/DrawerPanel.vue';
import FieldErrors from '../components/FieldErrors.vue';
import FilterBar from '../components/FilterBar.vue';
import PagerBar from '../components/PagerBar.vue';
import StatusBadge from '../components/StatusBadge.vue';

const toasts = useToasts();
const list = useResourceList('/categories', {
    defaultFilters: { status: '', from: '', to: '' },
});

const filterFields = [
    { key: 'status', label: 'Status', type: 'select', options: ['', 'active', 'archived'] },
    { key: 'from', label: 'Created from', type: 'date' },
    { key: 'to', label: 'Created to', type: 'date' },
];

const columns = [
    { key: 'name', label: 'Name', sortKey: 'name' },
    { key: 'slug', label: 'Slug', sortKey: 'slug' },
    { key: 'products_count', label: 'Products', sortKey: 'products_count' },
    { key: 'status', label: 'Status', sortKey: 'status' },
    { key: 'created_at', label: 'Created', sortKey: 'created_at' },
];

const drawer = reactive({ open: false, mode: 'detail', record: null, busy: false, errors: {} });
const form = reactive({ name: '', slug: '', status: 'active' });

onMounted(() => list.load(1));

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString() : '—';
}

function resetForm(record = null) {
    Object.assign(form, {
        name: record?.name ?? '',
        slug: record?.slug ?? '',
        status: record?.status ?? 'active',
    });
    drawer.errors = {};
}

function openCreate() {
    resetForm();
    Object.assign(drawer, { open: true, mode: 'create', record: null });
}

async function openDetail(row) {
    Object.assign(drawer, { open: true, mode: 'detail', record: row, busy: true });

    try {
        const { data } = await api.get(`/categories/${row.id}`);
        drawer.record = data;
    } catch (exception) {
        toasts.error(exception.message);
    } finally {
        drawer.busy = false;
    }
}

function enterEdit() {
    resetForm(drawer.record);
    drawer.mode = 'edit';
}

async function submit() {
    drawer.busy = true;
    drawer.errors = {};

    try {
        // A blank slug is sent as null so the server derives one from the name.
        const payload = {
            name: form.name,
            slug: form.slug || null,
            status: form.status,
        };

        if (drawer.mode === 'create') {
            const { data } = await api.post('/categories', payload);
            toasts.success(`Category “${data.name}” created.`);
        } else {
            const { data } = await api.put(`/categories/${drawer.record.id}`, payload);
            toasts.success(`Category “${data.name}” updated.`);
        }

        drawer.open = false;
        await list.load(list.filters.page);
    } catch (exception) {
        if (exception instanceof ApiError) {
            drawer.errors = exception.fieldErrors;
            toasts.error(exception.message);
        }
    } finally {
        drawer.busy = false;
    }
}

async function destroy() {
    if (!globalThis.confirm(`Delete “${drawer.record.name}”? This cannot be undone.`)) {
        return;
    }

    drawer.busy = true;

    try {
        await api.delete(`/categories/${drawer.record.id}`);
        toasts.success('Category deleted.');
        drawer.open = false;
        await list.load(1);
    } catch (exception) {
        toasts.error(exception.message);
    } finally {
        drawer.busy = false;
    }
}
</script>

<template>
    <section>
        <div class="resource-toolbar">
            <div>
                <p class="eyebrow">Catalog</p>
                <h1>Categories</h1>
            </div>
            <button type="button" @click="openCreate">New category</button>
        </div>

        <FilterBar
            :fields="filterFields"
            :model-value="list.filters"
            search-placeholder="Search name or slug"
            @apply="list.load(1)"
            @reset="list.reset()"
        />

        <p v-if="list.error.value" class="notice error">{{ list.error }}</p>

        <DataTable
            :columns="columns"
            :rows="list.rows.value"
            :loading="list.loading.value"
            :sort-mark="list.sortMark"
            @sort="list.sortBy"
            @select="openDetail"
        >
            <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
            <template #cell-created_at="{ value }">{{ formatDate(value) }}</template>
        </DataTable>

        <PagerBar
            :meta="list.meta"
            :per-page="list.filters.per_page"
            @change-page="list.load"
            @change-per-page="(value) => { list.filters.per_page = value; list.load(1); }"
        />

        <DrawerPanel
            :open="drawer.open"
            :title="drawer.mode === 'create' ? 'New category' : drawer.record?.name || 'Category'"
            :subtitle="drawer.mode === 'detail' ? drawer.record?.slug : ''"
            @close="drawer.open = false"
        >
            <div v-if="drawer.mode === 'detail'">
                <p v-if="drawer.busy">Loading…</p>
                <dl v-else-if="drawer.record" class="detail-list">
                    <dt>ID</dt><dd>{{ drawer.record.id }}</dd>
                    <dt>Name</dt><dd>{{ drawer.record.name }}</dd>
                    <dt>Slug</dt><dd>{{ drawer.record.slug }}</dd>
                    <dt>Status</dt><dd><StatusBadge :status="drawer.record.status" /></dd>
                    <dt>Products</dt><dd>{{ drawer.record.products_count ?? '—' }}</dd>
                </dl>
            </div>

            <form v-else class="drawer-form" @submit.prevent="submit">
                <label>Name <input v-model="form.name" type="text" required /></label>
                <FieldErrors :errors="drawer.errors" field="name" />

                <label>Slug <input v-model="form.slug" type="text" placeholder="Generated from name" /></label>
                <FieldErrors :errors="drawer.errors" field="slug" />

                <label>
                    Status
                    <select v-model="form.status" required>
                        <option value="active">active</option>
                        <option value="archived">archived</option>
                    </select>
                </label>
                <FieldErrors :errors="drawer.errors" field="status" />
            </form>

            <template #actions>
                <template v-if="drawer.mode === 'detail'">
                    <button type="button" class="ghost danger" :disabled="drawer.busy" @click="destroy">Delete</button>
                    <button type="button" :disabled="drawer.busy" @click="enterEdit">Edit</button>
                </template>
                <template v-else>
                    <button type="button" class="ghost" @click="drawer.open = false">Cancel</button>
                    <button type="button" :disabled="drawer.busy" @click="submit">
                        {{ drawer.busy ? 'Saving…' : 'Save' }}
                    </button>
                </template>
            </template>
        </DrawerPanel>
    </section>
</template>
