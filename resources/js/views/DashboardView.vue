<script setup>
import { onMounted, reactive, ref } from 'vue';
import { api } from '../api/client.js';
import { useToasts } from '../composables/useToasts.js';
import StatusBadge from '../components/StatusBadge.vue';

const toasts = useToasts();

const filters = reactive({ status: '', from: '', to: '' });
const summary = ref(null);
const lowStock = ref([]);
const recentOrders = ref([]);
const loading = ref(false);

onMounted(load);

async function load() {
    loading.value = true;

    try {
        const [report, inventory, orders] = await Promise.all([
            api.get('/orders/reports/summary', { ...filters }),
            // Availability ascending surfaces whatever is closest to selling out.
            api.get('/inventory', { sort: 'available_quantity', per_page: 5 }),
            api.get('/orders', { sort: '-created_at', per_page: 5 }),
        ]);

        summary.value = report.data;
        lowStock.value = inventory.data ?? [];
        recentOrders.value = orders.data ?? [];
    } catch (exception) {
        toasts.error(exception.message);
    } finally {
        loading.value = false;
    }
}

function reset() {
    Object.assign(filters, { status: '', from: '', to: '' });
    load();
}

const cards = [
    { key: 'orders_count', label: 'Orders' },
    { key: 'total_sales', label: 'Total sales', money: true },
    { key: 'average_order_value', label: 'Average order', money: true },
    { key: 'pending_count', label: 'Pending' },
    { key: 'confirmed_count', label: 'Confirmed' },
    { key: 'completed_count', label: 'Completed' },
    { key: 'cancelled_count', label: 'Cancelled' },
];
</script>

<template>
    <section>
        <div class="resource-toolbar">
            <div>
                <p class="eyebrow">Overview</p>
                <h1>Dashboard</h1>
            </div>
            <button type="button" :disabled="loading" @click="load">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
        </div>

        <form class="filters" @submit.prevent="load">
            <label>
                Status
                <select v-model="filters.status">
                    <option value="">Any</option>
                    <option value="pending">pending</option>
                    <option value="confirmed">confirmed</option>
                    <option value="completed">completed</option>
                    <option value="cancelled">cancelled</option>
                </select>
            </label>
            <label>From <input v-model="filters.from" type="date" /></label>
            <label>To <input v-model="filters.to" type="date" /></label>
            <div class="filter-actions">
                <button type="submit">Apply</button>
                <button type="button" class="ghost" @click="reset">Reset</button>
            </div>
        </form>

        <div v-if="summary" class="stat-grid">
            <article v-for="card in cards" :key="card.key" class="stat-card">
                <p class="stat-label">{{ card.label }}</p>
                <p class="stat-value">
                    <span v-if="card.money">$</span>{{ summary[card.key] }}
                </p>
            </article>
        </div>

        <div class="split-panels">
            <section class="panel">
                <h2>Closest to selling out</h2>
                <p class="eyebrow">Ranked by available stock: on hand minus units reserved by open orders.</p>
                <div class="table-scroll">
                <table class="mini-table">
                    <thead>
                        <tr><th>Product</th><th>On hand</th><th>Reserved</th><th>Available</th></tr>
                    </thead>
                    <tbody>
                        <tr v-if="!lowStock.length"><td colspan="4">No inventory records.</td></tr>
                        <tr v-for="item in lowStock" :key="item.id">
                            <td>{{ item.product_title ?? item.product_id }}</td>
                            <td>{{ item.quantity_on_hand }}</td>
                            <td>{{ item.quantity_reserved }}</td>
                            <td :class="{ 'text-warn': item.available_quantity === 0 }">
                                {{ item.available_quantity }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </section>

            <section class="panel">
                <h2>Latest orders</h2>
                <p class="eyebrow">The most recent orders visible to your account.</p>
                <div class="table-scroll">
                <table class="mini-table">
                    <thead>
                        <tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <tr v-if="!recentOrders.length"><td colspan="4">No orders yet.</td></tr>
                        <tr v-for="order in recentOrders" :key="order.id">
                            <td>{{ order.order_number }}</td>
                            <td>{{ order.customer_name ?? '—' }}</td>
                            <td>{{ order.total_amount }}</td>
                            <td><StatusBadge :status="order.status" /></td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </section>
        </div>
    </section>
</template>
