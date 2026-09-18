<script setup>
import { onMounted, reactive } from 'vue';
import { ApiError, api } from '../api/client.js';
import { searchCategories } from '../api/lookups.js';
import { useResourceList } from '../composables/useResourceList.js';
import { useToasts } from '../composables/useToasts.js';
import DataTable from '../components/DataTable.vue';
import DrawerPanel from '../components/DrawerPanel.vue';
import FieldErrors from '../components/FieldErrors.vue';
import FilterBar from '../components/FilterBar.vue';
import PagerBar from '../components/PagerBar.vue';
import SearchSelect from '../components/SearchSelect.vue';
import StatusBadge from '../components/StatusBadge.vue';

const emit = defineEmits(['inspect-inventory']);

const toasts = useToasts();
const list = useResourceList('/products', {
    defaultFilters: { status: '', category_id: '', min_price: '', max_price: '', from: '', to: '' },
});

const filterFields = [
    { key: 'status', label: 'Status', type: 'select', options: ['', 'active', 'draft', 'archived'] },
    { key: 'category_id', label: 'Category', type: 'remote', fetch: searchCategories, placeholder: 'Search categories' },
    { key: 'min_price', label: 'Min price', type: 'number', step: '0.01' },
    { key: 'max_price', label: 'Max price', type: 'number', step: '0.01' },
    { key: 'from', label: 'Created from', type: 'date' },
    { key: 'to', label: 'Created to', type: 'date' },
];

const columns = [
    { key: 'name', label: 'Name', sortKey: 'name' },
    { key: 'sku', label: 'SKU', sortKey: 'sku' },
    { key: 'category_name', label: 'Category', sortKey: 'category_name' },
    { key: 'price', label: 'Price', sortKey: 'price' },
    { key: 'stock', label: 'On hand', sortKey: 'stock' },
    { key: 'available_stock', label: 'Available', sortKey: 'available_stock' },
    { key: 'units_sold', label: 'Sold', sortKey: 'units_sold' },
    { key: 'gross_sales', label: 'Gross sales', sortKey: 'gross_sales' },
    { key: 'status', label: 'Status', sortKey: 'status' },
];

const drawer = reactive({ open: false, mode: 'detail', record: null, busy: false, errors: {} });
const form = reactive({ category_id: '', category_label: '', name: '', sku: '', description: '', price: '', status: 'active', stock_quantity: 0 });

onMounted(() => list.load(1));

