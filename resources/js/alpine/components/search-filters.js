import Alpine from '@alpinejs/csp';

Alpine.data('searchFilters', () => ({
    kind: 'cat',
    root: null,
    init() { this.root = this.$el; },
    get isText() { return ['actors', 'director', 'title', 'plot'].includes(this.kind); },
    close() { this.root.open = false; },
    closeAndFocus() { this.close(); this.$refs.trigger.focus(); },
}));

import { modalLifecycle } from './modal-lifecycle.js';

Alpine.data('searchFeed', () => ({
    ...modalLifecycle(),
    open: false,
    init() { this.initModal(); },
    openFeed() { this.open = true; },
    close() { this.open = false; },
    destroy() { this._modalTeardown?.(); },
}));
