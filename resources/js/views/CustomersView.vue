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

const toasts = useToasts();
const list = useResourceList('/customers', {
    defaultFilters: { from: '', to: '' },
});

const filterFields = [
    { key: 'from', label: 'Created from', type: 'date' },
    { key: 'to', label: 'Created to', type: 'date' },
];

const columns = [
    { key: 'name', label: 'Name', sortKey: 'name' },
    { key: 'email', label: 'Email', sortKey: 'email' },
    { key: 'phone', label: 'Phone', sortKey: 'phone' },
    { key: 'orders_count', label: 'Orders', sortKey: 'orders_count' },
    { key: 'total_order_amount', label: 'Total spend', sortKey: 'total_order_amount' },
    { key: 'customer_value_rank', label: 'Value rank', sortKey: 'customer_value_rank' },
    { key: 'created_at', label: 'Created', sortKey: 'created_at' },
];

const drawer = reactive({ open: false, mode: 'detail', record: null, busy: false, errors: {} });
const form = reactive({ name: '', email: '', phone: '' });

onMounted(() => list.load(1));

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString() : '—';
}

function resetForm(record = null) {
    Object.assign(form, {
        name: record?.name ?? '',
        email: record?.email ?? '',
        phone: record?.phone ?? '',
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
        const { data } = await api.get(`/customers/${row.id}`);
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
        const payload = {
            name: form.name,
            email: form.email,
            phone: form.phone || null,
        };

        if (drawer.mode === 'create') {
            const { data } = await api.post('/customers', payload);
            toasts.success(`Customer “${data.name}” created.`);
        } else {
            const { data } = await api.put(`/customers/${drawer.record.id}`, payload);
            toasts.success(`Customer “${data.name}” updated.`);
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
        await api.delete(`/customers/${drawer.record.id}`);
        toasts.success('Customer deleted.');
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
                <p class="eyebrow">Directory</p>
                <h1>Customers</h1>
            </div>
            <button type="button" @click="openCreate">New customer</button>
        </div>

        <FilterBar
            :fields="filterFields"
            :model-value="list.filters"
            search-placeholder="Search name, email or phone"
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
            :title="drawer.mode === 'create' ? 'New customer' : drawer.record?.name || 'Customer'"
            :subtitle="drawer.mode === 'detail' ? drawer.record?.email : ''"
            @close="drawer.open = false"
        >
            <div v-if="drawer.mode === 'detail'">
                <p v-if="drawer.busy">Loading…</p>
                <dl v-else-if="drawer.record" class="detail-list">
                    <dt>ID</dt><dd>{{ drawer.record.id }}</dd>
                    <dt>Name</dt><dd>{{ drawer.record.name }}</dd>
                    <dt>Email</dt><dd>{{ drawer.record.email }}</dd>
                    <dt>Phone</dt><dd>{{ drawer.record.phone ?? '—' }}</dd>
                    <dt>Orders</dt><dd>{{ drawer.record.orders_count ?? '—' }}</dd>
                    <dt>Completed orders</dt><dd>{{ drawer.record.completed_orders_count ?? '—' }}</dd>
                    <dt>Total spend</dt><dd>{{ drawer.record.total_order_amount ?? '—' }}</dd>
                    <dt>Value rank</dt><dd>{{ drawer.record.customer_value_rank ?? '—' }}</dd>
                </dl>
            </div>

            <form v-else class="drawer-form" @submit.prevent="submit">
                <label>Name <input v-model="form.name" type="text" required /></label>
                <FieldErrors :errors="drawer.errors" field="name" />

                <label>Email <input v-model="form.email" type="email" required /></label>
                <FieldErrors :errors="drawer.errors" field="email" />

                <label>Phone <input v-model="form.phone" type="text" /></label>
                <FieldErrors :errors="drawer.errors" field="phone" />
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
