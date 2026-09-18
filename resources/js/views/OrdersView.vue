<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ApiError, api, newIdempotencyKey } from '../api/client.js';
import { searchCustomers, searchOrderableProducts } from '../api/lookups.js';
import { useResourceList } from '../composables/useResourceList.js';
import { useToasts } from '../composables/useToasts.js';
import DataTable from '../components/DataTable.vue';
import DrawerPanel from '../components/DrawerPanel.vue';
import FieldErrors from '../components/FieldErrors.vue';
import FilterBar from '../components/FilterBar.vue';
import PagerBar from '../components/PagerBar.vue';
import SearchSelect from '../components/SearchSelect.vue';
import StatusBadge from '../components/StatusBadge.vue';

const toasts = useToasts();
const list = useResourceList('/orders', {
    defaultFilters: { status: '', customer_id: '', min_total: '', max_total: '', from: '', to: '' },
});

const filterFields = [
    { key: 'status', label: 'Status', type: 'select', options: ['', 'pending', 'confirmed', 'completed', 'cancelled'] },
    { key: 'customer_id', label: 'Customer', type: 'remote', fetch: searchCustomers, placeholder: 'Search customers' },
    { key: 'min_total', label: 'Min total', type: 'number', step: '0.01' },
    { key: 'max_total', label: 'Max total', type: 'number', step: '0.01' },
    { key: 'from', label: 'From', type: 'date' },
    { key: 'to', label: 'To', type: 'date' },
];

const columns = [
    { key: 'order_number', label: 'Order', sortKey: 'order_number' },
    { key: 'customer_name', label: 'Customer', sortKey: 'customer_name' },
    { key: 'items_count', label: 'Items', sortKey: 'items_count' },
    { key: 'total_amount', label: 'Total', sortKey: 'total_amount' },
    { key: 'status', label: 'Status', sortKey: 'status' },
    { key: 'created_at', label: 'Placed', sortKey: 'created_at' },
];

const detail = reactive({ open: false, order: null, history: [], busy: false, note: '' });
const composer = reactive({ open: false, busy: false, errors: {}, customerId: '', lines: [], shortfalls: [] });

// The key identifies the user's intent, so it is generated once when the form
// opens and reused for every retry of that same submission.
const idempotencyKey = ref('');

/** Transitions the API accepts from each state; cancelling has its own endpoint. */
const nextStatuses = {
    pending: ['confirmed'],
    confirmed: ['completed'],
    completed: [],
    cancelled: [],
};

onMounted(() => list.load(1));

function openComposer() {
    Object.assign(composer, {
        open: true,
        busy: false,
        errors: {},
        customerId: '',
        lines: [{ product_id: '', quantity: 1, label: '', available: null }],
        shortfalls: [],
    });
    idempotencyKey.value = newIdempotencyKey();
}

function addLine() {
    composer.lines.push({ product_id: '', quantity: 1, label: '', available: null });
}

/** Remember the chosen product's label and availability for the line. */
function onProductSelected(line, option) {
    line.label = option?.label ?? '';
    line.available = option ? Number.parseInt(option.hint, 10) : null;
}

function removeLine(index) {
    composer.lines.splice(index, 1);
}

async function submitOrder() {
    composer.busy = true;
    composer.errors = {};
    composer.shortfalls = [];

    try {
        const response = await api.post(
            '/orders',
            {
                customer_id: composer.customerId,
                items: composer.lines.map((line) => ({
                    product_id: line.product_id,
                    quantity: Number(line.quantity),
                })),
            },
            { 'Idempotency-Key': idempotencyKey.value },
        );

        toasts.success(
            response.replayed
                ? `Order ${response.data.order_number} was already placed with this key — showing the original result.`
                : `Order ${response.data.order_number} created.`,
        );

        composer.open = false;
        await list.load(1);
    } catch (exception) {
        if (exception instanceof ApiError) {
            composer.errors = exception.fieldErrors;
            composer.shortfalls = exception.stockShortfalls;
            toasts.error(exception.message);
        }
    } finally {
        composer.busy = false;
    }
}

