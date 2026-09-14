/**
 * Alpine.data('toastContainer') - Toast notification container
 * Renders toast items from the global $store.toast
 */
import Alpine from '@alpinejs/csp';

Alpine.data('toastContainer', () => ({
    items() {
        return Alpine.store('toast').items;
    },

    dismiss(id) {
        Alpine.store('toast').dismiss(id);
    },

    activateAction(id) {
        Alpine.store('toast').activateAction(id);
    },

    iconFor(type) {
        return Alpine.store('toast').iconFor(type);
    }
}));
