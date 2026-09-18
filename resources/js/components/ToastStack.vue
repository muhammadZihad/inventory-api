<script setup>
import { useToasts } from '../composables/useToasts.js';

const { toasts, dismiss } = useToasts();
</script>

<template>
    <div class="toast-stack" aria-live="polite">
        <div v-for="toast in toasts" :key="toast.id" class="toast" :class="toast.type">
            <span>{{ toast.message }}</span>
            <button type="button" class="toast-close" @click="dismiss(toast.id)" aria-label="Dismiss">×</button>
        </div>
    </div>
</template>