async function openDetail(row) {
    Object.assign(detail, { open: true, order: row, history: [], busy: true, note: '' });

    try {
        const [order, history] = await Promise.all([
            api.get(`/orders/${row.id}`),
            api.get(`/orders/${row.id}/history`, { per_page: 50 }),
        ]);
        detail.order = order.data;
        detail.history = history.data ?? [];
    } catch (exception) {
        toasts.error(exception.message);
    } finally {
        detail.busy = false;
    }
}

async function changeStatus(status) {
    detail.busy = true;

    try {
        const { data } = await api.patch(`/orders/${detail.order.id}/status`, {
            status,
            note: detail.note || null,
        });
        detail.order = data;
        detail.note = '';
        toasts.success(`Order moved to ${status}.`);
        await Promise.all([refreshHistory(), list.load(list.filters.page)]);
    } catch (exception) {
        toasts.error(exception.message);
    } finally {
        detail.busy = false;
    }
}

async function cancelOrder() {
    if (!globalThis.confirm('Cancel this order and release its reserved stock?')) {
        return;
    }

    detail.busy = true;

    try {
        const { data } = await api.post(`/orders/${detail.order.id}/cancel`);
        detail.order = data;
        toasts.success('Order cancelled and reserved stock released.');
        await Promise.all([refreshHistory(), list.load(list.filters.page)]);
    } catch (exception) {
        toasts.error(exception.message);
    } finally {
        detail.busy = false;
    }
}

