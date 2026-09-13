/**
 * Alpine.store('toast') - Global toast notification state
 * Provides a showToast() method accessible from any Alpine component.
 */
import Alpine from '@alpinejs/csp';

Alpine.store('toast', {
    items: [],
    _nextId: 0,
    duration: 5000,

    init() {
        this.duration = document.querySelector('[data-public-toasts]') ? 3200 : 5000;
        // Process server-side flash messages on load
        this._processFlashMessages();
    },

    /** Show a toast notification */
    show(message, type, action = null) {
        type = type || 'success';
        if (message && typeof message === 'object') {
            action = message.action || action;
            message = message.message;
        }
        if (typeof message !== 'string' || !message) return;
        const id = ++this._nextId;
        this.items.push({ id, message, type, action: this._normalizeAction(action), removing: false });

        setTimeout(() => this.dismiss(id), this.duration);
        return id;
    },

    /** Dismiss a toast by id */
    dismiss(id) {
        const item = this.items.find(t => t.id === id);
        if (item && !item.removing) {
            item.removing = true;
            setTimeout(() => {
                this.items = this.items.filter(t => t.id !== id);
            }, 300);
        }
    },

    activateAction(id) {
        const item = this.items.find(toast => toast.id === id);
        if (!item?.action || item.removing) return;
        this.dismiss(id);
        item.action.callback?.();
    },

    _normalizeAction(action) {
        if (!action || typeof action.label !== 'string' || !action.label.trim()) return null;
        if (typeof action.callback === 'function') {
            return { label: action.label, callback: action.callback, href: null };
        }
        if (typeof action.href !== 'string') return null;
        try {
            const url = new URL(action.href, window.location.href);
            if (url.protocol === 'https:' || url.protocol === 'http:') {
                return { label: action.label, href: url.href, callback: null };
            }
        } catch {
            return null;
        }
        return null;
    },

    /** Get icon class for a toast type */
    iconFor(type) {
        if (type === 'success') return 'fa-check-circle';
        if (type === 'error') return 'fa-exclamation-circle';
        if (type === 'warning') return 'fa-exclamation-triangle';
        return 'fa-info-circle';
    },

    /** Process flash messages from the server */
    _processFlashMessages() {
        const el = document.getElementById('flash-messages-data');
        if (!el) return;

        const messages = JSON.parse(el.dataset.messages || '{}');

        for (const type of ['success', 'error', 'warning', 'info']) {
            const entries = Array.isArray(messages[type]) ? messages[type] : [messages[type]];
            entries.forEach(message => this.show(message, type));
        }
    }
});

// Keep backward-compatible global showToast for non-Alpine code
window.showToast = function(message, type, action) {
    return Alpine.store('toast').show(message, type, action);
};
