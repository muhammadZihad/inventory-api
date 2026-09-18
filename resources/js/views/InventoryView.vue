<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { ApiError, api } from '../api/client.js';
import { searchProducts } from '../api/lookups.js';
import { useResourceList } from '../composables/useResourceList.js';
import { useToasts } from '../composables/useToasts.js';
import DataTable from '../components/DataTable.vue';
import DrawerPanel from '../components/DrawerPanel.vue';
import FieldErrors from '../components/FieldErrors.vue';
import FilterBar from '../components/FilterBar.vue';
import PagerBar from '../components/PagerBar.vue';

const props = defineProps({
    focusProductId: { type: String, default: '' },
});

const toasts = useToasts();
const list = useResourceList('/inventory', {
    defaultFilters: {
        product_id: '',
        min_quantity_on_hand: '',
        max_quantity_on_hand: '',
        min_available_quantity: '',
        max_available_quantity: '',
        from: '',
        to: '',
    },
});

const filterFields = [
    { key: 'product_id', label: 'Product', type: 'remote', fetch: searchProducts, placeholder: 'Search products' },
    { key: 'min_quantity_on_hand', label: 'Min on hand', type: 'number' },
    { key: 'max_quantity_on_hand', label: 'Max on hand', type: 'number' },
    { key: 'min_available_quantity', label: 'Min available', type: 'number' },
    { key: 'max_available_quantity', label: 'Max available', type: 'number' },
    { key: 'from', label: 'Created from', type: 'date' },
    { key: 'to', label: 'Created to', type: 'date' },
];

const columns = [
    { key: 'product_title', label: 'Product', sortKey: 'product_title' },
    { key: 'quantity_on_hand', label: 'On hand', sortKey: 'quantity_on_hand' },
    { key: 'quantity_reserved', label: 'Reserved', sortKey: 'quantity_reserved' },
    { key: 'available_quantity', label: 'Available', sortKey: 'available_quantity' },
];

const drawer = reactive({ open: false, record: null, tab: 'adjust', busy: false, errors: {} });
const adjustment = reactive({ type: 'restock', quantity: 1 });
const movements = ref([]);
const movementFilter = ref('');

const movementTypes = [
    '',
    'order_reserved',
    'reservation_released',
    'order_fulfilled',
    'restock',
    'correction',
];

onMounted(() => {
    if (props.focusProductId) {
        list.filters.product_id = props.focusProductId;
    }

    list.load(1);
});

// Opening this view from a product row pre-filters it to that product.
watch(
    () => props.focusProductId,
    (productId) => {
        list.filters.product_id = productId || '';
        list.load(1);
    },
);

async function openDetail(row) {
    Object.assign(drawer, { open: true, record: row, tab: 'adjust', errors: {} });
    Object.assign(adjustment, { type: 'restock', quantity: 1 });
    movementFilter.value = '';
    await loadMovements();
}

async function loadMovements() {
    if (!drawer.record) {
        return;
    }

    try {
        const response = await api.get(`/inventory/${drawer.record.product_id}/movements`, {
            type: movementFilter.value,
            per_page: 25,
        });
        movements.value = response.data ?? [];
    } catch (exception) {
        toasts.error(exception.message);
        movements.value = [];
    }
}

async function submitAdjustment() {
    drawer.busy = true;
    drawer.errors = {};

    try {
        const { data } = await api.post(`/inventory/${drawer.record.product_id}/adjust`, {
            type: adjustment.type,
            quantity: Number(adjustment.quantity),
        });

        // The adjust response omits the product relation, so the already
        // resolved title is carried over rather than blanking the heading.
        drawer.record = { ...data, product_title: drawer.record.product_title };
        toasts.success(`Stock updated: ${data.quantity_on_hand} on hand, ${data.available_quantity} available.`);
        await Promise.all([loadMovements(), list.load(list.filters.page)]);
    } catch (exception) {
        if (exception instanceof ApiError) {
            drawer.errors = exception.fieldErrors;
            toasts.error(exception.message);
        }
    } finally {
        drawer.busy = false;
    }
}