async function refreshHistory() {
    const history = await api.get(`/orders/${detail.order.id}/history`, { per_page: 50 });
    detail.history = history.data ?? [];
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

/** Resolve a shortfall's product id back to the label shown in its line. */
function productName(productId) {
    return composer.lines.find((line) => line.product_id === productId)?.label || productId;
}
</script>

<template>
    <section>
        <div class="resource-toolbar">
            <div>
                <p class="eyebrow">Fulfilment</p>
                <h1>Orders</h1>
            </div>
            <button type="button" @click="openComposer">New order</button>
        </div>

        <FilterBar
            :fields="filterFields"
            :model-value="list.filters"
            search-placeholder="Search order number"
            @apply="list.load(1)"
            @reset="list.reset()"
        />

        <p v-if="list.error.value" class="notice error">{{ list.error }}</p>

        <DataTable
            :columns="columns"
            :rows="list.rows.value"
            :loading="list.loading.value"
            :sort-mark="list.sortMark"
            empty-message="No orders yet."
            @sort="list.sortBy"
            @select="openDetail"
        >
            <template #cell-status="{ value }"><StatusBadge :status="value" /></template>
            <template #cell-created_at="{ value }">{{ formatDate(value) }}</template>
            <template #cell-customer_name="{ row }">{{ row.customer_name ?? '—' }}</template>
        </DataTable>

        <PagerBar
            :meta="list.meta"
            :per-page="list.filters.per_page"
            @change-page="list.load"
            @change-per-page="(value) => { list.filters.per_page = value; list.load(1); }"
        />

        <!-- Order composer -->
        <DrawerPanel
            :open="composer.open"
            title="New order"
            subtitle="Stock is reserved when the order is placed"
            @close="composer.open = false"
        >
            <form class="drawer-form" @submit.prevent="submitOrder">
                <label>
                    Customer
                    <SearchSelect
                        v-model="composer.customerId"
                        :fetch="searchCustomers"
                        placeholder="Search customers by name or email"
                        required
                    />
                </label>
                <FieldErrors :errors="composer.errors" field="customer_id" />

                <h3>Line items</h3>
                <div v-for="(line, index) in composer.lines" :key="index" class="line-item">
                    <SearchSelect
                        v-model="line.product_id"
                        :fetch="searchOrderableProducts"
                        :initial-label="line.label"
                        placeholder="Search products by name or SKU"
                        required
                        @select="onProductSelected(line, $event)"
                    />
                    <input v-model="line.quantity" type="number" min="1" required />
                    <button
                        type="button"
                        class="ghost"
                        :disabled="composer.lines.length === 1"
                        @click="removeLine(index)"
                    >
                        Remove
                    </button>
                    <p v-if="line.available !== null" class="eyebrow">
                        {{ line.available }} available
                    </p>
                </div>

                <button type="button" class="ghost" @click="addLine">Add line</button>
                <FieldErrors :errors="composer.errors" field="items" />

                <div v-if="composer.shortfalls.length" class="notice error">
                    <p>Not enough stock for:</p>
                    <ul>
                        <li v-for="shortfall in composer.shortfalls" :key="shortfall.product_id">
                            {{ productName(shortfall.product_id) }} — requested {{ shortfall.requested }},
                            {{ shortfall.available }} available
                        </li>
                    </ul>
                </div>

                <p class="eyebrow">
                    Sent with Idempotency-Key <code>{{ idempotencyKey }}</code>. Retrying this submission replays the
                    original result instead of placing a second order.
                </p>
            </form>

            <template #actions>
                <button type="button" class="ghost" @click="composer.open = false">Cancel</button>
                <button type="button" :disabled="composer.busy" @click="submitOrder">
                    {{ composer.busy ? 'Placing…' : 'Place order' }}
                </button>
            </template>
        </DrawerPanel>

        <!-- Order detail -->
        <DrawerPanel
            :open="detail.open"
            :title="detail.order?.order_number || 'Order'"
            :subtitle="detail.order?.customer_name"
            @close="detail.open = false"
        >
            <div v-if="detail.order">
                <dl class="detail-list">
                    <dt>Status</dt><dd><StatusBadge :status="detail.order.status" /></dd>
                    <dt>Total</dt><dd>{{ detail.order.total_amount }}</dd>
                    <dt>Items</dt><dd>{{ detail.order.items_count }}</dd>
                    <dt>Placed</dt><dd>{{ formatDate(detail.order.created_at) }}</dd>
                    <dt v-if="detail.order.cancelled_at">Cancelled</dt>
                    <dd v-if="detail.order.cancelled_at">{{ formatDate(detail.order.cancelled_at) }}</dd>
                </dl>

                <div class="nested-list">
                    <h3>Line items</h3>
                    <div class="table-scroll">
                    <table class="mini-table">
                        <thead><tr><th>Product</th><th>Qty</th><th>Unit</th><th>Line total</th></tr></thead>
                        <tbody>
                            <tr v-for="item in detail.order.items ?? []" :key="item.id">
                                <td>{{ item.product_name ?? item.product_id }}</td>
                                <td>{{ item.quantity }}</td>
                                <td>{{ item.unit_price }}</td>
                                <td>{{ item.line_total }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>

                <div class="nested-list">
                    <h3>Status history</h3>
                    <ol class="timeline">
                        <li v-for="entry in detail.history" :key="entry.id">
                            <span class="timeline-when">{{ formatDate(entry.created_at) }}</span>
                            <span>
                                {{ entry.from_status ?? 'created' }} → <strong>{{ entry.to_status }}</strong>
                            </span>
                            <span v-if="entry.note" class="eyebrow">{{ entry.note }}</span>
                        </li>
                        <li v-if="!detail.history.length">No transitions recorded.</li>
                    </ol>
                </div>

                <div v-if="nextStatuses[detail.order.status]?.length" class="drawer-form">
                    <label>
                        Transition note (optional)
                        <input v-model="detail.note" type="text" placeholder="Why is this moving?" />
                    </label>
                </div>
            </div>

            <template #actions>
                <button
                    v-for="status in nextStatuses[detail.order?.status] ?? []"
                    :key="status"
                    type="button"
                    :disabled="detail.busy"
                    @click="changeStatus(status)"
                >
                    Mark {{ status }}
                </button>
                <button
                    v-if="detail.order && !['completed', 'cancelled'].includes(detail.order.status)"
                    type="button"
                    class="ghost danger"
                    :disabled="detail.busy"
                    @click="cancelOrder"
                >
                    Cancel order
                </button>
                <button type="button" class="ghost" @click="detail.open = false">Close</button>
            </template>
        </DrawerPanel>
    </section>
</template>
