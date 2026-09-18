<script setup>
const props = defineProps({
    meta: { type: Object, required: true },
    perPage: { type: Number, required: true },
});

const emit = defineEmits(['change-page', 'change-per-page']);
const perPageOptions = [10, 15, 25, 50, 100];
</script>

<template>
    <div class="pager">
        <div class="summary">
            <template v-if="props.meta.total">
                Showing <strong>{{ props.meta.from }}</strong>–<strong>{{ props.meta.to }}</strong>
                of <strong>{{ props.meta.total }}</strong>
            </template>
            <template v-else>No records</template>
        </div>

        <div class="page-size">
            <label>
                Per page
                <select
                    :value="props.perPage"
                    @change="emit('change-per-page', Number($event.target.value))"
                >
                    <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
                </select>
            </label>
        </div>

        <div class="page-actions">
            <button
                type="button"
                :disabled="props.meta.current_page <= 1"
                @click="emit('change-page', props.meta.current_page - 1)"
            >
                Previous
            </button>
            <span>Page {{ props.meta.current_page }} of {{ props.meta.last_page || 1 }}</span>
            <button
                type="button"
                :disabled="props.meta.current_page >= props.meta.last_page"
                @click="emit('change-page', props.meta.current_page + 1)"
            >
                Next
            </button>
        </div>
    </div>
</template>
