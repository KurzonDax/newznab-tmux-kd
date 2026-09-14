import Alpine from '@alpinejs/csp';

// Sort dropdown
Alpine.data('sortDropdown', () => ({
    open: false,

    toggle() { this.open = !this.open; },
    close() { this.open = false; },
    chevronClass() { return this.open ? 'rotate-180' : ''; }
}));