function resetForm(record = null) {
    Object.assign(form, {
        category_id: record?.category_id ?? '',
        category_label: record?.category_name ?? '',
        name: record?.name ?? '',
        sku: record?.sku ?? '',
        description: record?.description ?? '',
        price: record?.price ?? '',
        status: record?.status ?? 'active',
        stock_quantity: 0,
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
        const { data } = await api.get(`/products/${row.id}`);
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
        if (drawer.mode === 'create') {
            const { data } = await api.post('/products', {
                category_id: form.category_id,
                name: form.name,
                sku: form.sku,
                description: form.description || null,
                price: String(form.price),
                status: form.status,
                stock_quantity: Number(form.stock_quantity),
            });
            toasts.success(`Product “${data.name}” created.`);
        } else {
            const { data } = await api.put(`/products/${drawer.record.id}`, {
                category_id: form.category_id,
                name: form.name,
                sku: form.sku,
                description: form.description || null,
                price: String(form.price),
                status: form.status,
            });
            toasts.success(`Product “${data.name}” updated.`);
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
        await api.delete(`/products/${drawer.record.id}`);
        toasts.success('Product deleted.');
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
                <h1>Products</h1>
            </div>
            <button type="button" @click="openCreate">New product</button>
        </div>

        <FilterBar
            :fields="filterFields"
            :model-value="list.filters"
            search-placeholder="Search name, SKU or description"
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
            <template #cell-price="{ value }">{{ value }}</template>
            <template #cell-gross_sales="{ value }">{{ value }}</template>
            <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
            <template #cell-available_stock="{ row }">
                <span :class="{ 'text-warn': row.available_stock === 0 }">{{ row.available_stock }}</span>
            </template>
        </DataTable>

        <PagerBar
            :meta="list.meta"
            :per-page="list.filters.per_page"
            @change-page="list.load"
            @change-per-page="(value) => { list.filters.per_page = value; list.load(1); }"
        />

        <DrawerPanel
            :open="drawer.open"
            :title="drawer.mode === 'create' ? 'New product' : drawer.record?.name || 'Product'"
            :subtitle="drawer.mode === 'detail' ? drawer.record?.sku : ''"
            @close="drawer.open = false"
        >
            <div v-if="drawer.mode === 'detail'">
                <p v-if="drawer.busy">Loading…</p>
                <dl v-else-if="drawer.record" class="detail-list">
                    <dt>ID</dt><dd>{{ drawer.record.id }}</dd>
                    <dt>Category</dt><dd>{{ drawer.record.category_name ?? '—' }}</dd>
                    <dt>Price</dt><dd>{{ drawer.record.price }}</dd>
                    <dt>Status</dt><dd><StatusBadge :status="drawer.record.status" /></dd>
                    <dt>On hand</dt><dd>{{ drawer.record.stock }}</dd>
                    <dt>Available</dt><dd>{{ drawer.record.available_stock }}</dd>
                    <dt>Reserved</dt>
                    <dd>{{ (drawer.record.stock ?? 0) - (drawer.record.available_stock ?? 0) }}</dd>
                    <dt>Units sold</dt><dd>{{ drawer.record.units_sold }}</dd>
                    <dt>Orders</dt><dd>{{ drawer.record.orders_count }}</dd>
                    <dt>Gross sales</dt><dd>{{ drawer.record.gross_sales }}</dd>
                    <dt>Sales rank</dt><dd>{{ drawer.record.sales_rank ?? '—' }}</dd>
                    <dt>Description</dt><dd>{{ drawer.record.description ?? '—' }}</dd>
                </dl>
            </div>

            <form v-else class="drawer-form" @submit.prevent="submit">
                <label>
                    Category
                    <SearchSelect
                        v-model="form.category_id"
                        :fetch="searchCategories"
                        :initial-label="form.category_label"
                        placeholder="Search categories by name or slug"
                        required
                    />
                </label>
                <FieldErrors :errors="drawer.errors" field="category_id" />

                <label>Name <input v-model="form.name" type="text" required /></label>
                <FieldErrors :errors="drawer.errors" field="name" />

                <label>SKU <input v-model="form.sku" type="text" required /></label>
                <FieldErrors :errors="drawer.errors" field="sku" />

                <label>Price <input v-model="form.price" type="number" step="0.01" min="0" required /></label>
                <FieldErrors :errors="drawer.errors" field="price" />

                <label>
                    Status
                    <select v-model="form.status" required>
                        <option value="active">active</option>
                        <option value="draft">draft</option>
                        <option value="archived">archived</option>
                    </select>
                </label>
                <FieldErrors :errors="drawer.errors" field="status" />

                <label v-if="drawer.mode === 'create'">
                    Opening stock
                    <input v-model="form.stock_quantity" type="number" min="0" required />
                </label>
                <FieldErrors :errors="drawer.errors" field="stock_quantity" />

                <label>Description <textarea v-model="form.description" rows="3"></textarea></label>
                <FieldErrors :errors="drawer.errors" field="description" />
            </form>

            <template #actions>
                <template v-if="drawer.mode === 'detail'">
                    <button type="button" class="ghost danger" :disabled="drawer.busy" @click="destroy">Delete</button>
                    <button type="button" :disabled="drawer.busy" @click="enterEdit">Edit</button>
                    <button type="button" class="ghost" @click="emit('inspect-inventory', drawer.record)">
                        Inventory
                    </button>
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
