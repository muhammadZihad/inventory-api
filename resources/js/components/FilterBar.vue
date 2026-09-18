<script setup>
/**
 * Renders a declarative filter set.
 *
 * Each view describes its filters as data, so every list gets the same
 * search-plus-filters behaviour without repeating the markup.
 */
import SearchSelect from './SearchSelect.vue';

defineProps({
    // Each field is { key, label, type, ... }. A field of type 'remote'
    // additionally supplies a `fetch` loader and renders a searchable select.
    fields: { type: Array, default: () => [] },
    modelValue: { type: Object, required: true },
    searchPlaceholder: { type: String, default: 'Search' },
    searchable: { type: Boolean, default: true },
});

const emit = defineEmits(['apply', 'reset']);
</script>

<template>
    <form class="filters" @submit.prevent="emit('apply')">
        <label v-if="searchable">
            Search
            <input v-model="modelValue.search" type="search" :placeholder="searchPlaceholder" />
        </label>

        <label v-for="field in fields" :key="field.key">
            {{ field.label }}
            <SearchSelect
                v-if="field.type === 'remote'"
                v-model="modelValue[field.key]"
                :fetch="field.fetch"
                :initial-label="field.initialLabel"
                :placeholder="field.placeholder || 'Search…'"
                @select="emit('apply')"
            />
            <select v-else-if="field.type === 'select'" v-model="modelValue[field.key]">
                <option v-for="option in field.options" :key="option.value ?? option" :value="option.value ?? option">
                    {{ option.label ?? (option === '' ? 'Any' : option) }}
                </option>
            </select>
            <input
                v-else
                v-model="modelValue[field.key]"
                :type="field.type || 'text'"
                :step="field.step"
                :placeholder="field.placeholder"
            />
        </label>

        <div class="filter-actions">
            <button type="submit">Apply</button>
            <button type="button" class="ghost" @click="emit('reset')">Reset</button>
        </div>
    </form>
</template>
