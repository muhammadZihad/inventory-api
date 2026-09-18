<script setup>
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
});

const emit = defineEmits(['close']);
</script>

<template>
    <div v-if="open" class="drawer-backdrop" @click.self="emit('close')">
        <aside class="drawer" role="dialog" aria-modal="true">
            <header class="drawer-header">
                <div>
                    <h2>{{ title }}</h2>
                    <p v-if="subtitle" class="eyebrow">{{ subtitle }}</p>
                </div>
                <button type="button" class="ghost" @click="emit('close')">Close</button>
            </header>

            <div class="drawer-body">
                <slot />
            </div>

            <footer v-if="$slots.actions" class="drawer-actions">
                <slot name="actions" />
            </footer>
        </aside>
    </div>
</template>
