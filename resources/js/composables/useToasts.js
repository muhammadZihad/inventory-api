import { reactive } from 'vue';

const toasts = reactive([]);
let nextId = 1;

/**
 * A small global toast queue.
 *
 * Kept module-level so any view can raise a notification without threading
 * callbacks through the component tree.
 */
export function useToasts() {
    function push(message, type = 'info', timeout = 4500) {
        const id = nextId++;
        toasts.push({ id, message, type });

        if (timeout) {
            setTimeout(() => dismiss(id), timeout);
        }

        return id;
    }

    function dismiss(id) {
        const index = toasts.findIndex((toast) => toast.id === id);

        if (index !== -1) {
            toasts.splice(index, 1);
        }
    }

    return {
        toasts,
        dismiss,
        success: (message) => push(message, 'success'),
        error: (message) => push(message, 'error', 7000),
        info: (message) => push(message, 'info'),
    };
}