/** Render a signed delta with an explicit sign so direction is unambiguous. */
function signed(value) {
    return value > 0 ? `+${value}` : String(value);
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <section>
        <div class="resource-toolbar">
            <div>
                <p class="eyebrow">Stock</p>
                <h1>Inventory</h1>
            </div>
            <button v-if="list.filters.product_id" type="button" class="ghost" @click="list.filters.product_id = ''; list.load(1)">
                Clear product filter
            </button>
        </div>

        <FilterBar
            :fields="filterFields"
            :model-value="list.filters"
            :searchable="false"
            @apply="list.load(1)"
            @reset="list.reset()"
        />

        <p v-if="list.error.value" class="notice error">{{ list.error }}</p>

        <DataTable
            :columns="columns"
            :rows="list.rows.value"
            :loading="list.loading.value"
            :sort-mark="list.sortMark"
            empty-message="No inventory records found."
            @sort="list.sortBy"
            @select="openDetail"
        >
            <template #cell-product_title="{ row }">{{ row.product_title ?? row.product_id }}</template>
            <template #cell-available_quantity="{ row }">
                <span :class="{ 'text-warn': row.available_quantity === 0 }">{{ row.available_quantity }}</span>
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
            :title="drawer.record?.product_title || 'Inventory'"
            subtitle="Adjust stock and review the movement ledger"
            @close="drawer.open = false"
        >
            <div v-if="drawer.record">
                <dl class="detail-list">
                    <dt>On hand</dt><dd>{{ drawer.record.quantity_on_hand }}</dd>
                    <dt>Reserved</dt><dd>{{ drawer.record.quantity_reserved }}</dd>
                    <dt>Available</dt><dd>{{ drawer.record.available_quantity }}</dd>
                </dl>

                <div class="tabs inner-tabs">
                    <button type="button" :class="{ active: drawer.tab === 'adjust' }" @click="drawer.tab = 'adjust'">
                        Adjust
                    </button>
                    <button
                        type="button"
                        :class="{ active: drawer.tab === 'movements' }"
                        @click="drawer.tab = 'movements'"
                    >
                        Movements
                    </button>
                </div>

                <form v-if="drawer.tab === 'adjust'" class="drawer-form" @submit.prevent="submitAdjustment">
                    <label>
                        Type
                        <select v-model="adjustment.type">
                            <option value="restock">restock — receiving new goods</option>
                            <option value="correction">correction — recount, damage or shrinkage</option>
                        </select>
                    </label>
                    <FieldErrors :errors="drawer.errors" field="type" />

                    <label>
                        Quantity
                        <input v-model="adjustment.quantity" type="number" step="1" required />
                    </label>
                    <FieldErrors :errors="drawer.errors" field="quantity" />

                    <p class="eyebrow">
                        A restock must be positive. A correction may be negative, but stock cannot drop below the
                        {{ drawer.record.quantity_reserved }} unit(s) reserved for open orders.
                    </p>

                    <button type="submit" :disabled="drawer.busy">
                        {{ drawer.busy ? 'Applying…' : 'Apply adjustment' }}
                    </button>
                </form>

                <div v-else class="nested-list">
                    <label class="inline-field">
                        Type
                        <select v-model="movementFilter" @change="loadMovements">
                            <option v-for="type in movementTypes" :key="type" :value="type">
                                {{ type === '' ? 'All' : type.replace('_', ' ') }}
                            </option>
                        </select>
                    </label>

                    <div class="table-scroll">
                    <table class="mini-table">
                        <thead>
                            <tr><th>When</th><th>Type</th><th>On hand</th><th>Reserved</th></tr>
                        </thead>
                        <tbody>
                            <tr v-if="!movements.length"><td colspan="4">No movements recorded.</td></tr>
                            <tr v-for="movement in movements" :key="movement.id">
                                <td>{{ formatDate(movement.created_at) }}</td>
                                <td>{{ movement.type.replace('_', ' ') }}</td>
                                <td>{{ signed(movement.quantity_delta) }} → {{ movement.quantity_after }}</td>
                                <td>{{ signed(movement.reserved_delta) }} → {{ movement.reserved_after }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </DrawerPanel>
    </section>
</template>
