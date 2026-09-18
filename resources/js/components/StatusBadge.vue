<script setup>
defineProps({
    status: { type: String, required: true },
});
</script>

<template>
    <span class="badge" :class="`badge-${status}`">{{ status.replace('_', ' ') }}</span>
</template>
