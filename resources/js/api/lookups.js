import { api } from './client.js';

/**
 * Option loaders for the searchable selects.
 *
 * Each returns at most one page of matches, letting the server do the
 * filtering — the catalog and customer tables are far too large to load into
 * the browser.
 */
const PAGE_SIZE = 20;

/** Search customers by name, email or phone. */
export async function searchCustomers(search) {
    const { data } = await api.get('/customers', { search, per_page: PAGE_SIZE, sort: 'name' });

    return (data ?? []).map((customer) => ({
        value: customer.id,
        label: customer.name,
        hint: customer.email,
    }));
}

/** Search orderable products, showing what each one has available. */
export async function searchOrderableProducts(search) {
    const { data } = await api.get('/products', {
        search,
        status: 'active',
        per_page: PAGE_SIZE,
        sort: 'name',
    });

    return (data ?? []).map((product) => ({
        value: product.id,
        label: product.name,
        hint: `${product.available_stock} available`,
    }));
}

/** Search the whole catalog, including draft and archived products. */
export async function searchProducts(search) {
    const { data } = await api.get('/products', { search, per_page: PAGE_SIZE, sort: 'name' });

    return (data ?? []).map((product) => ({
        value: product.id,
        label: product.name,
        hint: product.sku,
    }));
}

/** Search categories by name or slug. */
export async function searchCategories(search) {
    const { data } = await api.get('/categories', { search, per_page: PAGE_SIZE, sort: 'name' });

    return (data ?? []).map((category) => ({
        value: category.id,
        label: category.name,
        hint: category.slug,
    }));
}
