<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * A combobox whose options come from a search endpoint.
 *
 * The dataset behind these fields is far too large to load into a <select>, so
 * options are fetched per keystroke (debounced) and the server does the
 * filtering. The chosen label is kept locally so the field still reads
 * correctly after the option list has moved on.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    // Receives the current query, returns [{ value, label, hint }].
    fetch: { type: Function, required: true },
    placeholder: { type: String, default: 'Search…' },
    // Label to show for an id that was set from outside this component.
    initialLabel: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'select']);

const root = ref(null);
const input = ref(null);
const query = ref('');
const label = ref(props.initialLabel);
const options = ref([]);
const open = ref(false);
const loading = ref(false);
const highlighted = ref(-1);

let debounceTimer = null;
// Guards against a slow earlier request overwriting a newer one's results.
let requestToken = 0;

watch(() => props.initialLabel, (value) => {
    if (!open.value) {
        label.value = value;
    }
});

watch(() => props.modelValue, (value) => {
    if (!value) {
        label.value = '';
    }
});

onMounted(() => document.addEventListener('mousedown', onDocumentClick));
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentClick);
    clearTimeout(debounceTimer);
});

function onDocumentClick(event) {
    if (root.value && !root.value.contains(event.target)) {
        close();
    }
}

function close() {
    open.value = false;
    highlighted.value = -1;
    query.value = '';
}

async function runSearch(term) {
    const token = ++requestToken;
    loading.value = true;

    try {
        const results = await props.fetch(term);

        if (token === requestToken) {
            options.value = results;
            highlighted.value = results.length ? 0 : -1;
        }
    } catch {
        if (token === requestToken) {
            options.value = [];
        }
    } finally {
        if (token === requestToken) {
            loading.value = false;
        }
    }
}

function onInput() {
    open.value = true;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => runSearch(query.value), 250);
}

async function onFocus() {
    open.value = true;

    if (!options.value.length) {
        await runSearch('');
    }

    await nextTick();
}

function choose(option) {
    label.value = option.label;
    emit('update:modelValue', option.value);
    emit('select', option);
    close();
}

function clear() {
    label.value = '';
    emit('update:modelValue', '');
    emit('select', null);
    options.value = [];
}

function onKeydown(event) {
    if (!open.value && ['ArrowDown', 'Enter'].includes(event.key)) {
        onFocus();

        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value = Math.min(highlighted.value + 1, options.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value = Math.max(highlighted.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();

        if (options.value[highlighted.value]) {
            choose(options.value[highlighted.value]);
        }
    } else if (event.key === 'Escape') {
        close();
    }
}
</script>

<template>
    <div ref="root" class="search-select" :class="{ 'is-open': open }">
        <div class="search-select-control">
            <input
                ref="input"
                type="text"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="open"
                :disabled="disabled"
                :required="required && !modelValue"
                :placeholder="modelValue && !open ? '' : placeholder"
                :value="open ? query : label"
                @input="query = $event.target.value; onInput()"
                @focus="onFocus"
                @keydown="onKeydown"
            />
            <button
                v-if="modelValue && !disabled"
                type="button"
                class="search-select-clear"
                aria-label="Clear selection"
                @click="clear"
            >
                ×
            </button>
        </div>

        <ul v-if="open" class="search-select-list" role="listbox">
            <li v-if="loading" class="search-select-status">Searching…</li>
            <li v-else-if="!options.length" class="search-select-status">No matches</li>
            <li
                v-for="(option, index) in options"
                v-else
                :key="option.value"
                role="option"
                :aria-selected="index === highlighted"
                :class="{ highlighted: index === highlighted }"
                @mousedown.prevent="choose(option)"
                @mouseenter="highlighted = index"
            >
                <span>{{ option.label }}</span>
                <span v-if="option.hint" class="search-select-hint">{{ option.hint }}</span>
            </li>
        </ul>
    </div>
</template>
