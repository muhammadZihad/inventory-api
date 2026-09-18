<script setup>
defineProps({
    errors: { type: Object, default: () => ({}) },
    field: { type: String, required: true },
});
</script>

<template>
    <p v-if="errors[field]" class="field-error">{{ errors[field][0] }}</p>
</template>
