<script setup>
import { ref } from 'vue';
import { useAuth } from './composables/useAuth.js';
import { useToasts } from './composables/useToasts.js';
import LoginPanel from './components/LoginPanel.vue';
import ToastStack from './components/ToastStack.vue';
import DashboardView from './views/DashboardView.vue';
import ProductsView from './views/ProductsView.vue';
import CategoriesView from './views/CategoriesView.vue';
import CustomersView from './views/CustomersView.vue';
import InventoryView from './views/InventoryView.vue';
import OrdersView from './views/OrdersView.vue';

const { user, isAuthenticated, logout } = useAuth();
const toasts = useToasts();

const tabs = [
    { key: 'dashboard', label: 'Dashboard', component: DashboardView },
    { key: 'orders', label: 'Orders', component: OrdersView },
    { key: 'inventory', label: 'Inventory', component: InventoryView },
    { key: 'products', label: 'Products', component: ProductsView },
    { key: 'categories', label: 'Categories', component: CategoriesView },
    { key: 'customers', label: 'Customers', component: CustomersView },
];

const activeTab = ref('dashboard');
const focusProductId = ref('');

/** Jump from a product to its stock record with the list pre-filtered. */
function inspectInventory(product) {
    focusProductId.value = product?.product_id ?? product?.id ?? '';
    activeTab.value = 'inventory';
}

function selectTab(key) {
    if (key !== 'inventory') {
        focusProductId.value = '';
    }

    activeTab.value = key;
}

async function signOut() {
    await logout();
    toasts.info('Signed out.');
    activeTab.value = 'dashboard';
}
</script>

<template>
    <div class="shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">Order Processing &amp; Inventory</p>
                <h1>Operations Console</h1>
            </div>

            <div v-if="isAuthenticated" class="topbar-actions">
                <span class="eyebrow">{{ user?.name }} · {{ user?.email }}</span>
                <a class="ghost-link" href="/docs" target="_blank" rel="noopener">API docs</a>
                <button type="button" class="ghost" @click="signOut">Sign out</button>
            </div>
        </header>

        <LoginPanel v-if="!isAuthenticated" />

        <main v-else class="workspace">
            <nav class="tabs">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    :class="{ active: activeTab === tab.key }"
                    @click="selectTab(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <component
                :is="tabs.find((tab) => tab.key === activeTab).component"
                :key="activeTab"
                :focus-product-id="activeTab === 'inventory' ? focusProductId : undefined"
                @inspect-inventory="inspectInventory"
            />
        </main>

        <ToastStack />
    </div>
</template>
